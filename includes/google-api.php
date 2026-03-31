<?php
/**
 * Google API & Cron Job Logic
 *
 * Implements the "Two-Cron" system:
 * 1. A self-spawning, 150-second, "Discovery-first" backfill queue with error emailing and memory logging.
 * 2. A once-daily (6am UTC) update for "complete" sites with error emailing and memory logging.
 *
 * @package BITE-theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// === CRON A: THE BACKFILL QUEUE (Self-Spawning, 150-Second Loop) ===

/**
 * 1A. The "Starter" - Triggers the backfill queue if it's not running.
 */
function bite_trigger_backfill_queue() {
    global $wpdb;
    // Don't schedule if already scheduled OR if it's currently marked as running (transient)
    if ( wp_next_scheduled( 'bite_backfill_hook' ) || get_transient( 'bite_backfill_running' ) ) {
        return;
    }
    $sites_table = $wpdb->prefix . 'bite_sites';
    // Check if any sites need initial backfill or catching up
    $needs_work = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT site_id FROM $sites_table WHERE backfill_status = %s OR backfill_status = %s LIMIT 1",
            'pending',
            'in_progress'
        )
    );
    if ( $needs_work ) {
        // Schedule to run almost immediately
        wp_schedule_single_event( time() + 10, 'bite_backfill_hook' );
    }
}
add_action( 'init', 'bite_trigger_backfill_queue' );


/**
 * 2A. The "Engine" - Processes in a 150-second loop and re-schedules itself.
 */
