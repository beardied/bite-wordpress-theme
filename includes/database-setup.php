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

    // 7. Domain Metrics Table (Authority scores from external APIs)
    $table_name_domain_metrics = $wpdb->prefix . 'bite_domain_metrics';
    $sql_domain_metrics = "CREATE TABLE $table_name_domain_metrics (
        metric_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        site_id INT UNSIGNED NOT NULL,
        recorded_at DATE NOT NULL,
        moz_da INT UNSIGNED DEFAULT NULL,
        moz_pa INT UNSIGNED DEFAULT NULL,
        moz_ref_domains INT UNSIGNED DEFAULT NULL,
        srt_da INT UNSIGNED DEFAULT NULL,
        srt_pa INT UNSIGNED DEFAULT NULL,
        srt_backlinks BIGINT UNSIGNED DEFAULT NULL,
        opr_rank DECIMAL(4,2) DEFAULT NULL,
        opr_global_rank BIGINT UNSIGNED DEFAULT NULL,
        pagespeed_score INT UNSIGNED DEFAULT NULL,
        pagespeed_accessibility INT UNSIGNED DEFAULT NULL,
        pagespeed_best_practices INT UNSIGNED DEFAULT NULL,
        pagespeed_seo INT UNSIGNED DEFAULT NULL,
        authority_index DECIMAL(5,2) DEFAULT NULL,
        data_source_json LONGTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (metric_id),
        UNIQUE KEY uq_site_date (site_id, recorded_at),
        KEY idx_recorded_at (recorded_at),
        KEY idx_site_id (site_id)
    ) $charset_collate;";
    dbDelta( $sql_domain_metrics );
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

    // Migration: Create domain_metrics table if not exists
    $domain_metrics_table = $wpdb->prefix . 'bite_domain_metrics';
    $dm_exists = $wpdb->get_var( "SHOW TABLES LIKE '$domain_metrics_table'" );
    if ( ! $dm_exists ) {
        $sql_domain_metrics = "CREATE TABLE $domain_metrics_table (
            metric_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id INT UNSIGNED NOT NULL,
            recorded_at DATE NOT NULL,
            moz_da INT UNSIGNED DEFAULT NULL,
            moz_pa INT UNSIGNED DEFAULT NULL,
            moz_ref_domains INT UNSIGNED DEFAULT NULL,
            srt_da INT UNSIGNED DEFAULT NULL,
            srt_pa INT UNSIGNED DEFAULT NULL,
            srt_backlinks BIGINT UNSIGNED DEFAULT NULL,
            opr_rank DECIMAL(4,2) DEFAULT NULL,
            opr_global_rank BIGINT UNSIGNED DEFAULT NULL,
            pagespeed_score INT UNSIGNED DEFAULT NULL,
            authority_index DECIMAL(5,2) DEFAULT NULL,
            data_source_json LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (metric_id),
            UNIQUE KEY uq_site_date (site_id, recorded_at),
            KEY idx_recorded_at (recorded_at),
            KEY idx_site_id (site_id)
        ) $charset_collate;";
        dbDelta( $sql_domain_metrics );
    }

    // Migration: Add pagespeed_score column if missing
    $column_exists = $wpdb->get_results( "SHOW COLUMNS FROM $domain_metrics_table LIKE 'pagespeed_score'" );
    if ( empty( $column_exists ) ) {
        $wpdb->query( "ALTER TABLE $domain_metrics_table ADD COLUMN pagespeed_score INT UNSIGNED DEFAULT NULL AFTER opr_global_rank" );
    }

    // Migration: Add PageSpeed category columns if missing
    $columns_to_add = array(
        'pagespeed_accessibility'    => 'INT UNSIGNED DEFAULT NULL AFTER pagespeed_score',
        'pagespeed_best_practices'   => 'INT UNSIGNED DEFAULT NULL AFTER pagespeed_accessibility',
        'pagespeed_seo'              => 'INT UNSIGNED DEFAULT NULL AFTER pagespeed_best_practices',
        'mobile_score'               => 'INT UNSIGNED DEFAULT NULL AFTER pagespeed_seo',
        'mobile_accessibility'       => 'INT UNSIGNED DEFAULT NULL AFTER mobile_score',
        'mobile_best_practices'      => 'INT UNSIGNED DEFAULT NULL AFTER mobile_accessibility',
        'mobile_seo'                 => 'INT UNSIGNED DEFAULT NULL AFTER mobile_best_practices',
    );
    foreach ( $columns_to_add as $col_name => $col_def ) {
        $col_exists = $wpdb->get_results( "SHOW COLUMNS FROM $domain_metrics_table LIKE '$col_name'" );
        if ( empty( $col_exists ) ) {
            $wpdb->query( "ALTER TABLE $domain_metrics_table ADD COLUMN $col_name $col_def" );
        }
    }

    // Migration: Create GA4 daily summary table if not exists
    $ga4_table = $wpdb->prefix . 'bite_ga4_daily_summary';
    $ga4_exists = $wpdb->get_var( "SHOW TABLES LIKE '$ga4_table'" );
    if ( ! $ga4_exists ) {
        $sql_ga4 = "CREATE TABLE $ga4_table (
            ga4_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id INT UNSIGNED NOT NULL,
            date DATE NOT NULL,
            sessions INT UNSIGNED DEFAULT 0,
            users INT UNSIGNED DEFAULT 0,
            pageviews INT UNSIGNED DEFAULT 0,
            bounce_rate DECIMAL(5,2) DEFAULT NULL,
            avg_session_duration DECIMAL(10,2) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (ga4_id),
            UNIQUE KEY uq_site_date_ga4 (site_id, date),
            KEY idx_date (date),
            KEY idx_site_id (site_id)
        ) $charset_collate;";
        dbDelta( $sql_ga4 );
    }

    // Migration: Add ga4_property_id column to sites table if missing
    $sites_table = $wpdb->prefix . 'bite_sites';
    $ga4_col_exists = $wpdb->get_results( "SHOW COLUMNS FROM $sites_table LIKE 'ga4_property_id'" );
    if ( empty( $ga4_col_exists ) ) {
        $wpdb->query( "ALTER TABLE $sites_table ADD COLUMN ga4_property_id VARCHAR(50) NULL AFTER gsc_credentials" );
    }

    // Migration: Add ga4_backfill_status column to sites table if missing
    $ga4_status_col = $wpdb->get_results( "SHOW COLUMNS FROM $sites_table LIKE 'ga4_backfill_status'" );
    if ( empty( $ga4_status_col ) ) {
        $wpdb->query( "ALTER TABLE $sites_table ADD COLUMN ga4_backfill_status ENUM('pending', 'in_progress', 'complete', 'auth_error') NULL AFTER ga4_property_id" );
    }

    // Migration: Add bing_backfill_status column to sites table if missing
    $bing_status_col = $wpdb->get_results( "SHOW COLUMNS FROM $sites_table LIKE 'bing_backfill_status'" );
    if ( empty( $bing_status_col ) ) {
        $wpdb->query( "ALTER TABLE $sites_table ADD COLUMN bing_backfill_status ENUM('pending', 'in_progress', 'complete', 'auth_error') NULL AFTER ga4_backfill_status" );
    }

    // Migration: Create Bing daily summary table if not exists
    $bing_table = $wpdb->prefix . 'bite_bing_daily_summary';
    $bing_exists = $wpdb->get_var( "SHOW TABLES LIKE '$bing_table'" );
    if ( ! $bing_exists ) {
        $sql_bing = "CREATE TABLE $bing_table (
            bing_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id INT UNSIGNED NOT NULL,
            date DATE NOT NULL,
            clicks INT UNSIGNED DEFAULT 0,
            impressions INT UNSIGNED DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (bing_id),
            UNIQUE KEY uq_site_date_bing (site_id, date),
            KEY idx_date (date),
            KEY idx_site_id (site_id)
        ) $charset_collate;";
        dbDelta( $sql_bing );
    }
}
add_action( 'init', 'bite_create_missing_tables' );
