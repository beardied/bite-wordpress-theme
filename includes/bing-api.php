<?php
/**
 * Bing Webmaster Tools API Integration
 *
 * Handles Bing WMT traffic stats fetching, API key storage, and backfill.
 * Uses API key authentication (no OAuth).
 *
 * @package BITE-theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'BITE_BING_API_BASE', 'https://ssl.bing.com/webmaster/api.svc/json/' );

/**
 * Get a user's Bing Webmaster Tools API key
 *
 * @param int $user_id User ID (defaults to current user)
 * @return string The API key or empty string
 */
function bite_get_bing_api_key( $user_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    return get_user_meta( $user_id, 'bite_bing_api_key', true );
}

/**
 * Store a user's Bing Webmaster Tools API key
 *
 * @param int    $user_id User ID
 * @param string $api_key The API key
 * @return bool
 */
function bite_set_bing_api_key( $user_id, $api_key ) {
    return update_user_meta( $user_id, 'bite_bing_api_key', sanitize_text_field( $api_key ) );
}

/**
 * Check if a user has configured their Bing API key
 *
 * @param int $user_id User ID (defaults to current user)
 * @return bool
 */
function bite_user_has_bing_connection( $user_id = null ) {
    $key = bite_get_bing_api_key( $user_id );
    return ! empty( $key );
}

/**
 * Parse Bing's ASP.NET JSON date format: /Date(1234567890123-0700)/
 * Returns YYYY-MM-DD in UTC.
 *
 * @param string $date_string Bing date string
 * @return string|null YYYY-MM-DD or null
 */
function bite_parse_bing_date( $date_string ) {
    if ( preg_match( '/\/Date\((\d+)([+-]\d{4})\)\//', $date_string, $matches ) ) {
        $ms = intval( $matches[1] );
        $offset_str = $matches[2]; // e.g. -0700 or +0530
        $sign = $offset_str[0] === '+' ? 1 : -1;
        $hours = intval( substr( $offset_str, 1, 2 ) );
        $mins  = intval( substr( $offset_str, 3, 2 ) );
        $offset_ms = ( $hours * 3600 + $mins * 60 ) * 1000 * $sign;
        // Reverse offset to get UTC (same logic as .NET DateTime)
        $utc_ms = $ms - $offset_ms;
        return date( 'Y-m-d', floor( $utc_ms / 1000 ) );
    } elseif ( preg_match( '/\/Date\((\d+)\)\//', $date_string, $matches ) ) {
        return date( 'Y-m-d', floor( intval( $matches[1] ) / 1000 ) );
    }
    return null;
}

/**
 * Fetch traffic statistics from Bing Webmaster Tools
 * GetRankAndTrafficStats returns ALL historical data in one call.
 *
 * @param string $api_key  Bing API key
 * @param string $site_url Full site URL (e.g. https://example.com/)
 * @return array|WP_Error Array of daily stats or error
 */
function bite_fetch_bing_traffic_stats( $api_key, $site_url ) {
    $url = BITE_BING_API_BASE . 'GetRankAndTrafficStats'
        . '?apikey=' . urlencode( $api_key )
        . '&siteUrl=' . urlencode( $site_url );

    $response = wp_remote_get( $url, array( 'timeout' => 45 ) );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );

    if ( $code !== 200 ) {
        return new WP_Error( 'bing_api_error', 'Bing API returned HTTP ' . $code . ': ' . substr( $body, 0, 500 ) );
    }

    $data = json_decode( $body, true );

    if ( ! isset( $data['d'] ) || ! is_array( $data['d'] ) ) {
        return new WP_Error( 'bing_invalid_response', 'Unexpected Bing API response format' );
    }

    $results = array();
    foreach ( $data['d'] as $entry ) {
        $date = bite_parse_bing_date( $entry['Date'] ?? '' );
        if ( ! $date ) {
            continue;
        }
        $results[] = array(
            'date'        => $date,
            'clicks'      => intval( $entry['Clicks'] ?? 0 ),
            'impressions' => intval( $entry['Impressions'] ?? 0 ),
        );
    }

    // Sort by date ascending
    usort( $results, function( $a, $b ) {
        return strcmp( $a['date'], $b['date'] );
    } );

    return $results;
}

/**
 * Fetch and store Bing traffic data for a single site
 * This handles both backfill (all history) and daily updates.
 *
 * @param int    $site_id The site ID
 * @param string $mode    'backfill' or 'daily'
 * @return array|WP_Error Summary or error
 */