function bite_run_backfill_chunk() {
    global $wpdb;

    // Set a transient to prevent overlaps and signal that the process is active
    set_transient( 'bite_backfill_running', true, 600 ); // 10 min expiry safety

    $time_limit = time() + 150; // Set the time limit for this run (2.5 minutes)
    $sites_table = $wpdb->prefix . 'bite_sites';
    $more_work_to_do = false; // Flag to check if we need to re-schedule at the end

    // --- Start Processing Loop (runs for max 150 seconds) ---
    while ( time() < $time_limit ) {

        // Prioritize sites already in progress
        $site_to_process = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $sites_table WHERE backfill_status = %s LIMIT 1",
                'in_progress'
            )
        );

        // If no site is in progress, find a pending one
        if ( ! $site_to_process ) {
            $site_to_process = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM $sites_table WHERE backfill_status = %s LIMIT 1",
                    'pending'
                )
            );

            // If we found a pending site, prepare it for processing
            if ( $site_to_process ) {
                // Check if a start date is already set (by the daily update)
                if ( ! empty( $site_to_process->backfill_next_date ) ) {
                    // Start date exists, just switch status to in_progress
                    error_log( 'BITE Queue: Found "pending" site ID ' . $site_to_process->site_id . ' with start date ' . $site_to_process->backfill_next_date . '. Resuming update.' );
                    $wpdb->update(
                        $sites_table,
                        array( 'backfill_status' => 'in_progress' ),
                        array( 'site_id' => $site_to_process->site_id )
                    );
                    $site_to_process->backfill_status = 'in_progress'; // Update local object
                } else {
                    // No start date - must be a new site. Run Discovery.
                    error_log( 'BITE Queue: Found new "pending" site ID ' . $site_to_process->site_id . '. Running Discovery call...' );
                    $first_day = bite_find_first_data_day( $site_to_process->gsc_property, $site_to_process->site_id );

                    // Handle Discovery API Error
                    if( is_wp_error($first_day) ) {
                        $error_message = 'BITE Queue Error: Discovery call failed for site ID ' . $site_to_process->site_id . '. Error: ' . $first_day->get_error_message();
                        error_log($error_message);
                         // *** EMAIL NOTIFICATION ***
                        $admin_email = get_option('admin_email');
                        wp_mail(
                            $admin_email,
                            '[BITE System Alert] GSC Discovery API Call Failed',
                            $error_message . "\n\nThe backfill queue might be stalled. Please check API credentials and logs."
                        );
                        $more_work_to_do = false; // Don't reschedule immediately after critical failure
                        break; // Exit while loop
                    }

                    // Handle Case: No data found in GSC at all
                    if( $first_day === null ) {
                        error_log( 'BITE Queue: No data found via Discovery for site ID ' . $site_to_process->site_id . '. Marking as complete.' );
                        $wpdb->update( $sites_table, array( 'backfill_status' => 'complete', 'backfill_next_date' => date('Y-m-d') ), array( 'site_id' => $site_to_process->site_id ) );
                        $site_to_process = null; // Prevent further processing in this loop
                        $more_work_to_do = true; // Still need to check if OTHER pending sites exist
                        continue; // Continue while loop to look for another site
                    }

                    // Discovery successful, update site status and start date
                    $wpdb->update(
                        $sites_table,
                        array( 'backfill_status' => 'in_progress', 'backfill_next_date' => $first_day ),
                        array( 'site_id' => $site_to_process->site_id )
                    );
                    $site_to_process->backfill_status = 'in_progress';
                    $site_to_process->backfill_next_date = $first_day;
                    error_log( 'BITE Queue: Discovery successful for site ID ' . $site_to_process->site_id . '. First day of data is: ' . $first_day );
                }
            }
        }

        // If after all checks, there's no site to process, exit the loop
        if ( ! $site_to_process ) {
            $more_work_to_do = false; // No pending or in_progress sites found
            break; // Exit while loop
        }

        // --- We have an "in_progress" site, process ONE day ---
        $site_id = $site_to_process->site_id;
        $gsc_property = $site_to_process->gsc_property;
        $target_end_date = date( 'Y-m-d', strtotime( '-2 days' ) );
        $date_to_fetch = $site_to_process->backfill_next_date;

        // Check if this site is already caught up for this run
        if ( $date_to_fetch > $target_end_date ) {
            $wpdb->update( $sites_table, array( 'backfill_status' => 'complete', 'backfill_next_date' => date('Y-m-d') ), array( 'site_id' => $site_id ) );
            error_log( 'BITE Queue: Backfill/Catch-up complete for site ID ' . $site_id );
            // Check if there are OTHER pending sites before deciding to stop
            $other_pending_sites = $wpdb->get_var( $wpdb->prepare( "SELECT site_id FROM $sites_table WHERE backfill_status = %s LIMIT 1", 'pending' ) );
            $more_work_to_do = (bool) $other_pending_sites; // Continue if other sites are pending
            if( ! $more_work_to_do ) {
                 break; // No more pending sites, exit the while loop
            }
            $site_to_process = null; // Clear current site so the loop fetches the next pending one
            continue; // Continue while loop to find next pending site
        }

        // If we reached here, there's work to do for the current site
        $more_work_to_do = true; // Signal that we should reschedule at the end
        $all_rows_for_day = array();
        $all_totals_for_day = array();
        $api_error_occurred = false; // Flag for API errors within the device loop

        // --- Fetch Data for the Day (D/M/T Totals & Keywords) ---
        foreach ( array( 'DESKTOP', 'MOBILE', 'TABLET' ) as $device ) {
            $device_lower = strtolower($device);

            // 1. Get TOTALS for this device/day
            $totals_data = bite_fetch_gsc_totals( $gsc_property, $date_to_fetch, $date_to_fetch, $device, $site_id );
            if ( is_wp_error( $totals_data ) ) {
                $error_message = 'BITE Queue Error: Failed to fetch TOTALS for site ID ' . $site_id . ' (Device: ' . $device . ') on date ' . $date_to_fetch . '. Error: ' . $totals_data->get_error_message();
                error_log( $error_message );
                // *** EMAIL NOTIFICATION ***
                $admin_email = get_option('admin_email');
                wp_mail(
                   $admin_email,
                   '[BITE System Alert] GSC Totals API Fetch Failed',
                   $error_message . "\n\nThe backfill queue might be stalled. Please check API credentials and logs."
                );
                $api_error_occurred = true; // Set flag
                break; // Exit foreach device loop
            }
            // Store totals if found
            if( !empty($totals_data['rows']) ) {
                $all_totals_for_day[] = array(
                    'site_id' => $site_id, 'date' => $date_to_fetch, 'device' => $device_lower,
                    'total_clicks' => $totals_data['rows'][0]['clicks'],
                    'total_impressions' => $totals_data['rows'][0]['impressions'],
                    'total_ctr' => $totals_data['rows'][0]['ctr'],
                    'total_position' => $totals_data['rows'][0]['position'],
                );
            }

            // 2. Get KEYWORDS for this device/day
            $data = bite_fetch_gsc_data( $gsc_property, $date_to_fetch, $date_to_fetch, $device, $site_id );
            if ( is_wp_error( $data ) ) {
                $error_message = 'BITE Queue Error: Failed to fetch KEYWORDS for site ID ' . $site_id . ' (Device: ' . $device . ') on date ' . $date_to_fetch . '. Error: ' . $data->get_error_message();
                error_log( $error_message );
                // *** EMAIL NOTIFICATION ***
                 $admin_email = get_option('admin_email');
                 wp_mail(
                    $admin_email,
                    '[BITE System Alert] GSC Keywords API Fetch Failed',
                    $error_message . "\n\nThe backfill queue might be stalled. Please check API credentials and logs."
                 );
                $api_error_occurred = true; // Set flag
                break; // Exit foreach device loop
            }
            // Store keyword rows if found, adding device info
            if ( ! empty( $data['rows'] ) ) {
                foreach( $data['rows'] as &$row ) {
                    $row['device'] = $device_lower; // Add device identifier
                }
                $all_rows_for_day = array_merge( $all_rows_for_day, $data['rows'] );
            }
        } // end foreach device loop

        // If an API error occurred in the device loop, stop processing this site for this chunk
        if( $api_error_occurred ) {
            $more_work_to_do = false; // Don't immediately reschedule after API failure
            break; // Exit while loop
        }

        // --- Insert Data for the Day ---
        $db_insert_error = false;
        if ( ! empty( $all_totals_for_day ) ) {
            $summary_result = bite_insert_daily_summary( $all_totals_for_day );
             if( is_wp_error($summary_result) ) {
                 $error_message = 'BITE Queue Error: Failed to insert summary data for site ID ' . $site_id . ' on date ' . $date_to_fetch . '. Error: ' . $summary_result->get_error_message();
                 error_log($error_message);
                 // *** EMAIL NOTIFICATION ***
                 $admin_email = get_option('admin_email');
                 wp_mail(
                    $admin_email,
                    '[BITE System Alert] Database Summary Insert Failed',
                    $error_message . "\n\nThe backfill queue might be stalled. Please check database status and logs."
                 );
                 $db_insert_error = true; // Set flag
             }
        }

        if ( ! $db_insert_error && ! empty( $all_rows_for_day ) ) {
            $rows_inserted = bite_insert_gsc_data( $site_id, $all_rows_for_day, $date_to_fetch );
            if ( is_wp_error( $rows_inserted ) ) {
                 $error_message = 'BITE Queue Error: Failed to insert keyword data for site ID ' . $site_id . ' on date ' . $date_to_fetch . '. Error: ' . $rows_inserted->get_error_message();
                 error_log($error_message);
                 // *** EMAIL NOTIFICATION ***
                 $admin_email = get_option('admin_email');
                 wp_mail(
                    $admin_email,
                    '[BITE System Alert] Database Keyword Insert Failed',
                    $error_message . "\n\nThe backfill queue might be stalled. Please check database status and logs."
                 );
                 $db_insert_error = true; // Set flag
            } else {
                 error_log( "BITE Queue: Site ID $site_id, Date $date_to_fetch. Inserted $rows_inserted keyword rows (from 3 device queries)." );
            }
        } elseif ( !$db_insert_error ) {
             error_log( "BITE Queue: Site ID $site_id, Date $date_to_fetch. No keyword data found to insert." );
        }

        // If a DB error occurred, stop processing for this run
        if( $db_insert_error ) {
             $more_work_to_do = false; // Don't reschedule immediately
             break; // Exit while loop
        }

        // --- Success for this day! Move to the next day ---
        $next_start_date = date( 'Y-m-d', strtotime( $date_to_fetch . ' +1 day' ) );
        $wpdb->update( $sites_table, array( 'backfill_next_date' => $next_start_date ), array( 'site_id' => $site_id ) );
        // Update local object so the *next iteration of this while loop* uses the new date
        if($site_to_process) {
            $site_to_process->backfill_next_date = $next_start_date;
        }

    } // --- End Processing Loop (while time() < $time_limit) ---

    // If there's potentially more work (either for this site or others), reschedule
    if ( $more_work_to_do ) {
        wp_schedule_single_event( time(), 'bite_backfill_hook' ); // Schedule to run again immediately
    }

    // *** PEAK MEMORY LOGGING for Backfill ***
    $peak_memory_backfill = memory_get_peak_usage(true); // true = real usage
    $current_peak_data_backfill = get_option('bite_peak_memory_data_backfill', array('peak' => 0, 'time' => 0));
    // Check if current run's peak is higher than the stored peak
    if ($peak_memory_backfill > $current_peak_data_backfill['peak']) {
        update_option('bite_peak_memory_data_backfill', array('peak' => $peak_memory_backfill, 'time' => time()));
        error_log( sprintf('BITE Backfill Chunk: New peak memory recorded: %.2f MB.', $peak_memory_backfill / 1024 / 1024) ); // Log the new peak
    } else {
         error_log( sprintf('BITE Backfill Chunk: Run complete. Peak Memory: %.2f MB.', $peak_memory_backfill / 1024 / 1024) ); // Log current run's peak
    }

    // Clear the 'running' transient
    delete_transient( 'bite_backfill_running' );
}
add_action( 'bite_backfill_hook', 'bite_run_backfill_chunk' );


