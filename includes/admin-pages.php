<?php
/**
 * Admin Pages Setup
 *
 * Creates the admin menu and pages for managing Niches and Sites.
 *
 * @package BITE-theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Check if required database tables exist and show admin notice if not.
 */
function bite_check_database_tables() {
    global $wpdb;
    
    $required_tables = array(
        $wpdb->prefix . 'bite_niches',
        $wpdb->prefix . 'bite_sites',
        $wpdb->prefix . 'bite_keywords',
        $wpdb->prefix . 'bite_daily_summary',
        $wpdb->prefix . 'bite_user_sites',
        $wpdb->prefix . 'bite_reviews',
    );
    
    $missing_tables = array();
    foreach ( $required_tables as $table ) {
        $exists = $wpdb->get_var( "SHOW TABLES LIKE '$table'" );
        if ( ! $exists ) {
            $missing_tables[] = $table;
        }
    }
    
    if ( ! empty( $missing_tables ) ) {
        echo '<div class="notice notice-error"><p><strong>BITE Database Error:</strong> The following required tables are missing: <code>' . implode( '</code>, <code>', $missing_tables ) . '</code>. Please deactivate and reactivate the theme to create them.</p></div>';
    }
}
add_action( 'admin_notices', 'bite_check_database_tables' );

/**
 * Register the admin menu pages.
 */
function bite_register_admin_menu() {
    // Add the top-level menu page
    add_menu_page(
        __( 'BITE Admin', 'bite-theme' ),
        __( 'BITE Admin', 'bite-theme' ),
        'manage_options',
        'bite-admin-main',
        'bite_admin_page_sites', // Make 'Manage Sites' the main page
        'dashicons-chart-line',
        20
    );

    // Add the "Manage Sites" submenu page
    add_submenu_page(
        'bite-admin-main',
        __( 'Manage Sites', 'bite-theme' ),
        __( 'Manage Sites', 'bite-theme' ),
        'manage_options',
        'bite-admin-main', // Use the same slug as the parent
        'bite_admin_page_sites'
    );

    // Add the "Manage Niches" submenu page
    add_submenu_page(
        'bite-admin-main',
        __( 'Manage Niches', 'bite-theme' ),
        __( 'Manage Niches', 'bite-theme' ),
        'manage_options',
        'bite-admin-niches',
        'bite_admin_page_niches'
    );

    // Add the "Settings" submenu page
    add_submenu_page(
        'bite-admin-main',
        __( 'BITE Settings', 'bite-theme' ),
        __( 'Settings', 'bite-theme' ),
        'manage_options',
        'bite-admin-settings',
        'bite_admin_page_settings'
    );
}
add_action( 'admin_menu', 'bite_register_admin_menu' );

/**
 * Handles all form submissions from the admin pages.
 * This runs on 'admin_init' before any HTML is rendered.
 */