function bite_fetch_and_store_bing_for_site( $site_id, $mode = 'daily' ) {
    global $wpdb;

    $sites_table = $wpdb->prefix . 'bite_sites';
    $site = $wpdb->get_row( $wpdb->prepare(
        "SELECT site_id, domain, gsc_property, bing_backfill_status FROM $sites_table WHERE site_id = %d",
        $site_id
    ) );

    if ( ! $site ) {
        return new WP_Error( 'no_site', 'Site not found' );
    }

    // Build site URL from domain or gsc_property
    $site_url = $site->gsc_property;
    if ( empty( $site_url ) ) {
        $site_url = 'https://' . $site->domain . '/';
    }

    // Find a user with access who has a Bing API key
    $user_sites_table = $wpdb->prefix . 'bite_user_sites';
    $user_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT user_id FROM $user_sites_table WHERE site_id = %d ORDER BY assigned_at ASC LIMIT 1",
        $site_id
    ) );

    if ( ! $user_id ) {
        return new WP_Error( 'no_user', 'No user assigned to this site' );
    }

    $api_key = bite_get_bing_api_key( $user_id );
    if ( empty( $api_key ) ) {
        return new WP_Error( 'no_bing_key', 'No Bing API key configured' );
    }

    $stats = bite_fetch_bing_traffic_stats( $api_key, $site_url );

    if ( is_wp_error( $stats ) ) {
        // Check for auth errors
        $error_msg = strtolower( $stats->get_error_message() );
        if ( strpos( $error_msg, 'unauthorized' ) !== false || strpos( $error_msg, 'forbidden' ) !== false || strpos( $error_msg, 'invalid' ) !== false ) {
            $wpdb->update( $sites_table, array( 'bing_backfill_status' => 'auth_error' ), array( 'site_id' => $site_id ), array( '%s' ), array( '%d' ) );
        }
        return $stats;
    }

    $bing_table = $wpdb->prefix . 'bite_bing_daily_summary';
    $stored = 0;

    if ( $mode === 'daily' ) {
        // Only store yesterday's data (or any new days)
        $latest_date = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(date) FROM $bing_table WHERE site_id = %d",
            $site_id
        ) );
        if ( ! $latest_date ) {
            $latest_date = '1970-01-01';
        }
        foreach ( $stats as $row ) {
            if ( $row['date'] > $latest_date ) {
                $wpdb->replace(
                    $bing_table,
                    array(
                        'site_id'     => $site_id,
                        'date'        => $row['date'],
                        'clicks'      => $row['clicks'],
                        'impressions' => $row['impressions'],
                    ),
                    array( '%d', '%s', '%d', '%d' )
                );
                $stored++;
            }
        }
    } else {
        // Backfill mode: store everything
        foreach ( $stats as $row ) {
            $wpdb->replace(
                $bing_table,
                array(
                    'site_id'     => $site_id,
                    'date'        => $row['date'],
                    'clicks'      => $row['clicks'],
                    'impressions' => $row['impressions'],
                ),
                array( '%d', '%s', '%d', '%d' )
            );
            $stored++;
        }
    }

    return array(
        'days_fetched' => count( $stats ),
        'days_stored'  => $stored,
    );
}

/**
 * Run Bing backfill for a single site
 *
 * @param int $site_id The site ID
 * @return array|WP_Error Summary or error
 */
function bite_run_bing_backfill_for_site( $site_id ) {
    global $wpdb;

    $sites_table = $wpdb->prefix . 'bite_sites';
    $wpdb->update( $sites_table, array( 'bing_backfill_status' => 'in_progress' ), array( 'site_id' => $site_id ), array( '%s' ), array( '%d' ) );

    $result = bite_fetch_and_store_bing_for_site( $site_id, 'backfill' );

    if ( is_wp_error( $result ) ) {
        $error_msg = strtolower( $result->get_error_message() );
        if ( strpos( $error_msg, 'unauthorized' ) !== false || strpos( $error_msg, 'forbidden' ) !== false || strpos( $error_msg, 'invalid' ) !== false ) {
            $wpdb->update( $sites_table, array( 'bing_backfill_status' => 'auth_error' ), array( 'site_id' => $site_id ), array( '%s' ), array( '%d' ) );
        } else {
            $wpdb->update( $sites_table, array( 'bing_backfill_status' => 'pending' ), array( 'site_id' => $site_id ), array( '%s' ), array( '%d' ) );
        }
        return $result;
    }

    $wpdb->update( $sites_table, array( 'bing_backfill_status' => 'complete' ), array( 'site_id' => $site_id ), array( '%s' ), array( '%d' ) );

    error_log( "BITE Bing Backfill: Site $site_id complete. Days fetched: {$result['days_fetched']}, Stored: {$result['days_stored']}" );

    return $result;
}