// === CRON B: THE ONCE-DAILY UPDATE (6am UTC) ===

/**
 * 1B. Schedule the once-daily update event.
 */
function bite_schedule_daily_update_event() {
    if ( ! wp_next_scheduled( 'bite_daily_update_hook' ) ) {
        // Schedule for 6am UTC tomorrow
        $six_am_utc = strtotime( 'tomorrow 6:00 AM UTC' );
        wp_schedule_event( $six_am_utc, 'daily', 'bite_daily_update_hook' );
    }
}
add_action( 'init', 'bite_schedule_daily_update_event' );

/**
 * 2B. The function that runs at 6am UTC.
 * Checks 'complete' sites, finds the last date, and flags sites needing updates
 * by setting their status to 'pending' and updating 'backfill_next_date'.
 */
function bite_run_daily_update() {
    global $wpdb;

    // Safety check: Don't run if the backfill process is somehow still active
    if ( get_transient( 'bite_backfill_running' ) ) {
        error_log( 'BITE Daily Update: Skipped. Backfill process is currently running.' );
        return;
    }

    $sites_table = $wpdb->prefix . 'bite_sites';

    // Get all sites currently marked as 'complete'
    $complete_sites = $wpdb->get_results(
        // Only select columns we need
        $wpdb->prepare( "SELECT site_id, backfill_next_date FROM $sites_table WHERE backfill_status = %s", 'complete' )
    );

    if ( ! $complete_sites ) {
        error_log( 'BITE Daily Update: No sites marked as "complete" found.' );
        // *** UPDATED: PEAK MEMORY LOGGING for Daily (even if no sites) ***
        $peak_memory_daily_none = memory_get_peak_usage(true);
        $current_peak_data_daily_none = get_option('bite_peak_memory_data_daily', array('peak' => 0, 'time' => 0));
        // Check if current run's peak is higher than the stored peak
        if ($peak_memory_daily_none > $current_peak_data_daily_none['peak']) {
            update_option('bite_peak_memory_data_daily', array('peak' => $peak_memory_daily_none, 'time' => time()));
             error_log( sprintf('BITE Daily Update: New peak memory recorded (no sites run): %.2f MB.', $peak_memory_daily_none / 1024 / 1024) );
        } else {
             error_log( sprintf('BITE Daily Update: Run complete (no sites). Peak Memory: %.2f MB.', $peak_memory_daily_none / 1024 / 1024) );
        }
        return; // No sites to check
    }

    error_log( 'BITE Daily Update: Starting daily check for ' . count( $complete_sites ) . ' completed sites.' );

    $target_end_date = date( 'Y-m-d', strtotime( '-2 days' ) ); // The latest date GSC data is available for

    foreach ( $complete_sites as $site ) {
        $site_id = $site->site_id;
        $metrics_table = $wpdb->prefix . 'bite_metrics_site_' . $site_id;

        // Find the MAX date directly from the site's metrics table
        // Use backticks around table name for safety
        $last_day_in_db = $wpdb->get_var( "SELECT MAX(date) FROM `$metrics_table`" );

        // If the table is empty, default to fetching from 16 months ago (safety check)
        if ( ! $last_day_in_db ) {
            $last_day_in_db = date( 'Y-m-d', strtotime( '-16 months' ) );
            error_log( "BITE Daily Update: Warning! Site ID $site_id marked complete but has no data. Setting check start date to $last_day_in_db." );
        }

        // Calculate the first day we NEED data for
        $next_day_needed = date( 'Y-m-d', strtotime( $last_day_in_db . ' +1 day' ) );

        // Compare with the target date
        if ( $next_day_needed <= $target_end_date ) {
            // This site needs an update! Flag it for the backfill processor.
            $update_result = $wpdb->update(
                $sites_table,
                array(
                    'backfill_status'     => 'pending', // Mark for processing
                    'backfill_next_date'  => $next_day_needed // Tell processor where to start
                ),
                array( 'site_id' => $site_id ) // Where condition
            );

            if ( $update_result !== false ) {
                error_log( "BITE Daily Update: Site ID $site_id needs update from $next_day_needed to $target_end_date. Flagged for processing." );
                // Trigger the backfill queue immediately
                if (!wp_next_scheduled('bite_backfill_hook') && !get_transient('bite_backfill_running')) {
                    wp_schedule_single_event( time() + 5, 'bite_backfill_hook' );
                     error_log( "BITE Daily Update: Triggered backfill queue for site ID $site_id." );
                }
            } else {
                 // *** EMAIL NOTIFICATION for flagging failure ***
                $error_message = "BITE Daily Update Error: Failed to flag Site ID $site_id for update. DB Error: " . $wpdb->last_error;
                error_log( $error_message );
                $admin_email = get_option('admin_email');
                wp_mail(
                    $admin_email,
                    '[BITE System Alert] Failed to Queue Daily Update',
                    $error_message . "\n\nPlease check the BITE system and server logs."
                );
            }
        } else {
             // Site is already up-to-date, update 'backfill_next_date' to today to mark check completed
             $wpdb->update( $sites_table, array( 'backfill_next_date' => date('Y-m-d') ), array( 'site_id' => $site_id ) );
             // Optional log: error_log( "BITE Daily Update: Site ID $site_id is already up-to-date (Last data: $last_day_in_db)." );
        }
    } // end foreach site

    // *** UPDATED: PEAK MEMORY LOGGING for Daily ***
    $peak_memory_daily = memory_get_peak_usage(true);
    $current_peak_data_daily = get_option('bite_peak_memory_data_daily', array('peak' => 0, 'time' => 0));
    // Check if current run's peak is higher than the stored peak
    if ($peak_memory_daily > $current_peak_data_daily['peak']) {
        update_option('bite_peak_memory_data_daily', array('peak' => $peak_memory_daily, 'time' => time()));
         error_log( sprintf('BITE Daily Update: New peak memory recorded: %.2f MB.', $peak_memory_daily / 1024 / 1024) );
    } else {
         error_log( sprintf('BITE Daily Update: Run complete. Peak Memory: %.2f MB.', $peak_memory_daily / 1024 / 1024) );
    }

    error_log( 'BITE Daily Update: All completed sites checked.' ); // Final log after memory check
}
// Keep the action hook registration:
add_action( 'bite_daily_update_hook', 'bite_run_daily_update' );


