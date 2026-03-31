<?php
/**
 * Dynamic XML Sitemap Generator
 *
 * Generates sitemap for public-facing pages only.
 *
 * @package BITE-theme
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Generate and serve XML sitemap
 */
function bite_generate_sitemap() {
    // Only run on sitemap.xml requests
    if ( ! isset( $_SERVER['REQUEST_URI'] ) || strpos( $_SERVER['REQUEST_URI'], 'sitemap.xml' ) === false ) {
        return;
    }
    
    // Prevent WordPress from processing this as a 404
    global $wp_query;
    if ( isset( $wp_query ) && is_object( $wp_query ) ) {
        $wp_query->is_404 = false;
    }
    
    // Set headers
    header( 'Content-Type: application/xml; charset=UTF-8' );
    header( 'HTTP/1.1 200 OK' );
    
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    
    // Homepage
    echo bite_sitemap_url( home_url( '/' ), '1.0', 'daily' );
    
    // Contact page
    $contact_page = get_page_by_path( 'contact' );
    if ( $contact_page && $contact_page->post_status === 'publish' ) {
        echo bite_sitemap_url( get_permalink( $contact_page->ID ), '0.8', 'weekly' );
    }
    
    // Any other public pages (non-password protected, published)
    $public_pages = get_posts( array(
        'post_type'      => 'page',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'meta_query'     => array(
            array(
                'key'     => '_wp_page_template',
                'value'   => array(
                    'template-dashboard.php',
                    'template-opportunity-finder.php',
                    'template-global-champions.php',
                    'template-emerging-trends.php',
                    'template-keyword-explorer.php',
                    'template-ctr-efficiency.php',
                ),
                'compare' => 'NOT IN',
            ),
        ),
    ) );
    
    foreach ( $public_pages as $page ) {
        // Skip if already added (contact page or homepage)
        if ( $page->ID === $contact_page->ID ) {
            continue;
        }
        
        // Skip homepage/front page (already added manually above)
        if ( $page->ID == get_option( 'page_on_front' ) || trailingslashit( get_permalink( $page->ID ) ) === trailingslashit( home_url( '/' ) ) ) {
            continue;
        }
        
        $priority = '0.6';
        $changefreq = 'weekly';
        
        // Higher priority for landing page
        $page_template = get_page_template_slug( $page->ID );
        if ( $page_template === 'template-sales-landing.php' ) {
            $priority = '0.9';
            $changefreq = 'daily';
        }
        
        echo bite_sitemap_url( get_permalink( $page->ID ), $priority, $changefreq, $page->post_modified );
    }
    
    echo '</urlset>';
    exit;
}
add_action( 'template_redirect', 'bite_generate_sitemap', 1 );

/**
 * Helper function to output URL element
 */
function bite_sitemap_url( $url, $priority = '0.5', $changefreq = 'weekly', $lastmod = '' ) {
    $output = "  <url>\n";
    $output .= "    <loc>" . esc_url( $url ) . "</loc>\n";
    
    if ( $lastmod ) {
        $output .= "    <lastmod>" . mysql2date( 'Y-m-d', $lastmod ) . "</lastmod>\n";
    } else {
        $output .= "    <lastmod>" . date( 'Y-m-d' ) . "</lastmod>\n";
    }
    
    $output .= "    <changefreq>" . $changefreq . "</changefreq>\n";
    $output .= "    <priority>" . $priority . "</priority>\n";
    $output .= "  </url>\n";
    
    return $output;
}

/**
 * Add sitemap to robots.txt
 */
function bite_sitemap_robots( $output ) {
    $output .= "Sitemap: " . home_url( '/sitemap.xml' ) . "\n";
    return $output;
}
add_filter( 'robots_txt', 'bite_sitemap_robots', 10, 1 );