function bite_handle_admin_form_actions() {
    global $wpdb;

    // Check if we are on one of our admin pages before processing
    if ( ! isset( $_POST['bite_action'] ) ) {
        return;
    }

    // --- Add Niche ---
    if ( isset( $_POST['bite_niche_name'] ) && $_POST['bite_action'] === 'add_niche' ) {
        check_admin_referer( 'bite_add_niche_nonce' ); // Verify security nonce
        
        $niche_name = sanitize_text_field( $_POST['bite_niche_name'] );
        if ( ! empty( $niche_name ) ) {
            $table_name = $wpdb->prefix . 'bite_niches';
            $wpdb->insert(
                $table_name,
                array( 'niche_name' => $niche_name ),
                array( '%s' )
            );
        }
    }

    // --- Delete Niche ---
    if ( isset( $_POST['bite_niche_id'] ) && $_POST['bite_action'] === 'delete_niche' ) {
        check_admin_referer( 'bite_delete_niche_nonce' ); // Verify security nonce

        $niche_id = absint( $_POST['bite_niche_id'] );
        if ( $niche_id > 0 ) {
            $table_name = $wpdb->prefix . 'bite_niches';
            $wpdb->delete( $table_name, array( 'niche_id' => $niche_id ), array( '%d' ) );
        }
    }

    // --- Add Site ---
    if ( $_POST['bite_action'] === 'add_site' ) {
        check_admin_referer( 'bite_add_site_nonce' );

        // Process uploaded JSON file
        $gsc_credentials = '';
        if ( isset( $_FILES['bite_gsc_credentials'] ) && ! empty( $_FILES['bite_gsc_credentials']['tmp_name'] ) ) {
            $uploaded_file = $_FILES['bite_gsc_credentials'];
            
            // Verify it's a JSON file
            if ( $uploaded_file['type'] === 'application/json' || pathinfo( $uploaded_file['name'], PATHINFO_EXTENSION ) === 'json' ) {
                $json_content = file_get_contents( $uploaded_file['tmp_name'] );
                $credentials_data = json_decode( $json_content, true );
                
                // Validate JSON structure
                if ( $credentials_data && isset( $credentials_data['client_email'] ) && isset( $credentials_data['private_key'] ) ) {
                    $gsc_credentials = $json_content;
                } else {
                    add_action( 'admin_notices', function() {
                        echo '<div class="notice notice-error"><p>Invalid JSON file. Please upload a valid Google Service Account key file.</p></div>';
                    } );
                    return;
                }
            } else {
                add_action( 'admin_notices', function() {
                    echo '<div class="notice notice-error"><p>Please upload a valid JSON file.</p></div>';
                } );
                return;
            }
        }

        $site_data = array(
            'niche_id'        => absint( $_POST['bite_niche_id'] ),
            'name'            => sanitize_text_field( $_POST['bite_site_name'] ),
            'domain'          => sanitize_text_field( $_POST['bite_domain'] ),
            'gsc_property'    => sanitize_text_field( $_POST['bite_gsc_property'] ),
            'gsc_credentials' => $gsc_credentials,
            'backfill_status' => 'pending',
        );

        // Insert into the main sites table
        $table_name = $wpdb->prefix . 'bite_sites';
        $result = $wpdb->insert( $table_name, $site_data );
        
        if ( $result === false ) {
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-error"><p>Failed to add site. Database error: ' . esc_html( $GLOBALS['wpdb']->last_error ) . '</p></div>';
            } );
        } else {
            // Get the new ID and create the metrics table
            $new_site_id = $wpdb->insert_id;
            if ( $new_site_id > 0 ) {
                bite_create_metrics_table_for_site( $new_site_id );
                add_action( 'admin_notices', function() {
                    echo '<div class="notice notice-success"><p>Site added successfully! Data fetching will begin automatically.</p></div>';
                } );
            }
        }
    }
    
    // --- Delete Site ---
    if ( isset( $_POST['bite_site_id'] ) && $_POST['bite_action'] === 'delete_site' ) {
        check_admin_referer( 'bite_delete_site_nonce' );

        $site_id = absint( $_POST['bite_site_id'] );
        if ( $site_id > 0 ) {
            // 1. Delete the site row
            $table_name = $wpdb->prefix . 'bite_sites';
            $wpdb->delete( $table_name, array( 'site_id' => $site_id ), array( '%d' ) );

            // 2. Drop the associated metrics table
            bite_delete_metrics_table_for_site( $site_id );
        }
    }
}
add_action( 'admin_init', 'bite_handle_admin_form_actions' );


/**
 * Renders the "Manage Niches" admin page.
 */
