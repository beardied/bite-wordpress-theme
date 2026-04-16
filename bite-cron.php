<?php
/**
 * BITE Dedicated Cron Script
 * 
 * Place this file in your WordPress root directory and add to crontab:
 * */2 * * * * cd /path/to/wordpress && php -q bite-cron.php >> /var/log/bite-cron.log 2>&1
 */

set_time_limit(300);
require_once __DIR__ . '/../../../wp-load.php';

if (get_transient('bite_backfill_running')) {
    echo date('Y-m-d H:i:s') . " - Backfill already running. Skipping.\n";
    exit(0);
}

echo date('Y-m-d H:i:s') . " - Starting BITE backfill...\n";
bite_run_backfill_chunk();
echo date('Y-m-d H:i:s') . " - Backfill cycle completed.\n";
exit(0);