// === HELPER FUNCTIONS ===

/**
 * 3. The "Discovery" call. Finds the first day of data.
 * 
 * @param string $gsc_property The GSC property URL
 * @param int $site_id Optional site ID for site-specific credentials
 */
function bite_find_first_data_day( $gsc_property, $site_id = null ) {
    $start_date = date( 'Y-m-d', strtotime( '-16 months' ) );
    $end_date = date( 'Y-m-d', strtotime( '-2 days' ) );

    $access_token = bite_get_google_access_token( $site_id );
    if ( is_wp_error( $access_token ) ) return $access_token;

    $gsc_api_url = 'https://searchconsole.googleapis.com/webmasters/v3/sites/' . urlencode( $gsc_property ) . '/searchAnalytics/query';
    $request_body = json_encode( array(
        'startDate'  => $start_date,
        'endDate'    => $end_date,
        'dimensions' => array( 'date' ),
        'rowLimit'   => 10,
        'dataState'  => 'all'
    ) );
    $response = wp_remote_post( $gsc_api_url, array(
        'method'  => 'POST',
        'headers' => array( 'Authorization' => 'Bearer ' . $access_token, 'Content-Type'  => 'application/json' ),
        'body'    => $request_body, 'timeout' => 30,
    ) );
    if ( is_wp_error( $response ) ) return $response;
    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );
    if ( isset( $data['error'] ) ) return new WP_Error( 'api_error', $data['error']['message'], $data );

    if ( ! empty( $data['rows'] ) ) {
        $earliest_date = $end_date;
        foreach( $data['rows'] as $row ) {
            $row_date = $row['keys'][0];
            if( strtotime($row_date) < strtotime($earliest_date) ) {
                $earliest_date = $row_date;
            }
        }
        return $earliest_date;
    }
    return null; // No data
}


