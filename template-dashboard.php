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
$extracted_email = '';

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
            // Validate required fields
            if ( empty( $_POST['bite_site_name'] ) ) {
                $form_error = 'Please enter a site name.';
            } elseif ( empty( $_POST['bite_domain'] ) ) {
                $form_error = 'Please enter a domain.';
            } elseif ( empty( $_POST['bite_gsc_property'] ) ) {
                $form_error = 'Please enter your Google Search Console property.';
            } elseif ( ! isset( $_FILES['bite_gsc_credentials'] ) || empty( $_FILES['bite_gsc_credentials']['tmp_name'] ) ) {
                $form_error = 'Please upload your Google Service Account JSON key file.';
            } else {
                // Process uploaded JSON file
                $uploaded_file = $_FILES['bite_gsc_credentials'];
                
                if ( $uploaded_file['type'] === 'application/json' || pathinfo( $uploaded_file['name'], PATHINFO_EXTENSION ) === 'json' ) {
                    $json_content = file_get_contents( $uploaded_file['tmp_name'] );
                    $credentials_data = json_decode( $json_content, true );
                    
                    if ( $credentials_data && isset( $credentials_data['client_email'] ) && isset( $credentials_data['private_key'] ) ) {
                        $gsc_credentials = $json_content;
                        $extracted_email = $credentials_data['client_email'];
                        
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
                            $form_error = 'Failed to add site to database. Error: ' . $wpdb->last_error;
                        }
                    } else {
                        $form_error = 'Invalid JSON file. The file should contain "client_email" and "private_key" fields. Please download the correct Service Account key from Google Cloud Console.';
                        $extracted_email = '';
                    }
                } else {
                    $form_error = 'Please upload a valid JSON file (with .json extension). The file you uploaded appears to be a different format.';
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
$current_site_count = count( $user_site_ids );
$remaining_sites = ( $site_limit === 0 ) ? -1 : max( 0, $site_limit - $current_site_count );
$can_add_more = ( $site_limit === 0 ) || ( $current_site_count < $site_limit );

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
    'site_count'        => $current_site_count,
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
                    <span class="bite-plan-badge"><?php echo esc_html( $plan_display ); ?> Plan</span>
                    <?php if ( $site_limit > 0 ) : ?>
                        <span class="bite-sites-count"><?php echo $current_site_count; ?> of <?php echo $site_limit; ?> sites used</span>
                    <?php else : ?>
                        <span class="bite-sites-count"><?php echo $current_site_count; ?> sites</span>
                    <?php endif; ?>
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
            
            <!-- Add Site Wizard -->
            <?php if ( $can_add_more ) : ?>
                <div class="bite-wizard-container" id="add-site">
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
                    
                    <div class="bite-wizard-header">
                        <h3><?php echo empty( $user_sites ) ? '🚀 Add Your First Site' : '➕ Add Another Site'; ?></h3>
                        <p>Follow these 5 simple steps to connect your Google Search Console data.</p>
                        <?php if ( $site_limit > 0 ) : ?>
                            <span class="bite-wizard-remaining"><?php echo $remaining_sites; ?> of <?php echo $site_limit; ?> sites remaining</span>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Step-by-Step Wizard -->
                    <div class="bite-wizard" id="site-wizard">
                        <!-- Progress Bar -->
                        <div class="bite-wizard-progress">
                            <div class="bite-wizard-progress-bar" id="wizard-progress"></div>
                            <div class="bite-wizard-steps-indicator">
                                <span class="step-dot active" data-step="1"></span>
                                <span class="step-dot" data-step="2"></span>
                                <span class="step-dot" data-step="3"></span>
                                <span class="step-dot" data-step="4"></span>
                                <span class="step-dot" data-step="5"></span>
                            </div>
                        </div>
                        
                        <!-- Step 1: Create Google Cloud Project -->
                        <div class="bite-wizard-step active" data-step="1">
                            <div class="bite-step-content">
                                <div class="bite-step-icon">🔧</div>
                                <h4>Step 1: Create a Google Cloud Project</h4>
                                <p>First, you need a Google Cloud project to access the API.</p>
                                
                                <div class="bite-step-box">
                                    <h5>What to do:</h5>
                                    <ol class="bite-step-instructions">
                                        <li>Go to <a href="https://console.cloud.google.com/" target="_blank" class="bite-external-link">Google Cloud Console →</a></li>
                                        <li>Click the <strong>project selector</strong> at the top (shows "Select a project")</li>
                                        <li>Click <strong>"New Project"</strong></li>
                                        <li>Give your project a name like "My Website Analytics"</li>
                                        <li>Click <strong>"Create"</strong></li>
                                    </ol>
                                </div>
                                
                                <div class="bite-step-tip">
                                    <strong>Tip:</strong> You'll need a Google account. If you don't have one, create it first at <a href="https://accounts.google.com/" target="_blank">accounts.google.com</a>
                                </div>
                                
                                <div class="bite-step-actions">
                                    <button type="button" class="bite-button bite-button-primary" onclick="wizardNext()">I've Created the Project →</button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Step 2: Enable Search Console API -->
                        <div class="bite-wizard-step" data-step="2">
                            <div class="bite-step-content">
                                <div class="bite-step-icon">🚀</div>
                                <h4>Step 2: Enable the Search Console API</h4>
                                <p>Now we need to turn on access to your search data.</p>
                                
                                <div class="bite-step-box">
                                    <h5>What to do:</h5>
                                    <ol class="bite-step-instructions">
                                        <li>Make sure you're in your new project (check the project selector at top)</li>
                                        <li>Click the <strong>hamburger menu (☰)</strong> in top left</li>
                                        <li>Go to <strong>"APIs & Services"</strong> → <strong>"Library"</strong></li>
                                        <li>Search for <strong>"Google Search Console API"</strong></li>
                                        <li>Click on it, then click the <strong>"Enable"</strong> button</li>
                                    </ol>
                                </div>
                                
                                <div class="bite-step-tip">
                                    <strong>Note:</strong> This is free. You may need to accept terms of service.
                                </div>
                                
                                <div class="bite-step-actions">
                                    <button type="button" class="bite-button bite-button-secondary" onclick="wizardPrev()">← Back</button>
                                    <button type="button" class="bite-button bite-button-primary" onclick="wizardNext()">I've Enabled the API →</button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Step 3: Create Service Account -->
                        <div class="bite-wizard-step" data-step="3">
                            <div class="bite-step-content">
                                <div class="bite-step-icon">👤</div>
                                <h4>Step 3: Create a Service Account</h4>
                                <p>This creates a special account that BITE will use to read your data.</p>
                                
                                <div class="bite-step-box">
                                    <h5>What to do:</h5>
                                    <ol class="bite-step-instructions">
                                        <li>In Google Cloud Console, click the <strong>hamburger menu (☰)</strong></li>
                                        <li>Go to <strong>"IAM & Admin"</strong> → <strong>"Service Accounts"</strong></li>
                                        <li>Click <strong>"Create Service Account"</strong> at the top</li>
                                        <li><strong>Service account name:</strong> Type "bite-reader"</li>
                                        <li>Click <strong>"Create and Continue"</strong></li>
                                        <li>For <strong>Role</strong>, select: <code>Project</code> → <code>Viewer</code></li>
                                        <li>Click <strong>"Continue"</strong> then <strong>"Done"</strong></li>
                                    </ol>
                                </div>
                                
                                <div class="bite-step-actions">
                                    <button type="button" class="bite-button bite-button-secondary" onclick="wizardPrev()">← Back</button>
                                    <button type="button" class="bite-button bite-button-primary" onclick="wizardNext()">I've Created the Account →</button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Step 4: Download JSON Key -->
                        <div class="bite-wizard-step" data-step="4">
                            <div class="bite-step-content">
                                <div class="bite-step-icon">🔑</div>
                                <h4>Step 4: Download Your Key File</h4>
                                <p>Download the file BITE needs to connect to your data.</p>
                                
                                <div class="bite-step-box">
                                    <h5>What to do:</h5>
                                    <ol class="bite-step-instructions">
                                        <li>You should be on the "Service Accounts" page</li>
                                        <li>Find your "bite-reader" account and <strong>click on it</strong></li>
                                        <li>Click the <strong>"Keys"</strong> tab at the top</li>
                                        <li>Click <strong>"Add Key"</strong> → <strong>"Create New Key"</strong></li>
                                        <li>Select <strong>"JSON"</strong> and click <strong>"Create"</strong></li>
                                        <li>A file will download - <strong>keep this file safe!</strong></li>
                                    </ol>
                                </div>
                                
                                <div class="bite-step-tip warning">
                                    <strong>Important:</strong> This file contains sensitive information. Don't share it with anyone.
                                </div>
                                
                                <div class="bite-step-actions">
                                    <button type="button" class="bite-button bite-button-secondary" onclick="wizardPrev()">← Back</button>
                                    <button type="button" class="bite-button bite-button-primary" onclick="wizardNext()">I've Downloaded the Key →</button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Step 5: Add to Search Console & Form -->
                        <div class="bite-wizard-step" data-step="5">
                            <div class="bite-step-content">
                                <div class="bite-step-icon">🔗</div>
                                <h4>Step 5: Give Access in Search Console</h4>
                                <p>Now let BITE access your search data.</p>
                                
                                <div class="bite-step-box">
                                    <h5>What to do:</h5>
                                    <ol class="bite-step-instructions">
                                        <li>Go to <a href="https://search.google.com/search-console" target="_blank" class="bite-external-link">Google Search Console →</a></li>
                                        <li>Select your website property from the list</li>
                                        <li>Click the <strong>gear icon (Settings)</strong> in the left sidebar</li>
                                        <li>Click <strong>"Users and Permissions"</strong></li>
                                        <li>Click the <strong>blue "Add User"</strong> button</li>
                                        <li>Paste the <strong>Service Account Email</strong> shown below</li>
                                        <li>Set permission to <strong>"Restricted Property User"</strong></li>
                                        <li>Click <strong>"Add"</strong></li>
                                    </ol>
                                </div>
                                
                                <div class="bite-step-email-box" id="email-display-box" style="display: none;">
                                    <p class="bite-email-label">Your Service Account Email:</p>
                                    <div class="bite-email-value" id="service-account-email">Upload your JSON file to see the email</div>
                                    <button type="button" class="bite-copy-btn" onclick="copyEmail()">
                                        <span class="material-icons">content_copy</span> Copy
                                    </button>
                                </div>
                                
                                <form method="POST" action="#add-site" class="bite-wizard-form" enctype="multipart/form-data" id="site-form">
                                    <?php wp_nonce_field( 'bite_add_site', 'bite_add_site_nonce' ); ?>
                                    
                                    <div class="bite-form-group">
                                        <label for="bite_site_name">Site Name <span class="required">*</span></label>
                                        <input type="text" id="bite_site_name" name="bite_site_name" required 
                                               placeholder="e.g., My Business Website">
                                    </div>
                                    
                                    <div class="bite-form-row">
                                        <div class="bite-form-group">
                                            <label for="bite_domain">Domain <span class="required">*</span></label>
                                            <input type="text" id="bite_domain" name="bite_domain" required 
                                                   placeholder="e.g., example.com">
                                        </div>
                                        
                                        <div class="bite-form-group">
                                            <label for="bite_gsc_property">GSC Property <span class="required">*</span></label>
                                            <input type="text" id="bite_gsc_property" name="bite_gsc_property" required 
                                                   placeholder="sc-domain:example.com">
                                        </div>
                                    </div>
                                    
                                    <div class="bite-form-group">
                                        <label for="bite_gsc_credentials">Upload Your JSON Key File <span class="required">*</span></label>
                                        <div class="bite-file-upload">
                                            <input type="file" id="bite_gsc_credentials" name="bite_gsc_credentials" 
                                                   accept=".json" required onchange="extractEmailFromJSON(this)">
                                            <label for="bite_gsc_credentials" class="bite-file-label">
                                                <span class="material-icons">upload_file</span>
                                                <span class="bite-file-text" id="file-text">Click to choose your JSON file...</span>
                                            </label>
                                        </div>
                                        <p class="bite-field-help">This is the file you downloaded in Step 4 (ends in .json)</p>
                                    </div>
                                    
                                    <div class="bite-step-actions">
                                        <button type="button" class="bite-button bite-button-secondary" onclick="wizardPrev()">← Back</button>
                                        <button type="submit" name="bite_add_site_submit" class="bite-button bite-button-primary bite-button-large">
                                            <span class="material-icons">add</span>
                                            Add Site
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <script>
                    let currentStep = 1;
                    const totalSteps = 5;
                    
                    function updateProgress() {
                        const progress = ((currentStep - 1) / (totalSteps - 1)) * 100;
                        document.getElementById('wizard-progress').style.setProperty('--progress', progress + '%');
                        document.getElementById('wizard-progress').style.width = progress + '%';
                        
                        document.querySelectorAll('.step-dot').forEach((dot, index) => {
                            dot.classList.remove('active', 'completed');
                            if (index + 1 < currentStep) {
                                dot.classList.add('completed');
                            } else if (index + 1 === currentStep) {
                                dot.classList.add('active');
                            }
                        });
                    }
                    
                    function wizardNext() {
                        if (currentStep < totalSteps) {
                            document.querySelector('.bite-wizard-step.active').classList.remove('active');
                            currentStep++;
                            document.querySelector('.bite-wizard-step[data-step="' + currentStep + '"]').classList.add('active');
                            updateProgress();
                            window.location.hash = 'step-' + currentStep;
                        }
                    }
                    
                    function wizardPrev() {
                        if (currentStep > 1) {
                            document.querySelector('.bite-wizard-step.active').classList.remove('active');
                            currentStep--;
                            document.querySelector('.bite-wizard-step[data-step="' + currentStep + '"]').classList.add('active');
                            updateProgress();
                            window.location.hash = 'step-' + currentStep;
                        }
                    }
                    
                    function extractEmailFromJSON(input) {
                        const file = input.files[0];
                        if (file) {
                            document.getElementById('file-text').textContent = file.name;
                            
                            const reader = new FileReader();
                            reader.onload = function(e) {
                                try {
                                    const data = JSON.parse(e.target.result);
                                    if (data.client_email) {
                                        document.getElementById('service-account-email').textContent = data.client_email;
                                        document.getElementById('email-display-box').style.display = 'block';
                                    }
                                } catch (err) {
                                    console.error('Invalid JSON');
                                }
                            };
                            reader.readAsText(file);
                        }
                    }
                    
                    function copyEmail() {
                        const email = document.getElementById('service-account-email').textContent;
                        navigator.clipboard.writeText(email).then(function() {
                            alert('Email copied to clipboard!');
                        });
                    }
                    
                    // Initialize progress
                    updateProgress();
                    </script>
                </div>
            <?php else : ?>
                <div class="bite-limit-reached">
                    <div class="bite-notice warning">
                        <span class="material-icons">info</span>
                        <div>
                            <p><strong>Site Limit Reached</strong></p>
                            <p>You've reached the maximum of <strong><?php echo $site_limit; ?></strong> sites for your <?php echo esc_html( $plan_display ); ?> plan.</p>
                            <a href="<?php echo esc_url( home_url( '/contact/?plan=upgrade' ) ); ?>" class="bite-button bite-button-primary">
                                Upgrade Plan
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </section>

    </main>
    
</div>

<?php get_footer(); ?>
