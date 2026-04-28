<?php
/**
 * Security Scan API Integration
 *
 * Handles daily security header scanning and SSL Labs assessment.
 * Sends alert emails on score/grade drops.
 *
 * @package BITE-theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'BITE_SSLLABS_API_BASE', 'https://api.ssllabs.com/api/v3/' );
define( 'BITE_SECURITY_ALERT_THRESHOLD', 10 ); // Alert if score drops by more than this

// ============================================================================
// SECURITY HEADERS SCAN
// ============================================================================

/**
 * Scan security headers for a single site
 *
 * @param string $url Site URL (e.g. https://example.com/)
 * @return array Header scan results
 */
function bite_scan_security_headers( $url ) {
    $response = wp_remote_get( $url, array(
        'timeout'    => 20,
        'sslverify'  => false,
        'user-agent' => 'BITE-SecurityScanner/1.0',
    ) );

    $headers = array();
    $score = 0;
    $missing = array();

    if ( is_wp_error( $response ) ) {
        return array(
            'score'           => 0,
            'hsts'            => 0,
            'csp'             => 0,
            'x_frame_options' => 0,
            'x_content_type_options' => 0,
            'referrer_policy' => 0,
            'permissions_policy' => 0,
            'missing_headers' => 'Site unreachable',
            'raw_headers'     => '',
        );
    }

    $all_headers = wp_remote_retrieve_headers( $response );
    $headers_lower = array();
    foreach ( $all_headers->getAll() as $key => $value ) {
        $headers_lower[ strtolower( $key ) ] = is_array( $value ) ? implode( ', ', $value ) : $value;
    }

    // 1. Strict-Transport-Security (HSTS) — 20 pts
    $hsts = isset( $headers_lower['strict-transport-security'] ) ? $headers_lower['strict-transport-security'] : '';
    if ( ! empty( $hsts ) && stripos( $hsts, 'max-age' ) !== false ) {
        preg_match( '/max-age=(\d+)/i', $hsts, $matches );
        $max_age = isset( $matches[1] ) ? intval( $matches[1] ) : 0;
        if ( $max_age >= 31536000 ) {
            $score += 20;
        } elseif ( $max_age > 0 ) {
            $score += 10;
            $missing[] = 'HSTS max-age too short';
        }
    } else {
        $missing[] = 'Strict-Transport-Security';
    }

    // 2. Content-Security-Policy — 20 pts
    $csp = isset( $headers_lower['content-security-policy'] ) ? $headers_lower['content-security-policy'] : '';
    if ( ! empty( $csp ) && stripos( $csp, 'default-src' ) !== false ) {
        $score += 20;
    } elseif ( ! empty( $csp ) ) {
        $score += 10;
        $missing[] = 'CSP missing default-src';
    } else {
        $missing[] = 'Content-Security-Policy';
    }

    // 3. X-Frame-Options — 15 pts
    $xfo = isset( $headers_lower['x-frame-options'] ) ? $headers_lower['x-frame-options'] : '';
    if ( ! empty( $xfo ) && ( stripos( $xfo, 'deny' ) !== false || stripos( $xfo, 'sameorigin' ) !== false ) ) {
        $score += 15;
    } else {
        $missing[] = 'X-Frame-Options';
    }

    // 4. X-Content-Type-Options — 15 pts
    $xcto = isset( $headers_lower['x-content-type-options'] ) ? $headers_lower['x-content-type-options'] : '';
    if ( ! empty( $xcto ) && stripos( $xcto, 'nosniff' ) !== false ) {
        $score += 15;
    } else {
        $missing[] = 'X-Content-Type-Options';
    }

    // 5. Referrer-Policy — 15 pts
    $rp = isset( $headers_lower['referrer-policy'] ) ? $headers_lower['referrer-policy'] : '';
    if ( ! empty( $rp ) && stripos( $rp, 'no-referrer' ) !== false ) {
        $score += 15;
    } elseif ( ! empty( $rp ) && stripos( $rp, 'strict-origin' ) !== false ) {
        $score += 10;
    } elseif ( ! empty( $rp ) ) {
        $score += 5;
    } else {
        $missing[] = 'Referrer-Policy';
    }

    // 6. Permissions-Policy — 15 pts
    $pp = isset( $headers_lower['permissions-policy'] ) ? $headers_lower['permissions-policy'] : '';
    if ( empty( $pp ) ) {
        // Check old name
        $pp = isset( $headers_lower['feature-policy'] ) ? $headers_lower['feature-policy'] : '';
    }
    if ( ! empty( $pp ) ) {
        $score += 15;
    } else {
        $missing[] = 'Permissions-Policy';
    }

    return array(
        'score'           => $score,
        'hsts'            => ! empty( $hsts ) && stripos( $hsts, 'max-age' ) !== false ? ( stripos( $hsts, '31536000' ) !== false ? 1 : 2 ) : 0,
        'csp'             => ! empty( $csp ) ? 1 : 0,
        'x_frame_options' => ! empty( $xfo ) && ( stripos( $xfo, 'deny' ) !== false || stripos( $xfo, 'sameorigin' ) !== false ) ? 1 : 0,
        'x_content_type_options' => ! empty( $xcto ) && stripos( $xcto, 'nosniff' ) !== false ? 1 : 0,
        'referrer_policy' => ! empty( $rp ) ? 1 : 0,
        'permissions_policy' => ! empty( $pp ) ? 1 : 0,
        'missing_headers' => implode( ', ', $missing ),
        'raw_headers'     => wp_json_encode( $headers_lower ),
    );
}

