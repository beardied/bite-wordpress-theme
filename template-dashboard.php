<?php
/**
 * Template Name: BITE Dashboard
 *
 * The main dashboard for logged-in users. Shows overview of accessible sites,
 * niches, quick stats, and navigation to tools.
 *
 * @package BITE-theme
 */

// Redirect non-logged-in users to login
if ( ! is_user_logged_in() ) {
    wp_redirect( wp_login_url() );
    exit;
}

get_header();

global $wpdb;
$current_user    = wp_get_current_user();
$current_user_id = get_current_user_id();
$user_site_ids   = bite_get_user_sites( $current_user_id );
$is_admin        = current_user_can( 'manage_options' );

// Get site details
$user_sites = array();
if ( ! empty( $user_site_ids ) ) {
    $sites_table = $wpdb->prefix . 'bite_sites';
    $niches_table = $wpdb->prefix . 'bite_niches';
    $placeholders = implode( ', ', array_fill( 0, count( $user_site_ids ), '%d' ) );
    $user_sites = $wpdb->get_results( $wpdb->prepare(
        "SELECT s.site_id, s.name, s.domain, s.gsc_property, s.created_at, n.niche_name 
         FROM $sites_table s 
         LEFT JOIN $niches_table n ON s.niche_id = n.niche_id 
         WHERE s.site_id IN ($placeholders) 
         ORDER BY s.name ASC",
        $user_site_ids
    ) );
}

// Get unique niches for this user
$user_niches = array();
foreach ( $user_sites as $site ) {
    if ( ! empty( $site->niche_name ) && ! in_array( $site->niche_name, $user_niches ) ) {
        $user_niches[] = $site->niche_name;
    }
}
sort( $user_niches );

// Get quick stats for the last 30 days
$quick_stats = array(
    'total_clicks'      => 0,
    'total_impressions' => 0,
    'avg_ctr'           => 0,
    'avg_position'      => 0,
    'site_count'        => count( $user_sites ),
);

if ( ! empty( $user_site_ids ) ) {
    $summary_table = $wpdb->prefix . 'bite_daily_summary';
    $placeholders  = implode( ', ', array_fill( 0, count( $user_site_ids ), '%d' ) );
    $start_date    = date( 'Y-m-d', strtotime( '-30 days' ) );
    $end_date      = date( 'Y-m-d' );
    
    $stats = $wpdb->get_row( $wpdb->prepare(
        "SELECT 
            SUM(total_clicks) as total_clicks,
            SUM(total_impressions) as total_impressions,
            AVG(total_ctr) as avg_ctr,
            AVG(total_position) as avg_position
         FROM $summary_table 
         WHERE site_id IN ($placeholders) 
         AND date BETWEEN %s AND %s",
        array_merge( $user_site_ids, array( $start_date, $end_date ) )
    ) );
    
    if ( $stats ) {
        $quick_stats['total_clicks']      = $stats->total_clicks ?: 0;
        $quick_stats['total_impressions'] = $stats->total_impressions ?: 0;
        $quick_stats['avg_ctr']           = $stats->avg_ctr ?: 0;
        $quick_stats['avg_position']      = $stats->avg_position ?: 0;
    }
}

// Calculate overall CTR from totals
if ( $quick_stats['total_impressions'] > 0 ) {
    $quick_stats['calculated_ctr'] = ( $quick_stats['total_clicks'] / $quick_stats['total_impressions'] ) * 100;
} else {
    $quick_stats['calculated_ctr'] = 0;
}

// Get available tools/pages
$tools = array(
    'opportunity-finder' => array(
        'title'       => 'Opportunity Finder',
        'description' => 'Find keywords that one site ranks for, but another is missing.',
        'icon'          => 'search',
        'color'         => 'blue',
    ),
    'global-champions'   => array(
        'title'       => 'Global Champions',
        'description' => 'Discover top-performing keywords across all sites in a niche.',
        'icon'          => 'trophy',
        'color'         => 'gold',
    ),
    'emerging-trends'    => array(
        'title'       => 'Emerging Trends',
        'description' => 'Identify keywords with rapid changes in impressions or clicks.',
        'icon'          => 'trending_up',
        'color'         => 'green',
    ),
    'keyword-explorer'   => array(
        'title'       => 'Keyword Explorer',
        'description' => 'Explore all keyword variations in your database.',
        'icon'          => 'explore',
        'color'         => 'purple',
    ),
    'ctr-efficiency'     => array(
        'title'       => 'CTR Efficiency',
        'description' => 'Compare CTR of discoverable vs anonymized keywords.',
        'icon'          => 'speed',
        'color'         => 'orange',
    ),
);

