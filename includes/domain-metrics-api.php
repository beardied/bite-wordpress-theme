<?php
/**
 * Domain Metrics API Integration
 *
 * Fetches authority scores from 2 FREE external APIs:
 * - OpenPageRank (OPR)          : 0-10 scale, batch up to 100 domains
 * - Google PageSpeed Insights   : 0-100 performance score (free, no key needed)
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
function bite_get_pagespeed_api_key() {
    return get_option( 'bite_pagespeed_api_key', '' );
}
function bite_is_opr_configured() {
    return ! empty( bite_get_opr_api_key() );
}
function bite_is_pagespeed_configured() {
    return true; // PageSpeed works without a key (lower quota) or with key (25k/day)
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

    $url = 'https://openpagerank.com/api/v1.0/getPageRank?';
    foreach ( array_values( $domains ) as $domain ) {
        $url .= 'domains[]=' . urlencode( $domain ) . '&';
    }
    $url = rtrim( $url, '&' );

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
   3. GOOGLE PAGESPEED INSIGHTS — SINGLE DOMAIN
   ============================================================ */

/**
 * Fetch PageSpeed Insights performance score for a single domain.
 * Free tier: 25,000 requests/day with API key, lower without.
 * Returns a 0-100 performance score.
 *
 * @param string $domain Clean domain (e.g. example.com).
 * @param string $strategy 'desktop' or 'mobile'.
 * @return array ['performance_score'=>int(0-100)] or WP_Error
 */
function bite_fetch_pagespeed_single( $domain, $strategy = 'desktop' ) {
    $api_key = bite_get_pagespeed_api_key();

    $url = add_query_arg(
        array(
            'url'      => 'https://' . $domain,
            'strategy' => $strategy,
        ),
        'https://www.googleapis.com/pagespeedonline/v5/runPagespeed'
    );

    if ( ! empty( $api_key ) ) {
        $url = add_query_arg( 'key', $api_key, $url );
    }

    $response = wp_remote_get( $url, array( 'timeout' => 45 ) );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    if ( $code !== 200 ) {
        $msg = $data['error']['message'] ?? "HTTP $code";
        return new WP_Error( 'pagespeed_http_error', $msg, $data );
    }

    $score = null;
    if ( isset( $data['lighthouseResult']['categories']['performance']['score'] ) ) {
        $raw = $data['lighthouseResult']['categories']['performance']['score'];
        // Score comes as 0-1 decimal, convert to 0-100 integer
        $score = intval( round( floatval( $raw ) * 100 ) );
    }

    return array(
        'performance_score' => $score,
    );
}

/* ============================================================
   4. AUTHORITY INDEX CALCULATION
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

    // PageSpeed: already 0-100
    if ( isset( $metrics['pagespeed_score'] ) && $metrics['pagespeed_score'] !== null ) {
        $scores[] = floatval( $metrics['pagespeed_score'] );
    }

    if ( empty( $scores ) ) {
        return null;
    }

    return round( array_sum( $scores ) / count( $scores ), 2 );
}

/* ============================================================
   5. MAIN ORCHESTRATOR — DAILY FETCH
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

    $summary = array(
        'total'     => count( $sites ),
        'opr'       => 0,
        'pagespeed' => 0,
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

    // ---------- PAGESPEED: ONE-BY-ONE ----------
    foreach ( $sites as $site ) {
        $ps_result = bite_fetch_pagespeed_single( $site->domain, 'desktop' );

        if ( is_wp_error( $ps_result ) ) {
            $summary['errors'][] = 'PageSpeed site ' . $site->site_id . ': ' . $ps_result->get_error_message();
            error_log( 'BITE Domain Metrics PageSpeed Error (site ' . $site->site_id . '): ' . $ps_result->get_error_message() );
            continue;
        }

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO $metrics_table (site_id, recorded_at, pagespeed_score)
             VALUES (%d, %s, %d)
             ON DUPLICATE KEY UPDATE
             pagespeed_score = VALUES(pagespeed_score)",
            $site->site_id,
            $today,
            $ps_result['performance_score']
        ) );
        $summary['pagespeed']++;
    }

    // ---------- CALCULATE AUTHORITY INDEX ----------
    $all_records = $wpdb->get_results( $wpdb->prepare(
        "SELECT metric_id, opr_rank, pagespeed_score FROM $metrics_table WHERE recorded_at = %s",
        $today
    ) );

    foreach ( $all_records as $record ) {
        $index = bite_calculate_authority_index( array(
            'opr_rank'        => $record->opr_rank,
            'pagespeed_score' => $record->pagespeed_score,
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
