<?php
/**
 * Reporting Functions
 *
 * This file contains functions for querying the database to display data on the dashboard.
 *
 * @package BITE-theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Get aggregated keyword data for the dashboard table.
 *
 * @param int    $site_id    The site ID to query.
 * @param string $start_date Start date in Y-m-d format.
 * @param string $end_date   End date in Y-m-d format.
 * @param string $device     Device to filter by ('all', 'desktop', 'mobile', 'tablet').
 * @return array|null An array of data rows, or null on failure.
 */
function bite_get_data_for_table( $site_id, $start_date, $end_date, $device = 'all' ) {
    global $wpdb;

    $site_id = absint( $site_id );
    if ( ! $site_id ) {
        return null;
    }

    $metrics_table = $wpdb->prefix . 'bite_metrics_site_' . $site_id;
    $keywords_table = $wpdb->prefix . 'bite_keywords';
    
    $params = array( $start_date, $end_date );
    $device_sql = '';
    
    if ( $device !== 'all' ) {
        $device_sql = " AND m.device = %s";
        $params[] = $device;
    }

    // This query gets the discoverable keywords
    $sql = $wpdb->prepare(
        "SELECT
            k.keyword,
            SUM(m.clicks) as total_clicks,
            SUM(m.impressions) as total_impressions,
            AVG(m.ctr) as avg_ctr,
            AVG(m.position) as avg_position
        FROM
            $metrics_table m
        JOIN
            $keywords_table k ON m.keyword_id = k.keyword_id
        WHERE
            m.date BETWEEN %s AND %s
            $device_sql
        GROUP BY
            k.keyword_id, k.keyword
        ORDER BY
            total_clicks DESC
        LIMIT 5000",
        $params
    );

    $results = $wpdb->get_results( $sql, ARRAY_A );
    return $results;
}

/**
 * Get aggregated data for the dashboard line chart.
 *
 * @param int    $site_id    The site ID to query.
 * @param string $start_date Start date in Y-m-d format.
 * @param string $end_date   End date in Y-m-d format.
 * @param string $device     Device to filter by ('all', 'desktop', 'mobile', 'tablet').
 * @return array|null An array of data, or null on failure.
 */
function bite_get_data_for_chart( $site_id, $start_date, $end_date, $device = 'all' ) {
    global $wpdb;

    $site_id = absint( $site_id );
    if ( ! $site_id ) return null;

    $summary_table = $wpdb->prefix . 'bite_daily_summary';
    $metrics_table = $wpdb->prefix . 'bite_metrics_site_' . $site_id;
    
    // --- 1. Get the TRUE TOTALS ---
    $params = array( $start_date, $end_date, $site_id );
    $device_sql = '';
    if ( $device !== 'all' ) {
        $device_sql = " AND device = %s";
        $params[] = $device;
    }
    $sql_totals = $wpdb->prepare(
        "SELECT
            date,
            SUM(total_clicks) as total_clicks,
            SUM(total_impressions) as total_impressions
        FROM $summary_table
        WHERE date BETWEEN %s AND %s AND site_id = %d
        $device_sql
        GROUP BY date
        ORDER BY date ASC",
        $params
    );
    $totals_results = $wpdb->get_results( $sql_totals, OBJECT_K ); // Index by date

    // --- 2. Get the DISCOVERABLE TOTALS ---
    $discoverable_params = array( $start_date, $end_date );
    if ( $device !== 'all' ) {
        $discoverable_sql = " AND device = %s";
        $discoverable_params[] = $device;
    } else {
        $discoverable_sql = "";
    }
    $discoverable_results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT date, SUM(clicks) as discoverable_clicks, SUM(impressions) as discoverable_impressions
             FROM $metrics_table
             WHERE date BETWEEN %s AND %s $discoverable_sql
             GROUP BY date ORDER BY date ASC",
            $discoverable_params
        ), OBJECT_K // Index by date
    );

    // --- 3. Combine the data ---
    $chart_data = array(
        'labels'                 => array(),
        'total_clicks'           => array(), // <-- CHANGED
        'anonymized_clicks'      => array(),
        'total_impressions'      => array(), // <-- CHANGED
        'anonymized_impressions' => array(),
    );
    
    if ( empty( $totals_results ) ) {
        return $chart_data; // No data at all
    }

    foreach ( $totals_results as $date => $total_row ) {
        $chart_data['labels'][] = date( 'd-m-Y', strtotime( $date ) );
        
        $total_clicks = (int) $total_row->total_clicks;
        $total_impressions = (int) $total_row->total_impressions;
        
        $discoverable_clicks = 0;
        $discoverable_impressions = 0;
        
        if( isset( $discoverable_results[$date] ) ) {
            $discoverable_clicks = (int) $discoverable_results[$date]->discoverable_clicks;
            $discoverable_impressions = (int) $discoverable_results[$date]->discoverable_impressions;
        }

        $anonymized_clicks = $total_clicks - $discoverable_clicks;
        $anonymized_impressions = $total_impressions - $discoverable_impressions;

        $chart_data['total_clicks'][] = $total_clicks; // <-- CHANGED
        $chart_data['anonymized_clicks'][] = ( $anonymized_clicks > 0 ) ? $anonymized_clicks : 0;
        $chart_data['total_impressions'][] = $total_impressions; // <-- CHANGED
        $chart_data['anonymized_impressions'][] = ( $anonymized_impressions > 0 ) ? $anonymized_impressions : 0;
    }

    return $chart_data;
}

