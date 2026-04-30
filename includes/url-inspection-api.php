<?php
/**
 * Google Search Console URL Inspection API Integration
 *
 * Handles URL inspection via GSC API v1, batch processing with 2,000/day limit,
 * and stores results for site health monitoring.
 *
 * @package BITE-theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Daily URL inspection quota limit
 */
define( 'BITE_URL_INSPECTION_DAILY_LIMIT', 2000 );

/**
 * Inspect a single URL via GSC URL Inspection API
 *
 * @param int    $user_id       User ID with OAuth access
 * @param string $site_url      GSC property URL (e.g. https://example.com/)
 * @param string $inspection_url URL to inspect
 * @return array|WP_Error Inspection result or error
 */
function bite_inspect_url( $user_id, $site_url, $inspection_url ) {
    $access_token = bite_get_user_access_token( $user_id );

    if ( is_wp_error( $access_token ) ) {
        return $access_token;
    }

    $body = array(
        'inspectionUrl' => $inspection_url,
        'siteUrl'       => $site_url,
        'languageCode'  => 'en-US',
    );

    $response = wp_remote_post(
        'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',
        array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 30,
        )
    );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $response_body = wp_remote_retrieve_body( $response );
    $data = json_decode( $response_body, true );

    if ( $code !== 200 ) {
        $error_msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
        return new WP_Error( 'url_inspection_error', $error_msg, $data );
    }

    $result = $data['inspectionResult'] ?? array();

    // Extract key fields
    $index_status = $result['indexStatusResult'] ?? array();
    $mobile = $result['mobileUsabilityResult'] ?? array();

    return array(
        'url'              => $inspection_url,
        'verdict'          => $index_status['verdict'] ?? null,
        'coverage_state'   => $index_status['coverageState'] ?? null,
        'last_crawled'     => isset( $index_status['lastCrawledTime'] ) ? date( 'Y-m-d H:i:s', strtotime( $index_status['lastCrawledTime'] ) ) : null,
        'page_fetch_state' => $index_status['pageFetchState'] ?? null,
        'robots_txt_state' => $index_status['robotsTxtState'] ?? null,
        'mobile_usability' => $mobile['verdict'] ?? null,
        'crawled_as'       => $index_status['crawledAs'] ?? null,
    );
}

/**
 * Store URL inspection result in database
 *
 * @param int   $site_id  Site ID
 * @param array $result   Result from bite_inspect_url()
 * @return bool
 */
function bite_store_url_inspection( $site_id, $result ) {
    global $wpdb;

    $table = $wpdb->prefix . 'bite_url_inspection';
    $today = date( 'Y-m-d' );

    $wpdb->replace(
        $table,
        array(
            'site_id'          => $site_id,
            'url'              => $result['url'],
            'inspected_at'     => $today,
            'verdict'          => $result['verdict'],
            'coverage_state'   => $result['coverage_state'],
            'mobile_usability' => $result['mobile_usability'],
            'last_crawled'     => $result['last_crawled'],
            'page_fetch_state' => $result['page_fetch_state'],
            'robots_txt_state' => $result['robots_txt_state'],
        ),
        array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
    );

    // Also update the sitemap_urls table with is_indexed status
    // NEUTRAL + "Submitted and indexed" means the URL IS indexed by Google
    $sitemap_table = $wpdb->prefix . 'bite_sitemap_urls';
    $is_indexed = 0;
    if ( $result['verdict'] === 'PASS' || $result['verdict'] === 'Pass' ) {
        $is_indexed = 1;
    } elseif ( $result['verdict'] === 'NEUTRAL' && $result['coverage_state'] === 'Submitted and indexed' ) {
        $is_indexed = 1;
    }

    $wpdb->query( $wpdb->prepare(
        "UPDATE $sitemap_table SET is_indexed = %d, last_inspected = %s WHERE site_id = %d AND url = %s",
        $is_indexed, $today, $site_id, $result['url']
    ) );

    return true;
}

/**
 * Get URLs to inspect for a site, prioritized for batch processing
 * Priority: 1) Never inspected, 2) Not indexed, 3) Not inspected in 7+ days
 *
 * @param int $site_id    Site ID
 * @param int $limit      Max URLs to return (default 100)
 * @return array Array of URL strings
 */