/**
 * Store security header scan results
 *
 * @param int   $site_id Site ID
 * @param array $results Results from bite_scan_security_headers()
 * @return bool
 */
function bite_store_security_header_scan( $site_id, $results ) {
    global $wpdb;

    $table = $wpdb->prefix . 'bite_security_headers';
    $today = date( 'Y-m-d' );

    $wpdb->replace(
        $table,
        array(
            'site_id'                  => $site_id,
            'scanned_at'               => $today,
            'overall_score'            => $results['score'],
            'hsts'                     => $results['hsts'],
            'csp'                      => $results['csp'],
            'x_frame_options'          => $results['x_frame_options'],
            'x_content_type_options'   => $results['x_content_type_options'],
            'referrer_policy'          => $results['referrer_policy'],
            'permissions_policy'       => $results['permissions_policy'],
            'missing_headers'          => $results['missing_headers'],
            'raw_headers'              => $results['raw_headers'],
        ),
        array( '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s' )
    );

    return true;
}

/**
 * Run security header scan for all sites
 *
 * @return array Summary
 */
function bite_run_all_security_header_scans() {
    global $wpdb;

    $sites_table = $wpdb->prefix . 'bite_sites';
    $sites = $wpdb->get_results( "SELECT site_id, domain, gsc_property FROM $sites_table ORDER BY site_id ASC" );

    $results = array(
        'processed' => 0,
        'errors'    => 0,
        'details'   => array(),
    );

    foreach ( $sites as $site ) {
        $url = $site->gsc_property;
        if ( empty( $url ) ) {
            $url = 'https://' . $site->domain . '/';
        }
        // Normalize URL
        if ( strpos( $url, 'sc-domain:' ) === 0 ) {
            $url = 'https://' . substr( $url, 10 ) . '/';
        } elseif ( ! preg_match( '/^https?:\/\//', $url ) ) {
            $url = 'https://' . $url;
        }

        $scan = bite_scan_security_headers( $url );
        bite_store_security_header_scan( $site->site_id, $scan );

        // Check for drops
        bite_check_security_alert( $site->site_id, $scan['score'], 'headers' );

        $results['processed']++;
        $results['details'][] = 'Site ' . $site->site_id . ': score ' . $scan['score'] . '/100';

        usleep( 500000 ); // 500ms between sites
    }

    return $results;
}

// ============================================================================
// SSL LABS SCAN
// ============================================================================

/**
 * Call SSL Labs API
 *
 * @param string $endpoint API endpoint (e.g. 'analyze')
 * @param array  $params   Query parameters
 * @return array|WP_Error Response data or error
 */
function bite_call_ssllabs_api( $endpoint, $params = array() ) {
    $url = BITE_SSLLABS_API_BASE . $endpoint;
    if ( ! empty( $params ) ) {
        $url .= '?' . http_build_query( $params );
    }

    $response = wp_remote_get( $url, array( 'timeout' => 30 ) );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );

    if ( $code === 429 ) {
        return new WP_Error( 'ssllabs_rate_limit', 'SSL Labs rate limit exceeded' );
    }
    if ( $code === 503 || $code === 529 ) {
        return new WP_Error( 'ssllabs_overloaded', 'SSL Labs service overloaded' );
    }
    if ( $code !== 200 ) {
        return new WP_Error( 'ssllabs_error', 'SSL Labs API error: HTTP ' . $code );
    }

    $data = json_decode( $body, true );
    if ( ! $data ) {
        return new WP_Error( 'ssllabs_parse', 'Failed to parse SSL Labs response' );
    }

    return $data;
}

