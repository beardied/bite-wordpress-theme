<?php
/**
 * Disable Comments
 *
 * Completely disables the WordPress comments system.
 *
 * @package BITE-theme
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================
// 1. Disable comments on post types
// ============================================

/**
 * Disable support for comments and trackbacks in post types
 */
function bite_disable_comments_post_types() {
    $post_types = get_post_types();
    foreach ( $post_types as $post_type ) {
        if ( post_type_supports( $post_type, 'comments' ) ) {
            remove_post_type_support( $post_type, 'comments' );
            remove_post_type_support( $post_type, 'trackbacks' );
        }
    }
}
add_action( 'admin_init', 'bite_disable_comments_post_types' );

// ============================================
// 2. Close comments on the front-end
// ============================================

/**
 * Close comments on frontend
 */
function bite_disable_comments_status() {
    return false;
}
add_filter( 'comments_open', 'bite_disable_comments_status', 20, 2 );
add_filter( 'pings_open', 'bite_disable_comments_status', 20, 2 );

// ============================================
// 3. Hide existing comments
// ============================================

/**
 * Hide existing comments
 */
function bite_disable_comments_hide( $comments ) {
    $comments = array();
    return $comments;
}
add_filter( 'comments_array', 'bite_disable_comments_hide', 10, 2 );

// ============================================
// 4. Remove comments from admin menu
// ============================================

/**
 * Remove comments page from admin menu
 */
function bite_disable_comments_admin_menu() {
    remove_menu_page( 'edit-comments.php' );
}
add_action( 'admin_menu', 'bite_disable_comments_admin_menu' );

// ============================================
// 5. Redirect any user trying to access comments page
// ============================================

/**
 * Redirect comments page to admin dashboard
 */
function bite_disable_comments_admin_menu_redirect() {
    global $pagenow;
    if ( $pagenow === 'edit-comments.php' ) {
        wp_redirect( admin_url() );
        exit;
    }
}
add_action( 'admin_init', 'bite_disable_comments_admin_menu_redirect' );

// ============================================
// 6. Remove comments metabox from dashboard
// ============================================

/**
 * Remove comments metabox from dashboard
 */
function bite_disable_comments_dashboard() {
    remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
}
add_action( 'admin_init', 'bite_disable_comments_dashboard' );

// ============================================
// 7. Remove comments from admin bar
// ============================================

/**
 * Remove comments from admin bar
 */
function bite_disable_comments_admin_bar() {
    global $wp_admin_bar;
    if ( is_admin_bar_showing() && $wp_admin_bar ) {
        $wp_admin_bar->remove_menu( 'comments' );
    }
}
add_action( 'wp_before_admin_bar_render', 'bite_disable_comments_admin_bar' );
add_action( 'admin_bar_menu', 'bite_disable_comments_admin_bar', 999 );

// ============================================
// 8. Remove comments links from post/page lists
// ============================================

/**
 * Remove comments column from posts list
 */
function bite_disable_comments_column( $columns ) {
    unset( $columns['comments'] );
    return $columns;
}
add_filter( 'manage_posts_columns', 'bite_disable_comments_column' );
add_filter( 'manage_pages_columns', 'bite_disable_comments_column' );

// ============================================
// 9. Disable comment feeds
// ============================================

/**
 * Disable comments feed
 */
function bite_disable_comments_feed() {
    wp_die( __( 'Comments are disabled.', 'bite-theme' ) );
}
add_action( 'do_feed_rss2_comments', 'bite_disable_comments_feed', 1 );
add_action( 'do_feed_atom_comments', 'bite_disable_comments_feed', 1 );

// ============================================
// 10. Remove comment reply script from frontend
// ============================================

/**
 * Remove comment-reply script
 */
function bite_disable_comments_script() {
    wp_deregister_script( 'comment-reply' );
}
add_action( 'wp_enqueue_scripts', 'bite_disable_comments_script', 100 );

// ============================================
// 11. Disable comments REST API endpoints
// ============================================

/**
 * Filter REST API for comments
 */
function bite_filter_rest_endpoints( $endpoints ) {
    if ( isset( $endpoints['/wp/v2/comments'] ) ) {
        unset( $endpoints['/wp/v2/comments'] );
    }
    if ( isset( $endpoints['/wp/v2/comments/(?P<id>[\d]+)'] ) ) {
        unset( $endpoints['/wp/v2/comments/(?P<id>[\d]+)'] );
    }
    return $endpoints;
}
add_filter( 'rest_endpoints', 'bite_filter_rest_endpoints', 1000 );

// ============================================
// 12. Remove comment count from posts
// ============================================

/**
 * Remove comment count
 */
function bite_remove_comment_count( $count ) {
    return 0;
}
add_filter( 'get_comments_number', 'bite_remove_comment_count', 10, 2 );
