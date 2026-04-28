<?php
/**
 * Google Analytics 4 API Integration
 *
 * Handles GA4 property listing, OAuth scope management, and daily data fetching.
 *
 * @package BITE-theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * GA4 OAuth scope
 */
define( 'BITE_GA4_SCOPE', 'https://www.googleapis.com/auth/analytics.readonly' );

/**
 * Get the combined OAuth scope (GSC + GA4)
 */
function bite_get_google_oauth_scope( $include_ga4 = false ) {
    $scope = 'https://www.googleapis.com/auth/webmasters.readonly';
    if ( $include_ga4 ) {
        $scope .= ' ' . BITE_GA4_SCOPE;
    }
    return $scope;
}

/**
 * Check if the user's OAuth token includes GA4 scope
 * We check by attempting to list GA4 properties; if it fails with 403, GA4 scope is missing.
 * We also cache this in user meta for quick checks.
 *
 * @param int $user_id The user ID
 * @return bool True if GA4 scope is granted
 */
function bite_user_has_ga4_scope( $user_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }

    // Check cached flag first
    $cached = get_user_meta( $user_id, 'bite_ga4_scope_granted', true );
    if ( $cached === 'yes' ) {
        return true;
    }
    if ( $cached === 'no' ) {
        return false;
    }

    // Not cached - do a live check by trying to list GA4 properties
    $access_token = bite_get_user_access_token( $user_id );
    if ( is_wp_error( $access_token ) ) {
        update_user_meta( $user_id, 'bite_ga4_scope_granted', 'no' );
        return false;
    }

    $response = wp_remote_get( 'https://analyticsadmin.googleapis.com/v1beta/accountSummaries?pageSize=1', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $access_token,
        ),
        'timeout' => 15,
    ) );

    if ( is_wp_error( $response ) ) {
        update_user_meta( $user_id, 'bite_ga4_scope_granted', 'no' );
        return false;
    }

    $code = wp_remote_retrieve_response_code( $response );
    if ( $code === 200 ) {
        update_user_meta( $user_id, 'bite_ga4_scope_granted', 'yes' );
        return true;
    }

    // 403 = scope not granted, 401 = token issue
    update_user_meta( $user_id, 'bite_ga4_scope_granted', 'no' );
    return false;
}

/**
 * Clear the cached GA4 scope flag (call after OAuth reconnect)
 *
 * @param int $user_id The user ID
 */
function bite_clear_ga4_scope_cache( $user_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    delete_user_meta( $user_id, 'bite_ga4_scope_granted' );
}

/**
 * Generate the Google OAuth authorization URL with optional GA4 scope
 *
 * @param int  $user_id The user ID to authorize
 * @param bool $include_ga4 Whether to include GA4 analytics scope
 * @return string|WP_Error The authorization URL or error
 */
function bite_get_google_auth_url_with_scope( $user_id = null, $include_ga4 = false ) {
    if ( ! bite_is_oauth_configured() ) {
        return new WP_Error( 'oauth_not_configured', 'Google OAuth is not configured. Please contact the administrator.' );
    }

    $client_id = bite_get_google_client_id();
    $redirect_uri = bite_get_oauth_redirect_uri();

    // Generate and store state parameter for security
    $state = wp_create_nonce( 'bite_google_oauth_' . $user_id );
    update_user_meta( $user_id, 'bite_oauth_state', $state );

    // Store whether this is a GA4-enhanced auth in the state
    $state_payload = base64_encode( $user_id . ':' . $state . ':' . ( $include_ga4 ? 'ga4' : 'gsc' ) );

    // Build authorization URL
    $params = array(
        'client_id'     => $client_id,
        'redirect_uri'  => $redirect_uri,
        'response_type' => 'code',
        'scope'         => bite_get_google_oauth_scope( $include_ga4 ),
        'access_type'   => 'offline',
        'prompt'        => 'consent',
        'state'         => $state_payload,
    );

    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( $params );
}

/**
 * Fetch list of GA4 properties for a user
 *
 * @param int $user_id The user ID
 * @return array|WP_Error List of properties or error
 */
