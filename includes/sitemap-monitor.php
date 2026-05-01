<?php
/**
 * Sitemap Monitor
 *
 * Auto-detects sitemaps from robots.txt, parses sitemap XML,
 * tracks URL changes over time, and manages sitemap configuration.
 *
 * @package BITE-theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Detect sitemap URL for a domain
 * Strategy: robots.txt → common paths → manual override
 *
 * @param string $domain Domain name (e.g. example.com)
 * @return string|false Detected sitemap URL or false
 */
function bite_detect_sitemap( $domain ) {
    $domain = sanitize_text_field( $domain );

    // Normalize domain for URL building
    if ( ! preg_match( '/^https?:\/\//', $domain ) ) {
        $domain = 'https://' . $domain;
    }
    $domain = rtrim( $domain, '/' );

    // 1. Check robots.txt for Sitemap: directive
    $robots_url = $domain . '/robots.txt';
    $robots_response = wp_remote_get( $robots_url, array( 'timeout' => 15 ) );

    if ( ! is_wp_error( $robots_response ) && wp_remote_retrieve_response_code( $robots_response ) === 200 ) {
        $robots_body = wp_remote_retrieve_body( $robots_response );
        if ( preg_match_all( '/Sitemap:\s*(.+)/i', $robots_body, $matches ) ) {
            foreach ( $matches[1] as $sitemap_url ) {
                $sitemap_url = trim( $sitemap_url );
                // Validate it exists
                $head = wp_remote_head( $sitemap_url, array( 'timeout' => 15, 'sslverify' => false ) );
                if ( ! is_wp_error( $head ) && wp_remote_retrieve_response_code( $head ) === 200 ) {
                    $content_type = wp_remote_retrieve_header( $head, 'content-type' );
                    if ( stripos( $content_type, 'xml' ) !== false || stripos( $content_type, 'text' ) !== false ) {
                        return $sitemap_url;
                    }
                }
            }
        }
    }

    // 2. Fallback to common sitemap paths
    $fallbacks = array(
        $domain . '/sitemap.xml',
        $domain . '/sitemap_index.xml',
        $domain . '/sitemap-index.xml',
        $domain . '/wp-sitemap.xml',
        $domain . '/sitemaps.xml',
    );

    foreach ( $fallbacks as $url ) {
        $head = wp_remote_head( $url, array( 'timeout' => 15, 'sslverify' => false ) );
        if ( ! is_wp_error( $head ) && wp_remote_retrieve_response_code( $head ) === 200 ) {
            $content_type = wp_remote_retrieve_header( $head, 'content-type' );
            if ( stripos( $content_type, 'xml' ) !== false || stripos( $content_type, 'text' ) !== false ) {
                return $url;
            }
        }
    }

    return false;
}

/**
 * Parse a sitemap XML and extract all URLs
 * Handles both regular sitemaps and sitemap indexes (recursively).
 *
 * @param string $sitemap_url The sitemap URL to parse
 * @return array|WP_Error Array of URL entries or error
 */
function bite_parse_sitemap( $sitemap_url ) {
    $response = wp_remote_get( $sitemap_url, array( 'timeout' => 30, 'sslverify' => false ) );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return new WP_Error( 'sitemap_fetch_failed', 'Failed to fetch sitemap: HTTP ' . wp_remote_retrieve_response_code( $response ) );
    }

    $body = wp_remote_retrieve_body( $response );

    // Suppress XML errors
    libxml_use_internal_errors( true );
    $xml = simplexml_load_string( $body );
    libxml_clear_errors();

    if ( ! $xml ) {
        return new WP_Error( 'sitemap_parse_error', 'Failed to parse sitemap XML' );
    }

    $urls = array();

    // Check if it's a sitemap index (contains <sitemap> entries)
    if ( isset( $xml->sitemap ) ) {
        foreach ( $xml->sitemap as $sitemap ) {
            $loc = (string) $sitemap->loc;
            if ( empty( $loc ) ) {
                continue;
            }
            $sub_urls = bite_parse_sitemap( $loc );
            if ( ! is_wp_error( $sub_urls ) ) {
                $urls = array_merge( $urls, $sub_urls );
            }
            // Small delay between sub-sitemap fetches
            usleep( 200000 ); // 200ms
        }
        return $urls;
    }

    // Regular sitemap (contains <url> entries)
    if ( isset( $xml->url ) ) {
        foreach ( $xml->url as $url ) {
            $loc = (string) $url->loc;
            if ( empty( $loc ) ) {
                continue;
            }
            $urls[] = array(
                'url'      => $loc,
                'lastmod'  => (string) ( $url->lastmod ?? '' ),
                'priority' => (string) ( $url->priority ?? '0.5' ),
            );
        }
    }

    return $urls;
}