/**
 * Get aggregated data for the Opportunity Finder tool.
 *
 * @param int    $source_site_id The "source" site.
 * @param int    $target_site_id The "target" site.
 * @param string $start_date Start date in Y-m-d format.
 * @param string $end_date   End date in Y-m-d format.
 * @param string $device     Device to filter by.
 * @return array|null An array of data rows, or null on failure.
 */
function bite_get_opportunity_finder_data( $source_site_id, $target_site_id, $start_date, $end_date, $device = 'all' ) {
    global $wpdb;

    $source_site_id = absint( $source_site_id );
    $target_site_id = absint( $target_site_id );
    if ( ! $source_site_id || ! $target_site_id ) {
        return null;
    }

    $source_metrics_table = $wpdb->prefix . 'bite_metrics_site_' . $source_site_id;
    $target_metrics_table = $wpdb->prefix . 'bite_metrics_site_' . $target_site_id;
    $keywords_table = $wpdb->prefix . 'bite_keywords';

    $params = array( $start_date, $end_date );
    $device_sql = '';
    if ( $device !== 'all' ) {
        $device_sql = " AND device = %s";
        $params[] = $device;
    }
    
    // Add params for the sub-queries
    $params_source = $params;
    $params_target = $params;

    // This is the core logic.
    // 1. Find all good keywords the SOURCE site ranks for.
    // 2. Find all keywords the TARGET site ranks for.
    // 3. Select keywords from SOURCE that ARE NOT IN TARGET.
    $sql = $wpdb->prepare(
        "SELECT
            k.keyword,
            m_source.total_clicks,
            m_source.total_impressions,
            m_source.avg_position
        FROM
            (
                -- Step 1: Get all keywords for the SOURCE site
                SELECT
                    keyword_id,
                    SUM(clicks) as total_clicks,
                    SUM(impressions) as total_impressions,
                    AVG(position) as avg_position
                FROM $source_metrics_table
                WHERE date BETWEEN %s AND %s $device_sql
                GROUP BY keyword_id
            ) AS m_source
        JOIN
            $keywords_table k ON m_source.keyword_id = k.keyword_id
        WHERE
            m_source.avg_position < 20 AND m_source.total_impressions > 50 -- Filter for keywords that are actually good
        AND NOT EXISTS
            (
                -- Step 2 & 3: Find keywords that ARE NOT IN the TARGET site's list
                SELECT 1
                FROM $target_metrics_table m_target
                WHERE m_target.keyword_id = m_source.keyword_id
                AND m_target.date BETWEEN %s AND %s $device_sql
            )
        ORDER BY
            m_source.total_impressions DESC
        LIMIT 500",
        array_merge( $params_source, $params_target ) // Combine all params
    );
    
    $results = $wpdb->get_results( $sql, ARRAY_A );
    return $results;
}

/**
 * Get aggregated keyword data for the Global Champions tool.
 *
 * This function queries all sites in a given niche and aggregates their data.
 *
 * @param int    $niche_id   The niche ID to query.
 * @param string $start_date Start date in Y-m-d format.
 * @param string $end_date   End date in Y-m-d format.
 * @param string $device     Device to filter by.
 * @return array|null An array of data rows, or null on failure.
 */
