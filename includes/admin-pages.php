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

    // Add the "System Status" submenu page
    add_submenu_page(
        'bite-admin-main',
        __( 'BITE System Status', 'bite-theme' ),
        __( 'System Status', 'bite-theme' ),
        'manage_options',
        'bite-admin-system',
        'bite_admin_page_system'
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

        // Check if admin has connected their Google account
        $admin_id = get_current_user_id();
        if ( ! bite_user_has_google_connection( $admin_id ) ) {
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-error"><p>You must connect your Google account in BITE Settings before adding sites. <a href="' . admin_url( 'admin.php?page=bite-admin-settings' ) . '">Go to Settings →</a></p></div>';
            } );
            return;
        }

        $site_data = array(
            'niche_id'        => absint( $_POST['bite_niche_id'] ),
            'name'            => sanitize_text_field( $_POST['bite_site_name'] ),
            'domain'          => sanitize_text_field( $_POST['bite_domain'] ),
            'gsc_property'    => sanitize_text_field( $_POST['bite_gsc_property'] ),
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
                // Grant admin access to the site
                bite_grant_user_site_access( $admin_id, $new_site_id, $admin_id );
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
    
    // --- Edit Site ---
    if ( isset( $_POST['bite_action'] ) && $_POST['bite_action'] === 'edit_site' ) {
        check_admin_referer( 'bite_edit_site_nonce' );
        
        $site_id = absint( $_POST['bite_site_id'] );
        if ( $site_id > 0 ) {
            $site_data = array(
                'niche_id'     => absint( $_POST['bite_niche_id'] ),
                'name'         => sanitize_text_field( $_POST['bite_site_name'] ),
                'domain'       => sanitize_text_field( $_POST['bite_domain'] ),
                'gsc_property' => sanitize_text_field( $_POST['bite_gsc_property'] ),
            );
            
            $table_name = $wpdb->prefix . 'bite_sites';
            $result = $wpdb->update( $table_name, $site_data, array( 'site_id' => $site_id ) );
            
            if ( $result !== false ) {
                add_action( 'admin_notices', function() {
                    echo '<div class="notice notice-success"><p>Site updated successfully.</p></div>';
                } );
            } else {
                add_action( 'admin_notices', function() {
                    echo '<div class="notice notice-error"><p>Failed to update site.</p></div>';
                } );
            }
        }
    }

    // === SYSTEM STATUS ACTIONS ===

    // --- Trigger Backfill ---
    if ( $_POST['bite_action'] === 'trigger_backfill' ) {
        check_admin_referer( 'bite_system_status_nonce' );
        if ( ! wp_next_scheduled( 'bite_backfill_hook' ) && ! get_transient( 'bite_backfill_running' ) ) {
            wp_schedule_single_event( time() + 5, 'bite_backfill_hook' );
        }
        wp_redirect( admin_url( 'admin.php?page=bite-admin-system&bite_notice=backfill_triggered' ) );
        exit;
    }

    // --- Run Daily Update Check ---
    if ( $_POST['bite_action'] === 'run_daily_update' ) {
        check_admin_referer( 'bite_system_status_nonce' );
        bite_run_daily_update();
        wp_redirect( admin_url( 'admin.php?page=bite-admin-system&bite_notice=daily_update_ran' ) );
        exit;
    }

    // --- Reset Site Status ---
    if ( isset( $_POST['bite_site_id'] ) && $_POST['bite_action'] === 'reset_site' ) {
        check_admin_referer( 'bite_system_status_nonce' );
        $site_id = absint( $_POST['bite_site_id'] );
        if ( $site_id > 0 ) {
            bite_clear_auth_error( $site_id );
            bite_clear_retry_backoff( $site_id );
            $wpdb->update(
                $wpdb->prefix . 'bite_sites',
                array( 'backfill_status' => 'pending', 'backfill_next_date' => null ),
                array( 'site_id' => $site_id ),
                array( '%s', '%s' ),
                array( '%d' )
            );
        }
        wp_redirect( admin_url( 'admin.php?page=bite-admin-system&bite_notice=site_reset' ) );
        exit;
    }

    // --- Resume Site (auth_error → pending) ---
    if ( isset( $_POST['bite_site_id'] ) && $_POST['bite_action'] === 'resume_site' ) {
        check_admin_referer( 'bite_system_status_nonce' );
        $site_id = absint( $_POST['bite_site_id'] );
        if ( $site_id > 0 ) {
            bite_clear_auth_error( $site_id );
            bite_clear_retry_backoff( $site_id );
        }
        wp_redirect( admin_url( 'admin.php?page=bite-admin-system&bite_notice=site_resumed' ) );
        exit;
    }

    // --- Clear Running Transient ---
    if ( $_POST['bite_action'] === 'clear_transient' ) {
        check_admin_referer( 'bite_system_status_nonce' );
        delete_transient( 'bite_backfill_running' );
        wp_redirect( admin_url( 'admin.php?page=bite-admin-system&bite_notice=transient_cleared' ) );
        exit;
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
    
    // Check if editing a site
    $edit_site_id = isset( $_GET['edit_site'] ) ? absint( $_GET['edit_site'] ) : 0;
    $edit_site = null;
    if ( $edit_site_id > 0 ) {
        $edit_site = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $site_table WHERE site_id = %d",
            $edit_site_id
        ) );
    }
    ?>
    <div class="wrap bite-admin-wrap">
        <h1><?php esc_html_e( 'Manage Sites', 'bite-theme' ); ?></h1>
        
        <?php if ( $edit_site ) : ?>
            <h2><?php esc_html_e( 'Edit Site', 'bite-theme' ); ?></h2>
            <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=bite-admin-main' ) ); ?>" class="button">&larr; Back to Sites</a></p>
            
            <form method="post" action="" class="bite-admin-form" enctype="multipart/form-data">
                <input type="hidden" name="bite_action" value="edit_site">
                <input type="hidden" name="bite_site_id" value="<?php echo esc_attr( $edit_site->site_id ); ?>">
                <?php wp_nonce_field( 'bite_edit_site_nonce' ); ?>
                
                <div class="bite-admin-form-container">
                    <div class="bite-admin-form-col-1">
                        <p>
                            <label for="bite_site_name"><?php esc_html_e( 'Site Name:', 'bite-theme' ); ?></label>
                            <input type="text" id="bite_site_name" name="bite_site_name" required 
                                   value="<?php echo esc_attr( $edit_site->name ); ?>">
                        </p>
                        <p>
                            <label for="bite_domain"><?php esc_html_e( 'Domain:', 'bite-theme' ); ?></label>
                            <input type="text" id="bite_domain" name="bite_domain" required 
                                   value="<?php echo esc_attr( $edit_site->domain ); ?>">
                        </p>
                        <p>
                            <label for="bite_gsc_property"><?php esc_html_e( 'GSC Property:', 'bite-theme' ); ?></label>
                            <input type="text" id="bite_gsc_property" name="bite_gsc_property" required 
                                   value="<?php echo esc_attr( $edit_site->gsc_property ); ?>">
                        </p>
                    </div>
                    <div class="bite-admin-form-col-1">
                        <p>
                            <label for="bite_niche_id"><?php esc_html_e( 'Niche:', 'bite-theme' ); ?></label>
                            <select id="bite_niche_id" name="bite_niche_id">
                                <option value="0"><?php esc_html_e( 'None', 'bite-theme' ); ?></option>
                                <?php foreach ( $niches as $niche ) : ?>
                                    <option value="<?php echo esc_attr( $niche->niche_id ); ?>" 
                                            <?php selected( $edit_site->niche_id, $niche->niche_id ); ?>>
                                        <?php echo esc_html( $niche->niche_name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                        <p>
                            <label for="bite_gsc_credentials">
                                <?php esc_html_e( 'GSC Service Account JSON:', 'bite-theme' ); ?>
                                <span class="description" style="display: block; font-weight: normal; color: #666;">
                                    Upload new file only if you want to update credentials (optional)
                                </span>
                            </label>
                            <input type="file" id="bite_gsc_credentials" name="bite_gsc_credentials" accept=".json">
                        </p>
                        <p>
                            <?php submit_button( __( 'Update Site', 'bite-theme' ), 'primary', 'submit', false ); ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=bite-admin-main' ) ); ?>" class="button" style="margin-left: 10px;">Cancel</a>
                        </p>
                    </div>
                </div>
            </form>
            
            <hr>
        <?php endif; ?>
        
        <h2><?php esc_html_e( 'Add New Site', 'bite-theme' ); ?></h2>
        
        <?php 
        $admin_connected = bite_user_has_google_connection( get_current_user_id() );
        if ( ! $admin_connected ) : 
        ?>
            <div class="notice notice-error" style="margin: 20px 0;">
                <p><strong>⚠️ Google Account Not Connected</strong></p>
                <p>You must connect your Google account in <a href="<?php echo admin_url( 'admin.php?page=bite-admin-settings' ); ?>">BITE Settings</a> before adding sites.</p>
            </div>
        <?php else : ?>
            <div class="notice notice-success" style="margin: 20px 0;">
                <p>✅ Your Google account is connected. You can now add sites from your Search Console.</p>
            </div>
        
        <form method="post" action="" class="bite-admin-form">
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
                        <span class="description">
                            Must match exactly as shown in your Google Search Console.
                        </span>
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
                        <?php submit_button( __( 'Add Site', 'bite-theme' ) ); ?>
                    </p>
                </div>
            </div>
        </form>
        <?php endif; ?>

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
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=bite-admin-main&edit_site=' . $site->site_id ) ); ?>" class="button button-small" style="margin-right: 5px;">Edit</a>
                                <form method="post" action="" class="bite-delete-form" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this site AND all its tracked keyword data? This cannot be undone.');">
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
    register_setting( 'bite_settings', 'bite_google_client_id' );
    register_setting( 'bite_settings', 'bite_google_client_secret' );
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

    // Handle OAuth disconnect
    if ( isset( $_GET['disconnect_google'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'disconnect_google' ) ) {
        bite_disconnect_google_account( get_current_user_id() );
        echo '<div class="notice notice-success"><p>Google account disconnected.</p></div>';
    }

    // Save settings
    if ( isset( $_POST['bite_settings_save'] ) && isset( $_POST['bite_settings_nonce'] ) && wp_verify_nonce( $_POST['bite_settings_nonce'], 'bite_save_settings' ) ) {
        $contact_email = sanitize_email( $_POST['bite_contact_email'] );
        $client_id = sanitize_text_field( $_POST['bite_google_client_id'] );
        $client_secret = sanitize_text_field( $_POST['bite_google_client_secret'] );
        
        update_option( 'bite_contact_email', $contact_email );
        update_option( 'bite_google_client_id', $client_id );
        update_option( 'bite_google_client_secret', $client_secret );
        update_option( 'bite_opr_api_key', sanitize_text_field( $_POST['bite_opr_api_key'] ?? '' ) );
        update_option( 'bite_srt_api_key', sanitize_text_field( $_POST['bite_srt_api_key'] ?? '' ) );
        update_option( 'bite_moz_access_id', sanitize_text_field( $_POST['bite_moz_access_id'] ?? '' ) );
        update_option( 'bite_moz_secret_key', sanitize_text_field( $_POST['bite_moz_secret_key'] ?? '' ) );
        
        echo '<div class="notice notice-success"><p>Settings saved successfully.</p></div>';
    }

    // Get current settings
    $contact_email = get_option( 'bite_contact_email', get_option( 'admin_email' ) );
    $client_id = bite_get_google_client_id();
    $client_secret = bite_get_google_client_secret();
    $is_connected = bite_user_has_google_connection( get_current_user_id() );
    ?>
    <div class="wrap bite-admin-wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        
        <!-- Google OAuth Configuration Section -->
        <h2>🔐 Google Search Console OAuth Configuration</h2>
        
        <?php if ( ! bite_is_oauth_configured() ) : ?>
            <div class="notice notice-warning">
                <p><strong>OAuth Not Configured:</strong> You need to set up Google OAuth credentials before users can connect their Google accounts.</p>
            </div>
        <?php endif; ?>
        
        <form method="post" action="">
            <?php wp_nonce_field( 'bite_save_settings', 'bite_settings_nonce' ); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="bite_google_client_id">Google Client ID</label>
                    </th>
                    <td>
                        <input type="text" 
                               id="bite_google_client_id" 
                               name="bite_google_client_id" 
                               value="<?php echo esc_attr( $client_id ); ?>" 
                               class="regular-text"
                               style="width: 100%; max-width: 600px;"
                               placeholder="e.g., 123456789-abc123.apps.googleusercontent.com">
                        <p class="description">
                            The Client ID from your Google Cloud OAuth 2.0 credentials.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="bite_google_client_secret">Google Client Secret</label>
                    </th>
                    <td>
                        <input type="password" 
                               id="bite_google_client_secret" 
                               name="bite_google_client_secret" 
                               value="<?php echo esc_attr( $client_secret ); ?>" 
                               class="regular-text"
                               style="width: 100%; max-width: 600px;"
                               placeholder="Your Client Secret">
                        <p class="description">
                            The Client Secret from your Google Cloud OAuth 2.0 credentials.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Authorized Redirect URI</th>
                    <td>
                        <code style="background: #f0f0f1; padding: 8px 12px; display: inline-block; word-break: break-all;">
                            <?php echo esc_html( bite_get_oauth_redirect_uri() ); ?>
                        </code>
                        <p class="description">
                            Copy this URL and add it to your Google Cloud OAuth credentials as an "Authorized redirect URI".
                        </p>
                    </td>
                </tr>
            </table>
            
            <h3>Connection Status</h3>
            <?php if ( $is_connected ) : ?>
                <div class="notice notice-success inline" style="margin: 15px 0;">
                    <p>✅ Your Google account is connected. You can now add sites from your Search Console.</p>
                </div>
                <p>
                    <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=bite-admin-settings&disconnect_google=1' ), 'disconnect_google' ); ?>" 
                       class="button button-secondary" 
                       onclick="return confirm('Are you sure? This will disconnect your Google account and stop data fetching for all your sites.');">
                        Disconnect Google Account
                    </a>
                </p>
            <?php else : ?>
                <div class="notice notice-warning inline" style="margin: 15px 0;">
                    <p>⚠️ Your Google account is not connected. You need to connect before adding sites.</p>
                </div>
                <?php if ( bite_is_oauth_configured() ) : ?>
                    <p>
                        <a href="<?php echo esc_url( bite_get_google_auth_url( get_current_user_id() ) ); ?>" class="button button-primary">
                            Connect Google Account
                        </a>
                    </p>
                <?php else : ?>
                    <p><em>Enter your OAuth credentials above first, then save settings.</em></p>
                <?php endif; ?>
            <?php endif; ?>
            
            <hr style="margin: 30px 0;">

            <h2>📊 Domain Authority API Keys</h2>
            <p style="color: #666; margin-bottom: 15px;">These APIs power the B.I.T.E. Authority Index and backlink tracking. All keys are optional — missing keys simply disable that data source.</p>

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="bite_opr_api_key">OpenPageRank API Key</label></th>
                    <td>
                        <input type="password" id="bite_opr_api_key" name="bite_opr_api_key"
                               value="<?php echo esc_attr( get_option( 'bite_opr_api_key', '' ) ); ?>"
                               class="regular-text" style="width: 100%; max-width: 600px;">
                        <p class="description">
                            Free key from <a href="https://www.domcop.com/openpagerank/" target="_blank">OpenPageRank</a>. Batch up to 100 domains per call. 10,000 requests/hour.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="bite_srt_api_key">SEO Review Tools API Key</label></th>
                    <td>
                        <input type="password" id="bite_srt_api_key" name="bite_srt_api_key"
                               value="<?php echo esc_attr( get_option( 'bite_srt_api_key', '' ) ); ?>"
                               class="regular-text" style="width: 100%; max-width: 600px;">
                        <p class="description">
                            Free tier: ~50 requests/day. <a href="https://www.seoreviewtools.com/" target="_blank">Get key here</a>. Covers the first 50 sites added to BITE as a bonus feature.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="bite_moz_access_id">Moz Access ID</label></th>
                    <td>
                        <input type="text" id="bite_moz_access_id" name="bite_moz_access_id"
                               value="<?php echo esc_attr( get_option( 'bite_moz_access_id', '' ) ); ?>"
                               class="regular-text" style="width: 100%; max-width: 600px;">
                        <p class="description">
                            From your <a href="https://moz.com/products/api" target="_blank">Mozscape API</a> credentials.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="bite_moz_secret_key">Moz Secret Key</label></th>
                    <td>
                        <input type="password" id="bite_moz_secret_key" name="bite_moz_secret_key"
                               value="<?php echo esc_attr( get_option( 'bite_moz_secret_key', '' ) ); ?>"
                               class="regular-text" style="width: 100%; max-width: 600px;">
                        <p class="description">
                            Free tier requires a 10-second delay between requests. BITE handles this automatically.
                        </p>
                    </td>
                </tr>
            </table>

            <hr style="margin: 30px 0;">
            
            <h2>📧 Contact Form Settings</h2>
            
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
        
        <hr style="margin: 30px 0;">
        
        <h2>📘 Google Cloud Setup Instructions</h2>
        
        <div style="background: #f0f6fc; border: 1px solid #c5d9ed; padding: 20px; border-radius: 4px;">
            <p>Follow these steps to create your Google OAuth application:</p>
            
            <ol>
                <li>Go to <a href="https://console.cloud.google.com/" target="_blank">Google Cloud Console</a></li>
                <li>Create a new project (or select an existing one)</li>
                <li>Enable the <strong>Google Search Console API</strong>:
                    <ul style="margin-top: 5px;">
                        <li>Menu (☰) → <strong>APIs & Services</strong> → <strong>Library</strong></li>
                        <li>Search "Google Search Console API" and click <strong>Enable</strong></li>
                    </ul>
                </li>
                <li>Create OAuth 2.0 credentials:
                    <ul style="margin-top: 5px;">
                        <li>Menu (☰) → <strong>APIs & Services</strong> → <strong>Credentials</strong></li>
                        <li>Click <strong>Create Credentials</strong> → <strong>OAuth client ID</strong></li>
                        <li>Application type: <strong>Web application</strong></li>
                        <li>Name: "BITE Dashboard" (or your preferred name)</li>
                        <li>Authorized redirect URIs: Add the URL shown above</li>
                        <li>Click <strong>Create</strong></li>
                    </ul>
                </li>
                <li>Copy the <strong>Client ID</strong> and <strong>Client Secret</strong> and paste them above</li>
                <li>Configure the OAuth consent screen:
                    <ul style="margin-top: 5px;">
                        <li>Menu (☰) → <strong>APIs & Services</strong> → <strong>OAuth consent screen</strong></li>
                        <li>User Type: <strong>External</strong></li>
                        <li>App name: "BITE Dashboard"</li>
                        <li>User support email: Your email</li>
                        <li>Developer contact email: Your email</li>
                        <li>Scopes: Add ".../auth/webmasters.readonly" (Search Console read-only access)</li>
                        <li>Test users: Add your own email address</li>
                    </ul>
                </li>
            </ol>
            
            <p style="margin-top: 15px;">
                <strong>Note:</strong> While in "Testing" mode, your OAuth app can support up to 100 test users. 
                For production use with more users, you'll need to submit your app for verification.
            </p>
        </div>
    </div>
    <?php
}
/**
 * Renders the "System Status" admin page.
 */
function bite_admin_page_system() {
    global $wpdb;
    $sites_table = $wpdb->prefix . "bite_sites";

    $total_sites   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $sites_table" );
    $pending       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $sites_table WHERE backfill_status = \"pending\"" );
    $in_progress   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $sites_table WHERE backfill_status = \"in_progress\"" );
    $complete      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $sites_table WHERE backfill_status = \"complete\"" );
    $auth_errors   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $sites_table WHERE backfill_status = \"auth_error\"" );

    $backfill_scheduled = wp_next_scheduled( "bite_backfill_hook" );
    $daily_scheduled    = wp_next_scheduled( "bite_daily_update_hook" );
    $backfill_running   = get_transient( "bite_backfill_running" );

    $sites = $wpdb->get_results( "SELECT * FROM $sites_table ORDER BY site_id DESC" );
    $auth_error_sites = $wpdb->get_results( "SELECT * FROM $sites_table WHERE backfill_status = \"auth_error\" ORDER BY site_id DESC" );

    $bite_logs = array();
    $log_file = ini_get( "error_log" );
    if ( ! empty( $log_file ) && file_exists( $log_file ) && is_readable( $log_file ) ) {
        $log_lines = file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        if ( $log_lines ) {
            $log_lines = array_reverse( $log_lines );
            $count = 0;
            foreach ( $log_lines as $line ) {
                if ( stripos( $line, "BITE" ) !== false ) {
                    $bite_logs[] = $line;
                    $count++;
                    if ( $count >= 100 ) break;
                }
            }
        }
    }

    if ( isset( $_GET["bite_notice"] ) ) {
        $notices = array(
            "backfill_triggered" => array( "success", "Backfill triggered. It will start within a few seconds." ),
            "daily_update_ran"   => array( "success", "Daily update check completed. Any sites needing new data have been flagged." ),
            "site_reset"         => array( "success", "Site has been reset to pending status." ),
            "site_resumed"       => array( "success", "Site auth error cleared and set to resume." ),
            "transient_cleared"  => array( "success", "The backfill running transient has been cleared." ),
        );
        $notice_key = sanitize_text_field( $_GET["bite_notice"] );
        if ( isset( $notices[ $notice_key ] ) ) {
            echo "<div class=\"notice notice-" . esc_attr( $notices[ $notice_key ][0] ) . " is-dismissible\"><p>" . esc_html( $notices[ $notice_key ][1] ) . "</p></div>";
        }
    }
    ?>
    <div class="wrap">
        <h1>BITE System Status</h1>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin: 20px 0;">
            <div style="background: #f0f0f1; padding: 20px; border-radius: 8px; text-align: center; border-left: 4px solid #2271b1;">
                <div style="font-size: 2em; font-weight: bold; color: #2271b1;"><?php echo $total_sites; ?></div>
                <div style="color: #555; font-size: 0.9em;">Total Sites</div>
            </div>
            <div style="background: #f0f0f1; padding: 20px; border-radius: 8px; text-align: center; border-left: 4px solid #f0c33c;">
                <div style="font-size: 2em; font-weight: bold; color: #b38f00;"><?php echo $pending; ?></div>
                <div style="color: #555; font-size: 0.9em;">Pending</div>
            </div>
            <div style="background: #f0f0f1; padding: 20px; border-radius: 8px; text-align: center; border-left: 4px solid #72aee6;">
                <div style="font-size: 2em; font-weight: bold; color: #2271b1;"><?php echo $in_progress; ?></div>
                <div style="color: #555; font-size: 0.9em;">In Progress</div>
            </div>
            <div style="background: #f0f0f1; padding: 20px; border-radius: 8px; text-align: center; border-left: 4px solid #00a32a;">
                <div style="font-size: 2em; font-weight: bold; color: #00a32a;"><?php echo $complete; ?></div>
                <div style="color: #555; font-size: 0.9em;">Complete</div>
            </div>
            <div style="background: #f0f0f1; padding: 20px; border-radius: 8px; text-align: center; border-left: 4px solid #d63638;">
                <div style="font-size: 2em; font-weight: bold; color: #d63638;"><?php echo $auth_errors; ?></div>
                <div style="color: #555; font-size: 0.9em;">Auth Errors</div>
            </div>
        </div>

        <h2>System Status</h2>
        <table class="widefat" style="margin-bottom: 20px;">
            <tbody>
                <tr><th style="width: 200px;">Backfill Scheduled</th><td><?php echo $backfill_scheduled ? "<span style=\"color:#00a32a\">Yes - next run: " . human_time_diff( $backfill_scheduled, time() ) . "</span>" : "<span style=\"color:#d63638\">Not scheduled</span>"; ?></td></tr>
                <tr><th>Daily Update Scheduled</th><td><?php echo $daily_scheduled ? "<span style=\"color:#00a32a\">Yes - next run: " . human_time_diff( $daily_scheduled, time() ) . "</span>" : "<span style=\"color:#d63638\">Not scheduled</span>"; ?></td></tr>
                <tr><th>Backfill Running Now</th><td><?php echo $backfill_running ? "<span style=\"color:#b38f00\">Yes - transient active</span>" : "<span style=\"color:#00a32a\">No - idle</span>"; ?></td></tr>
            </tbody>
        </table>

        <h2>How This System Works</h2>
        <div style="background: #f0f6fc; border: 1px solid #c5d9ed; padding: 15px 20px; border-radius: 4px; margin-bottom: 25px; font-size: 13px; line-height: 1.6;">
            <p><strong>There are two separate processes that run automatically:</strong></p>
            <ol style="margin-left: 20px;">
                <li><strong>Daily Update (6am UTC)</strong> — Checks all "complete" sites for missing days since the last data was fetched. If gaps are found, the site is flagged as "pending" with the next needed date. <em>This is the scheduler.</em></li>
                <li><strong>Backfill Queue (every 2 minutes)</strong> — Processes sites flagged as "pending" or "in_progress", fetching one day of data at a time. <em>This is the worker.</em></li>
            </ol>
            <p><strong>Site statuses explained:</strong></p>
            <ul style="margin-left: 20px;">
                <li><strong>pending</strong> — Site is waiting to start (or needs new data). Daily update sets this.</li>
                <li><strong>in_progress</strong> — Backfill is actively fetching historical data for this site.</li>
                <li><strong>complete</strong> — Site is up to date. Daily update monitors these.</li>
                <li><strong>auth_error</strong> — Google token expired or revoked. User must reconnect their account.</li>
            </ul>
            <p><strong>What to do when things go wrong:</strong></p>
            <ul style="margin-left: 20px;">
                <li><strong>No new data appearing?</strong> Run "Daily Update Check" first to flag sites, then "Trigger Backfill" to process them.</li>
                <li><strong>Backfill stuck?</strong> Check if "Backfill Running Now" says Yes for a long time. If so, "Clear Running Transient" to release it.</li>
                <li><strong>Auth errors?</strong> Tell the affected user to visit their dashboard and click "Reconnect Google Account".</li>
            </ul>
        </div>

        <h2>Manual Controls</h2>
        <div style="margin-bottom: 25px;">
            <form method="POST" action="" style="display:inline-block; margin-right:10px;">
                <?php wp_nonce_field( "bite_system_status_nonce" ); ?>
                <input type="hidden" name="bite_action" value="run_daily_update">
                <button type="submit" class="button button-primary">Run Daily Update Check</button>
            </form>
            <form method="POST" action="" style="display:inline-block; margin-right:10px;">
                <?php wp_nonce_field( "bite_system_status_nonce" ); ?>
                <input type="hidden" name="bite_action" value="trigger_backfill">
                <button type="submit" class="button button-primary">Trigger Backfill Now</button>
            </form>
            <form method="POST" action="" style="display:inline-block;">
                <?php wp_nonce_field( "bite_system_status_nonce" ); ?>
                <input type="hidden" name="bite_action" value="clear_transient">
                <button type="submit" class="button button-secondary" onclick="return confirm(\"Clear the running transient? Only do this if you believe the backfill is stuck.\");">Clear Running Transient</button>
            </form>
        </div>
        <p style="color: #666; font-size: 12px; margin-bottom: 25px;">
            <strong>Run Daily Update Check</strong> = Scans all "complete" sites for missing days and flags them.<br>
            <strong>Trigger Backfill Now</strong> = Processes sites already flagged as "pending" or "in_progress".<br>
            <strong>Tip:</strong> If you want to force a full catch-up, run <em>Daily Update Check</em> first, wait 10 seconds, then run <em>Trigger Backfill</em>.
        </p>

        <h2>Sites Overview</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:50px">ID</th>
                    <th>Name</th>
                    <th>Domain</th>
                    <th style="width:120px">Status</th>
                    <th style="width:140px">Next Date</th>
                    <th style="width:200px">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $sites ) ) : ?>
                    <tr><td colspan="6" style="text-align:center; padding: 20px;">No sites found.</td></tr>
                <?php else : ?>
                    <?php foreach ( $sites as $site ) :
                        $owner = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}bite_user_sites WHERE site_id = %d ORDER BY assigned_at ASC LIMIT 1", $site->site_id ) );
                        $owner_name = $owner ? get_userdata( $owner )->display_name : "Unknown";
                        $status_color = array( "pending" => "#f0c33c", "in_progress" => "#72aee6", "complete" => "#00a32a", "auth_error" => "#d63638" );
                        $color = isset( $status_color[ $site->backfill_status ] ) ? $status_color[ $site->backfill_status ] : "#666";
                    ?>
                    <tr>
                        <td><?php echo $site->site_id; ?></td>
                        <td><strong><?php echo esc_html( $site->name ); ?></strong><br><small>Owner: <?php echo esc_html( $owner_name ); ?></small></td>
                        <td><?php echo esc_html( $site->domain ); ?></td>
                        <td><span style="display:inline-block; padding: 3px 8px; border-radius: 4px; background: <?php echo $color; ?>22; color: <?php echo $color; ?>; font-weight: 600; font-size: 0.85em;"><?php echo esc_html( $site->backfill_status ); ?></span></td>
                        <td><?php echo $site->backfill_next_date ? esc_html( $site->backfill_next_date ) : "-"; ?></td>
                        <td>
                            <?php if ( $site->backfill_status === "auth_error" ) : ?>
                                <form method="POST" action="" style="display:inline-block;">
                                    <?php wp_nonce_field( "bite_system_status_nonce" ); ?>
                                    <input type="hidden" name="bite_action" value="resume_site">
                                    <input type="hidden" name="bite_site_id" value="<?php echo $site->site_id; ?>">
                                    <button type="submit" class="button button-small" style="background:#00a32a; color:#fff; border-color:#00a32a;">Clear Auth</button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" action="" style="display:inline-block;">
                                <?php wp_nonce_field( "bite_system_status_nonce" ); ?>
                                <input type="hidden" name="bite_action" value="reset_site">
                                <input type="hidden" name="bite_site_id" value="<?php echo $site->site_id; ?>">
                                <button type="submit" class="button button-small" onclick="return confirm(\"Reset this site to pending? This will restart data collection from the beginning.\");">Reset</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <h2 style="margin-top: 30px;">Auth Error Details</h2>
        <?php if ( empty( $auth_error_sites ) ) : ?>
            <p style="color: #666;">No auth errors at this time.</p>
        <?php else : ?>
            <table class="wp-list-table widefat fixed striped" style="margin-bottom: 20px;">
                <thead>
                    <tr><th>Site</th><th>Property</th><th>Error Time</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php foreach ( $auth_error_sites as $site ) :
                        $error_data = get_option( "bite_auth_error_" . $site->site_id );
                        $error_time = $error_data ? $error_data["error_time"] : "Unknown";
                    ?>
                    <tr>
                        <td><?php echo esc_html( $site->name ); ?></td>
                        <td><code><?php echo esc_html( $site->gsc_property ); ?></code></td>
                        <td><?php echo esc_html( $error_time ); ?></td>
                        <td>
                            <form method="POST" action="" style="display:inline-block;">
                                <?php wp_nonce_field( "bite_system_status_nonce" ); ?>
                                <input type="hidden" name="bite_action" value="resume_site">
                                <input type="hidden" name="bite_site_id" value="<?php echo $site->site_id; ?>">
                                <button type="submit" class="button button-small" style="background:#00a32a; color:#fff; border-color:#00a32a;">Clear & Resume</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2 style="margin-top: 30px;">Recent BITE Logs (last 100 lines)</h2>
        <?php if ( empty( $bite_logs ) ) : ?>
            <p style="color: #666;">No BITE logs found. Check your PHP error_log configuration.</p>
        <?php else : ?>
            <div style="background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 4px; font-family: monospace; font-size: 12px; max-height: 500px; overflow-y: auto;">
                <?php foreach ( $bite_logs as $log_line ) : ?>
                    <div style="border-bottom: 1px solid #333; padding: 4px 0;"><?php echo esc_html( $log_line ); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}