function bite_get_urls_to_inspect( $site_id, $limit = 100 ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bite_sitemap_urls';

    // First: URLs never inspected
    $never_inspected = $wpdb->get_col( $wpdb->prepare(
        "SELECT url FROM $table WHERE site_id = %d AND last_inspected IS NULL AND last_seen >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) ORDER BY first_seen ASC LIMIT %d",
        $site_id, $limit
    ) );

    if ( count( $never_inspected ) >= $limit ) {
        return array_slice( $never_inspected, 0, $limit );
    }

    // Second: URLs not indexed (need re-checking)
    $remaining = $limit - count( $never_inspected );
    $not_indexed = $wpdb->get_col( $wpdb->prepare(
        "SELECT url FROM $table WHERE site_id = %d AND (is_indexed = 0 OR is_indexed IS NULL) AND last_seen >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND url NOT IN ('" . implode( "','", array_map( 'esc_sql', $never_inspected ) ) . "') ORDER BY last_inspected ASC LIMIT %d",
        $site_id, $remaining
    ) );

    $combined = array_merge( $never_inspected, $not_indexed );

    if ( count( $combined ) >= $limit ) {
        return array_slice( $combined, 0, $limit );
    }

    // Third: URLs not inspected in 7+ days
    $remaining = $limit - count( $combined );
    $stale = $wpdb->get_col( $wpdb->prepare(
        "SELECT url FROM $table WHERE site_id = %d AND last_inspected < DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND last_seen >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND url NOT IN ('" . implode( "','", array_map( 'esc_sql', $combined ) ) . "') ORDER BY last_inspected ASC LIMIT %d",
        $site_id, $remaining
    ) );

    return array_slice( array_merge( $combined, $stale ), 0, $limit );
}

/**
 * Run URL inspection batch for a single site
 * Respects the 2,000 URL/day limit across all sites.
 *
 * @param int   $site_id      Site ID
 * @param int   $quota_remaining Remaining daily quota
 * @return array|WP_Error Summary or error
 */
function bite_run_url_inspection_batch( $site_id, &$quota_remaining ) {
    global $wpdb;

    if ( $quota_remaining <= 0 ) {
        return new WP_Error( 'quota_exhausted', 'Daily URL inspection quota exhausted' );
    }

    $sites_table = $wpdb->prefix . 'bite_sites';
    $site = $wpdb->get_row( $wpdb->prepare(
        "SELECT site_id, gsc_property, sitemap_url FROM $sites_table WHERE site_id = %d",
        $site_id
    ) );

    if ( ! $site || empty( $site->gsc_property ) ) {
        return new WP_Error( 'no_gsc_property', 'No GSC property configured' );
    }

    // Ensure sitemap is parsed first
    if ( empty( $site->sitemap_url ) ) {
        $sitemap_result = bite_run_sitemap_parse_for_site( $site_id );
        if ( is_wp_error( $sitemap_result ) ) {
            return $sitemap_result;
        }
    }

    // Find a user with OAuth access
    $user_sites_table = $wpdb->prefix . 'bite_user_sites';
    $user_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT user_id FROM $user_sites_table WHERE site_id = %d ORDER BY assigned_at ASC LIMIT 1",
        $site_id
    ) );

    if ( ! $user_id || ! bite_user_has_google_connection( $user_id ) ) {
        return new WP_Error( 'no_oauth', 'No Google OAuth connection' );
    }

    $urls = bite_get_urls_to_inspect( $site_id, min( 100, $quota_remaining ) );

    if ( empty( $urls ) ) {
        return array(
            'inspected' => 0,
            'message'   => 'No URLs pending inspection',
        );
    }

    $inspected = 0;
    $errors = 0;

    foreach ( $urls as $url ) {
        if ( $quota_remaining <= 0 ) {
            break;
        }

        $result = bite_inspect_url( $user_id, $site->gsc_property, $url );

        if ( is_wp_error( $result ) ) {
            $errors++;
            $error_msg = strtolower( $result->get_error_message() );
            if ( strpos( $error_msg, 'unauthenticated' ) !== false || strpos( $error_msg, 'permission' ) !== false ) {
                return new WP_Error( 'auth_error', 'GSC auth failed during URL inspection' );
            }
            usleep( 100000 ); // 100ms
            continue;
        }

        bite_store_url_inspection( $site_id, $result );
        $inspected++;
        $quota_remaining--;

        usleep( 100000 ); // 100ms between inspections to avoid rate limits
    }

    return array(
        'inspected' => $inspected,
        'errors'    => $errors,
        'remaining' => $quota_remaining,
    );
}

/**
 * Run URL inspection for all sites (daily batch)
 * Respects the 2,000 URL/day global limit.
 *
 * @return array Summary
 */
