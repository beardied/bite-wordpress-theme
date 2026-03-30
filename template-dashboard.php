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
    $sites_table  = $wpdb->prefix . 'bite_sites';
    $niches_table = $wpdb->prefix . 'bite_niches';
    $placeholders = implode( ', ', array_fill( 0, count( $user_site_ids ), '%d' ) );
    $user_sites   = $wpdb->get_results( $wpdb->prepare(
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

// Dashboard tools menu
$dashboard_tools = array(
    array(
        'slug'  => 'dashboard',
        'title' => 'Dashboard',
        'icon'  => 'dashboard',
    ),
    array(
        'slug'  => 'opportunity-finder',
        'title' => 'Opportunity Finder',
        'icon'  => 'search',
    ),
    array(
        'slug'  => 'global-champions',
        'title' => 'Global Champions',
        'icon'  => 'emoji_events',
    ),
    array(
        'slug'  => 'emerging-trends',
        'title' => 'Emerging Trends',
        'icon'  => 'trending_up',
    ),
    array(
        'slug'  => 'keyword-explorer',
        'title' => 'Keyword Explorer',
        'icon'  => 'travel_explore',
    ),
    array(
        'slug'  => 'ctr-efficiency',
        'title' => 'CTR Efficiency',
        'icon'  => 'speed',
    ),
);

// Get current page slug for active state
$current_slug = 'dashboard';
if ( is_page() ) {
    $current_slug = get_post_field( 'post_name', get_post() );
}

?>

<!-- Material Icons Font -->
<link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">

<!-- Floating Toggle Button (visible when sidebar is collapsed) -->
<button id="bite-sidebar-toggle-float" class="bite-sidebar-toggle-float" aria-label="Open Menu" style="display: none;">
    <span class="material-icons">menu</span>
</button>

<div class="bite-dashboard-wrapper">
    
    <!-- Slide-out Sidebar -->
    <aside id="bite-sidebar" class="bite-sidebar">
        <div class="bite-sidebar-header">
            <button id="bite-sidebar-toggle" class="bite-sidebar-toggle" aria-label="Toggle Menu">
                <span class="bite-toggle-icon">☰</span>
            </button>
            <span class="bite-sidebar-title">Menu</span>
        </div>
        
        <nav class="bite-sidebar-nav">
            <ul class="bite-sidebar-menu">
                <?php foreach ( $dashboard_tools as $tool ) : 
                    $tool_url = ( $tool['slug'] === 'dashboard' ) 
                        ? get_permalink() 
                        : get_permalink( get_page_by_path( $tool['slug'] ) );
                    $is_active = ( $current_slug === $tool['slug'] ) ? 'active' : '';
                ?>
                    <li class="bite-menu-item <?php echo esc_attr( $is_active ); ?>">
                        <a href="<?php echo esc_url( $tool_url ); ?>">
                            <span class="bite-menu-icon material-icons"><?php echo esc_html( $tool['icon'] ); ?></span>
                            <span class="bite-menu-text"><?php echo esc_html( $tool['title'] ); ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            
            <?php if ( $is_admin ) : ?>
                <div class="bite-sidebar-divider"></div>
                <ul class="bite-sidebar-menu">
                    <li class="bite-menu-item">
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=bite-admin-main' ) ); ?>">
                            <span class="bite-menu-icon material-icons">settings</span>
                            <span class="bite-menu-text">Manage System</span>
                        </a>
                    </li>
                </ul>
            <?php endif; ?>
        </nav>
        
        <div class="bite-sidebar-footer">
            <!-- Account Section -->
            <div class="bite-sidebar-account">
                <div class="bite-account-header">
                    <span class="bite-account-avatar"><?php echo esc_html( strtoupper( substr( $current_user->display_name, 0, 1 ) ) ); ?></span>
                    <div class="bite-account-details">
                        <span class="bite-account-name"><?php echo esc_html( $current_user->display_name ); ?></span>
                        <span class="bite-account-role"><?php echo $is_admin ? 'Administrator' : 'Client'; ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Help Section -->
            <div class="bite-sidebar-help">
                <a href="https://orangewidow.com/contact" target="_blank" class="bite-help-link">
                    <span class="bite-menu-icon material-icons">help_outline</span>
                    <span class="bite-menu-text">Need Help?</span>
                </a>
            </div>
            
            <!-- Logout -->
            <a href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>" class="bite-sidebar-logout">
                <span class="bite-menu-icon material-icons">logout</span>
                <span class="bite-menu-text">Log Out</span>
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main id="main" class="bite-dashboard-main-content" role="main">
        
        <!-- Welcome Section -->
        <section class="bite-dashboard-welcome">
            <div class="bite-welcome-content">
                <h1 class="bite-welcome-title">
                    Welcome back, <?php echo esc_html( $current_user->display_name ); ?>!
                </h1>
                <p class="bite-welcome-subtitle">
                    You have access to 
                    <strong><?php echo count( $user_sites ); ?> site<?php echo count( $user_sites ) !== 1 ? 's' : ''; ?></strong>
                    <?php if ( ! empty( $user_niches ) ) : ?>
                        across <strong><?php echo count( $user_niches ); ?> niche<?php echo count( $user_niches ) !== 1 ? 's' : ''; ?></strong>
                    <?php endif; ?>.
                </p>
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

        <!-- Sites Section -->
        <section class="bite-dashboard-section bite-sites-section">
            <div class="bite-section-header">
                <h2>Your Sites</h2>
                <span class="bite-badge"><?php echo count( $user_sites ); ?> total</span>
            </div>
            
            <?php if ( ! empty( $user_sites ) ) : ?>
                <div class="bite-sites-list">
                    <?php foreach ( $user_sites as $site ) : ?>
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

    </main>
    
</div>

<script>
(function() {
    const sidebar = document.getElementById('bite-sidebar');
    const toggleBtn = document.getElementById('bite-sidebar-toggle');
    const floatToggleBtn = document.getElementById('bite-sidebar-toggle-float');
    
    // Check for saved state
    const sidebarCollapsed = localStorage.getItem('bite-sidebar-collapsed');
    if (sidebarCollapsed === 'true') {
        sidebar.classList.add('collapsed');
        floatToggleBtn.style.display = 'flex';
    }
    
    // Toggle function
    function toggleSidebar() {
        const isCollapsed = sidebar.classList.toggle('collapsed');
        floatToggleBtn.style.display = isCollapsed ? 'flex' : 'none';
        localStorage.setItem('bite-sidebar-collapsed', isCollapsed);
    }
    
    // Event listeners
    if (toggleBtn) {
        toggleBtn.addEventListener('click', toggleSidebar);
    }
    
    if (floatToggleBtn) {
        floatToggleBtn.addEventListener('click', toggleSidebar);
    }
    
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(e) {
        if (window.innerWidth <= 768) {
            if (!sidebar.contains(e.target) && !floatToggleBtn.contains(e.target)) {
                if (!sidebar.classList.contains('collapsed')) {
                    toggleSidebar();
                }
            }
        }
    });
})();
</script>

<?php get_footer(); ?>
