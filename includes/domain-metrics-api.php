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
 * Fetch PageSpeed Insights scores for a single domain.
 * Returns all 4 Lighthouse category scores (Performance, Accessibility,
 * Best Practices, SEO).
 *
 * @param string $domain Clean domain (e.g. example.com).
 * @param string $strategy 'desktop' or 'mobile'.
 * @return array All category scores or WP_Error
 */
function bite_fetch_pagespeed_single( $domain, $strategy = 'desktop' ) {
    $api_key = bite_get_pagespeed_api_key();

    $base_url = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
    $url = $base_url . '?' . http_build_query(
        array(
            'url'      => 'https://' . $domain,
            'strategy' => $strategy,
        ),
        '',
        '&'
    );
    $url .= '&category=PERFORMANCE&category=ACCESSIBILITY&category=BEST_PRACTICES&category=SEO';

    if ( ! empty( $api_key ) ) {
        $url .= '&key=' . urlencode( $api_key );
    }

    $response = wp_remote_get( $url, array( 'timeout' => 90 ) );

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

    $categories = $data['lighthouseResult']['categories'] ?? array();

    $extract_score = function( $cat_data ) {
        if ( isset( $cat_data['score'] ) ) {
            return intval( round( floatval( $cat_data['score'] ) * 100 ) );
        }
        return null;
    };

    return array(
        'performance_score' => $extract_score( $categories['performance'] ?? array() ),
        'accessibility'     => $extract_score( $categories['accessibility'] ?? array() ),
        'best_practices'    => $extract_score( $categories['best-practices'] ?? array() ),
        'seo'               => $extract_score( $categories['seo'] ?? array() ),
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
            "INSERT INTO $metrics_table (site_id, recorded_at, pagespeed_score, pagespeed_accessibility, pagespeed_best_practices, pagespeed_seo)
             VALUES (%d, %s, %d, %d, %d, %d)
             ON DUPLICATE KEY UPDATE
             pagespeed_score = VALUES(pagespeed_score),
             pagespeed_accessibility = VALUES(pagespeed_accessibility),
             pagespeed_best_practices = VALUES(pagespeed_best_practices),
             pagespeed_seo = VALUES(pagespeed_seo)",
            $site->site_id,
            $today,
            $ps_result['performance_score'],
            $ps_result['accessibility'],
            $ps_result['best_practices'],
            $ps_result['seo']
        ) );
        $summary['pagespeed']++;

        // Check for significant score drops vs previous record
        bite_check_pagespeed_drops( $site, $ps_result );
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

/* ============================================================
   6. PAGESPEED SCORE DROP ALERTS
   ============================================================ */

/**
 * Check if any PageSpeed category dropped by more than 10 points
 * since the previous record, and send email alerts if so.
 *
 * @param object $site      Site row from bite_sites.
 * @param array  $new_scores Current scores from PageSpeed API.
 */
function bite_check_pagespeed_drops( $site, $new_scores ) {
    global $wpdb;

    // Rate limit: one alert per site per day
    $transient_key = 'bite_ps_alert_' . $site->site_id . '_' . date( 'Ymd' );
    if ( get_transient( $transient_key ) ) {
        return;
    }

    $metrics_table = $wpdb->prefix . 'bite_domain_metrics';

    // Get the most recent previous record (before today)
    $prev = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM $metrics_table
         WHERE site_id = %d AND recorded_at < %s AND pagespeed_score IS NOT NULL
         ORDER BY recorded_at DESC LIMIT 1",
        $site->site_id,
        date( 'Y-m-d' )
    ) );

    if ( ! $prev ) {
        return; // No previous data to compare
    }

    $categories = array(
        'Performance'    => array( 'prev' => $prev->pagespeed_score,           'new' => $new_scores['performance_score'] ),
        'Accessibility'  => array( 'prev' => $prev->pagespeed_accessibility,   'new' => $new_scores['accessibility'] ),
        'Best Practices' => array( 'prev' => $prev->pagespeed_best_practices,  'new' => $new_scores['best_practices'] ),
        'SEO'            => array( 'prev' => $prev->pagespeed_seo,             'new' => $new_scores['seo'] ),
    );

    $drops = array();
    foreach ( $categories as $name => $vals ) {
        if ( $vals['prev'] !== null && $vals['new'] !== null ) {
            $diff = $vals['prev'] - $vals['new'];
            if ( $diff > 10 ) {
                $drops[] = array(
                    'name' => $name,
                    'prev' => intval( $vals['prev'] ),
                    'new'  => intval( $vals['new'] ),
                    'diff' => intval( $diff ),
                );
            }
        }
    }

    if ( empty( $drops ) ) {
        return;
    }

    // Build the email
    $subject = sprintf( '[BITE Alert] PageSpeed Score Drop: %s', $site->domain );

    $body_lines = array(
        sprintf( 'Hi,' ),
        '',
        sprintf( 'Your site %s has experienced significant PageSpeed score drops since the last check:', $site->domain ),
        '',
    );

    foreach ( $drops as $drop ) {
        $body_lines[] = sprintf( '%s: %d → %d  (-%d) ⚠️', $drop['name'], $drop['prev'], $drop['new'], $drop['diff'] );
    }

    $body_lines[] = '';
    $body_lines[] = sprintf( 'View full stats: %s', home_url( '/all-stats/?site_id=' . $site->site_id ) );
    $body_lines[] = '';
    $body_lines[] = '---';
    $body_lines[] = 'BITE Bulk Insight Tracking Engine';

    $headers = array( 'Content-Type: text/plain; charset=UTF-8' );

    // Email all users with access to this site
    $user_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT user_id FROM {$wpdb->prefix}bite_user_sites WHERE site_id = %d",
        $site->site_id
    ) );

    $sent = false;
    foreach ( $user_ids as $user_id ) {
        $user = get_userdata( intval( $user_id ) );
        if ( $user && ! empty( $user->user_email ) ) {
            wp_mail( $user->user_email, $subject, implode( "\r\n", $body_lines ), $headers );
            $sent = true;
        }
    }

    // Also email admin contact if no users found
    if ( ! $sent ) {
        $admin_email = get_option( 'bite_contact_email', get_option( 'admin_email' ) );
        wp_mail( $admin_email, $subject, implode( "\r\n", $body_lines ), $headers );
    }

    // Set rate limit transient (24 hours)
    set_transient( $transient_key, 1, DAY_IN_SECONDS );

    error_log( 'BITE PageSpeed Alert sent for site ' . $site->site_id . ': ' . wp_json_encode( $drops ) );
}

