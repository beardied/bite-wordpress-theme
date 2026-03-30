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

        $site_data = array(
            'niche_id'       => absint( $_POST['bite_niche_id'] ),
            'name'           => sanitize_text_field( $_POST['bite_site_name'] ),
            'domain'         => sanitize_text_field( $_POST['bite_domain'] ),
            'gsc_property'   => sanitize_text_field( $_POST['bite_gsc_property'] ),
            'matomo_site_id' => absint( $_POST['bite_matomo_id'] ),
            'backfill_status' => 'pending', // Always set to pending on creation
        );

        // Insert into the main sites table
        $table_name = $wpdb->prefix . 'bite_sites';
        $wpdb->insert( $table_name, $site_data );
        
        // Get the new ID and create the metrics table
        $new_site_id = $wpdb->insert_id;
        if ( $new_site_id > 0 ) {
            bite_create_metrics_table_for_site( $new_site_id );
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
                        <label for="bite_gsc_property"><?php esc_html_e( 'GSC Property (e.g., sc-domain:domain.com OR https://domain.com/):', 'bite-theme' ); ?></label>
                        <input type="text" id="bite_gsc_property" name="bite_gsc_property" required>
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
                        <label for="bite_matomo_id"><?php esc_html_e( 'Matomo Site ID (Optional):', 'bite-theme' ); ?></label>
                        <input type="number" id="bite_matomo_id" name="bite_matomo_id" min="0" step="1">
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
                    <th><?php esc_html_e( 'Matomo ID', 'bite-theme' ); ?></th>
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
                            <td><?php echo esc_html( $site->matomo_site_id ); ?></td>
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