function bite_fetch_ga4_properties( $user_id ) {
    $access_token = bite_get_user_access_token( $user_id );

    if ( is_wp_error( $access_token ) ) {
        return $access_token;
    }

    $response = wp_remote_get( 'https://analyticsadmin.googleapis.com/v1beta/accountSummaries', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $access_token,
        ),
        'timeout' => 30,
    ) );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    if ( isset( $data['error'] ) ) {
        $code = $data['error']['code'] ?? 0;
        if ( $code === 403 || $code === 401 ) {
            update_user_meta( $user_id, 'bite_ga4_scope_granted', 'no' );
        }
        return new WP_Error( 'api_error', $data['error']['message'] ?? 'Unknown GA4 API error', $data );
    }

    $properties = array();
    if ( ! empty( $data['accountSummaries'] ) ) {
        foreach ( $data['accountSummaries'] as $account ) {
            $account_name = $account['displayName'] ?? 'Unknown Account';
            if ( ! empty( $account['propertySummaries'] ) ) {
                foreach ( $account['propertySummaries'] as $prop ) {
                    $properties[] = array(
                        'property'      => $prop['property'] ?? '',       // e.g. properties/123456789
                        'displayName'   => $prop['displayName'] ?? 'Unknown Property',
                        'accountName'   => $account_name,
                    );
                }
            }
        }
    }

    update_user_meta( $user_id, 'bite_ga4_scope_granted', 'yes' );
    return $properties;
}

/**
 * Extract numeric property ID from GA4 property resource name
 * e.g. "properties/123456789" -> "123456789"
 *
 * @param string $property Resource name
 * @return string Numeric property ID
 */
function bite_extract_ga4_property_id( $property ) {
    if ( strpos( $property, 'properties/' ) === 0 ) {
        return substr( $property, strlen( 'properties/' ) );
    }
    return sanitize_text_field( $property );
}

/**
 * Fetch GA4 daily metrics for a specific property and date range
 *
 * @param int    $user_id      The user ID
 * @param string $property_id  GA4 property ID (numeric, e.g. "123456789")
 * @param string $start_date   Start date (YYYY-MM-DD)
 * @param string $end_date     End date (YYYY-MM-DD)
 * @return array|WP_Error Metrics data or error
 */
function bite_fetch_ga4_daily_metrics( $user_id, $property_id, $start_date, $end_date ) {
    $access_token = bite_get_user_access_token( $user_id );

    if ( is_wp_error( $access_token ) ) {
        return $access_token;
    }

    $url = 'https://analyticsdata.googleapis.com/v1beta/properties/' . sanitize_text_field( $property_id ) . ':runReport';

    $body = array(
        'dateRanges' => array(
            array( 'startDate' => $start_date, 'endDate' => $end_date ),
        ),
        'dimensions' => array(
            array( 'name' => 'date' ),
        ),
        'metrics' => array(
            array( 'name' => 'sessions' ),
            array( 'name' => 'totalUsers' ),
            array( 'name' => 'screenPageViews' ),
            array( 'name' => 'bounceRate' ),
            array( 'name' => 'averageSessionDuration' ),
        ),
    );

    $response = wp_remote_post( $url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type'  => 'application/json',
        ),
        'body'    => wp_json_encode( $body ),
        'timeout' => 45,
    ) );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $response_body = wp_remote_retrieve_body( $response );
    $data = json_decode( $response_body, true );

    if ( isset( $data['error'] ) ) {
        return new WP_Error( 'ga4_api_error', $data['error']['message'] ?? 'Unknown GA4 API error', $data );
    }

    $results = array();
    if ( ! empty( $data['rows'] ) ) {
        foreach ( $data['rows'] as $row ) {
            $date = $row['dimensionValues'][0]['value'] ?? '';
            if ( empty( $date ) || strlen( $date ) !== 8 ) {
                continue;
            }
            // GA4 returns date as YYYYMMDD, convert to YYYY-MM-DD
            $formatted_date = substr( $date, 0, 4 ) . '-' . substr( $date, 4, 2 ) . '-' . substr( $date, 6, 2 );

            $results[] = array(
                'date'                   => $formatted_date,
                'sessions'               => intval( $row['metricValues'][0]['value'] ?? 0 ),
                'users'                  => intval( $row['metricValues'][1]['value'] ?? 0 ),
                'pageviews'              => intval( $row['metricValues'][2]['value'] ?? 0 ),
                'bounce_rate'            => isset( $row['metricValues'][3]['value'] ) ? round( floatval( $row['metricValues'][3]['value'] ) * 100, 2 ) : null,
                'avg_session_duration'   => isset( $row['metricValues'][4]['value'] ) ? round( floatval( $row['metricValues'][4]['value'] ), 2 ) : null,
            );
        }
    }

    return $results;
}

