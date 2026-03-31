<?php
/**
 * SEO Optimization
 *
 * Meta tags, Schema markup, and SEO enhancements for BITE theme.
 *
 * @package BITE-theme
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================
// 1. Meta Tags
// ============================================

/**
 * Add meta description and SEO tags to head
 */
function bite_seo_meta_tags() {
    // Default values
    $site_name = get_bloginfo( 'name' );
    $site_description = get_bloginfo( 'description' );
    $current_url = home_url( add_query_arg( array() ) );
    $logo = get_theme_mod( 'bite_logo' );
    
    // Page-specific meta
    if ( is_front_page() || is_home() ) {
        $title = $site_name . ' - ' . $site_description;
        $description = 'B.I.T.E. (Bulk Insight Tracking Engine) - Unlock the power of your Google Search Console data. Track, analyze, and optimize your websites\' performance with enterprise-grade analytics exclusively from OrangeWidow.';
    } elseif ( is_page( 'dashboard' ) ) {
        $title = 'Dashboard - ' . $site_name;
        $description = 'Access your B.I.T.E. dashboard to view performance metrics, track keywords, and analyze your website portfolio.';
    } elseif ( is_page( 'opportunity-finder' ) ) {
        $title = 'Opportunity Finder - ' . $site_name;
        $description = 'Compare keywords across your own sites. Find what works on one site and apply it to another with B.I.T.E. Opportunity Finder.';
    } elseif ( is_page( 'global-champions' ) ) {
        $title = 'Global Champions - ' . $site_name;
        $description = 'Identify top-performing keywords across your own sites and niches. See what\'s working in your portfolio.';
    } elseif ( is_page( 'emerging-trends' ) ) {
        $title = 'Emerging Trends - ' . $site_name;
        $description = 'Spot keywords with rapid impression changes. Catch trends early with B.I.T.E. Emerging Trends tool.';
    } elseif ( is_page( 'keyword-explorer' ) ) {
        $title = 'Keyword Explorer - ' . $site_name;
        $description = 'Search and analyze keyword variations across your entire portfolio. Find long-tail opportunities with B.I.T.E.';
    } elseif ( is_page( 'ctr-efficiency' ) ) {
        $title = 'CTR Efficiency Report - ' . $site_name;
        $description = 'Understand your anonymized vs. discoverable click ratios. Optimize for hidden traffic with B.I.T.E.';
    } else {
        $title = wp_title( '|', false, 'right' ) . $site_name;
        $description = $site_description;
    }
    
    // Output meta tags
    echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
    echo '<meta name="keywords" content="SEO, Google Search Console, keyword tracking, website analytics, OrangeWidow, BITE, Bulk Insight Tracking Engine, search performance, CTR optimization">' . "\n";
    echo '<meta name="author" content="OrangeWidow">' . "\n";
    echo '<meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">' . "\n";
    
    // Canonical URL
    echo '<link rel="canonical" href="' . esc_url( $current_url ) . '">' . "\n";
    
    // Open Graph (Facebook, LinkedIn)
    echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
    echo '<meta property="og:description" content="' . esc_attr( $description ) . '">' . "\n";
    echo '<meta property="og:url" content="' . esc_url( $current_url ) . '">' . "\n";
    echo '<meta property="og:site_name" content="' . esc_attr( $site_name ) . '">' . "\n";
    echo '<meta property="og:type" content="' . ( is_front_page() ? 'website' : 'article' ) . '">' . "\n";
    if ( $logo ) {
        echo '<meta property="og:image" content="' . esc_url( $logo ) . '">' . "\n";
    }
    
    // Twitter Card
    echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
    echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
    echo '<meta name="twitter:description" content="' . esc_attr( $description ) . '">' . "\n";
    if ( $logo ) {
        echo '<meta name="twitter:image" content="' . esc_url( $logo ) . '">' . "\n";
    }
    
    // Additional SEO meta
    echo '<meta name="geo.region" content="GB">' . "\n";
    echo '<meta name="geo.placename" content="United Kingdom">' . "\n";
}
add_action( 'wp_head', 'bite_seo_meta_tags', 1 );

// ============================================
// 2. Schema.org Markup
// ============================================

/**
 * Get aggregate review data
 */