function bite_get_global_champions_data( $niche_id, $start_date, $end_date, $device = 'all' ) {
    global $wpdb;

    $niche_id = absint( $niche_id );
    if ( ! $niche_id ) {
        return null;
    }

    // 1. Get all sites in this niche
    $sites_table = $wpdb->prefix . 'bite_sites';
    $sites_in_niche = $wpdb->get_col( $wpdb->prepare( "SELECT site_id FROM $sites_table WHERE niche_id = %d", $niche_id ) );

    if ( empty( $sites_in_niche ) ) {
        return array(); // No sites in this niche
    }

    // 2. Build a large UNION ALL query
    $union_sql_parts = array();
    $sql_params = array();
    $device_sql = '';

    if ( $device !== 'all' ) {
        $device_sql = " AND device = %s";
    }

    foreach ( $sites_in_niche as $site_id ) {
        $metrics_table = $wpdb->prefix . 'bite_metrics_site_' . $site_id;
        $union_sql_parts[] = "(
            SELECT %d as site_id, keyword_id, clicks, impressions, ctr, position 
            FROM $metrics_table 
            WHERE date BETWEEN %s AND %s
            $device_sql
        )";
        
        // Add parameters for this part of the query
        $sql_params[] = $site_id;
        $sql_params[] = $start_date;
        $sql_params[] = $end_date;
        if ( $device !== 'all' ) {
            $sql_params[] = $device;
        }
    }
    
    $unioned_query = implode( ' UNION ALL ', $union_sql_parts );
    $keywords_table = $wpdb->prefix . 'bite_keywords';

    // 3. This is the final query.
    // It groups the combined data from all sites by keyword.
    $final_sql = "
        SELECT
            k.keyword,
            SUM(unioned_data.clicks) as total_clicks,
            SUM(unioned_data.impressions) as total_impressions,
            AVG(unioned_data.position) as avg_position,
            COUNT(DISTINCT unioned_data.site_id) as site_count
        FROM
            ( $unioned_query ) AS unioned_data
        JOIN
            $keywords_table k ON unioned_data.keyword_id = k.keyword_id
        GROUP BY
            k.keyword_id, k.keyword
        ORDER BY
            total_clicks DESC
        LIMIT 500
    ";
    
    $results = $wpdb->get_results( $wpdb->prepare( $final_sql, $sql_params ), ARRAY_A );

    return $results;
}

/**
 * Get data for the Emerging Trends tool.
 *
 * Compares the first half of a date range to the second half.
 *
 * @param int    $site_id    The site ID to query.
 * @param string $start_date Start date in Y-m-d format.
 * @param string $end_date   End date in Y-m-d format.
 * @param string $device     Device to filter by.
 * @return array|null An array of data rows, or null on failure.
 */