/**
 * Fetch and store GA4 metrics for a single site (for a specific date)
 *
 * @param int    $site_id The site ID
 * @param string $date    Date to fetch (YYYY-MM-DD, defaults to yesterday)
 * @return bool|WP_Error Success or error
 */
function bite_fetch_and_store_ga4_for_site( $site_id, $date = null ) {
    global $wpdb;

    if ( ! $date ) {
        $date = date( 'Y-m-d', strtotime( '-1 day' ) );
    }

    $sites_table = $wpdb->prefix . 'bite_sites';
    $site = $wpdb->get_row( $wpdb->prepare(
        "SELECT site_id, domain, ga4_property_id FROM $sites_table WHERE site_id = %d",
        $site_id
    ) );

    if ( ! $site || empty( $site->ga4_property_id ) ) {
        return new WP_Error( 'no_ga4_property', 'No GA4 property configured for this site' );
    }

    // Find a user with access to this site who has Google OAuth connected
    $user_sites_table = $wpdb->prefix . 'bite_user_sites';
    $user_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT user_id FROM $user_sites_table WHERE site_id = %d LIMIT 1",
        $site_id
    ) );

    if ( ! $user_id || ! bite_user_has_google_connection( $user_id ) ) {
        return new WP_Error( 'no_oauth', 'No Google OAuth connection found' );
    }

    $metrics = bite_fetch_ga4_daily_metrics( $user_id, $site->ga4_property_id, $date, $date );

    if ( is_wp_error( $metrics ) ) {
        return $metrics;
    }

    if ( empty( $metrics ) ) {
        // No data for this date - store zeros so we know we checked
        $ga4_table = $wpdb->prefix . 'bite_ga4_daily_summary';
        $wpdb->replace(
            $ga4_table,
            array(
                'site_id'                => $site_id,
                'date'                   => $date,
                'sessions'               => 0,
                'users'                  => 0,
                'pageviews'              => 0,
                'bounce_rate'            => null,
                'avg_session_duration'   => null,
            ),
            array( '%d', '%s', '%d', '%d', '%d', '%s', '%s' )
        );
        return true;
    }

    $ga4_table = $wpdb->prefix . 'bite_ga4_daily_summary';
    foreach ( $metrics as $row ) {
        $wpdb->replace(
            $ga4_table,
            array(
                'site_id'                => $site_id,
                'date'                   => $row['date'],
                'sessions'               => $row['sessions'],
                'users'                  => $row['users'],
                'pageviews'              => $row['pageviews'],
                'bounce_rate'            => $row['bounce_rate'],
                'avg_session_duration'   => $row['avg_session_duration'],
            ),
            array( '%d', '%s', '%d', '%d', '%d', '%s', '%s' )
        );
    }

    return true;
}

/**
 * Fetch GA4 metrics for all sites that have a GA4 property configured
 * This is called by the daily cron job.
 *
 * @return array Summary of results
 */
function bite_fetch_all_ga4_metrics() {
    global $wpdb;

    $sites_table = $wpdb->prefix . 'bite_sites';
    // Only process sites that have completed backfill (or have data but no status set yet for backward compat)
    $sites = $wpdb->get_results( "SELECT site_id, ga4_property_id FROM $sites_table WHERE ga4_property_id IS NOT NULL AND ga4_property_id != '' AND (ga4_backfill_status = 'complete' OR ga4_backfill_status IS NULL)" );

    $results = array(
        'processed' => 0,
        'errors'    => 0,
        'details'   => array(),
    );

    $yesterday = date( 'Y-m-d', strtotime( '-1 day' ) );

    foreach ( $sites as $site ) {
        $result = bite_fetch_and_store_ga4_for_site( $site->site_id, $yesterday );
        if ( is_wp_error( $result ) ) {
            $results['errors']++;
            $results['details'][] = 'Site ' . $site->site_id . ': ' . $result->get_error_message();
        } else {
            $results['processed']++;
        }

        // Small delay to avoid rate limits
        usleep( 250000 ); // 250ms
    }

    return $results;
}