/**
 * 4. Fetches TOTALS from GSC API (no 'query' dimension).
 * 
 * @param string $gsc_property The GSC property URL
 * @param string $start_date Start date (Y-m-d)
 * @param string $end_date End date (Y-m-d)
 * @param string|null $device Device filter
 * @param int $site_id Optional site ID for site-specific credentials
 */
function bite_fetch_gsc_totals( $gsc_property, $start_date, $end_date, $device = null, $site_id = null ) {
    $access_token = bite_get_google_access_token( $site_id );
    if ( is_wp_error( $access_token ) ) return $access_token;

    $gsc_api_url = 'https://searchconsole.googleapis.com/webmasters/v3/sites/' . urlencode( $gsc_property ) . '/searchAnalytics/query';

    // For totals, group by date if range > 1 day, otherwise no dimension needed
    $dimensions = ( $start_date === $end_date ) ? array() : array( 'date' );

    $request_data = array(
        'startDate'  => $start_date,
        'endDate'    => $end_date,
        'dimensions' => $dimensions,
    );

    if( $device !== null ) {
        $request_data['dimensionFilterGroups'] = array(
            array('filters' => array(
                array( 'dimension'  => 'device', 'operator'   => 'equals', 'expression' => $device )
            ))
        );
    }

    $request_body = json_encode( $request_data );
    $response = wp_remote_post( $gsc_api_url, array(
        'method'  => 'POST',
        'headers' => array( 'Authorization' => 'Bearer ' . $access_token, 'Content-Type'  => 'application/json' ),
        'body'    => $request_body, 'timeout' => 30,
    ) );

    if ( is_wp_error( $response ) ) return $response;
    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );
    if ( isset( $data['error'] ) ) return new WP_Error( 'api_error', $data['error']['message'], $data );

    // Add date key manually for single-day queries for consistency
    if ( $start_date === $end_date && !empty($data['rows']) && empty($dimensions)) {
         if (isset($data['rows'][0])) { // Check if row exists
            $data['rows'][0]['keys'] = array( $start_date );
         }
    }

    return $data; // Returns array( 'rows' => [...] ) or empty array
}

/**
 * 5. Fetches KEYWORD data from GSC API.
 * 
 * @param string $gsc_property The GSC property URL
 * @param string $start_date Start date (Y-m-d)
 * @param string $end_date End date (Y-m-d)
 * @param string|null $device Device filter
 * @param int $site_id Optional site ID for site-specific credentials
 */
