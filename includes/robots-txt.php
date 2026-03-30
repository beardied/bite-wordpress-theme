<?php
/**
 * robots.txt Handling
 *
 * Disables WordPress virtual robots.txt and ensures physical file is used.
 *
 * @package BITE-theme
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Disable WordPress virtual robots.txt
 * This ensures the physical robots.txt file is served instead
 */
add_filter( 'robots_txt', '__return_empty_string', 999 );

/**
 * Redirect virtual robots.txt to physical file
 */
function bite_robots_txt_redirect() {
    if ( strpos( $_SERVER['REQUEST_URI'], 'robots.txt' ) !== false ) {
        $physical_robots = ABSPATH . 'robots.txt';
        if ( file_exists( $physical_robots ) ) {
            // Serve the physical file directly
            header( 'Content-Type: text/plain; charset=utf-8' );
            readfile( $physical_robots );
            exit;
        }
    }
}
add_action( 'init', 'bite_robots_txt_redirect', 1 );