/**
 * Trigger Bing backfill for any sites marked as pending
 *
 * @return array Summary of processed sites
 */
function bite_trigger_bing_backfill_queue() {
    global $wpdb;

    $sites_table = $wpdb->prefix . 'bite_sites';
    $pending_sites = $wpdb->get_results( "SELECT site_id FROM $sites_table WHERE bing_backfill_status = 'pending' OR bing_backfill_status = 'auth_error'" );

    $results = array(
        'processed' => 0,
        'errors'    => 0,
        'details'   => array(),
    );

    foreach ( $pending_sites as $site ) {
        $backfill_result = bite_run_bing_backfill_for_site( $site->site_id );
        if ( is_wp_error( $backfill_result ) ) {
            $results['errors']++;
            $results['details'][] = 'Site ' . $site->site_id . ': ' . $backfill_result->get_error_message();
        } else {
            $results['processed']++;
            $results['details'][] = 'Site ' . $site->site_id . ': fetched ' . $backfill_result['days_fetched'] . ' days';
        }
        usleep( 500000 ); // 500ms between sites
    }

    return $results;
}

/**
 * Fetch Bing metrics for all sites (daily update)
 * Called by the daily cron job.
 *
 * @return array Summary of results
 */
function bite_fetch_all_bing_metrics() {
    global $wpdb;

    $sites_table = $wpdb->prefix . 'bite_sites';
    $sites = $wpdb->get_results( "SELECT site_id FROM $sites_table WHERE bing_backfill_status = 'complete'" );

    $results = array(
        'processed' => 0,
        'errors'    => 0,
        'details'   => array(),
    );

    foreach ( $sites as $site ) {
        $result = bite_fetch_and_store_bing_for_site( $site->site_id, 'daily' );
        if ( is_wp_error( $result ) ) {
            $results['errors']++;
            $results['details'][] = 'Site ' . $site->site_id . ': ' . $result->get_error_message();
        } else {
            $results['processed']++;
        }
        usleep( 250000 ); // 250ms
    }

    return $results;
}

/**
 * Get Bing metrics history for a site within a date range
 *
 * @param int    $site_id    The site ID
 * @param string $start_date Start date (YYYY-MM-DD)
 * @param string $end_date   End date (YYYY-MM-DD)
 * @return array Array of metric rows
 */
function bite_get_bing_metrics_history( $site_id, $start_date, $end_date ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bite_bing_daily_summary';

    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $table WHERE site_id = %d AND date >= %s AND date <= %s ORDER BY date ASC",
        $site_id, $start_date, $end_date
    ) );
}

/**
 * Get latest Bing metrics for a site
 *
 * @param int $site_id The site ID
 * @return object|null Latest metrics row or null
 */
function bite_get_latest_bing_metrics( $site_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bite_bing_daily_summary';

    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM $table WHERE site_id = %d ORDER BY date DESC LIMIT 1",
        $site_id
    ) );
}

/**
 * AJAX handler: Save Bing API key
 */
function bite_ajax_save_bing_api_key() {
    check_ajax_referer( 'bite_bing_nonce', 'nonce' );

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_send_json_error( 'Not logged in' );
    }

    $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( $_POST['api_key'] ) : '';

    if ( empty( $api_key ) ) {
        delete_user_meta( $user_id, 'bite_bing_api_key' );
        wp_send_json_success( array( 'message' => 'Bing API key removed' ) );
    }

    bite_set_bing_api_key( $user_id, $api_key );

    // Validate the key by trying to get sites
    $test_url = BITE_BING_API_BASE . 'GetSites?apikey=' . urlencode( $api_key );
    $response = wp_remote_get( $test_url, array( 'timeout' => 15 ) );

    if ( is_wp_error( $response ) ) {
        wp_send_json_error( 'Network error validating key' );
    }

    $code = wp_remote_retrieve_response_code( $response );
    if ( $code !== 200 ) {
        $body = wp_remote_retrieve_body( $response );
        wp_send_json_error( 'Invalid API key or Bing API error (HTTP ' . $code . ')' );
    }

    wp_send_json_success( array( 'message' => 'Bing API key saved and validated' ) );
}
add_action( 'wp_ajax_bite_save_bing_api_key', 'bite_ajax_save_bing_api_key' );