/**
 * Store sitemap URLs in the database for a site
 * Tracks new URLs, removed URLs, and updates last_seen.
 *
 * @param int   $site_id     The site ID
 * @param array $urls        Array of URLs from bite_parse_sitemap()
 * @return array Summary of changes
 */
function bite_store_sitemap_urls( $site_id, $urls ) {
    global $wpdb;

    $table = $wpdb->prefix . 'bite_sitemap_urls';
    $today = date( 'Y-m-d' );

    $existing_urls = $wpdb->get_results( $wpdb->prepare(
        "SELECT url_id, url FROM $table WHERE site_id = %d",
        $site_id
    ), OBJECT_K );

    $existing_by_url = array();
    foreach ( $existing_urls as $row ) {
        $existing_by_url[ $row->url ] = $row->url_id;
    }

    $seen_urls = array();
    $new_count = 0;
    $updated_count = 0;

    foreach ( $urls as $url_entry ) {
        $url = $url_entry['url'];
        $seen_urls[] = $url;

        if ( isset( $existing_by_url[ $url ] ) ) {
            // Update last_seen and ensure source is sitemap
            $wpdb->update(
                $table,
                array( 'last_seen' => $today, 'source' => 'sitemap' ),
                array( 'url_id' => $existing_by_url[ $url ] ),
                array( '%s', '%s' ),
                array( '%d' )
            );
            $updated_count++;
        } else {
            // Insert new URL
            $wpdb->insert(
                $table,
                array(
                    'site_id'    => $site_id,
                    'url'        => $url,
                    'first_seen' => $today,
                    'last_seen'  => $today,
                    'source'     => 'sitemap',
                ),
                array( '%d', '%s', '%s', '%s', '%s' )
            );
            $new_count++;
        }
    }

    // Find removed URLs (not seen today but were seen before)
    $removed_count = $wpdb->query( $wpdb->prepare(
        "UPDATE $table SET last_seen = DATE_SUB(%s, INTERVAL 1 DAY) WHERE site_id = %d AND last_seen = %s AND url NOT IN ('" . implode( "','", array_map( 'esc_sql', $seen_urls ) ) . "')",
        $today, $site_id, $today
    ) );

    return array(
        'total'    => count( $urls ),
        'new'      => $new_count,
        'updated'  => $updated_count,
        'removed'  => $removed_count,
    );
}

/**
 * Get sitemap URLs for a site, optionally filtered by status
 *
 * @param int    $site_id The site ID
 * @param string $status  'all', 'indexed', 'not_indexed', 'recently_added', 'recently_removed'
 * @return array Array of URL rows
 */
function bite_get_sitemap_urls( $site_id, $status = 'all' ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bite_sitemap_urls';

    $where = 'site_id = %d';
    $params = array( $site_id );

    switch ( $status ) {
        case 'indexed':
            $where .= " AND source = 'sitemap' AND is_indexed = 1 AND last_seen >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
            break;
        case 'not_indexed':
            $where .= " AND source = 'sitemap' AND (is_indexed = 0 OR is_indexed IS NULL) AND last_seen >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
            break;
        case 'recently_added':
            $where .= " AND source = 'sitemap' AND first_seen >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
            break;
        case 'recently_removed':
            $where .= " AND source = 'sitemap' AND last_seen < DATE_SUB(CURDATE(), INTERVAL 6 DAY)";
            break;
        default:
            $where .= " AND source = 'sitemap' AND last_seen >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    }

    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $table WHERE $where ORDER BY last_seen DESC, url ASC",
        $params
    ) );
}