function bite_admin_page_niches() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'bite_niches';
    $niches = $wpdb->get_results( "SELECT * FROM $table_name ORDER BY niche_name ASC" );
    ?>
    <div class="wrap bite-admin-wrap">
        <h1><?php esc_html_e( 'Manage Niches', 'bite-theme' ); ?></h1>
        
        <div class="bite-admin-form-container">
            <div class="bite-admin-form-col-1">
                <h2><?php esc_html_e( 'Add New Niche', 'bite-theme' ); ?></h2>
                <form method="post" action="" class="bite-admin-form">
                    <input type="hidden" name="bite_action" value="add_niche">
                    <?php wp_nonce_field( 'bite_add_niche_nonce' ); ?>
                    
                    <p>
                        <label for="bite_niche_name"><?php esc_html_e( 'Niche Name:', 'bite-theme' ); ?></label>
                        <input type="text" id="bite_niche_name" name="bite_niche_name" required>
                    </p>
                    
                    <?php submit_button( __( 'Add Niche', 'bite-theme' ) ); ?>
                </form>
            </div>
            
            <div class="bite-admin-form-col-2">
                <h2><?php esc_html_e( 'Existing Niches', 'bite-theme' ); ?></h2>
                <table class="bite-admin-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'ID', 'bite-theme' ); ?></th>
                            <th><?php esc_html_e( 'Niche Name', 'bite-theme' ); ?></th>
                            <th><?php esc_html_e( 'Action', 'bite-theme' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( ! empty( $niches ) ) : ?>
                            <?php foreach ( $niches as $niche ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $niche->niche_id ); ?></td>
                                    <td><?php echo esc_html( $niche->niche_name ); ?></td>
                                    <td>
                                        <form method="post" action="" class="bite-delete-form">
                                            <input type="hidden" name="bite_action" value="delete_niche">
                                            <input type="hidden" name="bite_niche_id" value="<?php echo esc_attr( $niche->niche_id ); ?>">
                                            <?php wp_nonce_field( 'bite_delete_niche_nonce' ); ?>
                                            <?php submit_button( __( 'Delete', 'bite-theme' ), 'secondary small', 'submit', false ); ?>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="3"><?php esc_html_e( 'No niches found. Add one to get started.', 'bite-theme' ); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Renders the "Manage Sites" admin page.
 */
function bite_admin_page_sites() {
    global $wpdb;
    
    // Get all niches for the dropdown
    $niche_table = $wpdb->prefix . 'bite_niches';
    $niches = $wpdb->get_results( "SELECT * FROM $niche_table ORDER BY niche_name ASC" );

    // Get all sites with their niche names
    $site_table = $wpdb->prefix . 'bite_sites';
    $sites = $wpdb->get_results(
        "SELECT s.*, n.niche_name 
         FROM $site_table s
         LEFT JOIN $niche_table n ON s.niche_id = n.niche_id
         ORDER BY s.name ASC"
    );
    ?>
    <div class="wrap bite-admin-wrap">
        <h1><?php esc_html_e( 'Manage Sites', 'bite-theme' ); ?></h1>
        
        <h2><?php esc_html_e( 'Add New Site', 'bite-theme' ); ?></h2>
        
        <!-- Google API Setup Instructions -->
        <div class="bite-api-instructions" style="background: #f0f6fc; border: 1px solid #c5d9ed; padding: 20px; margin-bottom: 20px; border-radius: 4px;">
            <h3 style="margin-top: 0;">📘 Google Search Console API Setup Required</h3>
            <p>Before adding a site, you need to set up Google Search Console API access. Each site requires its own Service Account credentials.</p>
            
            <details>
                <summary style="cursor: pointer; font-weight: 600; color: #2271b1;">Click to view setup instructions</summary>
                <div style="margin-top: 15px; padding: 15px; background: #fff; border-radius: 4px;">
                    <h4>Step-by-Step Setup:</h4>
                    <ol>
                        <li>Go to <a href="https://console.cloud.google.com/" target="_blank">Google Cloud Console</a></li>
                        <li>Create a new project (or use existing)</li>
                        <li>Enable the <strong>Google Search Console API</strong></li>
                        <li>Go to <strong>IAM & Admin > Service Accounts</strong></li>
                        <li>Create a Service Account:
                            <ul>
                                <li>Name: bite-[domain-name]</li>
                                <li>Grant "Viewer" role for Search Console</li>
                            </ul>
                        </li>
                        <li>Create a JSON key for the service account (Keys > Add Key > Create New Key)</li>
                        <li>In Google Search Console, add the service account email as a <strong>Restricted Property User</strong></li>
                        <li>Upload the JSON file below when adding the site</li>
                    </ol>
                    
                    <h4>GSC Property Format:</h4>
                    <ul>
                        <li><strong>Domain property:</strong> <code>sc-domain:example.com</code> (recommended)</li>
                        <li><strong>URL prefix:</strong> <code>https://www.example.com/</code> (must match exactly)</li>
                    </ul>
                </div>
            </details>
        </div>
        
        <form method="post" action="" class="bite-admin-form" enctype="multipart/form-data">
            <input type="hidden" name="bite_action" value="add_site">
            <?php wp_nonce_field( 'bite_add_site_nonce' ); ?>
            
            <div class="bite-admin-form-container">
                <div class="bite-admin-form-col-1">
                    <p>
                        <label for="bite_site_name"><?php esc_html_e( 'Site Name:', 'bite-theme' ); ?></label>
                        <input type="text" id="bite_site_name" name="bite_site_name" required>
                    </p>
                    <p>
                        <label for="bite_domain"><?php esc_html_e( 'Domain (e.g., example.com):', 'bite-theme' ); ?></label>
                        <input type="text" id="bite_domain" name="bite_domain" required>
                    </p>
                    <p>
                        <label for="bite_gsc_property"><?php esc_html_e( 'GSC Property:', 'bite-theme' ); ?></label>
                        <input type="text" id="bite_gsc_property" name="bite_gsc_property" required 
                               placeholder="sc-domain:example.com OR https://www.example.com/">
                    </p>
                </div>
                <div class="bite-admin-form-col-1">
                    <p>
                        <label for="bite_niche_id"><?php esc_html_e( 'Niche:', 'bite-theme' ); ?></label>
                        <select id="bite_niche_id" name="bite_niche_id" required>
                            <option value=""><?php esc_html_e( 'Select a Niche', 'bite-theme' ); ?></option>
                            <?php foreach ( $niches as $niche ) : ?>
                                <option value="<?php echo esc_attr( $niche->niche_id ); ?>">
                                    <?php echo esc_html( $niche->niche_name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <p>
                        <label for="bite_gsc_credentials">
                            <?php esc_html_e( 'GSC Service Account JSON:', 'bite-theme' ); ?>
                            <span class="description" style="display: block; font-weight: normal; color: #666;">
                                Upload the JSON key file from Google Cloud Console
                            </span>
                        </label>
                        <input type="file" id="bite_gsc_credentials" name="bite_gsc_credentials" accept=".json" required>
                    </p>
                    <p>
                        <?php submit_button( __( 'Add Site', 'bite-theme' ) ); ?>
                    </p>
                </div>
            </div>
        </form>

        <h2><?php esc_html_e( 'Existing Sites', 'bite-theme' ); ?></h2>
        <table class="bite-admin-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'ID', 'bite-theme' ); ?></th>
                    <th><?php esc_html_e( 'Site Name', 'bite-theme' ); ?></th>
                    <th><?php esc_html_e( 'Niche', 'bite-theme' ); ?></th>
                    <th><?php esc_html_e( 'GSC Property', 'bite-theme' ); ?></th>
                    <th><?php esc_html_e( 'Backfill', 'bite-theme' ); ?></th>
                    <th><?php esc_html_e( 'Action', 'bite-theme' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! empty( $sites ) ) : ?>
                    <?php foreach ( $sites as $site ) : ?>
                        <tr>
                            <td><?php echo esc_html( $site->site_id ); ?></td>
                            <td><strong><?php echo esc_html( $site->name ); ?></strong><br><?php echo esc_html( $site->domain ); ?></td>
                            <td><?php echo esc_html( $site->niche_name ); ?></td>
                            <td><?php echo esc_html( $site->gsc_property ); ?></td>
                            <td><?php echo esc_html( $site->backfill_status ); ?></td>
                            <td>
                                <form method="post" action="" class="bite-delete-form" onsubmit="return confirm('Are you sure you want to delete this site AND all its tracked keyword data? This cannot be undone.');">
                                    <input type="hidden" name="bite_action" value="delete_site">
                                    <input type="hidden" name="bite_site_id" value="<?php echo esc_attr( $site->site_id ); ?>">
                                    <?php wp_nonce_field( 'bite_delete_site_nonce' ); ?>
                                    <?php submit_button( __( 'Delete', 'bite-theme' ), 'secondary small', 'submit', false ); ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="7"><?php esc_html_e( 'No sites found. Add one to get started.', 'bite-theme' ); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/**
 * Dynamically creates a metrics table for a new site.
 *
 * @param int $site_id The ID of the site.
 */
function bite_create_metrics_table_for_site( $site_id ) {
    if ( ! $site_id ) {
        return;
    }
    
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    
    $table_name = $wpdb->prefix . 'bite_metrics_site_' . absint( $site_id );
    $charset_collate = $wpdb->get_charset_collate();

    // This is the new schema with the 'device' column
    $sql = "CREATE TABLE $table_name (
        metric_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        keyword_id INT UNSIGNED NOT NULL,
        date DATE NOT NULL,
        device ENUM('desktop', 'mobile', 'tablet') NOT NULL,
        clicks INT UNSIGNED DEFAULT 0,
        impressions INT UNSIGNED DEFAULT 0,
        ctr DECIMAL(5,2) DEFAULT 0.00,
        position DECIMAL(5,2) DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (metric_id),
        UNIQUE KEY uq_keyword_date_device (keyword_id, date, device),
        KEY idx_date_device (date, device)
    ) $charset_collate;";

    dbDelta( $sql );
}

/**
 * Dynamically drops a metrics table when a site is deleted.
 *
 * @param int $site_id The ID of the site.
 */
function bite_delete_metrics_table_for_site( $site_id ) {
    if ( ! $site_id ) {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'bite_metrics_site_' . absint( $site_id );
    
    // Use a direct query to drop the table
    $wpdb->query( "DROP TABLE IF EXISTS $table_name" );
}


/**
 * Register the User Access submenu page.
 */
function bite_register_user_access_menu() {
    add_submenu_page(
        'bite-admin-main',
        __( 'User Access', 'bite-theme' ),
        __( 'User Access', 'bite-theme' ),
        'manage_options',
        'bite-admin-users',
        'bite_admin_page_user_access'
    );
}
add_action( 'admin_menu', 'bite_register_user_access_menu', 20 );

/**
 * Handle user access form submissions.
 */
function bite_handle_user_access_actions() {
    global $wpdb;
    
    if ( ! isset( $_POST['bite_user_action'] ) || ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Grant access
    if ( $_POST['bite_user_action'] === 'grant_access' && isset( $_POST['user_id'] ) && isset( $_POST['site_id'] ) ) {
        check_admin_referer( 'bite_grant_access_nonce' );
        
        $user_id = absint( $_POST['user_id'] );
        $site_id = absint( $_POST['site_id'] );
        
        // Check if user_sites table exists first
        $user_sites_table = $wpdb->prefix . 'bite_user_sites';
        $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$user_sites_table'" );
        
        if ( ! $table_exists ) {
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-error"><p><strong>Database Error:</strong> The user access table does not exist. Please deactivate and reactivate the BITE theme to create the required database tables.</p></div>';
            } );
            return;
        }
        
        $result = bite_grant_user_site_access( $user_id, $site_id, get_current_user_id() );
        
        if ( is_wp_error( $result ) ) {
            add_action( 'admin_notices', function() use ( $result ) {
                $error_message = $result->get_error_message();
                // Add debugging info for database errors
                if ( $result->get_error_code() === 'db_error' || $result->get_error_code() === 'insert_failed' ) {
                    global $wpdb;
                    $error_message .= ' <br><small>Debug: ' . esc_html( $wpdb->last_error ) . '</small>';
                }
                echo '<div class="notice notice-error"><p>' . wp_kses_post( $error_message ) . '</p></div>';
            } );
        } else {
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-success"><p>Access granted successfully!</p></div>';
            } );
        }
    }

    // Revoke access
    if ( $_POST['bite_user_action'] === 'revoke_access' && isset( $_POST['user_id'] ) && isset( $_POST['site_id'] ) ) {
        check_admin_referer( 'bite_revoke_access_nonce' );
        
        $user_id = absint( $_POST['user_id'] );
        $site_id = absint( $_POST['site_id'] );
        
        $result = bite_revoke_user_site_access( $user_id, $site_id );
        
        if ( is_wp_error( $result ) ) {
            add_action( 'admin_notices', function() use ( $result ) {
                echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
            } );
        } else {
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-success"><p>Access revoked successfully!</p></div>';
            } );
        }
    }
}
add_action( 'admin_init', 'bite_handle_user_access_actions' );

