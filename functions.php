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
// REVIEW SYSTEM AJAX HANDLERS
// ============================================

/**
 * Handle review submission
 */
function bite_ajax_submit_review() {
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
    
    // Validate rating
    if ( $rating < 1 || $rating > 5 ) {
        wp_send_json_error( 'Invalid rating' );
    }
    
    // Save rating to user meta
    update_user_meta( $user_id, 'bite_review_rating', $rating );
    update_user_meta( $user_id, 'bite_review_date', current_time( 'mysql' ) );
    
    // If rating is 4-5, mark as reviewed but don't hide yet (they might click Google link)
    // If rating is 1-3, don't mark as reviewed yet (they might submit feedback)
    if ( $rating <= 3 ) {
        update_user_meta( $user_id, 'bite_review_submitted', 'feedback_pending' );
    }
    
    wp_send_json_success( 'Review saved' );
}
add_action( 'wp_ajax_bite_submit_review', 'bite_ajax_submit_review' );

/**
 * Handle feedback submission
 */
function bite_ajax_submit_feedback() {
    // Check nonce
    if ( ! wp_verify_nonce( $_POST['nonce'], 'bite_review_nonce' ) ) {
        wp_send_json_error( 'Security check failed' );
    }
    
    // Check user is logged in
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( 'Not logged in' );
    }
    
    $user_id = get_current_user_id();
    $feedback = sanitize_textarea_field( $_POST['feedback'] ?? '' );
    $rating = get_user_meta( $user_id, 'bite_review_rating', true );
    
    // Get user info
    $user = get_userdata( $user_id );
    $user_email = $user->user_email;
    $user_name = $user->display_name;
    
    // Send feedback email to admin
    $to = get_option( 'bite_contact_email', get_option( 'admin_email' ) );
    $subject = 'BITE Feedback from ' . $user_name;
    
    $message = "Negative Feedback Received\n\n";
    $message .= "User: " . $user_name . " (" . $user_email . ")\n";
    $message .= "Rating: " . $rating . " stars\n\n";
    $message .= "Feedback:\n" . $feedback . "\n\n";
    $message .= "Date: " . current_time( 'mysql' ) . "\n";
    
    wp_mail( $to, $subject, $message );
    
    // Mark as reviewed
    update_user_meta( $user_id, 'bite_review_submitted', true );
    
    wp_send_json_success( 'Feedback submitted' );
}
add_action( 'wp_ajax_bite_submit_feedback', 'bite_ajax_submit_feedback' );
