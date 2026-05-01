<?php
/**
 * BITE Dedicated Cron Script
 * 
 * Place this file in your WordPress root directory and add to crontab:
 * Run every 2 minutes:
 * # 2min * * * * cd /path/to/wordpress && php -q bite-cron.php >> /var/log/bite-cron.log 2>&1
 */

set_time_limit(600); // 10 minutes — daily update + backfill can take 3-5 min
require_once __DIR__ . '/../../../wp-load.php';

// === 1. BACKFILL QUEUE ===
if (get_transient('bite_backfill_running')) {
    echo date('Y-m-d H:i:s') . " - Backfill already running. Skipping.\n";
} else {
    echo date('Y-m-d H:i:s') . " - Starting BITE backfill...\n";
    bite_run_backfill_chunk();
    echo date('Y-m-d H:i:s') . " - Backfill cycle completed.\n";
}

// === 2. DAILY UPDATE (runs once per day at ~6am UTC or whenever cron first fires) ===
// WP cron only fires on web visits, which is unreliable for low-traffic sites.
// We run the daily update directly here if it hasn't run today.
$last_daily_run = get_transient('bite_daily_update_last_run');
$today = date('Y-m-d');

if ( ! $last_daily_run || date('Y-m-d', strtotime($last_daily_run)) !== $today ) {
    if ( ! get_transient('bite_backfill_running') ) {
        echo date('Y-m-d H:i:s') . " - Starting BITE daily update...\n";
        
        // Load all required includes in case they weren't loaded by wp-load
        if ( ! function_exists( 'bite_run_daily_update' ) ) {
            require_once get_template_directory() . '/includes/google-api.php';
        }
        
        bite_run_daily_update();
        echo date('Y-m-d H:i:s') . " - Daily update completed.\n";
    } else {
        echo date('Y-m-d H:i:s') . " - Daily update deferred (backfill running).\n";
    }
} else {
    echo date('Y-m-d H:i:s') . " - Daily update already ran today ($today).\n";
}

exit(0);