function bite_get_review_aggregate() {
    global $wpdb;
    $reviews_table = $wpdb->prefix . 'bite_reviews';
    
    $results = $wpdb->get_row( "SELECT AVG(rating) as avg_rating, COUNT(*) as total FROM $reviews_table WHERE is_approved = 1" );
    
    return array(
        'average' => $results->avg_rating ? round( $results->avg_rating, 1 ) : 0,
        'count' => intval( $results->total ),
    );
}

/**
 * Add Schema.org JSON-LD markup
 */
function bite_schema_markup() {
    if ( ! is_front_page() && ! is_home() ) {
        return;
    }
    
    $site_name = get_bloginfo( 'name' );
    $site_description = get_bloginfo( 'description' );
    $site_url = home_url( '/' );
    $logo = get_theme_mod( 'bite_logo' );
    
    // Get review aggregate data
    $review_data = bite_get_review_aggregate();
    
    // Organization Schema
    $organization_schema = array(
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => 'OrangeWidow',
        'url' => 'https://orangewidow.com',
        'logo' => $logo ? $logo : '',
        'description' => 'SEO and Digital Marketing Agency',
        'sameAs' => array(
            'https://orangewidow.com',
        ),
    );
    
    // Software Application Schema (for BITE)
    $software_schema = array(
        '@context' => 'https://schema.org',
        '@type' => 'SoftwareApplication',
        'name' => 'B.I.T.E. - Bulk Insight Tracking Engine',
        'applicationCategory' => 'BusinessApplication',
        'operatingSystem' => 'Web',
        'offers' => array(
            '@type' => 'Offer',
            'price' => '0',
            'priceCurrency' => 'GBP',
        ),
        'description' => 'Enterprise-grade Google Search Console analytics platform for tracking, analyzing, and optimizing website performance across multiple properties.',
        'url' => $site_url,
        'provider' => array(
            '@type' => 'Organization',
            'name' => 'OrangeWidow',
            'url' => 'https://orangewidow.com',
        ),
        'featureList' => array(
            'Performance Dashboard',
            'Opportunity Finder',
            'Global Champions',
            'Emerging Trends',
            'Keyword Explorer',
            'CTR Efficiency Report',
        ),
    );
    
    // Add aggregate rating if we have reviews
    if ( $review_data['count'] > 0 ) {
        $software_schema['aggregateRating'] = array(
            '@type' => 'AggregateRating',
            'ratingValue' => $review_data['average'],
            'bestRating' => '5',
            'worstRating' => '1',
            'ratingCount' => $review_data['count'],
        );
    }
    
    // WebSite Schema
    $website_schema = array(
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => $site_name,
        'url' => $site_url,
        'description' => $site_description,
        'publisher' => array(
            '@type' => 'Organization',
            'name' => 'OrangeWidow',
        ),
        'potentialAction' => array(
            '@type' => 'SearchAction',
            'target' => array(
                '@type' => 'EntryPoint',
                'urlTemplate' => $site_url . '?s={search_term_string}',
            ),
            'query-input' => 'required name=search_term_string',
        ),
    );
    
    // WebPage Schema
    $webpage_schema = array(
        '@context' => 'https://schema.org',
        '@type' => 'WebPage',
        'name' => $site_name,
        'description' => 'Unlock the power of your Google Search Console data with B.I.T.E. - Enterprise-grade analytics exclusively from OrangeWidow.',
        'url' => $site_url,
        'isPartOf' => array(
            '@type' => 'WebSite',
            'name' => $site_name,
            'url' => $site_url,
        ),
        'about' => $software_schema,
        'primaryImageOfPage' => $logo ? array(
            '@type' => 'ImageObject',
            'url' => $logo,
        ) : null,
    );
    
    // Output schemas
    echo '<script type="application/ld+json">' . wp_json_encode( $organization_schema, JSON_PRETTY_PRINT ) . '</script>' . "\n";
    echo '<script type="application/ld+json">' . wp_json_encode( $software_schema, JSON_PRETTY_PRINT ) . '</script>' . "\n";
    echo '<script type="application/ld+json">' . wp_json_encode( $website_schema, JSON_PRETTY_PRINT ) . '</script>' . "\n";
    echo '<script type="application/ld+json">' . wp_json_encode( $webpage_schema, JSON_PRETTY_PRINT ) . '</script>' . "\n";
}
add_action( 'wp_head', 'bite_schema_markup', 5 );

// ============================================
// 3. Breadcrumbs Schema
// ============================================

