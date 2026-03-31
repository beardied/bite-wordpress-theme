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
$extracted_email = '';

if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['bite_add_site_submit'] ) ) {
    if ( ! isset( $_POST['bite_add_site_nonce'] ) || ! wp_verify_nonce( $_POST['bite_add_site_nonce'], 'bite_add_site' ) ) {
        $form_error = 'Security check failed. Please try again.';
    } else {
        $current_user_id = get_current_user_id();
        
        if ( ! bite_user_can_add_site( $current_user_id ) ) {
            $form_error = 'You have reached the maximum number of sites for your plan.';
        } else {
            if ( empty( $_POST['bite_site_name'] ) ) {
                $form_error = 'Please enter a site name.';
            } elseif ( empty( $_POST['bite_domain'] ) ) {
                $form_error = 'Please enter a domain.';
            } elseif ( empty( $_POST['bite_gsc_property'] ) ) {
                $form_error = 'Please enter your Google Search Console property.';
            } elseif ( empty( $_POST['bite_gsc_credentials'] ) ) {
                $form_error = 'JSON credentials not found. Please go back to Step 5 and upload your JSON file.';
            } else {
                // Don't sanitize JSON - it corrupts the content
                $json_content = wp_unslash( $_POST['bite_gsc_credentials'] );
                $credentials_data = json_decode( $json_content, true );
                
                // Debug: check for JSON errors
                $json_error = json_last_error();
                if ( $json_error !== JSON_ERROR_NONE ) {
                    $form_error = 'JSON parse error: ' . json_last_error_msg() . '. Please try uploading the file again.';
                } elseif ( ! $credentials_data ) {
                    $form_error = 'Could not parse JSON credentials. The data appears to be empty or corrupted.';
                } elseif ( ! isset( $credentials_data['client_email'] ) || ! isset( $credentials_data['private_key'] ) ) {
                    $form_error = 'Invalid JSON structure. Missing client_email or private_key fields.';
                } else {
                    $gsc_credentials = $json_content;
                    
                    global $wpdb;
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
}

get_header();

global $wpdb;
$current_user    = wp_get_current_user();
$current_user_id = get_current_user_id();
$user_site_ids   = bite_get_user_sites( $current_user_id );
$is_admin        = current_user_can( 'manage_options' );

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
        "SELECT s.site_id, s.name, s.domain, s.gsc_property, s.created_at, n.niche_name 
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
                                <?php if ( ! empty( $site->niche_name ) ) : ?>
                                    <span class="bite-site-niche"><?php echo esc_html( $site->niche_name ); ?></span>
                                <?php endif; ?>
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
                        <p>Follow these 6 simple steps to connect your Google Search Console data.</p>
                        <?php if ( $site_limit > 0 ) : ?>
                            <span class="bite-wizard-remaining"><?php echo $remaining_sites; ?> of <?php echo $site_limit; ?> sites remaining</span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="bite-wizard" id="site-wizard">
                        <div class="bite-wizard-progress">
                            <div class="bite-wizard-progress-bar" id="wizard-progress"></div>
                            <div class="bite-wizard-steps-indicator">
                                <span class="step-dot active" data-step="1"></span>
                                <span class="step-dot" data-step="2"></span>
                                <span class="step-dot" data-step="3"></span>
                                <span class="step-dot" data-step="4"></span>
                                <span class="step-dot" data-step="5"></span>
                                <span class="step-dot" data-step="6"></span>
                            </div>
                        </div>
                        
                        <!-- Step 1: Google Cloud Project -->
                        <div class="bite-wizard-step active" data-step="1">
                            <div class="bite-step-content">
                                <div class="bite-step-icon">🔧</div>
                                <h4>Step 1: Create Google Cloud Project</h4>
                                <p>First, create a project in Google Cloud Console.</p>
                                <div class="bite-step-box">
                                    <ol class="bite-step-instructions">
                                        <li>Go to <a href="https://console.cloud.google.com/" target="_blank" class="bite-external-link">Google Cloud Console →</a></li>
                                        <li>Click <strong>project selector</strong> at top → <strong>New Project</strong></li>
                                        <li>Name it (e.g., "My Website Analytics") → <strong>Create</strong></li>
                                    </ol>
                                </div>
                                <div class="bite-step-actions">
                                    <button type="button" class="bite-button bite-button-primary" onclick="wizardNext()">Next →</button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Step 2: Enable API -->
                        <div class="bite-wizard-step" data-step="2">
                            <div class="bite-step-content">
                                <div class="bite-step-icon">🚀</div>
                                <h4>Step 2: Enable Search Console API</h4>
                                <p>Turn on access to your search data.</p>
                                <div class="bite-step-box">
                                    <ol class="bite-step-instructions">
                                        <li>Click <strong>hamburger menu (☰)</strong> → <strong>APIs & Services</strong> → <strong>Library</strong></li>
                                        <li>Search <strong>"Google Search Console API"</strong></li>
                                        <li>Click it → <strong>Enable</strong></li>
                                    </ol>
                                </div>
                                <div class="bite-step-actions">
                                    <button type="button" class="bite-button bite-button-secondary" onclick="wizardPrev()">← Back</button>
                                    <button type="button" class="bite-button bite-button-primary" onclick="wizardNext()">Next →</button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Step 3: Service Account -->
                        <div class="bite-wizard-step" data-step="3">
                            <div class="bite-step-content">
                                <div class="bite-step-icon">👤</div>
                                <h4>Step 3: Create Service Account</h4>
                                <p>Create an account for BITE to access your data.</p>
                                <div class="bite-step-box">
                                    <ol class="bite-step-instructions">
                                        <li>Menu (☰) → <strong>IAM & Admin</strong> → <strong>Service Accounts</strong></li>
                                        <li>Click <strong>Create Service Account</strong></li>
                                        <li>Name: <code>bite-reader</code> → <strong>Create</strong></li>
                                        <li>Role: <strong>Project</strong> → <strong>Viewer</strong> → <strong>Done</strong></li>
                                    </ol>
                                </div>
                                <div class="bite-step-actions">
                                    <button type="button" class="bite-button bite-button-secondary" onclick="wizardPrev()">← Back</button>
                                    <button type="button" class="bite-button bite-button-primary" onclick="wizardNext()">Next →</button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Step 4: Download Key -->
                        <div class="bite-wizard-step" data-step="4">
                            <div class="bite-step-content">
                                <div class="bite-step-icon">🔑</div>
                                <h4>Step 4: Download JSON Key</h4>
                                <p>Get the key file BITE needs.</p>
                                <div class="bite-step-box">
                                    <ol class="bite-step-instructions">
                                        <li>Click on <strong>bite-reader</strong> account</li>
                                        <li><strong>Keys</strong> tab → <strong>Add Key</strong> → <strong>Create New Key</strong></li>
                                        <li>Select <strong>JSON</strong> → <strong>Create</strong></li>
                                        <li>Save the downloaded file</li>
                                    </ol>
                                </div>
                                <div class="bite-step-tip warning"><strong>Important:</strong> Keep this file safe - it contains sensitive data!</div>
                                <div class="bite-step-actions">
                                    <button type="button" class="bite-button bite-button-secondary" onclick="wizardPrev()">← Back</button>
                                    <button type="button" class="bite-button bite-button-primary" onclick="wizardNext()">Next →</button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Step 5: Upload JSON (extract email) -->
                        <div class="bite-wizard-step" data-step="5">
                            <div class="bite-step-content">
                                <div class="bite-step-icon">📂</div>
                                <h4>Step 5: Upload Your JSON Key</h4>
                                <p>Upload the file you downloaded in Step 4. We'll extract the service account email for Step 6.</p>
                                
                                <div class="bite-wizard-form" style="margin-top: 20px;">
                                    <div class="bite-form-group">
                                        <label for="json-upload">Select Your JSON Key File</label>
                                        <div class="bite-file-upload">
                                            <input type="file" id="json-upload" accept=".json" onchange="handleJSONUpload(this)">
                                            <label for="json-upload" class="bite-file-label">
                                                <span class="material-icons">upload_file</span>
                                                <span class="bite-file-text" id="upload-text">Click to select your JSON file...</span>
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <div class="bite-step-success" id="upload-success" style="display: none; margin-top: 25px; background: rgba(34,197,94,0.1); border: 1px solid #22c55e; padding: 20px; border-radius: 8px; color: #15803d;">
                                        <span class="material-icons" style="vertical-align: middle; margin-right: 8px;">check_circle</span>
                                        <strong>JSON file validated successfully!</strong>
                                        <p style="margin: 10px 0 0 0; font-size: 0.95em;">The service account email will be shown in the next step.</p>
                                    </div>
                                </div>
                                
                                <div class="bite-step-actions" style="margin-top: 30px;">
                                    <button type="button" class="bite-button bite-button-secondary" onclick="wizardPrev()">← Back</button>
                                    <button type="button" class="bite-button bite-button-primary" id="step5-next" onclick="wizardNext()" disabled>Next →</button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Step 6: Add to GSC + Submit Form -->
                        <div class="bite-wizard-step" data-step="6">
                            <div class="bite-step-content">
                                <div class="bite-step-icon">🔗</div>
                                <h4>Step 6: Add User to Search Console</h4>
                                <p>Add the service account to your Google Search Console, then complete the form below.</p>
                                
                                <div class="bite-step-email-box" id="email-display-box" style="margin-bottom: 25px;">
                                    <p class="bite-email-label">Copy this email and add it to Search Console:</p>
                                    <div class="bite-email-value" id="gsc-email-display">Loading...</div>
                                    <button type="button" class="bite-copy-btn" onclick="copyEmail()">
                                        <span class="material-icons">content_copy</span> Copy Email
                                    </button>
                                </div>
                                
                                <div class="bite-step-box">
                                    <h5>What to do in Google Search Console:</h5>
                                    <ol class="bite-step-instructions">
                                        <li>Go to <a href="https://search.google.com/search-console" target="_blank" class="bite-external-link">Google Search Console →</a></li>
                                        <li>Select your website property</li>
                                        <li>Click <strong>Settings</strong> (gear icon) in left sidebar</li>
                                        <li>Click <strong>Users and Permissions</strong></li>
                                        <li>Click <strong>Add User</strong></li>
                                        <li>Paste the email address shown above</li>
                                        <li>Set permission to <strong>Restricted Property User</strong></li>
                                        <li>Click <strong>Add</strong></li>
                                    </ol>
                                </div>
                                
                                <form method="POST" action="#add-site" class="bite-wizard-form" enctype="multipart/form-data">
                                    <?php wp_nonce_field( 'bite_add_site', 'bite_add_site_nonce' ); ?>
                                    <input type="hidden" id="hidden-json" name="bite_gsc_credentials">
                                    
                                    <div class="bite-form-group">
                                        <label>Site Name <span class="required">*</span></label>
                                        <input type="text" name="bite_site_name" required placeholder="e.g., My Website">
                                    </div>
                                    
                                    <div class="bite-form-row">
                                        <div class="bite-form-group">
                                            <label>Domain <span class="required">*</span></label>
                                            <input type="text" name="bite_domain" required placeholder="example.com">
                                        </div>
                                        <div class="bite-form-group">
                                            <label>GSC Property <span class="required">*</span></label>
                                            <input type="text" name="bite_gsc_property" required placeholder="sc-domain:example.com">
                                        </div>
                                    </div>
                                    
                                    <div class="bite-step-actions">
                                        <button type="button" class="bite-button bite-button-secondary" onclick="wizardPrev()">← Back</button>
                                        <button type="submit" name="bite_add_site_submit" class="bite-button bite-button-primary bite-button-large">
                                            <span class="material-icons">add</span> Add Site
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <script>
                    let currentStep = 1;
                    const totalSteps = 6;
                    let jsonContent = '';
                    let serviceEmail = '';
                    
                    function updateProgress() {
                        const progress = ((currentStep - 1) / (totalSteps - 1)) * 100;
                        document.getElementById('wizard-progress').style.width = progress + '%';
                        
                        document.querySelectorAll('.step-dot').forEach((dot, index) => {
                            dot.classList.remove('active', 'completed');
                            if (index + 1 < currentStep) dot.classList.add('completed');
                            else if (index + 1 === currentStep) dot.classList.add('active');
                        });
                    }
                    
                    function wizardNext() {
                        if (currentStep < totalSteps) {
                            document.querySelector('.bite-wizard-step.active').classList.remove('active');
                            currentStep++;
                            document.querySelector('.bite-wizard-step[data-step="' + currentStep + '"]').classList.add('active');
                            updateProgress();
                            
                            // Update email display in step 6
                            if (currentStep === 6 && serviceEmail) {
                                document.getElementById('gsc-email-display').textContent = serviceEmail;
                            }
                        }
                    }
                    
                    function wizardPrev() {
                        if (currentStep > 1) {
                            document.querySelector('.bite-wizard-step.active').classList.remove('active');
                            currentStep--;
                            document.querySelector('.bite-wizard-step[data-step="' + currentStep + '"]').classList.add('active');
                            updateProgress();
                        }
                    }
                    
                    function handleJSONUpload(input) {
                        const file = input.files[0];
                        if (file) {
                            document.getElementById('upload-text').textContent = file.name;
                            
                            const reader = new FileReader();
                            reader.onload = function(e) {
                                try {
                                    const data = JSON.parse(e.target.result);
                                    if (data.client_email && data.private_key) {
                                        jsonContent = e.target.result;
                                        serviceEmail = data.client_email;
                                        
                                        // Show success message in step 5
                                        document.getElementById('upload-success').style.display = 'block';
                                        document.getElementById('step5-next').disabled = false;
                                        
                                        // Store for form submission
                                        document.getElementById('hidden-json').value = jsonContent;
                                    } else {
                                        alert('Invalid JSON file. Must contain client_email and private_key.');
                                    }
                                } catch (err) {
                                    alert('Error reading JSON file. Please upload a valid Service Account key.');
                                }
                            };
                            reader.readAsText(file);
                        }
                    }
                    
                    function copyEmail() {
                        navigator.clipboard.writeText(serviceEmail).then(function() {
                            alert('Email copied! Now paste it in Google Search Console.');
                        });
                    }
                    
                    updateProgress();
                    </script>
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

<?php get_footer(); ?>