/**
 * Run SSL Labs assessment for a host (synchronous — for manual use)
 * First tries cache, then starts new assessment if needed.
 *
 * @param string $hostname Hostname (e.g. example.com)
 * @return array|WP_Error Assessment results or error
 */
function bite_run_ssllabs_assessment( $hostname ) {
    // Step 1: Try cache first (48h window)
    $cache_result = bite_call_ssllabs_api( 'analyze', array(
        'host'      => $hostname,
        'fromCache' => 'on',
        'all'       => 'done',
        'maxAge'    => 48,
    ) );

    if ( ! is_wp_error( $cache_result ) && isset( $cache_result['status'] ) && $cache_result['status'] === 'READY' ) {
        return $cache_result;
    }

    // Step 2: Start new assessment
    $start_result = bite_call_ssllabs_api( 'analyze', array(
        'host'      => $hostname,
        'startNew'  => 'on',
        'all'       => 'done',
    ) );

    if ( is_wp_error( $start_result ) ) {
        return $start_result;
    }

    // Step 3: Poll for completion (max 120 seconds, 15s intervals)
    $max_wait = 120;
    $elapsed = 0;
    $poll_interval = 15;

    while ( $elapsed < $max_wait ) {
        sleep( $poll_interval );
        $elapsed += $poll_interval;

        $poll_result = bite_call_ssllabs_api( 'analyze', array(
            'host' => $hostname,
            'all'  => 'done',
        ) );

        if ( is_wp_error( $poll_result ) ) {
            return $poll_result;
        }

        if ( isset( $poll_result['status'] ) && in_array( $poll_result['status'], array( 'READY', 'ERROR' ), true ) ) {
            return $poll_result;
        }
    }

    return new WP_Error( 'ssllabs_timeout', 'SSL Labs assessment timed out after ' . $max_wait . ' seconds' );
}

/**
 * Extract key SSL Labs data from assessment result
 *
 * @param array $assessment Full SSL Labs assessment result
 * @return array Simplified data
 */
function bite_extract_ssllabs_data( $assessment ) {
    $endpoints = $assessment['endpoints'] ?? array();
    if ( empty( $endpoints ) ) {
        return array(
            'grade'         => null,
            'has_warnings'  => 0,
            'cert_expiry'   => null,
            'protocols'     => '',
            'vulnerabilities' => '',
            'endpoints_json' => wp_json_encode( $assessment ),
        );
    }

    // Use the first endpoint (or lowest grade if multiple)
    $endpoint = $endpoints[0];
    $grade = $endpoint['grade'] ?? null;

    // Check all endpoints for warnings
    $has_warnings = 0;
    foreach ( $endpoints as $ep ) {
        if ( ! empty( $ep['hasWarnings'] ) ) {
            $has_warnings = 1;
            break;
        }
    }

    // Certificate expiry from details
    $cert_expiry = null;
    $details = $endpoint['details'] ?? array();
    $certs = $details['certs'] ?? array();
    if ( ! empty( $certs[0]['notAfter'] ) ) {
        $cert_expiry = date( 'Y-m-d', $certs[0]['notAfter'] / 1000 );
    }

    // Protocols
    $protocols = array();
    $protocol_list = $details['protocols'] ?? array();
    foreach ( $protocol_list as $p ) {
        $protocols[] = ( $p['name'] ?? '' ) . ' ' . ( $p['version'] ?? '' );
    }

    // Vulnerabilities
    $vulns = array();
    if ( ! empty( $details['heartbleed'] ) ) $vulns[] = 'Heartbleed';
    if ( ! empty( $details['poodle'] ) ) $vulns[] = 'POODLE';
    if ( ! empty( $details['freak'] ) ) $vulns[] = 'FREAK';
    if ( ! empty( $details['logjam'] ) ) $vulns[] = 'Logjam';
    if ( ! empty( $details['drownVulnerable'] ) ) $vulns[] = 'DROWN';

    return array(
        'grade'           => $grade,
        'has_warnings'    => $has_warnings,
        'cert_expiry'     => $cert_expiry,
        'protocols'       => implode( ', ', array_unique( $protocols ) ),
        'vulnerabilities' => implode( ', ', $vulns ),
        'endpoints_json'  => wp_json_encode( $assessment ),
    );
}

