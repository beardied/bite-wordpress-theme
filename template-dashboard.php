<?php
/**
 * Template Name: BITE Dashboard
 *
 * The main dashboard for logged-in users. Shows overview of accessible sites,
 * niches, quick stats, and navigation to tools.
 *
 * @package BITE-theme
 */

// Redirect non-logged-in users to login
if ( ! is_user_logged_in() ) {
    wp_redirect( wp_login_url() );
    exit;
}

// Process form submission for adding site
$form_message = '';
$form_error = '';

if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['bite_add_site_submit'] ) ) {
    // Verify nonce
    if ( ! isset( $_POST['bite_add_site_nonce'] ) || ! wp_verify_nonce( $_POST['bite_add_site_nonce'], 'bite_add_site' ) ) {
        $form_error = 'Security check failed. Please try again.';
    } else {
        $current_user_id = get_current_user_id();
        
        // Check if user can add more sites
        if ( ! bite_user_can_add_site( $current_user_id ) ) {
            $form_error = 'You have reached the maximum number of sites for your plan. Please upgrade to add more sites.';
        } else {
            // Process uploaded JSON file
            $gsc_credentials = '';
            if ( isset( $_FILES['bite_gsc_credentials'] ) && ! empty( $_FILES['bite_gsc_credentials']['tmp_name'] ) ) {
                $uploaded_file = $_FILES['bite_gsc_credentials'];
                
                if ( $uploaded_file['type'] === 'application/json' || pathinfo( $uploaded_file['name'], PATHINFO_EXTENSION ) === 'json' ) {
                    $json_content = file_get_contents( $uploaded_file['tmp_name'] );
                    $credentials_data = json_decode( $json_content, true );
                    
                    if ( $credentials_data && isset( $credentials_data['client_email'] ) && isset( $credentials_data['private_key'] ) ) {
                        $gsc_credentials = $json_content;
                    } else {
                        $form_error = 'Invalid JSON file. Please upload a valid Google Service Account key file.';
                    }
                } else {
                    $form_error = 'Please upload a valid JSON file.';
                }
            } else {
                $form_error = 'Please upload your Google Service Account JSON key file.';
            }
            
            if ( empty( $form_error ) ) {
                global $wpdb;
                
                // Get or create user's personal niche
                $niches_table = $wpdb->prefix . 'bite_niches';
                $user_niche_name = sanitize_text_field( $_POST['bite_site_name'] ) . ' - ' . $current_user_id;
                
                $existing_niche = $wpdb->get_var( $wpdb->prepare(
                    "SELECT niche_id FROM $niches_table WHERE niche_name = %s",
                    $user_niche_name
                ) );
                
                if ( $existing_niche ) {
                    $niche_id = $existing_niche;
                } else {
                    $wpdb->insert( $niches_table, array( 'niche_name' => $user_niche_name ) );
                    $niche_id = $wpdb->insert_id;
                }
                
                // Insert site
                $sites_table = $wpdb->prefix . 'bite_sites';
                $site_data = array(
                    'niche_id'        => $niche_id,
                    'name'            => sanitize_text_field( $_POST['bite_site_name'] ),
                    'domain'          => sanitize_text_field( $_POST['bite_domain'] ),
                    'gsc_property'    => sanitize_text_field( $_POST['bite_gsc_property'] ),
                    'gsc_credentials' => $gsc_credentials,
                    'backfill_status' => 'pending',
                );
                
                $result = $wpdb->insert( $sites_table, $site_data );
                
                if ( $result ) {
                    $new_site_id = $wpdb->insert_id;
                    
                    // Create metrics table
                    bite_create_metrics_table_for_site( $new_site_id );
                    
                    // Grant user access to this site
                    bite_grant_user_site_access( $current_user_id, $new_site_id, $current_user_id );
                    
                    $form_message = 'Site added successfully! Data will start appearing within a few minutes as we fetch your Google Search Console data.';
                } else {
                    $form_error = 'Failed to add site. Please try again.';
                }
            }
        }
    }
}

get_header();

global $wpdb;
$current_user    = wp_get_current_user();
$current_user_id = get_current_user_id();
$user_site_ids   = bite_get_user_sites( $current_user_id );
$is_admin        = current_user_can( 'manage_options' );