/**
 * Renders the User Access admin page.
 */
function bite_admin_page_user_access() {
    global $wpdb;
    
    // Get all sites
    $sites_table = $wpdb->prefix . 'bite_sites';
    $all_sites = $wpdb->get_results( "SELECT site_id, name, domain FROM $sites_table ORDER BY name ASC" );
    
    // Get all users with bite_viewer role (and admins for reference)
    $users = get_users( array(
        'fields' => array( 'ID', 'display_name', 'user_email', 'user_login' ),
        'orderby' => 'display_name',
    ) );
    
    // Get selected user for detailed view
    $selected_user_id = isset( $_GET['view_user'] ) ? absint( $_GET['view_user'] ) : 0;
    $selected_user = $selected_user_id ? get_userdata( $selected_user_id ) : null;
    ?>
    <div class="wrap bite-admin-wrap">
        <h1><?php esc_html_e( 'User Site Access', 'bite-theme' ); ?></h1>
        <p><?php esc_html_e( 'Manage which users can access which sites. Admins automatically have access to all sites.', 'bite-theme' ); ?></p>
        
        <?php if ( $selected_user ) : ?>
            <!-- Single User View -->
            <h2><?php echo esc_html( $selected_user->display_name ); ?> (<?php echo esc_html( $selected_user->user_email ); ?>)</h2>
            <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=bite-admin-users' ) ); ?>" class="button">&larr; Back to All Users</a></p>
            
            <?php
            $user_site_ids = bite_get_user_sites( $selected_user_id );
            $user_sites = array();
            if ( ! empty( $user_site_ids ) ) {
                $placeholders = implode( ', ', array_fill( 0, count( $user_site_ids ), '%d' ) );
                $user_sites = $wpdb->get_results( $wpdb->prepare(
                    "SELECT site_id, name, domain FROM $sites_table WHERE site_id IN ($placeholders) ORDER BY name ASC",
                    $user_site_ids
                ) );
            }
            ?>
            
            <h3><?php esc_html_e( 'Assigned Sites', 'bite-theme' ); ?></h3>
            <?php if ( ! empty( $user_sites ) ) : ?>
                <table class="bite-admin-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Site Name', 'bite-theme' ); ?></th>
                            <th><?php esc_html_e( 'Domain', 'bite-theme' ); ?></th>
                            <th><?php esc_html_e( 'Action', 'bite-theme' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $user_sites as $site ) : ?>
                            <tr>
                                <td><?php echo esc_html( $site->name ); ?></td>
                                <td><?php echo esc_html( $site->domain ); ?></td>
                                <td>
                                    <form method="post" action="" class="bite-delete-form">
                                        <input type="hidden" name="bite_user_action" value="revoke_access">
                                        <input type="hidden" name="user_id" value="<?php echo esc_attr( $selected_user_id ); ?>">
                                        <input type="hidden" name="site_id" value="<?php echo esc_attr( $site->site_id ); ?>">
                                        <?php wp_nonce_field( 'bite_revoke_access_nonce' ); ?>
                                        <?php submit_button( __( 'Revoke Access', 'bite-theme' ), 'secondary small', 'submit', false ); ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p><?php esc_html_e( 'No sites assigned to this user.', 'bite-theme' ); ?></p>
            <?php endif; ?>
            
            <h3><?php esc_html_e( 'Grant Access to Additional Sites', 'bite-theme' ); ?></h3>
            <form method="post" action="" class="bite-admin-form">
                <input type="hidden" name="bite_user_action" value="grant_access">
                <input type="hidden" name="user_id" value="<?php echo esc_attr( $selected_user_id ); ?>">
                <?php wp_nonce_field( 'bite_grant_access_nonce' ); ?>
                
                <p>
                    <label for="site_id"><?php esc_html_e( 'Select Site:', 'bite-theme' ); ?></label>
                    <select id="site_id" name="site_id" required>
                        <option value=""><?php esc_html_e( 'Select a site...', 'bite-theme' ); ?></option>
                        <?php foreach ( $all_sites as $site ) : 
                            // Skip if user already has access
                            if ( in_array( $site->site_id, $user_site_ids ) ) continue;
                        ?>
                            <option value="<?php echo esc_attr( $site->site_id ); ?>">
                                <?php echo esc_html( $site->name ); ?> (<?php echo esc_html( $site->domain ); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
                
                <?php submit_button( __( 'Grant Access', 'bite-theme' ) ); ?>
            </form>
            
        <?php else : ?>
            <!-- All Users List -->
            <h2><?php esc_html_e( 'All Users', 'bite-theme' ); ?></h2>
            <table class="bite-admin-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'User', 'bite-theme' ); ?></th>
                        <th><?php esc_html_e( 'Role', 'bite-theme' ); ?></th>
                        <th><?php esc_html_e( 'Sites Assigned', 'bite-theme' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'bite-theme' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $users as $user ) : 
                        $user_data = get_userdata( $user->ID );
                        $roles = $user_data->roles;
                        $role_names = array_map( function( $role ) {
                            $role_obj = get_role( $role );
                            return $role_obj ? translate_user_role( $role_obj->name ) : $role;
                        }, $roles );
                        
                        $user_site_count = count( bite_get_user_sites( $user->ID ) );
                        $is_admin = user_can( $user->ID, 'manage_options' );
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $user->display_name ); ?></strong><br>
                                <small><?php echo esc_html( $user->user_email ); ?></small>
                            </td>
                            <td><?php echo esc_html( implode( ', ', $role_names ) ); ?></td>
                            <td>
                                <?php if ( $is_admin ) : ?>
                                    <span class="bite-badge-admin"><?php esc_html_e( 'All Sites (Admin)', 'bite-theme' ); ?></span>
                                <?php else : ?>
                                    <?php echo esc_html( $user_site_count ); ?> sites
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( ! $is_admin ) : ?>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=bite-admin-users&view_user=' . $user->ID ) ); ?>" class="button"><?php esc_html_e( 'Manage Access', 'bite-theme' ); ?></a>
                                <?php else : ?>
                                    <em><?php esc_html_e( 'Auto-access', 'bite-theme' ); ?></em>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}


