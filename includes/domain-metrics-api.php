<?php
/**
 * Domain Metrics API Integration
 *
 * Fetches authority scores from 3 external APIs:
 * - OpenPageRank (OPR)     : 0-10 scale, batch up to 100 domains
 * - SEO Review Tools (SRT) : 0-100 scale, DA/PA/backlinks, 50/day free
 * - Mozscape (Moz)         : 0-100 scale, DA/PA/ref domains, 10s delay
 *
 * Stores results in wp_bite_domain_metrics table daily.
 *
 * @package BITE-theme
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ============================================================
   1. API KEY HELPERS
   ============================================================ */

function bite_get_opr_api_key() {
    return get_option( 'bite_opr_api_key', '' );
}
function bite_get_srt_api_key() {
    return get_option( 'bite_srt_api_key', '' );
}
function bite_get_moz_access_id() {
    return get_option( 'bite_moz_access_id', '' );
}
function bite_get_moz_secret_key() {
    return get_option( 'bite_moz_secret_key', '' );
}
function bite_is_opr_configured() {
    return ! empty( bite_get_opr_api_key() );
}
function bite_is_srt_configured() {
    return ! empty( bite_get_srt_api_key() );
}
function bite_is_moz_configured() {
    return ! empty( bite_get_moz_access_id() ) && ! empty( bite_get_moz_secret_key() );
}

/* ============================================================
   2. OPEN PAGE RANK (OPR) — BATCH FETCH
   ============================================================ */

/**
 * Fetch OpenPageRank for up to 100 domains in one call.
 *
 * @param array $domains Array of domain strings.
 * @return array [domain => ['rank'=>float,'global_rank'=>int]] or WP_Error
 */
function bite_fetch_opr_batch( $domains ) {
    $api_key = bite_get_opr_api_key();
    if ( empty( $api_key ) ) {
        return new WP_Error( 'opr_not_configured', 'OpenPageRank API key not set.' );
    }

    $domains = array_filter( array_map( 'trim', $domains ) );
    if ( empty( $domains ) ) {
        return new WP_Error( 'opr_no_domains', 'No domains provided.' );
    }
    if ( count( $domains ) > 100 ) {
        $domains = array_slice( $domains, 0, 100 );
    }

    $url = add_query_arg(
        array(
            'domains[]' => array_values( $domains ),
        ),
        'https://openpagerank.com/api/v1.0/getPageRank'
    );

    $response = wp_remote_get(
        $url,
        array(
            'headers' => array( 'API-OPR' => $api_key ),
            'timeout' => 30,
        )
    );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    if ( empty( $data['response'] ) || ! is_array( $data['response'] ) ) {
        return new WP_Error( 'opr_bad_response', 'Unexpected OPR response format.', $data );
    }

    $results = array();
    foreach ( $data['response'] as $item ) {
        $domain = $item['domain'] ?? '';
        if ( ! $domain ) {
            continue;
        }
        $results[ $domain ] = array(
            'rank'        => isset( $item['page_rank_decimal'] ) ? floatval( $item['page_rank_decimal'] ) : null,
            'global_rank' => isset( $item['rank'] ) ? intval( $item['rank'] ) : null,
            'status_code' => $item['status_code'] ?? null,
        );
    }

    return $results;
}

/* ============================================================
   3. SEO REVIEW TOOLS (SRT) — SINGLE DOMAIN
   ============================================================ */

/**
 * Fetch SRT authority score for a single domain.
 *
 * @param string $domain Clean domain (e.g. example.com).
 * @return array ['da'=>int,'pa'=>int,'backlinks'=>int] or WP_Error
 */
function bite_fetch_srt_single( $domain ) {
    $api_key = bite_get_srt_api_key();
    if ( empty( $api_key ) ) {
        return new WP_Error( 'srt_not_configured', 'SRT API key not set.' );
    }

    $url = add_query_arg(
        array(
            'url'     => $domain,
            'metrics' => 'pa|da|total_backlinks',
            'key'     => $api_key,
        ),
        'https://api.seoreviewtools.com/authority-score/'
    );

    $response = wp_remote_get( $url, array( 'timeout' => 30 ) );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    if ( ! empty( $data['error'] ) ) {
        return new WP_Error( 'srt_api_error', $data['error'], $data );
    }

    return array(
        'da'        => isset( $data['data']['da'] ) ? intval( $data['data']['da'] ) : null,
        'pa'        => isset( $data['data']['pa'] ) ? intval( $data['data']['pa'] ) : null,
        'backlinks' => isset( $data['data']['total_backlinks'] ) ? intval( $data['data']['total_backlinks'] ) : null,
    );
}

/* ============================================================
   4. MOZSCAPE (MOZ) — SINGLE DOMAIN
   ============================================================ */

/**
 * Fetch Moz metrics for a single domain.
 * Free tier requires 10-second delay between calls.
 *
 * @param string $domain Clean domain.
 * @return array ['da'=>int,'pa'=>int,'ref_domains'=>int] or WP_Error
 */