// Get user plan info
$user_plan = bite_get_user_plan( $current_user_id );
$site_limit = bite_get_user_site_limit( $current_user_id );
$remaining_sites = bite_get_user_remaining_sites( $current_user_id );
$can_add_more = bite_user_can_add_site( $current_user_id );

// Get site details
$user_sites = array();
if ( ! empty( $user_site_ids ) ) {
    $sites_table  = $wpdb->prefix . 'bite_sites';
    $niches_table = $wpdb->prefix . 'bite_niches';
    $placeholders = implode( ', ', array_fill( 0, count( $user_site_ids ), '%d' ) );
    $user_sites   = $wpdb->get_results( $wpdb->prepare(
        "SELECT s.site_id, s.name, s.domain, s.gsc_property, s.created_at, n.niche_name 
         FROM $sites_table s 
         LEFT JOIN $niches_table n ON s.niche_id = n.niche_id 
         WHERE s.site_id IN ($placeholders) 
         ORDER BY s.name ASC",
        $user_site_ids
    ) );
}

// Get unique niches for this user
$user_niches = array();
foreach ( $user_sites as $site ) {
    if ( ! empty( $site->niche_name ) && ! in_array( $site->niche_name, $user_niches ) ) {
        $user_niches[] = $site->niche_name;
    }
}
sort( $user_niches );

// Get quick stats for the last 30 days
$quick_stats = array(
    'total_clicks'      => 0,
    'total_impressions' => 0,
    'avg_ctr'           => 0,
    'avg_position'      => 0,
    'site_count'        => count( $user_sites ),
);

if ( ! empty( $user_site_ids ) ) {
    $summary_table = $wpdb->prefix . 'bite_daily_summary';
    $placeholders  = implode( ', ', array_fill( 0, count( $user_site_ids ), '%d' ) );
    $start_date    = date( 'Y-m-d', strtotime( '-30 days' ) );
    $end_date      = date( 'Y-m-d' );
    
    $stats = $wpdb->get_row( $wpdb->prepare(
        "SELECT 
            SUM(total_clicks) as total_clicks,
            SUM(total_impressions) as total_impressions,
            AVG(total_ctr) as avg_ctr,
            AVG(total_position) as avg_position
         FROM $summary_table 
         WHERE site_id IN ($placeholders) 
         AND date BETWEEN %s AND %s",
        array_merge( $user_site_ids, array( $start_date, $end_date ) )
    ) );
    
    if ( $stats ) {
        $quick_stats['total_clicks']      = $stats->total_clicks ?: 0;
        $quick_stats['total_impressions'] = $stats->total_impressions ?: 0;
        $quick_stats['avg_ctr']           = $stats->avg_ctr ?: 0;
        $quick_stats['avg_position']      = $stats->avg_position ?: 0;
    }
}

// Calculate overall CTR from totals
if ( $quick_stats['total_impressions'] > 0 ) {
    $quick_stats['calculated_ctr'] = ( $quick_stats['total_clicks'] / $quick_stats['total_impressions'] ) * 100;
} else {
    $quick_stats['calculated_ctr'] = 0;
}

// Get plan display name
$plan_names = array(
    'hosting'    => 'OrangeWidow Hosting',
    'solo'       => 'Solo',
    'pro'        => 'Pro',
    'agency'     => 'Agency',
    'enterprise' => 'Enterprise',
);
$plan_display = isset( $plan_names[ $user_plan ] ) ? $plan_names[ $user_plan ] : 'Solo';

?>