/**
 * Store SSL Labs scan results
 *
 * @param int   $site_id Site ID
 * @param array $data    Extracted data
 * @return bool
 */
function bite_store_ssl_labs_scan( $site_id, $data ) {
    global $wpdb;

    $table = $wpdb->prefix . 'bite_ssl_labs';
    $today = date( 'Y-m-d' );

    $wpdb->replace(
        $table,
        array(
            'site_id'         => $site_id,
            'scanned_at'      => $today,
            'grade'           => $data['grade'],
            'has_warnings'    => $data['has_warnings'],
            'cert_expiry'     => $data['cert_expiry'],
            'protocols'       => $data['protocols'],
            'vulnerabilities' => $data['vulnerabilities'],
            'endpoints_json'  => $data['endpoints_json'],
        ),
        array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
    );

    return true;
}

/**
 * Run SSL Labs scans for all sites.
 * Uses async polling via WP Cron to avoid blocking the daily update.
 *
 * @return array Summary
 */
function bite_run_all_ssl_labs_scans() {
    global $wpdb;

    $sites_table = $wpdb->prefix . 'bite_sites';
    $sites = $wpdb->get_results( "SELECT site_id, domain FROM $sites_table ORDER BY site_id ASC" );

    $results = array(
        'processed' => 0,
        'errors'    => 0,
        'details'   => array(),
    );

    $pending = array();

    foreach ( $sites as $site ) {
        $hostname = $site->domain;
        // Strip www. and any protocol
        $hostname = preg_replace( '/^https?:\/\//', '', $hostname );
        $hostname = preg_replace( '/^www\./', '', $hostname );
        $hostname = rtrim( $hostname, '/' );

        // Try cache first — if we have recent results, use them immediately
        $cache_result = bite_call_ssllabs_api( 'analyze', array(
            'host'      => $hostname,
            'fromCache' => 'on',
            'all'       => 'done',
            'maxAge'    => 48,
        ) );

        if ( ! is_wp_error( $cache_result ) && isset( $cache_result['status'] ) && $cache_result['status'] === 'READY' ) {
            // Cached result available — store immediately
            $data = bite_extract_ssllabs_data( $cache_result );
            bite_store_ssl_labs_scan( $site->site_id, $data );

            $grade_numeric = bite_grade_to_numeric( $data['grade'] );
            bite_check_security_alert( $site->site_id, $grade_numeric, 'ssl' );

            $results['processed']++;
            $results['details'][] = 'Site ' . $site->site_id . ' (' . $hostname . '): grade ' . ( $data['grade'] ?? 'N/A' ) . ' (cached)';
            continue;
        }

        // No cache — start a new assessment
        $start_result = bite_call_ssllabs_api( 'analyze', array(
            'host'      => $hostname,
            'startNew'  => 'on',
            'all'       => 'done',
        ) );

        if ( is_wp_error( $start_result ) ) {
            $results['errors']++;
            $results['details'][] = 'Site ' . $site->site_id . ' (' . $hostname . '): ' . $start_result->get_error_message();
            continue;
        }

        // Assessment started — add to pending list for async polling
        $pending[] = array(
            'site_id'  => $site->site_id,
            'hostname' => $hostname,
        );
        $results['details'][] = 'Site ' . $site->site_id . ' (' . $hostname . '): assessment started, polling scheduled';

        // Cool-off between starting new assessments
        sleep( 5 );
    }

    // If we have pending assessments, schedule the async poller
    if ( ! empty( $pending ) ) {
        set_transient( 'bite_ssllabs_pending', $pending, HOUR_IN_SECONDS );
        if ( ! wp_next_scheduled( 'bite_ssllabs_poll_hook' ) ) {
            wp_schedule_single_event( time() + 60, 'bite_ssllabs_poll_hook' );
        }
        error_log( 'BITE SSL Labs: ' . count( $pending ) . ' assessment(s) pending async polling' );
    }

    return $results;
}

/**
 * Async poller for SSL Labs assessments.
 * Scheduled via WP single event to avoid blocking the daily cron.
 */