function bite_fetch_gsc_data( $gsc_property, $start_date, $end_date, $device = null, $site_id = null ) {
    $access_token = bite_get_google_access_token( $site_id );
    if ( is_wp_error( $access_token ) ) return $access_token;

    $gsc_api_url = 'https://searchconsole.googleapis.com/webmasters/v3/sites/' . urlencode( $gsc_property ) . '/searchAnalytics/query';
    $all_rows = array();
    $start_row = 0;
    $row_limit = 25000;
    // Dimensions depend on whether it's a single day or range
    $dimensions = ( $start_date === $end_date ) ? array( 'query' ) : array( 'date', 'query' );

    $request_data = array(
        'startDate'  => $start_date,
        'endDate'    => $end_date,
        'dimensions' => $dimensions,
        'rowLimit'   => $row_limit,
    );

    if( $device !== null ) {
        $request_data['dimensionFilterGroups'] = array(
            array('filters' => array(
                array( 'dimension'  => 'device', 'operator'   => 'equals', 'expression' => $device )
            ))
        );
    }

    do {
        $request_data['startRow'] = $start_row;
        $request_body = json_encode( $request_data );
        $response = wp_remote_post( $gsc_api_url, array(
            'method'  => 'POST',
            'headers' => array( 'Authorization' => 'Bearer ' . $access_token, 'Content-Type'  => 'application/json' ),
            'body'    => $request_body, 'timeout' => 60, // Longer timeout for potentially large keyword lists
        ) );
        if ( is_wp_error( $response ) ) return $response;
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        if ( isset( $data['error'] ) ) return new WP_Error( 'api_error', $data['error']['message'], $data );
        if ( ! empty( $data['rows'] ) ) {
            $all_rows = array_merge( $all_rows, $data['rows'] );
            $row_count = count( $data['rows'] );
            $start_row += $row_count;
        } else {
            $row_count = 0;
        }
    } while ( $row_count === $row_limit ); // Continue pagination if we received a full batch

    return array( 'rows' => $all_rows );
}

/**
 * 6. Inserts GSC KEYWORD data into the database.
 * (No changes needed)
 */
function bite_insert_gsc_data( $site_id, $rows, $single_date = null ) {
    global $wpdb;
    $metrics_table = $wpdb->prefix . 'bite_metrics_site_' . absint( $site_id );
    $keywords_table = $wpdb->prefix . 'bite_keywords';
    $keywords_to_check = array();

    // Extract keywords, handling single vs multi-day format
    foreach ( $rows as $row ) {
        $keyword_key = ( $single_date === null ) ? 1 : 0; // Index of keyword in 'keys' array
        if ( isset( $row['keys'][ $keyword_key ] ) && ! empty( $row['keys'][ $keyword_key ] ) ) {
            // Clean invisible characters/whitespace just in case
            $clean_keyword = trim( $row['keys'][ $keyword_key ], " \t\n\r\0\x0B\xC2\xA0\xE2\x80\x8B" );
            if( ! empty( $clean_keyword ) ) {
                $keywords_to_check[] = $clean_keyword;
            }
        }
    }
    if ( empty( $keywords_to_check ) ) return 0; // No valid keywords found in this batch
    $keywords_to_check = array_unique( $keywords_to_check );

    // Build cache of existing keywords
    $keyword_cache = array();
    $keyword_chunks = array_chunk( $keywords_to_check, 1000 ); // Process in chunks for large queries
    foreach ( $keyword_chunks as $chunk ) {
        $placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
        $existing_keywords = $wpdb->get_results(
            $wpdb->prepare( "SELECT keyword, keyword_id FROM $keywords_table WHERE keyword IN ( $placeholders )", $chunk )
        );
        foreach ( $existing_keywords as $existing ) {
            $keyword_cache[ $existing->keyword ] = $existing->keyword_id;
        }
    }

    // Identify and insert new keywords
    $keywords_to_insert = array_diff( $keywords_to_check, array_keys( $keyword_cache ) );
    if ( ! empty( $keywords_to_insert ) ) {
        $insert_query_sql = "INSERT IGNORE INTO $keywords_table (keyword) VALUES ";
        $insert_values = array();
        $insert_params = array();
        foreach ( $keywords_to_insert as $new_keyword ) {
            $insert_values[] = '(%s)';
            $insert_params[] = $new_keyword;
        }
        // Only run query if there are values
        if ( ! empty( $insert_values ) ) {
            $insert_query_sql .= implode( ', ', $insert_values );
            $wpdb->query( $wpdb->prepare( $insert_query_sql, $insert_params ) );

            // Re-fetch newly added keyword IDs to update cache
            $newly_added_keywords = $wpdb->get_results(
                $wpdb->prepare( "SELECT keyword, keyword_id FROM $keywords_table WHERE keyword IN ( " . implode( ', ', array_fill( 0, count( $keywords_to_insert ), '%s' ) ) . " )", $keywords_to_insert )
            );
            foreach ( $newly_added_keywords as $newly_added ) {
                $keyword_cache[ $newly_added->keyword ] = $newly_added->keyword_id;
            }
        }
    }

    // Prepare bulk insert for metrics data
    $values_sql = array();
    $sql_placeholders = array();
    foreach ( $rows as $row ) {
        // Determine date and keyword based on context
        if( $single_date === null ) { // Multi-day fetch (date is in keys[0])
            $date = $row['keys'][0];
            $keyword = isset($row['keys'][1]) ? trim( $row['keys'][1], " \t\n\r\0\x0B\xC2\xA0\xE2\x80\x8B" ) : '';
        } else { // Single-day fetch (date is passed in, keyword is keys[0])
            $date = $single_date;
            $keyword = isset($row['keys'][0]) ? trim( $row['keys'][0], " \t\n\r\0\x0B\xC2\xA0\xE2\x80\x8B" ) : '';
        }
        $device = $row['device']; // Device was added earlier

        // Skip if keyword is empty or wasn't found/inserted in cache
        if ( ! isset( $keyword_cache[ $keyword ] ) || empty($keyword) ) continue;
        $keyword_id = $keyword_cache[ $keyword ];

        // Add data to placeholders
        array_push( $sql_placeholders, $keyword_id, $date, $device, $row['clicks'], $row['impressions'], $row['ctr'], $row['position'] );
        $values_sql[] = '(%d, %s, %s, %d, %d, %f, %f)'; // Matches table columns
    }

    // Execute bulk insert if there's data
    if ( empty( $values_sql ) ) return 0; // No valid rows to insert
    // Use backticks around table name
    $query = "INSERT IGNORE INTO `$metrics_table` (keyword_id, date, device, clicks, impressions, ctr, position) VALUES ";
    $query .= implode( ', ', $values_sql );
    $rows_affected = $wpdb->query( $wpdb->prepare( $query, $sql_placeholders ) );

    // Handle DB errors
    if ( $rows_affected === false ) return new WP_Error( 'db_insert_error', $wpdb->last_error );

    return $rows_affected; // Return number of rows inserted/affected
}