function bite_run_all_url_inspections() {
    global $wpdb;

    $quota = BITE_URL_INSPECTION_DAILY_LIMIT;

    // Check if we already ran today
    $last_run = get_transient( 'bite_url_inspection_last_run' );
    if ( $last_run && date( 'Y-m-d', strtotime( $last_run ) ) === date( 'Y-m-d' ) ) {
        return array(
            'skipped' => true,
            'message' => 'Already ran today',
        );
    }

    $sites_table = $wpdb->prefix . 'bite_sites';
    $sites = $wpdb->get_results( "SELECT site_id FROM $sites_table WHERE sitemap_url IS NOT NULL AND sitemap_url != '' ORDER BY site_id ASC" );

    $results = array(
        'sites_processed' => 0,
        'total_inspected' => 0,
        'errors'          => 0,
    );

    foreach ( $sites as $site ) {
        if ( $quota <= 0 ) {
            break;
        }

        $batch_result = bite_run_url_inspection_batch( $site->site_id, $quota );

        if ( is_wp_error( $batch_result ) ) {
            $results['errors']++;
            error_log( 'BITE URL Inspection Error (site ' . $site->site_id . '): ' . $batch_result->get_error_message() );
            continue;
        }

        $results['sites_processed']++;
        $results['total_inspected'] += $batch_result['inspected'];

        if ( $batch_result['inspected'] > 0 ) {
            error_log( 'BITE URL Inspection: Site ' . $site->site_id . ' inspected ' . $batch_result['inspected'] . ' URLs. Remaining quota: ' . $quota );
        }
    }

    set_transient( 'bite_url_inspection_last_run', current_time( 'mysql' ), DAY_IN_SECONDS );

    error_log( 'BITE URL Inspection Daily Complete: ' . $results['total_inspected'] . ' URLs inspected across ' . $results['sites_processed'] . ' sites' );

    return $results;
}

/**
 * Get URL inspection summary for a site
 *
 * @param int $site_id Site ID
 * @return array Summary stats
 */
function bite_get_url_inspection_summary( $site_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bite_url_inspection';
    $today = date( 'Y-m-d' );

    $total_inspected = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(DISTINCT url) FROM $table WHERE site_id = %d",
        $site_id
    ) );

    $latest = $wpdb->get_row( $wpdb->prepare(
        "SELECT verdict, coverage_state FROM $table WHERE site_id = %d AND inspected_at = %s ORDER BY inspection_id DESC LIMIT 1",
        $site_id, $today
    ) );

    $pass_count = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(DISTINCT url) FROM $table WHERE site_id = %d AND (verdict IN ('PASS', 'Pass') OR (verdict = 'NEUTRAL' AND coverage_state = 'Submitted and indexed'))",
        $site_id
    ) );

    $fail_count = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(DISTINCT url) FROM $table WHERE site_id = %d AND verdict NOT IN ('PASS', 'Pass', '') AND verdict IS NOT NULL AND NOT (verdict = 'NEUTRAL' AND coverage_state = 'Submitted and indexed')",
        $site_id
    ) );

    return array(
        'total_inspected' => intval( $total_inspected ),
        'pass'            => intval( $pass_count ),
        'fail'            => intval( $fail_count ),
        'today_latest'    => $latest ? $latest->verdict : null,
    );
}

/**
 * Get latest URL inspection results for a site
 *
 * @param int    $site_id Site ID
 * @param string $filter  'all', 'pass', 'fail', 'recent'
 * @param int    $limit   Max results
 * @return array Array of inspection rows
 */
function bite_get_latest_inspections( $site_id, $filter = 'all', $limit = 50 ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bite_url_inspection';

    $where = 'site_id = %d';
    $params = array( $site_id );

    switch ( $filter ) {
        case 'pass':
            $where .= " AND (verdict IN ('PASS', 'Pass') OR (verdict = 'NEUTRAL' AND coverage_state = 'Submitted and indexed'))";
            break;
        case 'fail':
            $where .= " AND verdict NOT IN ('PASS', 'Pass', '') AND verdict IS NOT NULL AND NOT (verdict = 'NEUTRAL' AND coverage_state = 'Submitted and indexed')";
            break;
        case 'recent':
            $where .= ' AND inspected_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)';
            break;
    }

    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $table WHERE $where ORDER BY inspected_at DESC, inspection_id DESC LIMIT %d",
        array_merge( $params, array( $limit ) )
    ) );
}

/**
 * AJAX handler: Run manual URL inspection for a specific URL
 */
function bite_ajax_inspect_single_url() {
    check_ajax_referer( 'bite_inspection_nonce', 'nonce' );

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_send_json_error( 'Not logged in' );
    }

    $site_id = isset( $_POST['site_id'] ) ? absint( $_POST['site_id'] ) : 0;
    $url = isset( $_POST['url'] ) ? esc_url_raw( $_POST['url'] ) : '';

    if ( ! $site_id || empty( $url ) ) {
        wp_send_json_error( 'Invalid parameters' );
    }

    $user_sites = bite_get_user_sites( $user_id );
    if ( ! in_array( $site_id, $user_sites ) && ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Access denied' );
    }

    global $wpdb;
    $sites_table = $wpdb->prefix . 'bite_sites';
    $site = $wpdb->get_row( $wpdb->prepare(
        "SELECT gsc_property FROM $sites_table WHERE site_id = %d",
        $site_id
    ) );

    if ( ! $site || empty( $site->gsc_property ) ) {
        wp_send_json_error( 'No GSC property configured' );
    }

    $result = bite_inspect_url( $user_id, $site->gsc_property, $url );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }

    bite_store_url_inspection( $site_id, $result );

    wp_send_json_success( array(
        'message'  => 'URL inspected',
        'result'   => $result,
    ) );
}
add_action( 'wp_ajax_bite_inspect_single_url', 'bite_ajax_inspect_single_url' );