/**
 * Register BITE settings
 */
function bite_register_settings() {
    register_setting( 'bite_settings', 'bite_contact_email' );
}
add_action( 'admin_init', 'bite_register_settings' );

/**
 * Display the Settings Page
 */
function bite_admin_page_settings() {
    // Check permissions
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'You do not have permission to access this page.', 'bite-theme' ) );
    }

    // Save settings
    if ( isset( $_POST['bite_settings_save'] ) && isset( $_POST['bite_settings_nonce'] ) && wp_verify_nonce( $_POST['bite_settings_nonce'], 'bite_save_settings' ) ) {
        $contact_email = sanitize_email( $_POST['bite_contact_email'] );
        update_option( 'bite_contact_email', $contact_email );
        echo '<div class="notice notice-success"><p>Settings saved successfully.</p></div>';
    }

    // Get current settings
    $contact_email = get_option( 'bite_contact_email', get_option( 'admin_email' ) );
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        
        <form method="post" action="">
            <?php wp_nonce_field( 'bite_save_settings', 'bite_settings_nonce' ); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="bite_contact_email">Contact Form Email</label>
                    </th>
                    <td>
                        <input type="email" 
                               id="bite_contact_email" 
                               name="bite_contact_email" 
                               value="<?php echo esc_attr( $contact_email ); ?>" 
                               class="regular-text"
                               placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
                        <p class="description">
                            The email address where contact form submissions will be sent. 
                            If not set, the WordPress admin email will be used.
                        </p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button( 'Save Settings', 'primary', 'bite_settings_save' ); ?>
        </form>
    </div>
    <?php
}