<div class="bite-dashboard-wrapper">
    
    <?php get_template_part( 'includes/dashboard-sidebar' ); ?>

    <!-- Main Content -->
    <main id="main" class="bite-dashboard-main-content" role="main">
        
        <!-- Welcome Section -->
        <section class="bite-dashboard-welcome">
            <div class="bite-welcome-content">
                <h1 class="bite-welcome-title">
                    Welcome back, <?php echo esc_html( $current_user->display_name ); ?>!
                </h1>
                <p class="bite-welcome-subtitle">
                    You have access to 
                    <strong><?php echo count( $user_sites ); ?> site<?php echo count( $user_sites ) !== 1 ? 's' : ''; ?></strong>
                    <?php if ( ! empty( $user_niches ) ) : ?>
                        across <strong><?php echo count( $user_niches ); ?> niche<?php echo count( $user_niches ) !== 1 ? 's' : ''; ?></strong>
                    <?php endif; ?>.
                    <span class="bite-plan-badge">Plan: <?php echo esc_html( $plan_display ); ?></span>
                </p>
            </div>
        </section>

        <!-- Quick Stats Cards -->
        <?php if ( ! empty( $user_sites ) ) : ?>
        <section class="bite-dashboard-stats">
            <div class="bite-stats-grid">
                <div class="bite-stat-card bite-stat-clicks">
                    <div class="bite-stat-icon">👆</div>
                    <div class="bite-stat-content">
                        <span class="bite-stat-value"><?php echo esc_html( number_format( $quick_stats['total_clicks'] ) ); ?></span>
                        <span class="bite-stat-label">Total Clicks (30 days)</span>
                    </div>
                </div>
                
                <div class="bite-stat-card bite-stat-impressions">
                    <div class="bite-stat-icon">👁️</div>
                    <div class="bite-stat-content">
                        <span class="bite-stat-value"><?php echo esc_html( number_format( $quick_stats['total_impressions'] ) ); ?></span>
                        <span class="bite-stat-label">Total Impressions (30 days)</span>
                    </div>
                </div>
                
                <div class="bite-stat-card bite-stat-ctr">
                    <div class="bite-stat-icon">📈</div>
                    <div class="bite-stat-content">
                        <span class="bite-stat-value"><?php echo esc_html( number_format( $quick_stats['calculated_ctr'], 2 ) ); ?>%</span>
                        <span class="bite-stat-label">Average CTR</span>
                    </div>
                </div>
                
                <div class="bite-stat-card bite-stat-position">
                    <div class="bite-stat-icon">🎯</div>
                    <div class="bite-stat-content">
                        <span class="bite-stat-value"><?php echo esc_html( number_format( $quick_stats['avg_position'], 1 ) ); ?></span>
                        <span class="bite-stat-label">Avg Position</span>
                    </div>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- Sites Section -->
        <section class="bite-dashboard-section bite-sites-section">
            <div class="bite-section-header">
                <h2>Your Sites</h2>
                <div class="bite-section-meta">
                    <span class="bite-badge"><?php echo count( $user_sites ); ?> 
                        <?php echo count( $user_sites ) === 1 ? 'site' : 'sites'; ?></span>
                    <?php if ( $site_limit > 0 ) : ?>
                        <span class="bite-limit-badge"><?php echo $remaining_sites; ?> remaining</span>
                    <?php elseif ( $site_limit === 0 ) : ?>
                        <span class="bite-limit-badge unlimited">Unlimited</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ( ! empty( $user_sites ) ) : ?>
                <div class="bite-sites-list">
                    <?php foreach ( $user_sites as $site ) : ?>
                        <div class="bite-site-card">
                            <div class="bite-site-header">
                                <h3 class="bite-site-name"><?php echo esc_html( $site->name ); ?></h3>
                                <?php if ( ! empty( $site->niche_name ) ) : ?>
                                    <span class="bite-site-niche"><?php echo esc_html( $site->niche_name ); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="bite-site-meta">
                                <span class="bite-site-domain"><?php echo esc_html( $site->domain ); ?></span>
                            </div>
                            <div class="bite-site-actions">
                                <a href="<?php echo esc_url( home_url( '/?site_id=' . $site->site_id ) ); ?>" class="bite-site-link">
                                    View Data →
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <!-- Add Site Form / CTA -->
            <?php if ( $can_add_more ) : ?>
                <div class="bite-add-site-section" id="add-site">
                    <?php if ( ! empty( $form_message ) ) : ?>
                        <div class="bite-notice success">
                            <span class="material-icons">check_circle</span>
                            <?php echo esc_html( $form_message ); ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ( ! empty( $form_error ) ) : ?>
                        <div class="bite-notice error">
                            <span class="material-icons">error</span>
                            <?php echo esc_html( $form_error ); ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="bite-add-site-header">
                        <h3><?php echo empty( $user_sites ) ? 'Get Started: Add Your First Site' : 'Add Another Site'; ?></h3>
                        <p>Follow the steps below to connect your website's Google Search Console data.</p>
                    </div>
                    
                    <div class="bite-setup-steps">
                        <div class="bite-step">
                            <div class="bite-step-number">1</div>
                            <div class="bite-step-content">
                                <h4>Create Google Cloud Project</h4>
                                <p>Go to <a href="https://console.cloud.google.com/" target="_blank">Google Cloud Console</a> and create a new project.</p>
                            </div>
                        </div>
                        <div class="bite-step">
                            <div class="bite-step-number">2</div>
                            <div class="bite-step-content">
                                <h4>Enable Search Console API</h4>
                                <p>In your project, go to "APIs & Services" and enable the "Google Search Console API".</p>
                            </div>
                        </div>
                        <div class="bite-step">
                            <div class="bite-step-number">3</div>
                            <div class="bite-step-content">
                                <h4>Create Service Account</h4>
                                <p>Go to "IAM & Admin > Service Accounts", create a new service account with "Viewer" role.</p>
                            </div>
                        </div>
                        <div class="bite-step">
                            <div class="bite-step-number">4</div>
                            <div class="bite-step-content">
                                <h4>Download JSON Key</h4>
                                <p>Create a JSON key for your service account and download it.</p>
                            </div>
                        </div>
                        <div class="bite-step">
                            <div class="bite-step-number">5</div>
                            <div class="bite-step-content">
                                <h4>Add to Search Console</h4>
                                <p>In <a href="https://search.google.com/search-console" target="_blank">GSC</a>, add your service account email as a "Restricted Property User".</p>
                            </div>
                        </div>
                    </div>
                    
                    <form method="POST" action="#add-site" class="bite-add-site-form" enctype="multipart/form-data">
                        <?php wp_nonce_field( 'bite_add_site', 'bite_add_site_nonce' ); ?>
                        
                        <div class="bite-form-row">
                            <div class="bite-form-group">
                                <label for="bite_site_name">Site Name <span class="required">*</span></label>
                                <input type="text" id="bite_site_name" name="bite_site_name" required 
                                       placeholder="My Website">
                            </div>
                            
                            <div class="bite-form-group">
                                <label for="bite_domain">Domain <span class="required">*</span></label>
                                <input type="text" id="bite_domain" name="bite_domain" required 
                                       placeholder="example.com">
                            </div>
                        </div>
                        
                        <div class="bite-form-group">
                            <label for="bite_gsc_property">Google Search Console Property <span class="required">*</span></label>
                            <input type="text" id="bite_gsc_property" name="bite_gsc_property" required 
                                   placeholder="sc-domain:example.com OR https://www.example.com/">
                            <p class="bite-field-help">Must match exactly what's in your GSC account. Use <code>sc-domain:</code> prefix for domain properties.</p>
                        </div>
                        
                        <div class="bite-form-group">
                            <label for="bite_gsc_credentials">Service Account JSON Key <span class="required">*</span></label>
                            <input type="file" id="bite_gsc_credentials" name="bite_gsc_credentials" 
                                   accept=".json" required>
                            <p class="bite-field-help">Upload the JSON file you downloaded from Google Cloud Console.</p>
                        </div>
                        
                        <button type="submit" name="bite_add_site_submit" class="bite-button bite-button-primary">
                            <span class="material-icons">add</span>
                            Add Site
                        </button>
                    </form>
                </div>
            <?php else : ?>
                <div class="bite-limit-reached">
                    <div class="bite-notice warning">
                        <span class="material-icons">info</span>
                        <p>You've reached the maximum number of sites for your <strong><?php echo esc_html( $plan_display ); ?></strong> plan.</p>
                        <a href="<?php echo esc_url( home_url( '/contact/?plan=upgrade' ) ); ?>" class="bite-button bite-button-small">
                            Upgrade Plan
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </section>

    </main>
    
</div>

<?php get_footer(); ?>