function bite_get_emerging_trends_data( $site_id, $start_date, $end_date, $device = 'all' ) {
    global $wpdb;

    $site_id = absint( $site_id );
    if ( ! $site_id ) {
        return null;
    }

    // 1. Calculate the date periods
    $start_timestamp = strtotime( $start_date );
    $end_timestamp = strtotime( $end_date );
    $total_days = ( $end_timestamp - $start_timestamp ) / 86400;
    
    if ( $total_days < 1 ) {
        return array(); // Not a valid range
    }

    $mid_point_timestamp = $start_timestamp + ( floor( $total_days / 2 ) * 86400 );
    $period_1_start = $start_date;
    $period_1_end = date( 'Y-m-d', $mid_point_timestamp );
    $period_2_start = date( 'Y-m-d', $mid_point_timestamp + 86400 );
    $period_2_end = $end_date;

    if( $period_2_start > $period_2_end ) {
        $period_2_start = $period_2_end;
    }

    $metrics_table = $wpdb->prefix . 'bite_metrics_site_' . $site_id;
    $keywords_table = $wpdb->prefix . 'bite_keywords';

    // 2. Build the device filter
    $params = array();
    $device_sql = '';
    if ( $device !== 'all' ) {
        $device_sql = " AND device = %s";
        $params[] = $device;
    }

    // 3. Build the two sub-queries
    $params_p1 = array_merge( array( $period_1_start, $period_1_end ), $params );
    $params_p2 = array_merge( array( $period_2_start, $period_2_end ), $params );
    
    // 4. This is a complex query using subqueries (WITH)
    // It gets the data for both periods, then joins them.
    $sql = "
        WITH Period1 AS (
            SELECT
                keyword_id,
                SUM(impressions) as p1_impressions,
                SUM(clicks) as p1_clicks
            FROM $metrics_table
            WHERE date BETWEEN %s AND %s $device_sql
            GROUP BY keyword_id
        ),
        Period2 AS (
            SELECT
                keyword_id,
                SUM(impressions) as p2_impressions,
                SUM(clicks) as p2_clicks
            FROM $metrics_table
            WHERE date BETWEEN %s AND %s $device_sql
            GROUP BY keyword_id
        )
        SELECT
            k.keyword,
            COALESCE(p1.p1_impressions, 0) as old_impressions,
            COALESCE(p2.p2_impressions, 0) as new_impressions,
            (COALESCE(p2.p2_impressions, 0) - COALESCE(p1.p1_impressions, 0)) as impressions_change,
            
            COALESCE(p1.p1_clicks, 0) as old_clicks,
            COALESCE(p2.p2_clicks, 0) as new_clicks,
            (COALESCE(p2.p2_clicks, 0) - COALESCE(p1.p1_clicks, 0)) as clicks_change
        FROM
            $keywords_table k
        LEFT JOIN
            Period1 p1 ON k.keyword_id = p1.keyword_id
        LEFT JOIN
            Period2 p2 ON k.keyword_id = p2.keyword_id
        WHERE
            (p1.p1_impressions > 0 OR p2.p2_impressions > 0) -- Must have impressions in at least one period
            AND (COALESCE(p2.p2_impressions, 0) - COALESCE(p1.p1_impressions, 0)) != 0 -- Only show keywords that changed
        ORDER BY
            impressions_change DESC
        LIMIT 500
    ";
    
    $results = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params_p1, $params_p2 ) ), ARRAY_A );
    return $results;
}

/**
 * Get aggregated keyword data for the Keyword Explorer tool.
 *
 * @param int    $niche_id     The niche ID to query.
 * @param string $start_date   Start date in Y-m-d format.
 * @param string $end_date     End date in Y-m-d format.
 * @param string $device       Device to filter by.
 * @param string $seed_keyword The keyword to search for.
 * @return array|null An array of data rows, or null on failure.
 */
function bite_get_keyword_explorer_data( $niche_id, $start_date, $end_date, $device = 'all', $seed_keyword = '' ) {
    global $wpdb;

    $niche_id = absint( $niche_id );
    if ( ! $niche_id || empty( $seed_keyword ) ) {
        return null;
    }

    // 1. Get all sites in this niche
    $sites_table = $wpdb->prefix . 'bite_sites';
    $sites_in_niche = $wpdb->get_col( $wpdb->prepare( "SELECT site_id FROM $sites_table WHERE niche_id = %d", $niche_id ) );

    if ( empty( $sites_in_niche ) ) {
        return array(); // No sites in this niche
    }

    // 2. Build a large UNION ALL query
    $union_sql_parts = array();
    $sql_params = array();
    $device_sql = '';

    if ( $device !== 'all' ) {
        $device_sql = " AND device = %s";
    }

    foreach ( $sites_in_niche as $site_id ) {
        $metrics_table = $wpdb->prefix . 'bite_metrics_site_' . $site_id;
        $union_sql_parts[] = "(
            SELECT %d as site_id, keyword_id, clicks, impressions, ctr, position 
            FROM $metrics_table 
            WHERE date BETWEEN %s AND %s
            $device_sql
        )";
        
        $sql_params[] = $site_id;
        $sql_params[] = $start_date;
        $sql_params[] = $end_date;
        if ( $device !== 'all' ) {
            $sql_params[] = $device;
        }
    }
    
    $unioned_query = implode( ' UNION ALL ', $union_sql_parts );
    $keywords_table = $wpdb->prefix . 'bite_keywords';
    
    // Prepare the LIKE term
    $like_term = '%' . $wpdb->esc_like( $seed_keyword ) . '%';

    // 3. This is the final query.
    // It groups the combined data and filters by the seed keyword.
    $final_sql = "
        SELECT
            k.keyword,
            SUM(unioned_data.clicks) as total_clicks,
            SUM(unioned_data.impressions) as total_impressions,
            AVG(unioned_data.position) as avg_position,
            COUNT(DISTINCT unioned_data.site_id) as site_count
        FROM
            ( $unioned_query ) AS unioned_data
        JOIN
            $keywords_table k ON unioned_data.keyword_id = k.keyword_id
        WHERE
            k.keyword LIKE %s
        GROUP BY
            k.keyword_id, k.keyword
        ORDER BY
            total_clicks DESC
        LIMIT 2000
    ";
    
    // Add the final LIKE parameter
    $sql_params[] = $like_term;
    
    $results = $wpdb->get_results( $wpdb->prepare( $final_sql, $sql_params ), ARRAY_A );

    return $results;
}