/**
 * 7. Inserts GSC TOTALS data into the summary database.
 * (No changes needed)
 */
function bite_insert_daily_summary( $summary_data_rows ) {
    global $wpdb;
    $summary_table = $wpdb->prefix . 'bite_daily_summary';

    $values_sql = array();
    $sql_placeholders = array();

    foreach ( $summary_data_rows as $row ) {
        array_push( $sql_placeholders,
            $row['site_id'], $row['date'], $row['device'],
            $row['total_clicks'], $row['total_impressions'], $row['total_ctr'], $row['total_position']
        );
        $values_sql[] = '(%d, %s, %s, %d, %d, %f, %f)';
    }

    if ( empty( $values_sql ) ) return 0;

    // Use INSERT ... ON DUPLICATE KEY UPDATE to handle daily refresh
    // Use backticks around table name
    $query = "INSERT INTO `$summary_table` (site_id, date, device, total_clicks, total_impressions, total_ctr, total_position) VALUES ";
    $query .= implode( ', ', $values_sql );
    $query .= " ON DUPLICATE KEY UPDATE
                total_clicks = VALUES(total_clicks),
                total_impressions = VALUES(total_impressions),
                total_ctr = VALUES(total_ctr),
                total_position = VALUES(total_position)";

    $rows_affected = $wpdb->query( $wpdb->prepare( $query, $sql_placeholders ) );

    if ( $rows_affected === false ) {
        // Log error but don't stop the whole process just for summary failure
        error_log("BITE DB Error: Failed to insert/update daily summary. Error: " . $wpdb->last_error);
        return new WP_Error( 'db_summary_insert_error', $wpdb->last_error );
    }
    return $rows_affected;
}


/**
 * 8. Reusable Google Access Token Function
 * (With detailed logging AND caching re-enabled)
 * 
 * @param int $site_id Optional site ID to use site-specific credentials
 */