function bite_fetch_moz_single( $domain ) {
    $access_id  = bite_get_moz_access_id();
    $secret_key = bite_get_moz_secret_key();

    if ( empty( $access_id ) || empty( $secret_key ) ) {
        return new WP_Error( 'moz_not_configured', 'Moz credentials not set.' );
    }

    $expires = time() + 300;
    $string_to_sign = $access_id . "\n" . $expires;
    $signature = base64_encode( hash_hmac( 'sha1', $string_to_sign, $secret_key, true ) );

    $url = add_query_arg(
        array(
            'Cols'         => '103079215108', // Bit flags for DA + PA + Ref Domains
            'AccessID'     => $access_id,
            'Expires'      => $expires,
            'Signature'    => $signature,
        ),
        'https://lsapi.seomoz.com/v2/url_metrics'
    );

    $response = wp_remote_post(
        $url,
        array(
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( array( 'targets' => array( $domain ) ) ),
            'timeout' => 30,
        )
    );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    if ( $code !== 200 ) {
        return new WP_Error( 'moz_http_error', "HTTP $code: " . ( $data['message'] ?? $body ), $data );
    }

    $result = $data['results'][0] ?? array();

    return array(
        'da'          => isset( $result['domain_authority'] ) ? intval( $result['domain_authority'] ) : null,
        'pa'          => isset( $result['page_authority'] ) ? intval( $result['page_authority'] ) : null,
        'ref_domains' => isset( $result['root_domains_to_root_domain'] ) ? intval( $result['root_domains_to_root_domain'] ) : null,
    );
}

/* ============================================================
   5. SRT SITE PRIORITY (First 50 sites)
   ============================================================ */

/**
 * Get the ordered list of sites eligible for SRT data.
 * First 50 sites by site_id (chronological add order).
 *
 * @return array Site IDs eligible for SRT.
 */
function bite_get_srt_eligible_sites() {
    global $wpdb;
    $sites = $wpdb->get_col( "SELECT site_id FROM {$wpdb->prefix}bite_sites ORDER BY site_id ASC LIMIT 50" );
    return $sites ? array_map( 'intval', $sites ) : array();
}

/**
 * Check if a specific site is eligible for SRT data.
 *
 * @param int $site_id
 * @return bool
 */
function bite_is_srt_eligible( $site_id ) {
    $eligible = bite_get_srt_eligible_sites();
    return in_array( intval( $site_id ), $eligible, true );
}

/* ============================================================
   6. AUTHORITY INDEX CALCULATION
   ============================================================ */

/**
 * Calculate a unified 0-100 Authority Index from available metrics.
 *
 * @param array $metrics Raw metrics array.
 * @return float|null
 */
function bite_calculate_authority_index( $metrics ) {
    $scores = array();

    // OPR: 0-10 → normalize to 0-100
    if ( isset( $metrics['opr_rank'] ) && $metrics['opr_rank'] !== null ) {
        $scores[] = floatval( $metrics['opr_rank'] ) * 10;
    }

    // Moz DA: already 0-100
    if ( isset( $metrics['moz_da'] ) && $metrics['moz_da'] !== null ) {
        $scores[] = floatval( $metrics['moz_da'] );
    }

    // SRT DA: already 0-100
    if ( isset( $metrics['srt_da'] ) && $metrics['srt_da'] !== null ) {
        $scores[] = floatval( $metrics['srt_da'] );
    }

    if ( empty( $scores ) ) {
        return null;
    }

    return round( array_sum( $scores ) / count( $scores ), 2 );
}

/* ============================================================
   7. MAIN ORCHESTRATOR — DAILY FETCH
   ============================================================ */

/**
 * Fetch and store domain metrics for all sites.
 * Runs after the daily GSC update.
 *
 * @return array Summary of results.
 */
