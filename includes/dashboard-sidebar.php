<?php
/**
 * Dashboard Sidebar Include
 *
 * Reusable slide-out sidebar for all dashboard/tool pages.
 *
 * @package BITE-theme
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Only show for logged-in users
if ( ! is_user_logged_in() ) {
    return;
}

$current_user    = wp_get_current_user();
$is_admin        = current_user_can( 'manage_options' );

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
$current_slug = '';
if ( is_page() ) {
    $current_slug = get_post_field( 'post_name', get_post() );
}

// Try to determine current page from template if slug is empty
if ( empty( $current_slug ) ) {
    $template = get_page_template_slug();
    if ( $template ) {
        $template_name = basename( $template, '.php' );
        $template_name = str_replace( 'template-', '', $template_name );
        $current_slug = $template_name;
    }
}
?>

<!-- Material Icons Font -->
<link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">

<!-- Floating Toggle Button (visible when sidebar is collapsed) -->
<button id="bite-sidebar-toggle-float" class="bite-sidebar-toggle-float" aria-label="Open Menu" style="display: none;">
    <span class="material-icons">menu</span>
</button>

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
                // Get URL for the tool
                if ( $tool['slug'] === 'dashboard' ) {
                    $dashboard_page = get_page_by_path( 'dashboard' );
                    $tool_url = $dashboard_page ? get_permalink( $dashboard_page->ID ) : home_url( '/' );
                } else {
                    $page = get_page_by_path( $tool['slug'] );
                    $tool_url = $page ? get_permalink( $page->ID ) : home_url( '/' );
                }
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

<script>
(function() {
    const sidebar = document.getElementById('bite-sidebar');
    const toggleBtn = document.getElementById('bite-sidebar-toggle');
    const floatToggleBtn = document.getElementById('bite-sidebar-toggle-float');
    
    // Check for saved state
    const sidebarCollapsed = localStorage.getItem('bite-sidebar-collapsed');
    if (sidebarCollapsed === 'true') {
        sidebar.classList.add('collapsed');
        if (floatToggleBtn) floatToggleBtn.style.display = 'flex';
    }
    
    // Toggle function
    function toggleSidebar() {
        const isCollapsed = sidebar.classList.toggle('collapsed');
        if (floatToggleBtn) floatToggleBtn.style.display = isCollapsed ? 'flex' : 'none';
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
            if (!sidebar.contains(e.target) && floatToggleBtn && !floatToggleBtn.contains(e.target)) {
                if (!sidebar.classList.contains('collapsed')) {
                    toggleSidebar();
                }
            }
        }
    });
})();
</script>