// Get page permalinks
foreach ( $tools as $slug => &$tool ) {
    $page = get_page_by_path( $slug );
    if ( $page ) {
        $tool['url'] = get_permalink( $page->ID );
    } else {
        $tool['url'] = home_url( '/' );
    }
}

?>

<main id="main" class="bite-dashboard-page" role="main">
    
    <!-- Welcome Section -->
    <section class="bite-dashboard-welcome">
        <div class="bite-welcome-content">
            <h1 class="bite-welcome-title">
                Welcome back, <?php echo esc_html( $current_user->display_name ); ?>!
            </h1>
            <p class="bite-welcome-subtitle">
                Here's your BITE dashboard overview. You have access to 
                <strong><?php echo count( $user_sites ); ?> site<?php echo count( $user_sites ) !== 1 ? 's' : ''; ?></strong>
                <?php if ( ! empty( $user_niches ) ) : ?>
                    across <strong><?php echo count( $user_niches ); ?> niche<?php echo count( $user_niches ) !== 1 ? 's' : ''; ?></strong>
                <?php endif; ?>.
            </p>
        </div>
        <div class="bite-welcome-actions">
            <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="bite-button bite-button-secondary">
                <span class="bite-icon">📊</span> Classic Dashboard
            </a>
            <?php if ( $is_admin ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=bite-admin-main' ) ); ?>" class="bite-button">
                    <span class="bite-icon">⚙️</span> Manage Sites
                </a>
            <?php endif; ?>
        </div>
    </section>

    <!-- Quick Stats Cards -->
    <section class="bite-dashboard-stats">
        <div class="bite-stats-grid">
            <div class="bite-stat-card bite-stat-clicks">
                <div class="bite-stat-icon">👆</div>
                <div class="bite-stat-content">
                    <span class="bite-stat-value"><?php echo esc_html( number_format( $quick_stats['total_clicks'] ) ); ?></span>
                    <span class="bite-stat-label">Total Clicks (30 days)</span>
                </div>
            </div>
            
            <div class="bite-stat-card bite-stat-impressions">
                <div class="bite-stat-icon">👁️</div>
                <div class="bite-stat-content">
                    <span class="bite-stat-value"><?php echo esc_html( number_format( $quick_stats['total_impressions'] ) ); ?></span>
                    <span class="bite-stat-label">Total Impressions (30 days)</span>
                </div>
            </div>
            
            <div class="bite-stat-card bite-stat-ctr">
                <div class="bite-stat-icon">📈</div>
                <div class="bite-stat-content">
                    <span class="bite-stat-value"><?php echo esc_html( number_format( $quick_stats['calculated_ctr'], 2 ) ); ?>%</span>
                    <span class="bite-stat-label">Average CTR</span>
                </div>
            </div>
            
            <div class="bite-stat-card bite-stat-position">
                <div class="bite-stat-icon">🎯</div>
                <div class="bite-stat-content">
                    <span class="bite-stat-value"><?php echo esc_html( number_format( $quick_stats['avg_position'], 1 ) ); ?></span>
                    <span class="bite-stat-label">Avg Position</span>
                </div>
            </div>
        </div>
    </section>

    <div class="bite-dashboard-grid">
        
        <!-- Left Column: Sites & Tools -->
        <div class="bite-dashboard-main">
            
            <!-- Sites Section -->
            <section class="bite-dashboard-section bite-sites-section">
                <div class="bite-section-header">
                    <h2>Your Sites</h2>
                    <span class="bite-badge"><?php echo count( $user_sites ); ?> total</span>
                </div>
                
                <?php if ( ! empty( $user_sites ) ) : ?>
                    <div class="bite-sites-list">
                        <?php foreach ( array_slice( $user_sites, 0, 6 ) as $site ) : ?>
                            <div class="bite-site-card">
                                <div class="bite-site-header">
                                    <h3 class="bite-site-name"><?php echo esc_html( $site->name ); ?></h3>
                                    <?php if ( ! empty( $site->niche_name ) ) : ?>
                                        <span class="bite-site-niche"><?php echo esc_html( $site->niche_name ); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="bite-site-meta">
                                    <span class="bite-site-domain"><?php echo esc_html( $site->domain ); ?></span>
                                </div>
                                <div class="bite-site-actions">
                                    <a href="<?php echo esc_url( home_url( '/?site_id=' . $site->site_id ) ); ?>" class="bite-site-link">
                                        View Data →
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if ( count( $user_sites ) > 6 ) : ?>
                        <div class="bite-sites-more">
                            <p>And <?php echo count( $user_sites ) - 6; ?> more site<?php echo ( count( $user_sites ) - 6 ) !== 1 ? 's' : ''; ?>...</p>
                            <a href="#" class="bite-link" onclick="jQuery('.bite-sites-list').css('max-height', 'none'); jQuery(this).parent().hide(); return false;">Show All</a>
                        </div>
                    <?php endif; ?>
                <?php else : ?>
                    <div class="bite-empty-state">
                        <p>No sites assigned to your account yet.</p>
                        <?php if ( $is_admin ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=bite-admin-main' ) ); ?>" class="bite-button">Add Sites</a>
                        <?php else : ?>
                            <p>Contact your administrator for access.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Tools Section -->
            <section class="bite-dashboard-section bite-tools-section">
                <div class="bite-section-header">
                    <h2>Analysis Tools</h2>
                </div>
                
                <div class="bite-tools-grid">
                    <?php foreach ( $tools as $slug => $tool ) : ?>
                        <a href="<?php echo esc_url( $tool['url'] ); ?>" class="bite-tool-card bite-tool-<?php echo esc_attr( $tool['color'] ); ?>">
                            <div class="bite-tool-icon"><?php echo esc_html( $tool['icon'] ); ?></div>
                            <div class="bite-tool-content">
                                <h3><?php echo esc_html( $tool['title'] ); ?></h3>
                                <p><?php echo esc_html( $tool['description'] ); ?></p>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
            
        </div>

        <!-- Right Column: Sidebar -->
        <aside class="bite-dashboard-sidebar">
            
            <!-- Niches Widget -->
            <?php if ( ! empty( $user_niches ) ) : ?>
                <div class="bite-widget bite-niches-widget">
                    <h3>Your Niches</h3>
                    <ul class="bite-niches-list">
                        <?php foreach ( $user_niches as $niche ) : ?>
                            <li>
                                <span class="bite-niche-dot"></span>
                                <?php echo esc_html( $niche ); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Quick Actions Widget -->
            <div class="bite-widget bite-actions-widget">
                <h3>Quick Actions</h3>
                <ul class="bite-actions-list">
                    <li>
                        <a href="<?php echo esc_url( home_url( '/' ) ); ?>">
                            <span class="bite-action-icon">📊</span>
                            View Full Dashboard
                        </a>
                    </li>
                    <li>
                        <a href="<?php echo esc_url( home_url( '/opportunity-finder/' ) ); ?>">
                            <span class="bite-action-icon">🔍</span>
                            Find Opportunities
                        </a>
                    </li>
                    <li>
                        <a href="<?php echo esc_url( home_url( '/emerging-trends/' ) ); ?>">
                            <span class="bite-action-icon">📈</span>
                            Check Trends
                        </a>
                    </li>
                    <?php if ( $is_admin ) : ?>
                        <li>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=bite-admin-main' ) ); ?>">
                                <span class="bite-action-icon">⚙️</span>
                                Manage System
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Account Info Widget -->
            <div class="bite-widget bite-account-widget">
                <h3>Account</h3>
                <div class="bite-account-info">
                    <div class="bite-account-row">
                        <span class="bite-account-label">Logged in as:</span>
                        <span class="bite-account-value"><?php echo esc_html( $current_user->display_name ); ?></span>
                    </div>
                    <div class="bite-account-row">
                        <span class="bite-account-label">Role:</span>
                        <span class="bite-account-value"><?php echo $is_admin ? 'Administrator' : 'Client'; ?></span>
                    </div>
                    <div class="bite-account-row">
                        <span class="bite-account-label">Access to:</span>
                        <span class="bite-account-value"><?php echo count( $user_sites ); ?> site<?php echo count( $user_sites ) !== 1 ? 's' : ''; ?></span>
                    </div>
                </div>
                <a href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>" class="bite-button bite-button-small bite-button-outline">Log Out</a>
            </div>

            <!-- Help Widget -->
            <div class="bite-widget bite-help-widget">
                <h3>Need Help?</h3>
                <p>Contact OrangeWidow support for assistance with your BITE dashboard.</p>
                <a href="https://orangewidow.com/contact" target="_blank" class="bite-button bite-button-small">Contact Support</a>
            </div>
            
        </aside>
        
    </div>

</main>

<?php
get_footer();