function bite_fetch_all_domain_metrics() {
    global $wpdb;

    $sites_table = $wpdb->prefix . 'bite_sites';
    $metrics_table = $wpdb->prefix . 'bite_domain_metrics';
    $today = date( 'Y-m-d' );

    $sites = $wpdb->get_results( "SELECT site_id, domain FROM $sites_table ORDER BY site_id ASC" );
    if ( empty( $sites ) ) {
        return array( 'status' => 'no_sites', 'message' => 'No sites found.' );
    }

    $srt_eligible = bite_get_srt_eligible_sites();
    $summary = array(
        'total'     => count( $sites ),
        'opr'       => 0,
        'srt'       => 0,
        'moz'       => 0,
        'errors'    => array(),
        'timestamp' => $today,
    );

    // ---------- OPR: BATCH ALL DOMAINS ----------
    if ( bite_is_opr_configured() ) {
        $domains = wp_list_pluck( $sites, 'domain' );
        $opr_results = bite_fetch_opr_batch( $domains );

        if ( is_wp_error( $opr_results ) ) {
            $summary['errors'][] = 'OPR: ' . $opr_results->get_error_message();
            error_log( 'BITE Domain Metrics OPR Error: ' . $opr_results->get_error_message() );
        } else {
            foreach ( $sites as $site ) {
                $domain = $site->domain;
                if ( isset( $opr_results[ $domain ] ) ) {
                    $wpdb->query( $wpdb->prepare(
                        "INSERT INTO $metrics_table (site_id, recorded_at, opr_rank, opr_global_rank)
                         VALUES (%d, %s, %f, %d)
                         ON DUPLICATE KEY UPDATE
                         opr_rank = VALUES(opr_rank),
                         opr_global_rank = VALUES(opr_global_rank)",
                        $site->site_id,
                        $today,
                        $opr_results[ $domain ]['rank'],
                        $opr_results[ $domain ]['global_rank']
                    ) );
                    $summary['opr']++;
                }
            }
        }
    }

    // ---------- SRT: ONE-BY-ONE (50 sites max) ----------
    if ( bite_is_srt_configured() ) {
        foreach ( $sites as $site ) {
            if ( ! in_array( intval( $site->site_id ), $srt_eligible, true ) ) {
                continue;
            }

            $srt_result = bite_fetch_srt_single( $site->domain );

            if ( is_wp_error( $srt_result ) ) {
                $summary['errors'][] = 'SRT site ' . $site->site_id . ': ' . $srt_result->get_error_message();
                error_log( 'BITE Domain Metrics SRT Error (site ' . $site->site_id . '): ' . $srt_result->get_error_message() );
                continue;
            }

            $wpdb->query( $wpdb->prepare(
                "INSERT INTO $metrics_table (site_id, recorded_at, srt_da, srt_pa, srt_backlinks)
                 VALUES (%d, %s, %d, %d, %d)
                 ON DUPLICATE KEY UPDATE
                 srt_da = VALUES(srt_da),
                 srt_pa = VALUES(srt_pa),
                 srt_backlinks = VALUES(srt_backlinks)",
                $site->site_id,
                $today,
                $srt_result['da'],
                $srt_result['pa'],
                $srt_result['backlinks']
            ) );
            $summary['srt']++;
        }
    }

    // ---------- MOZ: ONE-BY-ONE WITH 10s DELAY ----------
    if ( bite_is_moz_configured() ) {
        foreach ( $sites as $site ) {
            $moz_result = bite_fetch_moz_single( $site->domain );

            if ( is_wp_error( $moz_result ) ) {
                $summary['errors'][] = 'Moz site ' . $site->site_id . ': ' . $moz_result->get_error_message();
                error_log( 'BITE Domain Metrics Moz Error (site ' . $site->site_id . '): ' . $moz_result->get_error_message() );
                continue;
            }

            $wpdb->query( $wpdb->prepare(
                "INSERT INTO $metrics_table (site_id, recorded_at, moz_da, moz_pa, moz_ref_domains)
                 VALUES (%d, %s, %d, %d, %d)
                 ON DUPLICATE KEY UPDATE
                 moz_da = VALUES(moz_da),
                 moz_pa = VALUES(moz_pa),
                 moz_ref_domains = VALUES(moz_ref_domains)",
                $site->site_id,
                $today,
                $moz_result['da'],
                $moz_result['pa'],
                $moz_result['ref_domains']
            ) );
            $summary['moz']++;

            // Free tier rate limit: 10 seconds between requests
            if ( count( $sites ) > 1 ) {
                sleep( 10 );
            }
        }
    }

    // ---------- CALCULATE AUTHORITY INDEX ----------
    $all_records = $wpdb->get_results( $wpdb->prepare(
        "SELECT metric_id, opr_rank, moz_da, srt_da FROM $metrics_table WHERE recorded_at = %s",
        $today
    ) );

    foreach ( $all_records as $record ) {
        $index = bite_calculate_authority_index( array(
            'opr_rank' => $record->opr_rank,
            'moz_da'   => $record->moz_da,
            'srt_da'   => $record->srt_da,
        ) );

        if ( $index !== null ) {
            $wpdb->update(
                $metrics_table,
                array( 'authority_index' => $index ),
                array( 'metric_id' => $record->metric_id ),
                array( '%f' ),
                array( '%d' )
            );
        }
    }

    error_log( 'BITE Domain Metrics: Completed. ' . wp_json_encode( $summary ) );

    return $summary;
}

/* ============================================================
   8. HELPER: GET LATEST METRICS FOR A SITE
   ============================================================ */

/**
 * Get the most recent domain metrics row for a site.
 *
 * @param int $site_id
 * @return object|null
 */
function bite_get_latest_domain_metrics( $site_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bite_domain_metrics';
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM $table WHERE site_id = %d ORDER BY recorded_at DESC LIMIT 1",
        $site_id
    ) );
}

/**
 * Get domain metrics history for a site.
 *
 * @param int    $site_id
 * @param string $start_date Y-m-d
 * @param string $end_date   Y-m-d
 * @return array
 */
function bite_get_domain_metrics_history( $site_id, $start_date, $end_date ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bite_domain_metrics';
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $table
         WHERE site_id = %d AND recorded_at >= %s AND recorded_at <= %s
         ORDER BY recorded_at ASC",
        $site_id, $start_date, $end_date
    ) );
}