function bite_ssllabs_poll() {
    $pending = get_transient( 'bite_ssllabs_pending' );
    if ( empty( $pending ) || ! is_array( $pending ) ) {
        return;
    }

    $still_pending = array();

    foreach ( $pending as $job ) {
        $site_id  = $job['site_id'];
        $hostname = $job['hostname'];

        $result = bite_call_ssllabs_api( 'analyze', array(
            'host' => $hostname,
            'all'  => 'done',
        ) );

        if ( is_wp_error( $result ) ) {
            error_log( 'BITE SSL Labs Poll: Error for ' . $hostname . ' — ' . $result->get_error_message() );
            continue;
        }

        if ( isset( $result['status'] ) && $result['status'] === 'READY' ) {
            $data = bite_extract_ssllabs_data( $result );
            bite_store_ssl_labs_scan( $site_id, $data );

            $grade_numeric = bite_grade_to_numeric( $data['grade'] );
            bite_check_security_alert( $site_id, $grade_numeric, 'ssl' );

            error_log( 'BITE SSL Labs Poll: Site ' . $site_id . ' (' . $hostname . ') completed — grade ' . ( $data['grade'] ?? 'N/A' ) );
            continue; // Done — don't add back to pending
        }

        if ( isset( $result['status'] ) && $result['status'] === 'ERROR' ) {
            error_log( 'BITE SSL Labs Poll: Site ' . $site_id . ' (' . $hostname . ') assessment error' );
            continue; // Done with error — don't retry
        }

        // Still in progress — keep in pending list
        $still_pending[] = $job;
    }

    if ( ! empty( $still_pending ) ) {
        set_transient( 'bite_ssllabs_pending', $still_pending, HOUR_IN_SECONDS );
        // Schedule next poll in 30 seconds
        wp_schedule_single_event( time() + 30, 'bite_ssllabs_poll_hook' );
        error_log( 'BITE SSL Labs Poll: ' . count( $still_pending ) . ' assessment(s) still pending, next poll in 30s' );
    } else {
        delete_transient( 'bite_ssllabs_pending' );
        error_log( 'BITE SSL Labs Poll: All assessments complete' );
    }
}
add_action( 'bite_ssllabs_poll_hook', 'bite_ssllabs_poll' );

/**
 * Convert SSL grade to numeric for comparison
 * A+ = 100, A = 95, A- = 90, B = 80, C = 70, D = 60, E = 50, F = 40, T/M = 30
 *
 * @param string $grade SSL grade
 * @return int Numeric score
 */
function bite_grade_to_numeric( $grade ) {
    $map = array(
        'A+' => 100,
        'A'  => 95,
        'A-' => 90,
        'B'  => 80,
        'C'  => 70,
        'D'  => 60,
        'E'  => 50,
        'F'  => 40,
        'T'  => 30,
        'M'  => 30,
    );
    return isset( $map[ $grade ] ) ? $map[ $grade ] : 0;
}

// ============================================================================
// ALERT EMAILS
// ============================================================================

/**
 * Check if a security metric drop warrants an alert email
 * Rate limited: 1 alert per site per metric type per day.
 *
 * @param int    $site_id    Site ID
 * @param int    $new_score  New score (0-100 for headers, grade numeric for SSL)
 * @param string $type       'headers' or 'ssl'
 * @return bool Whether alert was sent
 */
