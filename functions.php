<?php
/**
 * BITE-theme functions and definitions
 *
 * @package BITE-theme
 */

// Define a constant for the BITE theme directory path for easy inclusion.
define( 'BITE_THEME_DIR', get_template_directory() );

// --- Includes ---
// Load all our core theme functions by including files from the /includes/ directory.

// 1. Core theme setup (roles, redirects, script enqueuing)
require_once BITE_THEME_DIR . '/includes/theme-setup.php';

// 1.5 Sidebar Menu Walker (Custom walker for Material Icons support)
require_once BITE_THEME_DIR . '/includes/class-sidebar-menu-walker.php';

// 2. Database setup (tables, activation hook)
require_once BITE_THEME_DIR . '/includes/database-setup.php';

// 3. Admin Pages (Site Management, Settings)
require_once BITE_THEME_DIR . '/includes/admin-pages.php';

// 4. BITE Dashboard Pages (The main UI for viewers)
// require_once BITE_THEME_DIR . '/includes/dashboard-pages.php';

// 5. Google API Logic (Cron jobs, data fetching)
require_once BITE_THEME_DIR . '/includes/google-api.php';

// 6. Charting & Reporting Functions
require_once BITE_THEME_DIR . '/includes/reporting.php';

// 7. User Access Control (Client isolation)
require_once BITE_THEME_DIR . '/includes/user-access.php';