function bite_get_google_access_token( $site_id = null ) {
    static $access_tokens = array();
    
    $cache_key = $site_id ? 'site_' . $site_id : 'global';
    
    // Return cached token if available
    if ( isset( $access_tokens[$cache_key] ) ) {
        return $access_tokens[$cache_key];
    }

    $scopes = array( 'https://www.googleapis.com/auth/webmasters.readonly' );
    $google_token_url = 'https://oauth2.googleapis.com/token';

    // Get credentials - site-specific or global
    $key_file_data = null;
    
    if ( $site_id ) {
        // Try to get site-specific credentials
        global $wpdb;
        $sites_table = $wpdb->prefix . 'bite_sites';
        $credentials_json = $wpdb->get_var( $wpdb->prepare(
            "SELECT gsc_credentials FROM $sites_table WHERE site_id = %d",
            $site_id
        ) );
        
        if ( $credentials_json ) {
            $key_file_data = json_decode( $credentials_json, true );
        }
    }
    
    // Fall back to global credentials if no site-specific credentials found
    if ( ! $key_file_data ) {
        $json_key_file_name = 'google-api-credentials.json';
        $key_file_path = get_template_directory() . '/' . $json_key_file_name;
        
        if ( ! file_exists( $key_file_path ) ) {
            error_log( 'BITE Auth Error: JSON Key file not found at ' . $key_file_path );
            return new WP_Error( 'key_file_missing', 'JSON Key file not found. Please upload credentials in site settings.' );
        }
        
        $key_file_data = json_decode( file_get_contents( $key_file_path ), true );
    }
    
    if ( ! $key_file_data ) {
        error_log( 'BITE Auth Error: Could not decode JSON credentials.' );
        return new WP_Error( 'key_file_corrupt', 'Could not decode JSON credentials.' );
    }

    // --- JWT Creation ---
    $jwt_header = base64UrlEncode( json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
    $iat = time(); $exp = $iat + 3600;
    $jwt_claim = base64UrlEncode( json_encode( array(
        'iss'   => $key_file_data['client_email'],
        'scope' => implode( ' ', $scopes ),
        'aud'   => $google_token_url,
        'exp'   => $exp,
        'iat'   => $iat,
    ) ) );
    $jwt_signature_input = $jwt_header . '.' . $jwt_claim;
    $signature = '';
    $private_key = $key_file_data['private_key'];
    if ( ! openssl_sign( $jwt_signature_input, $signature, $private_key, 'SHA256' ) ) {
         error_log( 'BITE Auth Error: Could not sign the JWT using openssl_sign.' ); // Specific log message
        return new WP_Error( 'openssl_error', 'Could not sign the JWT.' );
    }
    $jwt_signature = base64UrlEncode( $signature );
    $jwt = $jwt_header . '.' . $jwt_claim . '.' . $jwt_signature;
    // --- End JWT Creation ---

    error_log("BITE Auth: Attempting wp_remote_post to get NEW access token..."); // Log start of request

    // --- Call Google Token Endpoint ---
    $response = wp_remote_post( $google_token_url, array(
        'body' => array(
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt
        ),
        'headers' => array(
            'Content-Type' => 'application/x-www-form-urlencoded'
        ),
        'timeout' => 20 // Added timeout
    ) );
    // --- End Call ---

    // --- Start Response Logging ---
    // Log if wp_remote_post itself failed (e.g., cURL error, timeout)
    if ( is_wp_error( $response ) ) {
        error_log("BITE Auth Error: wp_remote_post failed. Error Code: " . $response->get_error_code() . " Message: " . $response->get_error_message());
        return $response; // Return the WP_Error
    }

    // Log the HTTP response code and headers received from Google
    $response_code = wp_remote_retrieve_response_code( $response );
    $response_headers = wp_remote_retrieve_headers( $response ); // Get headers object/array
    error_log("BITE Auth: Received HTTP response code for new token: " . $response_code);
    // Log headers (optional, uncomment if needed but can be verbose)
    // error_log("BITE Auth: Received Headers: " . print_r($response_headers, true));

    // Log the raw response body BEFORE decoding
    $body = wp_remote_retrieve_body( $response );
    error_log("BITE Auth: Raw response body for new token: " . $body); // This is critical

    // Try to decode the JSON body and log results/errors
    $token_data = json_decode( $body, true ); // true for associative array
    if ($token_data === null && json_last_error() !== JSON_ERROR_NONE) {
        error_log("BITE Auth Error: json_decode failed for new token. Error: " . json_last_error_msg() . " (Code: " . json_last_error() . ")");
    } else {
        // Use var_export for a slightly cleaner array output in logs
        error_log("BITE Auth: json_decode result for new token: " . var_export($token_data, true));
    }
    // --- End Response Logging ---

    // --- Check for access_token AFTER logging everything ---
    if ( ! isset( $token_data['access_token'] ) || empty( $token_data['access_token'] ) ) {
        error_log("BITE Auth Error: 'access_token' key not found or empty in decoded response.");
        // Return the error, including the (potentially non-JSON or error-containing) $token_data
        return new WP_Error( 'token_error', 'Error getting access token.', $token_data );
    }

    // Success path
    error_log("BITE Auth: New access token successfully retrieved (first few chars): " . substr($token_data['access_token'], 0, 10) . "...");
    $access_tokens[$cache_key] = $token_data['access_token']; // Store in array cache for reuse
    return $access_tokens[$cache_key];
}

/**
 * 9. Helper function for Base64 URL encoding
 * (No changes needed)
 */
function base64UrlEncode( $data ) {
    return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}

?>