/**
 * Get GA4 metrics history for a site within a date range
 *
 * @param int    $site_id    The site ID
 * @param string $start_date Start date (YYYY-MM-DD)
 * @param string $end_date   End date (YYYY-MM-DD)
 * @return array Array of metric rows
 */
function bite_get_ga4_metrics_history( $site_id, $start_date, $end_date ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bite_ga4_daily_summary';

    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $table WHERE site_id = %d AND date >= %s AND date <= %s ORDER BY date ASC",
        $site_id, $start_date, $end_date
    ) );
}

/**
 * Get latest GA4 metrics for a site
 *
 * @param int $site_id The site ID
 * @return object|null Latest metrics row or null
 */
function bite_get_latest_ga4_metrics( $site_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bite_ga4_daily_summary';

    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM $table WHERE site_id = %d ORDER BY date DESC LIMIT 1",
        $site_id
    ) );
}

/**
 * AJAX handler: Save GA4 property for a site
 */
function bite_ajax_save_ga4_property() {
    check_ajax_referer( 'bite_ga4_nonce', 'nonce' );

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_send_json_error( 'Not logged in' );
    }

    $site_id = isset( $_POST['site_id'] ) ? absint( $_POST['site_id'] ) : 0;
    $property_id = isset( $_POST['property_id'] ) ? sanitize_text_field( $_POST['property_id'] ) : '';

    if ( ! $site_id ) {
        wp_send_json_error( 'Invalid site' );
    }

    // Verify user has access to this site
    $user_sites = bite_get_user_sites( $user_id );
    if ( ! in_array( $site_id, $user_sites ) && ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Access denied' );
    }

    global $wpdb;
    $sites_table = $wpdb->prefix . 'bite_sites';

    if ( empty( $property_id ) ) {
        // Clear property
        $wpdb->update(
            $sites_table,
            array(
                'ga4_property_id'     => null,
                'ga4_backfill_status' => null,
            ),
            array( 'site_id' => $site_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
    } else {
        // Extract numeric ID if full resource name provided
        $numeric_id = bite_extract_ga4_property_id( $property_id );
        $wpdb->update(
            $sites_table,
            array(
                'ga4_property_id'     => $numeric_id,
                'ga4_backfill_status' => 'pending',
            ),
            array( 'site_id' => $site_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
    }

    wp_send_json_success( array( 'message' => 'GA4 property updated. Historical data will be imported automatically.' ) );

    // Trigger backfill immediately in the background (non-blocking)
    if ( ! empty( $property_id ) ) {
        wp_schedule_single_event( time() + 3, 'bite_ga4_backfill_hook', array( $site_id ) );
    }
}
add_action( 'wp_ajax_bite_save_ga4_property', 'bite_ajax_save_ga4_property' );

/**
 * AJAX handler: Disconnect GA4 for a site (just clears property ID)
 */
function bite_ajax_disconnect_ga4() {
    check_ajax_referer( 'bite_ga4_nonce', 'nonce' );

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_send_json_error( 'Not logged in' );
    }

    $site_id = isset( $_POST['site_id'] ) ? absint( $_POST['site_id'] ) : 0;

    if ( ! $site_id ) {
        wp_send_json_error( 'Invalid site' );
    }

    $user_sites = bite_get_user_sites( $user_id );
    if ( ! in_array( $site_id, $user_sites ) && ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Access denied' );
    }

    global $wpdb;
    $sites_table = $wpdb->prefix . 'bite_sites';
    $wpdb->update(
        $sites_table,
        array(
            'ga4_property_id'      => null,
            'ga4_backfill_status'  => null,
        ),
        array( 'site_id' => $site_id ),
        array( '%s', '%s' ),
        array( '%d' )
    );

    wp_send_json_success( array( 'message' => 'GA4 disconnected for this site' ) );
}
add_action( 'wp_ajax_bite_disconnect_ga4', 'bite_ajax_disconnect_ga4' );

/**
 * Run GA4 historical backfill for a single site
 * Pulls up to 14 months of data in 30-day chunks to avoid sampling/timeouts.
 *
 * @param int $site_id The site ID
 * @return array|WP_Error Summary of results or error
 */
function bite_run_ga4_backfill_for_site( $site_id ) {
    global $wpdb;

    $sites_table = $wpdb->prefix . 'bite_sites';
    $site = $wpdb->get_row( $wpdb->prepare(
        "SELECT site_id, ga4_property_id, ga4_backfill_status FROM $sites_table WHERE site_id = %d",
        $site_id
    ) );

    if ( ! $site || empty( $site->ga4_property_id ) ) {
        return new WP_Error( 'no_ga4_property', 'No GA4 property configured for this site' );
    }

    // Find a user with access to this site who has Google OAuth connected
    $user_sites_table = $wpdb->prefix . 'bite_user_sites';
    $user_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT user_id FROM $user_sites_table WHERE site_id = %d ORDER BY assigned_at ASC LIMIT 1",
        $site_id
    ) );

    if ( ! $user_id || ! bite_user_has_google_connection( $user_id ) ) {
        $wpdb->update( $sites_table, array( 'ga4_backfill_status' => 'auth_error' ), array( 'site_id' => $site_id ), array( '%s' ), array( '%d' ) );
        return new WP_Error( 'no_oauth', 'No Google OAuth connection found' );
    }

    // Verify GA4 scope
    if ( ! bite_user_has_ga4_scope( $user_id ) ) {
        $wpdb->update( $sites_table, array( 'ga4_backfill_status' => 'auth_error' ), array( 'site_id' => $site_id ), array( '%s' ), array( '%d' ) );
        return new WP_Error( 'no_ga4_scope', 'GA4 access not granted. Please reconnect with GA4 permissions.' );
    }

    // Mark as in_progress
    $wpdb->update( $sites_table, array( 'ga4_backfill_status' => 'in_progress' ), array( 'site_id' => $site_id ), array( '%s' ), array( '%d' ) );

    $end_date   = date( 'Y-m-d', strtotime( '-1 day' ) );
    $start_date = date( 'Y-m-d', strtotime( '-14 months' ) );

    $results = array(
        'days_fetched' => 0,
        'chunks'       => 0,
        'errors'       => array(),
    );

    $current_start = $start_date;

    while ( strtotime( $current_start ) <= strtotime( $end_date ) ) {
        $current_end = min( $end_date, date( 'Y-m-d', strtotime( $current_start . ' +29 days' ) ) );

        $metrics = bite_fetch_ga4_daily_metrics( $user_id, $site->ga4_property_id, $current_start, $current_end );

        if ( is_wp_error( $metrics ) ) {
            $error_msg = $metrics->get_error_message();
            $results['errors'][] = "Chunk $current_start to $current_end: $error_msg";

            // Check if auth error
            $error_code = strtolower( $error_msg );
            if ( strpos( $error_code, 'unauthenticated' ) !== false || strpos( $error_code, 'permission' ) !== false || strpos( $error_code, 'forbidden' ) !== false ) {
                $wpdb->update( $sites_table, array( 'ga4_backfill_status' => 'auth_error' ), array( 'site_id' => $site_id ), array( '%s' ), array( '%d' ) );
                return new WP_Error( 'backfill_auth_error', 'GA4 auth failed during backfill: ' . $error_msg );
            }

            // For other errors, continue to next chunk
            $current_start = date( 'Y-m-d', strtotime( $current_end . ' +1 day' ) );
            usleep( 500000 ); // 500ms
            continue;
        }

        $ga4_table = $wpdb->prefix . 'bite_ga4_daily_summary';
        foreach ( $metrics as $row ) {
            $wpdb->replace(
                $ga4_table,
                array(
                    'site_id'                => $site_id,
                    'date'                   => $row['date'],
                    'sessions'               => $row['sessions'],
                    'users'                  => $row['users'],
                    'pageviews'              => $row['pageviews'],
                    'bounce_rate'            => $row['bounce_rate'],
                    'avg_session_duration'   => $row['avg_session_duration'],
                ),
                array( '%d', '%s', '%d', '%d', '%d', '%s', '%s' )
            );
            $results['days_fetched']++;
        }

        $results['chunks']++;
        $current_start = date( 'Y-m-d', strtotime( $current_end . ' +1 day' ) );

        // Rate limit friendly delay between chunks
        usleep( 500000 ); // 500ms
    }

    // Mark as complete
    $wpdb->update( $sites_table, array( 'ga4_backfill_status' => 'complete' ), array( 'site_id' => $site_id ), array( '%s' ), array( '%d' ) );

    error_log( "BITE GA4 Backfill: Site $site_id complete. Days fetched: {$results['days_fetched']}, Chunks: {$results['chunks']}" );

    return $results;
}