function bite_check_security_alert( $site_id, $new_score, $type ) {
    global $wpdb;

    $transient_key = 'bite_security_alert_' . $type . '_' . $site_id . '_' . date( 'Ymd' );
    if ( get_transient( $transient_key ) ) {
        return false; // Already alerted today
    }

    // Get previous day's score
    $table = ( $type === 'headers' ) ? $wpdb->prefix . 'bite_security_headers' : $wpdb->prefix . 'bite_ssl_labs';
    $select_col = ( $type === 'headers' ) ? 'overall_score' : 'grade';
    $prev = $wpdb->get_row( $wpdb->prepare(
        "SELECT $select_col FROM $table WHERE site_id = %d AND scanned_at < CURDATE() ORDER BY scanned_at DESC LIMIT 1",
        $site_id
    ) );

    if ( ! $prev ) {
        return false; // No previous data to compare
    }

    if ( $type === 'headers' ) {
        $prev_score = intval( $prev->overall_score );
    } else {
        $prev_score = bite_grade_to_numeric( $prev->grade );
    }

    // No drop or improvement
    if ( $new_score >= $prev_score ) {
        return false;
    }

    $drop = $prev_score - $new_score;

    if ( $drop < BITE_SECURITY_ALERT_THRESHOLD ) {
        return false; // Drop is below threshold
    }

    // Find site owners to email
    $sites_table = $wpdb->prefix . 'bite_sites';
    $site = $wpdb->get_row( $wpdb->prepare( "SELECT name, domain FROM $sites_table WHERE site_id = %d", $site_id ) );

    $user_sites_table = $wpdb->prefix . 'bite_user_sites';
    $user_ids = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM $user_sites_table WHERE site_id = %d", $site_id ) );

    $subject = ( $type === 'headers' )
        ? '🔒 BITE Alert: Security Header Score Dropped for ' . $site->name
        : '🔒 BITE Alert: SSL Grade Dropped for ' . $site->name;

    $metric_name = ( $type === 'headers' ) ? 'Security Header Score' : 'SSL Grade';
    $prev_display = ( $type === 'headers' ) ? $prev_score . '/100' : $prev->grade;
    $new_display = ( $type === 'headers' ) ? $new_score . '/100' : bite_numeric_to_grade( $new_score );

    $message = "Hi there,\n\n";
    $message .= "BITE detected a drop in {$metric_name} for {$site->name} ({$site->domain}).\n\n";
    $message .= "Previous: {$prev_display}\n";
    $message .= "Current:  {$new_display}\n";
    $message .= "Drop:     {$drop} points\n\n";
    $message .= "Please review your site's security configuration.\n\n";
    $message .= "View details: " . home_url( '/site-health/?site_id=' . $site_id ) . "\n\n";
    $message .= "— BITE Security Monitor\n";

    $headers = array( 'Content-Type: text/plain; charset=UTF-8' );

    foreach ( $user_ids as $uid ) {
        $user = get_userdata( $uid );
        if ( $user ) {
            wp_mail( $user->user_email, $subject, $message, $headers );
        }
    }

    set_transient( $transient_key, 1, DAY_IN_SECONDS );

    error_log( "BITE Security Alert sent: Site $site_id $type dropped from $prev_score to $new_score" );

    return true;
}

/**
 * Reverse numeric grade back to letter for display
 *
 * @param int $numeric Numeric grade
 * @return string Letter grade
 */
function bite_numeric_to_grade( $numeric ) {
    if ( $numeric >= 100 ) return 'A+';
    if ( $numeric >= 95 )  return 'A';
    if ( $numeric >= 90 )  return 'A-';
    if ( $numeric >= 80 )  return 'B';
    if ( $numeric >= 70 )  return 'C';
    if ( $numeric >= 60 )  return 'D';
    if ( $numeric >= 50 )  return 'E';
    if ( $numeric >= 40 )  return 'F';
    return 'N/A';
}

// ============================================================================
// DATA RETRIEVAL
// ============================================================================

/**
 * Get latest security header scan for a site
 *
 * @param int $site_id Site ID
 * @return object|null
 */
function bite_get_latest_security_headers( $site_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bite_security_headers';

    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM $table WHERE site_id = %d ORDER BY scanned_at DESC LIMIT 1",
        $site_id
    ) );
}

/**
 * Get latest SSL Labs scan for a site
 *
 * @param int $site_id Site ID
 * @return object|null
 */
function bite_get_latest_ssl_labs( $site_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bite_ssl_labs';

    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM $table WHERE site_id = %d ORDER BY scanned_at DESC LIMIT 1",
        $site_id
    ) );
}

/**
 * Get security headers history for a site
 *
 * @param int    $site_id    Site ID
 * @param string $start_date YYYY-MM-DD
 * @param string $end_date   YYYY-MM-DD
 * @return array
 */
function bite_get_security_headers_history( $site_id, $start_date, $end_date ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bite_security_headers';

    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $table WHERE site_id = %d AND scanned_at >= %s AND scanned_at <= %s ORDER BY scanned_at ASC",
        $site_id, $start_date, $end_date
    ) );
}

/**
 * Get SSL Labs history for a site
 *
 * @param int    $site_id    Site ID
 * @param string $start_date YYYY-MM-DD
 * @param string $end_date   YYYY-MM-DD
 * @return array
 */
function bite_get_ssl_labs_history( $site_id, $start_date, $end_date ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bite_ssl_labs';

    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $table WHERE site_id = %d AND scanned_at >= %s AND scanned_at <= %s ORDER BY scanned_at ASC",
        $site_id, $start_date, $end_date
    ) );
}
