<?php
/**
 * Template Name: BITE Dashboard
 *
 * @package BITE-theme
 */

if ( ! is_user_logged_in() ) {
    wp_redirect( wp_login_url() );
    exit;
}

$form_message = '';
$form_error = '';

// Handle OAuth success/error messages
if ( isset( $_GET['oauth_success'] ) ) {
    $form_message = 'Google account connected successfully! You can now add sites from your Search Console.';
} elseif ( isset( $_GET['oauth_error'] ) ) {
    $error_code = sanitize_text_field( $_GET['oauth_error'] );
    $error_messages = array(
        'access_denied' => 'You denied access to your Google account. Please try again and approve the connection.',
        'invalid_state' => 'Invalid security token. Please try again.',
        'invalid_nonce' => 'Security check failed. Please try again.',
        'unauthorized'  => 'You are not authorized to perform this action.',
        'token_exchange_failed' => 'Failed to connect to Google. Please try again.',
        'storage_failed' => 'Failed to save connection. Please try again.',
        'missing_params' => 'Invalid request. Please try again.',
    );
    $form_error = isset( $error_messages[$error_code] ) ? $error_messages[$error_code] : 'An error occurred. Please try again.';
}

// Handle site addition from wizard
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['bite_add_site_submit'] ) ) {
    if ( ! isset( $_POST['bite_add_site_nonce'] ) || ! wp_verify_nonce( $_POST['bite_add_site_nonce'], 'bite_add_site' ) ) {
        $form_error = 'Security check failed. Please try again.';
    } else {
        $current_user_id = get_current_user_id();
        
        if ( ! bite_user_can_add_site( $current_user_id ) ) {
            $form_error = 'You have reached the maximum number of sites for your plan.';
        } elseif ( ! bite_user_has_google_connection( $current_user_id ) ) {
            $form_error = 'Please connect your Google account first.';
        } else {
            if ( empty( $_POST['bite_site_name'] ) ) {
                $form_error = 'Please enter a site name.';
            } elseif ( empty( $_POST['bite_domain'] ) ) {
                $form_error = 'Please enter a domain.';
            } elseif ( empty( $_POST['bite_gsc_property'] ) ) {
                $form_error = 'Please select a Google Search Console property.';
            } else {
                global $wpdb;
                $sites_table = $wpdb->prefix . 'bite_sites';
                $niches_table = $wpdb->prefix . 'bite_niches';
                
                // Handle niche - find existing or create new
                $niche_name = sanitize_text_field( $_POST['bite_niche'] ?? '' );
                $niche_id = 0;
                
                if ( ! empty( $niche_name ) ) {
                    $existing_niche_id = $wpdb->get_var( $wpdb->prepare(
                        "SELECT niche_id FROM $niches_table WHERE niche_name = %s",
                        $niche_name
                    ) );
                    
                    if ( $existing_niche_id ) {
                        $niche_id = $existing_niche_id;
                    } else {
                        $wpdb->insert( $niches_table, array( 'niche_name' => $niche_name ) );
                        $niche_id = $wpdb->insert_id;
                    }
                }
                
                $site_data = array(
                    'niche_id'        => $niche_id,
                    'name'            => sanitize_text_field( $_POST['bite_site_name'] ),
                    'domain'          => sanitize_text_field( $_POST['bite_domain'] ),
                    'gsc_property'    => sanitize_text_field( $_POST['bite_gsc_property'] ),
                    'backfill_status' => 'pending',
                );
                
                $result = $wpdb->insert( $sites_table, $site_data );
                
                if ( $result ) {
                    $new_site_id = $wpdb->insert_id;
                    bite_create_metrics_table_for_site( $new_site_id );
                    bite_grant_user_site_access( $current_user_id, $new_site_id, $current_user_id );
                    $form_message = 'Site added successfully! Data will appear shortly.';
                } else {
                    $form_error = 'Database error: ' . $wpdb->last_error;
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
$user_connected  = bite_user_has_google_connection( $current_user_id );

$user_plan = bite_get_user_plan( $current_user_id );
$site_limit = bite_get_user_site_limit( $current_user_id );
$current_site_count = count( $user_site_ids );
$remaining_sites = ( $site_limit === 0 ) ? -1 : max( 0, $site_limit - $current_site_count );
$can_add_more = ( $site_limit === 0 ) || ( $current_site_count < $site_limit );

$user_sites = array();
if ( ! empty( $user_site_ids ) ) {
    $sites_table  = $wpdb->prefix . 'bite_sites';
    $niches_table = $wpdb->prefix . 'bite_niches';
    $placeholders = implode( ', ', array_fill( 0, count( $user_site_ids ), '%d' ) );
    $user_sites   = $wpdb->get_results( $wpdb->prepare(
        "SELECT s.site_id, s.name, s.domain, s.gsc_property, s.created_at, s.backfill_status, n.niche_name 
         FROM $sites_table s 
         LEFT JOIN $niches_table n ON s.niche_id = n.niche_id 
         WHERE s.site_id IN ($placeholders) 
         ORDER BY s.name ASC",
        $user_site_ids
    ) );
}

$user_niches = array();
foreach ( $user_sites as $site ) {
    if ( ! empty( $site->niche_name ) && ! in_array( $site->niche_name, $user_niches ) ) {
        $user_niches[] = $site->niche_name;
    }
}

// Get all existing niches for autocomplete
$all_niches = $wpdb->get_col( "SELECT niche_name FROM {$wpdb->prefix}bite_niches ORDER BY niche_name ASC" );

// Fetch GSC properties if user is connected
$gsc_properties = array();
if ( $user_connected ) {
    $props = bite_fetch_gsc_properties( $current_user_id );
    if ( ! is_wp_error( $props ) ) {
        $gsc_properties = $props;
    }
}

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

    <main id="main" class="bite-dashboard-main-content" role="main">
        
        <section class="bite-dashboard-welcome">
            <div class="bite-welcome-content">
                <h1 class="bite-welcome-title">Welcome back, <?php echo esc_html( $current_user->display_name ); ?>!</h1>
                <p class="bite-welcome-subtitle">
                    <span class="bite-plan-badge"><?php echo esc_html( $plan_display ); ?> Plan</span>
                    <?php if ( $site_limit > 0 ) : ?>
                        <span class="bite-sites-count"><?php echo $current_site_count; ?> of <?php echo $site_limit; ?> sites used</span>
                    <?php else : ?>
                        <span class="bite-sites-count"><?php echo $current_site_count; ?> sites</span>
                    <?php endif; ?>
                </p>
            </div>
        </section>

        <?php if ( ! empty( $user_sites ) ) : ?>
        <section class="bite-dashboard-stats">
            <div class="bite-stats-grid">
                <div class="bite-stat-card bite-stat-clicks">
                    <div class="bite-stat-icon">👆</div>
                    <div class="bite-stat-content">
                        <span class="bite-stat-value"><?php echo esc_html( number_format( $quick_stats['total_clicks'] ?? 0 ) ); ?></span>
                        <span class="bite-stat-label">Total Clicks (30 days)</span>
                    </div>
                </div>
                <div class="bite-stat-card bite-stat-impressions">
                    <div class="bite-stat-icon">👁️</div>
                    <div class="bite-stat-content">
                        <span class="bite-stat-value"><?php echo esc_html( number_format( $quick_stats['total_impressions'] ?? 0 ) ); ?></span>
                        <span class="bite-stat-label">Total Impressions</span>
                    </div>
                </div>
                <div class="bite-stat-card bite-stat-ctr">
                    <div class="bite-stat-icon">📈</div>
                    <div class="bite-stat-content">
                        <span class="bite-stat-value"><?php echo esc_html( number_format( $quick_stats['calculated_ctr'] ?? 0, 2 ) ); ?>%</span>
                        <span class="bite-stat-label">Average CTR</span>
                    </div>
                </div>
                <div class="bite-stat-card bite-stat-position">
                    <div class="bite-stat-icon">🎯</div>
                    <div class="bite-stat-content">
                        <span class="bite-stat-value"><?php echo esc_html( number_format( $quick_stats['avg_position'] ?? 0, 1 ) ); ?></span>
                        <span class="bite-stat-label">Avg Position</span>
                    </div>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <section class="bite-dashboard-section bite-sites-section">
            <div class="bite-section-header">
                <h2>Your Sites</h2>
            </div>
            
            <?php if ( ! empty( $user_sites ) ) : ?>
                <div class="bite-sites-list">
                    <?php foreach ( $user_sites as $site ) : ?>
                        <div class="bite-site-card">
                            <div class="bite-site-header">
                                <h3 class="bite-site-name"><?php echo esc_html( $site->name ); ?></h3>
                                <div class="bite-site-badges">
                                    <?php if ( ! empty( $site->niche_name ) ) : ?>
                                        <span class="bite-site-niche"><?php echo esc_html( $site->niche_name ); ?></span>
                                    <?php endif; ?>
                                    <?php if ( $site->backfill_status === 'pending' || $site->backfill_status === 'in_progress' ) : ?>
                                        <span class="bite-site-status bite-status-pending">
                                            <span class="material-icons">hourglass_empty</span>
                                            Data Importing...
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="bite-site-meta">
                                <span class="bite-site-domain"><?php echo esc_html( $site->domain ); ?></span>
                            </div>
                            <div class="bite-site-actions">
                                <a href="<?php echo esc_url( home_url( '/?site_id=' . $site->site_id ) ); ?>" class="bite-site-link">View Data →</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <?php if ( $can_add_more ) : ?>
                <div class="bite-wizard-container" id="add-site">
                    <?php if ( ! empty( $form_message ) ) : ?>
                        <div class="bite-notice success"><span class="material-icons">check_circle</span><?php echo esc_html( $form_message ); ?></div>
                    <?php endif; ?>
                    <?php if ( ! empty( $form_error ) ) : ?>
                        <div class="bite-notice error"><span class="material-icons">error</span><?php echo esc_html( $form_error ); ?></div>
                    <?php endif; ?>
                    
                    <div class="bite-wizard-header">
                        <h3><?php echo empty( $user_sites ) ? '🚀 Add Your First Site' : '➕ Add Another Site'; ?></h3>
                        <?php if ( $site_limit > 0 ) : ?>
                            <span class="bite-wizard-remaining"><?php echo $remaining_sites; ?> of <?php echo $site_limit; ?> sites remaining</span>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ( ! $user_connected ) : ?>
                        <!-- Step 1: Connect Google Account -->
                        <div class="bite-wizard" id="site-wizard">
                            <div class="bite-wizard-progress">
                                <div class="bite-wizard-progress-bar" style="width: 0%;"></div>
                                <div class="bite-wizard-steps-indicator">
                                    <span class="step-dot active" data-step="1"></span>
                                    <span class="step-dot" data-step="2"></span>
                                </div>
                            </div>
                            
                            <div class="bite-wizard-step active" data-step="1">
                                <div class="bite-step-content">
                                    <div class="bite-step-icon">🔗</div>
                                    <h4>Connect Your Google Account</h4>
                                    <p>To access your Google Search Console data, you need to authorize BITE.</p>
                                    
                                    <div class="bite-step-box" style="text-align: center; padding: 40px;">
                                        <p style="font-size: 1.1em; margin-bottom: 25px;">
                                            Click the button below to securely connect your Google account.<br>
                                            We'll only access your Search Console data - nothing else.
                                        </p>
                                        <a href="<?php echo esc_url( bite_get_google_auth_url( $current_user_id ) ); ?>" class="bite-button bite-button-primary bite-button-large">
                                            <span class="material-icons" style="vertical-align: middle; margin-right: 8px;">login</span>
                                            Connect Google Account
                                        </a>
                                        <p style="margin-top: 20px; font-size: 0.9em; color: var(--text-muted);">
                                            You'll be redirected to Google to approve access.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                    <?php else : ?>
                        <!-- Step 2: Add Site (connected) -->
                        <div class="bite-wizard" id="site-wizard">
                            <div class="bite-wizard-progress">
                                <div class="bite-wizard-progress-bar" style="width: 100%;"></div>
                                <div class="bite-wizard-steps-indicator">
                                    <span class="step-dot completed" data-step="1"></span>
                                    <span class="step-dot active" data-step="2"></span>
                                </div>
                            </div>
                            
                            <div class="bite-wizard-step active" data-step="2">
                                <div class="bite-step-content">
                                    <div class="bite-step-icon">✅</div>
                                    <h4>Google Account Connected!</h4>
                                    <p>Your Google account is connected. Now add a site from your Search Console.</p>
                                    
                                    <form method="POST" action="#add-site" class="bite-wizard-form">
                                        <?php wp_nonce_field( 'bite_add_site', 'bite_add_site_nonce' ); ?>
                                        
                                        <div class="bite-form-group">
                                            <label>Select Google Search Console Property <span class="required">*</span></label>
                                            <?php if ( ! empty( $gsc_properties ) ) : ?>
                                                <select name="bite_gsc_property" id="gsc-property-select" required class="bite-form-select">
                                                    <option value="">-- Select a property --</option>
                                                    <?php foreach ( $gsc_properties as $prop ) : ?>
                                                        <option value="<?php echo esc_attr( $prop['property'] ); ?>">
                                                            <?php echo esc_html( $prop['property'] ); ?>
                                                            <?php if ( $prop['permission'] === 'siteOwner' ) echo '(Owner)'; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <p class="bite-field-help">Don't see your site? Make sure it's verified in <a href="https://search.google.com/search-console" target="_blank">Google Search Console</a>.</p>
                                            <?php else : ?>
                                                <div class="bite-notice warning" style="margin: 10px 0;">
                                                    <span class="material-icons">warning</span>
                                                    No Search Console properties found. 
                                                    <a href="https://search.google.com/search-console" target="_blank">Add your site to GSC first →</a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="bite-form-group">
                                            <label>Site Name <span class="required">*</span></label>
                                            <input type="text" name="bite_site_name" id="site-name-input" required placeholder="e.g., My Website">
                                        </div>
                                        
                                        <div class="bite-form-group">
                                            <label>Domain <span class="required">*</span></label>
                                            <input type="text" name="bite_domain" id="domain-input" required placeholder="example.com">
                                        </div>
                                        
                                        <div class="bite-form-group">
                                            <label>Niche (Optional)</label>
                                            <div class="bite-autocomplete-container">
                                                <input type="text" id="niche-input" name="bite_niche" placeholder="Start typing to see existing niches or create new..." autocomplete="off">
                                                <div class="bite-autocomplete-list" id="niche-suggestions"></div>
                                            </div>
                                            <p class="bite-field-help">Type to search existing niches, or enter a new one to create it.</p>
                                        </div>
                                        
                                        <div class="bite-step-actions">
                                            <a href="<?php echo wp_nonce_url( home_url( '/dashboard/?disconnect_google=1' ), 'disconnect_google' ); ?>" 
                                               class="bite-button bite-button-secondary" 
                                               onclick="return confirm('Disconnect your Google account? This will stop data fetching for all your sites.');">
                                                Disconnect Google
                                            </a>
                                            <button type="submit" name="bite_add_site_submit" class="bite-button bite-button-primary bite-button-large" <?php echo empty( $gsc_properties ) ? 'disabled' : ''; ?>>
                                                <span class="material-icons">add</span> Add Site
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else : ?>
                <div class="bite-limit-reached">
                    <div class="bite-notice warning">
                        <span class="material-icons">info</span>
                        <div>
                            <p><strong>Site Limit Reached</strong></p>
                            <p>Maximum <?php echo $site_limit; ?> sites for <?php echo esc_html( $plan_display ); ?> plan.</p>
                            <a href="<?php echo esc_url( home_url( '/contact/?plan=upgrade' ) ); ?>" class="bite-button bite-button-primary">Upgrade Plan</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>

<script>
// Auto-fill site name and domain from GSC property selection
document.getElementById('gsc-property-select')?.addEventListener('change', function() {
    const property = this.value;
    if (property) {
        // Extract domain from property
        let domain = property;
        if (property.startsWith('sc-domain:')) {
            domain = property.replace('sc-domain:', '');
        } else if (property.startsWith('http://') || property.startsWith('https://')) {
            const url = new URL(property);
            domain = url.hostname.replace(/^www\./, '');
        }
        
        // Auto-fill domain
        document.getElementById('domain-input').value = domain;
        
        // Auto-fill site name if empty
        const nameInput = document.getElementById('site-name-input');
        if (!nameInput.value) {
            // Capitalize first letter of each word
            const name = domain.split('.')[0]
                .replace(/-/g, ' ')
                .replace(/\b\w/g, l => l.toUpperCase());
            nameInput.value = name;
        }
    }
});

// Niche Autocomplete
const existingNiches = <?php echo json_encode( $all_niches ); ?>;
const nicheInput = document.getElementById('niche-input');
const nicheSuggestions = document.getElementById('niche-suggestions');

if (nicheInput) {
    nicheInput.addEventListener('input', function() {
        const value = this.value.toLowerCase();
        nicheSuggestions.innerHTML = '';
        
        if (value.length < 1) {
            nicheSuggestions.style.display = 'none';
            return;
        }
        
        const matches = existingNiches.filter(niche => 
            niche.toLowerCase().includes(value)
        );
        
        if (matches.length > 0) {
            nicheSuggestions.style.display = 'block';
            matches.forEach(niche => {
                const div = document.createElement('div');
                div.className = 'bite-autocomplete-item';
                div.textContent = niche;
                div.onclick = function() {
                    nicheInput.value = niche;
                    nicheSuggestions.style.display = 'none';
                };
                nicheSuggestions.appendChild(div);
            });
        } else {
            nicheSuggestions.style.display = 'none';
        }
    });
    
    // Hide suggestions when clicking outside
    document.addEventListener('click', function(e) {
        if (!nicheInput.contains(e.target) && !nicheSuggestions.contains(e.target)) {
            nicheSuggestions.style.display = 'none';
        }
    });
}
</script>

<?php get_footer(); ?>