/**
 * Trigger GA4 backfill for any sites marked as pending
 * This can be called by cron or manually.
 *
 * @return array Summary of processed sites
 */
function bite_trigger_ga4_backfill_queue() {
    global $wpdb;

    $sites_table = $wpdb->prefix . 'bite_sites';
    $pending_sites = $wpdb->get_results( "SELECT site_id FROM $sites_table WHERE ga4_backfill_status = 'pending' OR ga4_backfill_status = 'auth_error'" );

    $results = array(
        'processed' => 0,
        'errors'    => 0,
        'details'   => array(),
    );

    foreach ( $pending_sites as $site ) {
        $backfill_result = bite_run_ga4_backfill_for_site( $site->site_id );
        if ( is_wp_error( $backfill_result ) ) {
            $results['errors']++;
            $results['details'][] = 'Site ' . $site->site_id . ': ' . $backfill_result->get_error_message();
        } else {
            $results['processed']++;
            $results['details'][] = 'Site ' . $site->site_id . ': fetched ' . $backfill_result['days_fetched'] . ' days in ' . $backfill_result['chunks'] . ' chunks';
        }
    }

    return $results;
}

/**
 * AJAX handler: Get GA4 backfill status for a site
 */
function bite_ajax_get_ga4_backfill_status() {
    check_ajax_referer( 'bite_ga4_nonce', 'nonce' );

    $site_id = isset( $_POST['site_id'] ) ? absint( $_POST['site_id'] ) : 0;
    if ( ! $site_id ) {
        wp_send_json_error( 'Invalid site' );
    }

    global $wpdb;
    $sites_table = $wpdb->prefix . 'bite_sites';
    $status = $wpdb->get_var( $wpdb->prepare(
        "SELECT ga4_backfill_status FROM $sites_table WHERE site_id = %d",
        $site_id
    ) );

    // Count how many days we have
    $ga4_table = $wpdb->prefix . 'bite_ga4_daily_summary';
    $days_count = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $ga4_table WHERE site_id = %d",
        $site_id
    ) );

    wp_send_json_success( array(
        'status'      => $status ?: 'none',
        'days_stored' => intval( $days_count ),
    ) );
}
add_action( 'wp_ajax_bite_get_ga4_backfill_status', 'bite_ajax_get_ga4_backfill_status' );

/**
 * Cron hook: Run GA4 backfill for a specific site
 */
function bite_cron_run_ga4_backfill( $site_id ) {
    $result = bite_run_ga4_backfill_for_site( $site_id );
    if ( is_wp_error( $result ) ) {
        error_log( 'BITE GA4 Backfill Cron Error (site ' . $site_id . '): ' . $result->get_error_message() );
    } else {
        error_log( 'BITE GA4 Backfill Cron Complete (site ' . $site_id . '): fetched ' . $result['days_fetched'] . ' days in ' . $result['chunks'] . ' chunks' );
    }
}
add_action( 'bite_ga4_backfill_hook', 'bite_cron_run_ga4_backfill', 10, 1 );