/**
 * Get sitemap summary stats for a site
 *
 * @param int $site_id The site ID
 * @return array Stats array
 */
function bite_get_sitemap_summary( $site_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'bite_sitemap_urls';

    // Only count URLs that came from the actual sitemap
    $total = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE site_id = %d AND source = 'sitemap' AND last_seen >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)",
        $site_id
    ) );

    $indexed = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE site_id = %d AND source = 'sitemap' AND is_indexed = 1 AND last_seen >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)",
        $site_id
    ) );

    $not_indexed = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE site_id = %d AND source = 'sitemap' AND (is_indexed = 0 OR is_indexed IS NULL) AND last_seen >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)",
        $site_id
    ) );

    $recently_added = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE site_id = %d AND source = 'sitemap' AND first_seen >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)",
        $site_id
    ) );

    // GSC-discovered orphan URLs (not in sitemap)
    $gsc_orphans = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE site_id = %d AND source = 'gsc'",
        $site_id
    ) );

    // Total unique URLs Google knows about (sitemap + orphans)
    $gsc_total = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE site_id = %d AND gsc_known = 1",
        $site_id
    ) );

    return array(
        'total'           => intval( $total ),
        'indexed'         => intval( $indexed ),
        'not_indexed'     => intval( $not_indexed ),
        'recently_added'  => intval( $recently_added ),
        'gsc_orphans'     => intval( $gsc_orphans ),
        'gsc_total'       => intval( $gsc_total ),
    );
}

/**
 * Run sitemap parse and store for a single site
 *
 * @param int $site_id The site ID
 * @return array|WP_Error Summary or error
 */
function bite_run_sitemap_parse_for_site( $site_id ) {
    global $wpdb;

    $sites_table = $wpdb->prefix . 'bite_sites';
    $site = $wpdb->get_row( $wpdb->prepare(
        "SELECT site_id, domain, sitemap_url FROM $sites_table WHERE site_id = %d",
        $site_id
    ) );

    if ( ! $site ) {
        return new WP_Error( 'no_site', 'Site not found' );
    }

    $sitemap_url = $site->sitemap_url;

    if ( empty( $sitemap_url ) ) {
        // Try auto-detect
        $sitemap_url = bite_detect_sitemap( $site->domain );
        if ( $sitemap_url ) {
            $wpdb->update(
                $sites_table,
                array( 'sitemap_url' => $sitemap_url ),
                array( 'site_id' => $site_id ),
                array( '%s' ),
                array( '%d' )
            );
        } else {
            return new WP_Error( 'no_sitemap', 'No sitemap found for ' . $site->domain );
        }
    }

    $urls = bite_parse_sitemap( $sitemap_url );

    if ( is_wp_error( $urls ) ) {
        return $urls;
    }

    $store_result = bite_store_sitemap_urls( $site_id, $urls );

    error_log( "BITE Sitemap Parse: Site $site_id parsed {$store_result['total']} URLs (new: {$store_result['new']}, updated: {$store_result['updated']}, removed: {$store_result['removed']})" );

    return $store_result;
}

/**
 * Parse sitemaps for all sites that have one configured
 * Called by the daily cron.
 *
 * @return array Summary of results
 */
function bite_parse_all_sitemaps() {
    global $wpdb;

    $sites_table = $wpdb->prefix . 'bite_sites';
    $sites = $wpdb->get_results( "SELECT site_id, sitemap_url FROM $sites_table WHERE sitemap_url IS NOT NULL AND sitemap_url != ''" );

    $results = array(
        'processed' => 0,
        'errors'    => 0,
        'details'   => array(),
    );

    foreach ( $sites as $site ) {
        $result = bite_run_sitemap_parse_for_site( $site->site_id );
        if ( is_wp_error( $result ) ) {
            $results['errors']++;
            $results['details'][] = 'Site ' . $site->site_id . ': ' . $result->get_error_message();
        } else {
            $results['processed']++;
            $results['details'][] = 'Site ' . $site->site_id . ': ' . $result['total'] . ' URLs';
        }
        usleep( 500000 ); // 500ms between sites
    }

    return $results;
}

