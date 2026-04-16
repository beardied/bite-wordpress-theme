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

// 4.5. OAuth 2.0 Handler (Google authentication)
require_once BITE_THEME_DIR . '/includes/oauth-handler.php';

// 5. Google API Logic (Cron jobs, data fetching)
require_once BITE_THEME_DIR . '/includes/google-api.php';

// 6. Charting & Reporting Functions
require_once BITE_THEME_DIR . '/includes/reporting.php';

// 7. User Access Control (Client isolation)
require_once BITE_THEME_DIR . '/includes/user-access.php';

// 8. Custom Login Page Styling
require_once BITE_THEME_DIR . '/includes/login-page.php';

// 9. Disable Comments System
require_once BITE_THEME_DIR . '/includes/disable-comments.php';

// 10. robots.txt Handling (disable virtual, use physical)
require_once BITE_THEME_DIR . '/includes/robots-txt.php';

// 11. SEO Optimization (Meta tags, Schema markup)
require_once BITE_THEME_DIR . '/includes/seo.php';

// 12. Dynamic Sitemap Generator
require_once BITE_THEME_DIR . '/includes/sitemap.php';

// ============================================
// INTERNAL REVIEW SYSTEM AJAX HANDLERS
// ============================================

/**
 * Handle internal review submission
 */
function bite_ajax_submit_internal_review() {
    global $wpdb;
    
    // Check nonce
    if ( ! wp_verify_nonce( $_POST['nonce'], 'bite_review_nonce' ) ) {
        wp_send_json_error( 'Security check failed' );
    }
    
    // Check user is logged in
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( 'Not logged in' );
    }
    
    $user_id = get_current_user_id();
    $rating = intval( $_POST['rating'] );
    $name = sanitize_text_field( $_POST['name'] ?? '' );
    $review_text = sanitize_textarea_field( $_POST['review_text'] ?? '' );
    
    // Validate rating
    if ( $rating < 1 || $rating > 5 ) {
        wp_send_json_error( 'Invalid rating' );
    }
    
    // Use display name if no name provided
    if ( empty( $name ) ) {
        $user = get_userdata( $user_id );
        $name = $user->display_name;
    }
    
    // Save review to database
    $reviews_table = $wpdb->prefix . 'bite_reviews';
    $wpdb->insert(
        $reviews_table,
        array(
            'user_id' => $user_id,
            'user_name' => $name,
            'rating' => $rating,
            'review_text' => $review_text,
            'is_approved' => 1, // Auto-approve for now
        ),
        array( '%d', '%s', '%d', '%s', '%d' )
    );
    
    // Mark as reviewed
    update_user_meta( $user_id, 'bite_review_submitted', true );
    
    // Send notification to admin
    $to = get_option( 'bite_contact_email', get_option( 'admin_email' ) );
    $subject = 'New BITE Review from ' . $name;
    
    $message = "A new review has been submitted for B.I.T.E.\n\n";
    $message .= "User: " . $name . "\n";
    $message .= "Rating: " . $rating . " stars\n";
    $message .= "Review:\n" . $review_text . "\n\n";
    $message .= "Date: " . current_time( 'mysql' ) . "\n";
    
    wp_mail( $to, $subject, $message );
    
    wp_send_json_success( 'Review submitted' );
}
add_action( 'wp_ajax_bite_submit_internal_review', 'bite_ajax_submit_internal_review' );

// ============================================
// GOOGLE OAUTH DISCONNECT HANDLER
// ============================================

/**
 * Handle Google OAuth disconnect from dashboard
 */
function bite_handle_dashboard_disconnect() {
    if ( ! is_user_logged_in() ) {
        return;
    }
    
    if ( isset( $_GET['disconnect_google'] ) && isset( $_GET['_wpnonce'] ) ) {
        if ( wp_verify_nonce( $_GET['_wpnonce'], 'disconnect_google' ) ) {
            $result = bite_disconnect_google_account( get_current_user_id() );
            
            if ( ! is_wp_error( $result ) ) {
                wp_redirect( home_url( '/dashboard/?disconnected=1' ) );
                exit;
            }
        }
    }
}
add_action( 'template_redirect', 'bite_handle_dashboard_disconnect' );

// ============================================
// DASHBOARD DATEPICKER INITIALIZATION
// ============================================

/**
 * Initialize datepicker for dashboard data view
 */
function bite_dashboard_datepicker_init() {
    if ( ! is_page_template( 'template-dashboard.php' ) ) {
        return;
    }
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        if ( $('.bite-datepicker').length > 0 ) {
            $('.bite-datepicker').datepicker({
                dateFormat: 'dd-mm-yy',
                changeMonth: true,
                changeYear: true,
                maxDate: 0, // Today
                yearRange: '-5:+0',
                showAnim: 'slideDown'
            });
        }
    });
    </script>
    <?php
}
add_action( 'wp_footer', 'bite_dashboard_datepicker_init', 100 );