/* ============================================================
   7. METRICS LEGEND / EXPLANATION COMPONENT
   ============================================================ */

/**
 * Render a collapsible explanation of BITE metrics.
 * Shows what OPR, PageSpeed, and Authority Index mean.
 *
 * @return string HTML output.
 */
function bite_render_metrics_legend() {
    ob_start();
    ?>
    <div class="bite-metrics-legend" style="margin-bottom: 20px;">
        <button type="button" class="bite-legend-toggle" onclick="this.nextElementSibling.style.display = this.nextElementSibling.style.display === 'none' ? 'block' : 'none'; this.querySelector('.bite-legend-arrow').style.transform = this.nextElementSibling.style.display === 'none' ? 'rotate(0deg)' : 'rotate(180deg)';" style="background: var(--card-bg); border: 1px solid var(--border-light); padding: 12px 16px; border-radius: var(--radius-md); cursor: pointer; width: 100%; text-align: left; font-size: 0.95em; font-weight: 600; color: var(--text-color); display: flex; align-items: center; justify-content: space-between;">
            <span>📖 What do these scores mean?</span>
            <span class="bite-legend-arrow" style="display: inline-block; transition: transform 0.2s; font-size: 0.8em;">▼</span>
        </button>
        <div class="bite-legend-content" style="display: none; background: var(--bg-color); border: 1px solid var(--border-light); border-top: none; padding: 20px; border-radius: 0 0 var(--radius-md) var(--radius-md);">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                <div>
                    <h4 style="margin: 0 0 8px; color: #e91e63; font-size: 1em;">🏆 Authority Index</h4>
                    <p style="margin: 0; font-size: 0.9em; line-height: 1.5; color: #555;">
                        A blended 0-100 score combining <strong>OpenPageRank</strong> and <strong>PageSpeed Performance</strong>.<br><br>
                        <strong>How it's calculated:</strong><br>
                        OPR (0-10) × 10 + PageSpeed (0-100) ÷ 2 = average<br><br>
                        <strong>Max possible: 100</strong> (OPR 10.0 + PageSpeed 100)<br>
                        <strong>Higher is better.</strong>
                    </p>
                </div>
                <div>
                    <h4 style="margin: 0 0 8px; color: #e91e63; font-size: 1em;">🔗 OpenPageRank (OPR)</h4>
                    <p style="margin: 0; font-size: 0.9em; line-height: 1.5; color: #555;">
                        A measure of domain authority based on Google's original PageRank algorithm.<br><br>
                        <strong>Range: 0.00 – 10.00</strong><br>
                        <strong>Higher is better.</strong><br><br>
                        0-2 = New/low authority site<br>
                        3-5 = Moderate authority<br>
                        6-8 = Strong authority<br>
                        9-10 = Top-tier authority (rare)
                    </p>
                </div>
                <div>
                    <h4 style="margin: 0 0 8px; color: #2196f3; font-size: 1em;">⚡ PageSpeed Scores</h4>
                    <p style="margin: 0; font-size: 0.9em; line-height: 1.5; color: #555;">
                        Google's Lighthouse audit scores for your site.<br><br>
                        <strong>All scores: 0 – 100 (Higher is better)</strong><br><br>
                        <strong>Performance</strong> — How fast your site loads<br>
                        <strong>Accessibility</strong> — Usability for people with disabilities<br>
                        <strong>Best Practices</strong> — Modern web standards & security<br>
                        <strong>SEO</strong> — Search engine optimization basics<br><br>
                        0-49 = Poor &nbsp;|&nbsp; 50-89 = Needs Improvement &nbsp;|&nbsp; 90-100 = Good
                    </p>
                </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
