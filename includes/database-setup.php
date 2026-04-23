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
        niche_id INT UNSIGNED NOT NULL DEFAULT 0,
        name VARCHAR(255) NOT NULL,
        domain VARCHAR(255) NOT NULL,
        gsc_property VARCHAR(255) NOT NULL,
        gsc_credentials TEXT NULL,
        backfill_status ENUM('pending', 'in_progress', 'complete', 'auth_error') NOT NULL DEFAULT 'pending',
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
    
    // 4. Daily Summary Table
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
    
    // 5. User Site Access Table
    $table_name_user_sites = $wpdb->prefix . 'bite_user_sites';
    $sql_user_sites = "CREATE TABLE $table_name_user_sites (
        user_site_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        site_id INT UNSIGNED NOT NULL,
        assigned_by BIGINT UNSIGNED NOT NULL,
        assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_site_id),
        UNIQUE KEY uq_user_site (user_id, site_id),
        KEY idx_user_id (user_id),
        KEY idx_site_id (site_id)
    ) $charset_collate;";
    dbDelta( $sql_user_sites );
    
    // 6. Reviews Table
    $table_name_reviews = $wpdb->prefix . 'bite_reviews';
    $sql_reviews = "CREATE TABLE $table_name_reviews (
        review_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        user_name VARCHAR(100) NOT NULL,
        rating TINYINT UNSIGNED NOT NULL,
        review_text TEXT,
        is_approved TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (review_id),
        KEY idx_user_id (user_id),
        KEY idx_rating (rating),
        KEY idx_approved (is_approved)
    ) $charset_collate;";
    dbDelta( $sql_reviews );

    // 7. OAuth Tokens Table (NEW)
    $table_name_oauth = $wpdb->prefix . 'bite_user_oauth';
    $sql_oauth = "CREATE TABLE $table_name_oauth (
        oauth_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        refresh_token TEXT NOT NULL,
        access_token TEXT NULL,
        token_expires_at TIMESTAMP NULL,
        connected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (oauth_id),
        UNIQUE KEY uq_user_id (user_id),
        KEY idx_user_id (user_id)
    ) $charset_collate;";
    dbDelta( $sql_oauth );
}
add_action( 'after_switch_theme', 'bite_create_database_tables' );

/**
 * Create missing tables on theme version update
 */
function bite_create_missing_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    
    // Check if user_sites table exists
    $user_sites_table = $wpdb->prefix . 'bite_user_sites';
    $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$user_sites_table'" );
    
    if ( ! $table_exists ) {
        $sql_user_sites = "CREATE TABLE $user_sites_table (
            user_site_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            site_id INT UNSIGNED NOT NULL,
            assigned_by BIGINT UNSIGNED NOT NULL,
            assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_site_id),
            UNIQUE KEY uq_user_site (user_id, site_id),
            KEY idx_user_id (user_id),
            KEY idx_site_id (site_id)
        ) $charset_collate;";
        dbDelta( $sql_user_sites );
    }
    
    // Check if reviews table exists
    $reviews_table = $wpdb->prefix . 'bite_reviews';
    $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$reviews_table'" );
    
    if ( ! $table_exists ) {
        $sql_reviews = "CREATE TABLE $reviews_table (
            review_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            user_name VARCHAR(100) NOT NULL,
            rating TINYINT UNSIGNED NOT NULL,
            review_text TEXT,
            is_approved TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (review_id),
            KEY idx_user_id (user_id),
            KEY idx_rating (rating),
            KEY idx_approved (is_approved)
        ) $charset_collate;";
        dbDelta( $sql_reviews );
    }
    
    // Check if gsc_credentials column exists in sites table
    $sites_table = $wpdb->prefix . 'bite_sites';
    $column_exists = $wpdb->get_results( "SHOW COLUMNS FROM $sites_table LIKE 'gsc_credentials'" );
    
    if ( empty( $column_exists ) ) {
        $wpdb->query( "ALTER TABLE $sites_table ADD COLUMN gsc_credentials TEXT NULL AFTER gsc_property" );
    }

    // Check if OAuth tokens table exists (NEW)
    $oauth_table = $wpdb->prefix . 'bite_user_oauth';
    $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$oauth_table'" );
    
    if ( ! $table_exists ) {
        $sql_oauth = "CREATE TABLE $oauth_table (
            oauth_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            refresh_token TEXT NOT NULL,
            access_token TEXT NULL,
            token_expires_at TIMESTAMP NULL,
            connected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (oauth_id),
            UNIQUE KEY uq_user_id (user_id),
            KEY idx_user_id (user_id)
        ) $charset_collate;";
        dbDelta( $sql_oauth );
    }
    
    // Migration: Add 'auth_error' to backfill_status ENUM if not present
    $enum_check = $wpdb->get_row( "SHOW COLUMNS FROM $sites_table LIKE 'backfill_status'" );
    if ( $enum_check && strpos( $enum_check->Type, 'auth_error' ) === false ) {
        $wpdb->query( "ALTER TABLE $sites_table MODIFY COLUMN backfill_status ENUM('pending', 'in_progress', 'complete', 'auth_error') NOT NULL DEFAULT 'pending'" );
    }
}
add_action( 'init', 'bite_create_missing_tables' );