/**
 * Get data for the CTR Efficiency Report (Proportional Clicks).
 * (This version calculates the insight text)
 *
 * @param int    $site_id    The site ID to query.
 * @param string $start_date Start date in Y-m-d format.
 * @param string $end_date   End date in Y-m-d format.
 * @param string $device     Device to filter by.
 * @return array|null An array of data, or null on failure.
 */
function bite_get_ctr_efficiency_data( $site_id, $start_date, $end_date, $device = 'all' ) {
    // We re-use our main chart function, as it does all the hard data fetching
    $chart_data = bite_get_data_for_chart( $site_id, $start_date, $end_date, $device );

    if ( ! $chart_data || empty( $chart_data['labels'] ) ) {
        return null;
    }

    $proportional_data = array(
        'labels'               => $chart_data['labels'],
        'anonymized_clicks_pct'  => array(),
        'insight_text'         => '', // We will generate this now
    );
    
    // We need to calculate the *period totals* for the insight box
    $period_total_clicks = 0;
    $period_anonymized_clicks = 0;

    for ( $i = 0; $i < count( $chart_data['labels'] ); $i++ ) {
        $a_clicks = $chart_data['anonymized_clicks'][$i];
        $t_clicks = $chart_data['total_clicks'][$i];
        
        $day_total_clicks = $t_clicks; // Already the total

        // Add to period totals
        $period_total_clicks += $day_total_clicks;
        $period_anonymized_clicks += $a_clicks;
        
        // --- THE (float) FIX ---
        $anonymized_clicks_pct = ( $day_total_clicks > 0 ) ? ( (float)$a_clicks / (float)$day_total_clicks ) * 100 : 0;
        
        $proportional_data['anonymized_clicks_pct'][] = number_format( $anonymized_clicks_pct, 1 );
    }
    
    // Generate the "Insight" text
    $avg_anonymized_clicks_pct = ( $period_total_clicks > 0 ) ? ( (float)$period_anonymized_clicks / (float)$period_total_clicks ) * 100 : 0;
    
    // Call our new helper function to get the text
    $proportional_data['insight_text'] = bite_get_insight_text( $avg_anonymized_clicks_pct );

    return $proportional_data;
}

/**
 * Helper function to generate the insight text based on the avg percentage.
 *
 * @param float $avg_pct The average percentage of anonymized clicks.
 * @return string The formatted insight text.
 */
function bite_get_insight_text( $avg_pct ) {
    $avg_pct_formatted = '<strong>' . number_format( $avg_pct, 1 ) . '%</strong>';
    
    if ( $avg_pct <= 15 ) {
        return sprintf(
            esc_html__( 'Insight: For this period, "Anonymized" queries made up only %s of your total clicks. Your site\'s performance is overwhelmingly driven by your main "discoverable" head terms.', 'bite-theme' ),
            $avg_pct_formatted
        );
    } elseif ( $avg_pct > 15 && $avg_pct <= 35 ) {
        return sprintf(
            esc_html__( 'Insight: For this period, "Anonymized" queries made up %s of your total clicks. This is a healthy, balanced mix of both long-tail and head-term traffic.', 'bite-theme' ),
            $avg_pct_formatted
        );
    } elseif ( $avg_pct > 35 && $avg_pct <= 55 ) {
        return sprintf(
            esc_html__( 'Insight: "Anonymized" queries made up %s of your total clicks. This is a **strong** sign that your site is very effective at capturing high-intent, long-tail searches.', 'bite-theme' ),
            $avg_pct_formatted
        );
    } else { // Over 55%
        return sprintf(
            esc_html__( 'Insight: This is a "Gold Mine"! "Anonymized" queries made up an exceptional %s of your total clicks. Your site structure is perfectly optimized for capturing high-intent, long-tail searches.', 'bite-theme' ),
            $avg_pct_formatted
        );
    }
}
