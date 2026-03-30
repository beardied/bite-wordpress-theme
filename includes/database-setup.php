<?php
/**
 * Database Table Setup
 *
 * This file creates the necessary global database tables upon theme activation.
 *
 * @package BITE-theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Create the custom database tables on theme activation.
 */
function bite_create_database_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();

    // 1. Niches Table
    $table_name_niches = $wpdb->prefix . 'bite_niches';
    $sql_niches = "CREATE TABLE $table_name_niches (
        niche_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        niche_name VARCHAR(255) NOT NULL,
        PRIMARY KEY (niche_id),
        UNIQUE KEY uq_niche_name (niche_name)
    ) $charset_collate;";
    dbDelta( $sql_niches );

    // 2. Sites Table
    $table_name_sites = $wpdb->prefix . 'bite_sites';
    $sql_sites = "CREATE TABLE $table_name_sites (
        site_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        niche_id INT UNSIGNED NOT NULL,
        name VARCHAR(255) NOT NULL,
        domain VARCHAR(255) NOT NULL,
        gsc_property VARCHAR(255) NOT NULL,
        matomo_site_id INT UNSIGNED NULL,
        backfill_status ENUM('pending', 'in_progress', 'complete') NOT NULL DEFAULT 'pending',
        backfill_next_date DATE NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (site_id),
        KEY idx_niche_id (niche_id)
    ) $charset_collate;";
    dbDelta( $sql_sites );

    // 3. Keywords Table
    $table_name_keywords = $wpdb->prefix . 'bite_keywords';
    $sql_keywords = "CREATE TABLE $table_name_keywords (
        keyword_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        keyword VARCHAR(255) NOT NULL,
        PRIMARY KEY (keyword_id),
        UNIQUE KEY uq_keyword (keyword)
    ) $charset_collate;";
    dbDelta( $sql_keywords );
    
    // 4. NEW: Daily Summary Table
    // This table stores the *true totals* (including anonymized data)
    $table_name_summary = $wpdb->prefix . 'bite_daily_summary';
    $sql_summary = "CREATE TABLE $table_name_summary (
        summary_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        site_id INT UNSIGNED NOT NULL,
        date DATE NOT NULL,
        device ENUM('desktop', 'mobile', 'tablet') NOT NULL,
        total_clicks INT UNSIGNED DEFAULT 0,
        total_impressions INT UNSIGNED DEFAULT 0,
        total_ctr DECIMAL(5,2) DEFAULT 0.00,
        total_position DECIMAL(5,2) DEFAULT 0.00,
        PRIMARY KEY (summary_id),
        UNIQUE KEY uq_site_date_device (site_id, date, device),
        KEY idx_date (date)
    ) $charset_collate;";
    dbDelta( $sql_summary );
}
add_action( 'after_switch_theme', 'bite_create_database_tables' );