/**
 * Add BreadcrumbsList schema for internal pages
 */
function bite_breadcrumbs_schema() {
    if ( is_front_page() || is_home() ) {
        return;
    }
    
    $site_name = get_bloginfo( 'name' );
    $site_url = home_url( '/' );
    
    $breadcrumbs = array(
        array(
            '@type' => 'ListItem',
            'position' => 1,
            'name' => 'Home',
            'item' => $site_url,
        ),
    );
    
    if ( is_page() ) {
        $page_title = get_the_title();
        $page_url = get_permalink();
        
        $breadcrumbs[] = array(
            '@type' => 'ListItem',
            'position' => 2,
            'name' => $page_title,
            'item' => $page_url,
        );
    }
    
    $breadcrumb_schema = array(
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => $breadcrumbs,
    );
    
    echo '<script type="application/ld+json">' . wp_json_encode( $breadcrumb_schema, JSON_PRETTY_PRINT ) . '</script>' . "\n";
}
add_action( 'wp_head', 'bite_breadcrumbs_schema', 6 );

// ============================================
// 4. Remove unnecessary WordPress tags
// ============================================

/**
 * Clean up WordPress head
 */
function bite_clean_wp_head() {
    // Remove WordPress version
    remove_action( 'wp_head', 'wp_generator' );
    
    // Remove RSD link
    remove_action( 'wp_head', 'rsd_link' );
    
    // Remove Windows Live Writer link
    remove_action( 'wp_head', 'wlwmanifest_link' );
    
    // Remove shortlink
    remove_action( 'wp_head', 'wp_shortlink_wp_head' );
    
    // Remove adjacent posts links
    remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head' );
}
add_action( 'init', 'bite_clean_wp_head' );

// ============================================
// 5. Add preconnect for external resources
// ============================================

/**
 * Add preconnect hints for faster loading
 * Note: Material Icons are now self-hosted for better performance
 */
function bite_preconnect_hints() {
    // CDNs we use for scripts/styles
    echo '<link rel="preconnect" href="https://ajax.googleapis.com">' . "\n";
    echo '<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>' . "\n";
    echo '<link rel="preconnect" href="https://cdn.datatables.net" crossorigin>' . "\n";
}
add_action( 'wp_head', 'bite_preconnect_hints', 0 );

// ============================================
// 7. Add llms.txt link for AI crawlers
// ============================================

/**
 * Add llms.txt link
 */
function bite_llms_txt_link() {
    echo '<link rel="help" type="text/plain" href="' . esc_url( home_url( '/llms.txt' ) ) . '" title="AI Context Documentation">' . "\n";
}
add_action( 'wp_head', 'bite_llms_txt_link', 0 );

// ============================================
// 8. Matomo Analytics (public pages only)
// ============================================

/**
 * Add Matomo tracking code for non-logged-in users only
 */
function bite_matomo_tracking() {
    // Only track for non-logged-in users
    if ( is_user_logged_in() ) {
        return;
    }
    ?>
<!-- Matomo -->
<script>
  var _paq = window._paq = window._paq || [];
  /* tracker methods like "setCustomDimension" should be called before "trackPageView" */
  _paq.push(['trackPageView']);
  _paq.push(['enableLinkTracking']);
  (function() {
    var u="https://matomo.orangewidow.com/";
    _paq.push(['setTrackerUrl', u+'matomo.php']);
    _paq.push(['setSiteId', '10']);
    var d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];
    g.async=true; g.src=u+'matomo.js'; s.parentNode.insertBefore(g,s);
  })();
</script>
<noscript><p><img referrerpolicy="no-referrer-when-downgrade" src="https://matomo.orangewidow.com/matomo.php?idsite=10&amp;rec=1" style="border:0;" alt="" /></p></noscript>
<!-- End Matomo Code -->
    <?php
}
add_action( 'wp_head', 'bite_matomo_tracking', 100 );

// ============================================
// 6. Add theme color for mobile browsers
// ============================================

/**
 * Add theme color meta
 */
function bite_theme_color() {
    echo '<meta name="theme-color" content="#1A1A2E">' . "\n";
    echo '<meta name="msapplication-TileColor" content="#1A1A2E">' . "\n";
    echo '<meta name="apple-mobile-web-app-status-bar-style" content="#1A1A2E">' . "\n";
}
add_action( 'wp_head', 'bite_theme_color', 0 );