/**
 * AJAX handler: Disconnect Bing (clear API key)
 */
function bite_ajax_disconnect_bing() {
    check_ajax_referer( 'bite_bing_nonce', 'nonce' );

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_send_json_error( 'Not logged in' );
    }

    delete_user_meta( $user_id, 'bite_bing_api_key' );

    // Also clear backfill status for all sites this user has access to
    global $wpdb;
    $user_sites = bite_get_user_sites( $user_id );
    if ( ! empty( $user_sites ) ) {
        $sites_table = $wpdb->prefix . 'bite_sites';
        $placeholders = implode( ',', array_fill( 0, count( $user_sites ), '%d' ) );
        $wpdb->query( $wpdb->prepare(
            "UPDATE $sites_table SET bing_backfill_status = NULL WHERE site_id IN ($placeholders)",
            $user_sites
        ) );
    }

    wp_send_json_success( array( 'message' => 'Bing disconnected' ) );
}
add_action( 'wp_ajax_bite_disconnect_bing', 'bite_ajax_disconnect_bing' );

/**
 * AJAX handler: Trigger Bing backfill for a specific site
 */
function bite_ajax_trigger_bing_backfill() {
    check_ajax_referer( 'bite_bing_nonce', 'nonce' );

    $site_id = isset( $_POST['site_id'] ) ? absint( $_POST['site_id'] ) : 0;
    if ( ! $site_id ) {
        wp_send_json_error( 'Invalid site' );
    }

    $user_id = get_current_user_id();
    $user_sites = bite_get_user_sites( $user_id );
    if ( ! in_array( $site_id, $user_sites ) && ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Access denied' );
    }

    global $wpdb;
    $sites_table = $wpdb->prefix . 'bite_sites';
    $wpdb->update( $sites_table, array( 'bing_backfill_status' => 'pending' ), array( 'site_id' => $site_id ), array( '%s' ), array( '%d' ) );

    wp_schedule_single_event( time() + 3, 'bite_bing_backfill_hook', array( $site_id ) );

    wp_send_json_success( array( 'message' => 'Bing backfill triggered' ) );
}
add_action( 'wp_ajax_bite_trigger_bing_backfill', 'bite_ajax_trigger_bing_backfill' );

/**
 * AJAX handler: Get Bing backfill status for a site
 */
function bite_ajax_get_bing_backfill_status() {
    check_ajax_referer( 'bite_bing_nonce', 'nonce' );

    $site_id = isset( $_POST['site_id'] ) ? absint( $_POST['site_id'] ) : 0;
    if ( ! $site_id ) {
        wp_send_json_error( 'Invalid site' );
    }

    global $wpdb;
    $sites_table = $wpdb->prefix . 'bite_sites';
    $status = $wpdb->get_var( $wpdb->prepare(
        "SELECT bing_backfill_status FROM $sites_table WHERE site_id = %d",
        $site_id
    ) );

    $bing_table = $wpdb->prefix . 'bite_bing_daily_summary';
    $days_count = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $bing_table WHERE site_id = %d",
        $site_id
    ) );

    wp_send_json_success( array(
        'status'      => $status ?: 'none',
        'days_stored' => intval( $days_count ),
    ) );
}
add_action( 'wp_ajax_bite_get_bing_backfill_status', 'bite_ajax_get_bing_backfill_status' );

/**
 * Cron hook: Run Bing backfill for a specific site
 */
function bite_cron_run_bing_backfill( $site_id ) {
    $result = bite_run_bing_backfill_for_site( $site_id );
    if ( is_wp_error( $result ) ) {
        error_log( 'BITE Bing Backfill Cron Error (site ' . $site_id . '): ' . $result->get_error_message() );
    } else {
        error_log( 'BITE Bing Backfill Cron Complete (site ' . $site_id . '): fetched ' . $result['days_fetched'] . ' days, stored ' . $result['days_stored'] );
    }
}
add_action( 'bite_bing_backfill_hook', 'bite_cron_run_bing_backfill', 10, 1 );