/**
 * AJAX handler: Save sitemap URL for a site (manual override)
 */
function bite_ajax_save_sitemap_url() {
    check_ajax_referer( 'bite_sitemap_nonce', 'nonce' );

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_send_json_error( 'Not logged in' );
    }

    $site_id = isset( $_POST['site_id'] ) ? absint( $_POST['site_id'] ) : 0;
    $sitemap_url = isset( $_POST['sitemap_url'] ) ? esc_url_raw( $_POST['sitemap_url'] ) : '';

    if ( ! $site_id ) {
        wp_send_json_error( 'Invalid site' );
    }

    $user_sites = bite_get_user_sites( $user_id );
    if ( ! in_array( $site_id, $user_sites ) && ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Access denied' );
    }

    global $wpdb;
    $sites_table = $wpdb->prefix . 'bite_sites';

    if ( empty( $sitemap_url ) ) {
        $wpdb->update(
            $sites_table,
            array( 'sitemap_url' => null ),
            array( 'site_id' => $site_id ),
            array( '%s' ),
            array( '%d' )
        );
        wp_send_json_success( array( 'message' => 'Sitemap cleared' ) );
    }

    // Validate the sitemap URL
    $head = wp_remote_head( $sitemap_url, array( 'timeout' => 15, 'sslverify' => false ) );
    if ( is_wp_error( $head ) || wp_remote_retrieve_response_code( $head ) !== 200 ) {
        wp_send_json_error( 'Sitemap URL is not accessible' );
    }

    $wpdb->update(
        $sites_table,
        array( 'sitemap_url' => $sitemap_url ),
        array( 'site_id' => $site_id ),
        array( '%s' ),
        array( '%d' )
    );

    // Trigger immediate parse
    wp_schedule_single_event( time() + 3, 'bite_sitemap_parse_hook', array( $site_id ) );

    wp_send_json_success( array( 'message' => 'Sitemap saved and parsing scheduled' ) );
}
add_action( 'wp_ajax_bite_save_sitemap_url', 'bite_ajax_save_sitemap_url' );

/**
 * AJAX handler: Auto-detect sitemap for a site
 */
function bite_ajax_detect_sitemap() {
    check_ajax_referer( 'bite_sitemap_nonce', 'nonce' );

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
    $site = $wpdb->get_row( $wpdb->prepare(
        "SELECT domain FROM $sites_table WHERE site_id = %d",
        $site_id
    ) );

    if ( ! $site ) {
        wp_send_json_error( 'Site not found' );
    }

    $sitemap_url = bite_detect_sitemap( $site->domain );

    if ( ! $sitemap_url ) {
        wp_send_json_error( 'No sitemap detected. Try entering the URL manually.' );
    }

    $wpdb->update(
        $sites_table,
        array( 'sitemap_url' => $sitemap_url ),
        array( 'site_id' => $site_id ),
        array( '%s' ),
        array( '%d' )
    );

    // Trigger immediate parse
    wp_schedule_single_event( time() + 3, 'bite_sitemap_parse_hook', array( $site_id ) );

    wp_send_json_success( array(
        'message'     => 'Sitemap auto-detected: ' . $sitemap_url,
        'sitemap_url' => $sitemap_url,
    ) );
}
add_action( 'wp_ajax_bite_detect_sitemap', 'bite_ajax_detect_sitemap' );

/**
 * Cron hook: Parse sitemap for a specific site
 */
function bite_cron_parse_sitemap( $site_id ) {
    $result = bite_run_sitemap_parse_for_site( $site_id );
    if ( is_wp_error( $result ) ) {
        error_log( 'BITE Sitemap Parse Cron Error (site ' . $site_id . '): ' . $result->get_error_message() );
    } else {
        error_log( 'BITE Sitemap Parse Cron Complete (site ' . $site_id . '): ' . $result['total'] . ' URLs parsed' );
    }
}
add_action( 'bite_sitemap_parse_hook', 'bite_cron_parse_sitemap', 10, 1 );
