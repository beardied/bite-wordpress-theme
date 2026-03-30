<?php
/**
 * Custom Walker for Sidebar Menu
 *
 * Adds Material Icons support to WordPress menu items.
 * Icons are automatically detected from page URLs, or can be manually set via CSS Classes.
 *
 * To manually set an icon: Edit menu item → CSS Classes → Enter icon name (e.g., "search", "dashboard")
 *
 * @package BITE-theme
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * BITE Sidebar Menu Walker Class
 */
class BITE_Sidebar_Menu_Walker extends Walker_Nav_Menu {

    /**
     * Icon mapping for automatic detection based on URL patterns
     */
    private static $icon_mapping = array(
        // Dashboard
        'dashboard'         => 'dashboard',
        'home'              => 'home',
        
        // Tools
        'opportunity'       => 'search',
        'finder'            => 'search',
        'champions'         => 'emoji_events',
        'global'            => 'emoji_events',
        'trends'            => 'trending_up',
        'emerging'          => 'trending_up',
        'keyword'           => 'travel_explore',
        'explorer'          => 'travel_explore',
        'ctr'               => 'speed',
        'efficiency'        => 'speed',
        
        // Common
        'analytics'         => 'analytics',
        'report'            => 'assessment',
        'chart'             => 'insert_chart',
        'data'              => 'data_usage',
        'sites'             => 'language',
        'domains'           => 'language',
        'settings'          => 'settings',
        'config'            => 'settings',
        'admin'             => 'admin_panel_settings',
        'manage'            => 'settings_applications',
        'user'              => 'person',
        'profile'           => 'account_circle',
        'account'           => 'account_circle',
        'help'              => 'help_outline',
        'support'           => 'support_agent',
        'contact'           => 'mail',
        'logout'            => 'logout',
        'login'             => 'login',
    );

    /**
     * Full list of available Material Icons
     */
    private static $material_icons = array(
        'dashboard', 'home', 'search', 'emoji_events', 'trending_up', 'travel_explore', 
        'speed', 'settings', 'analytics', 'bar_chart', 'pie_chart', 'show_chart', 
        'insert_chart', 'assessment', 'insights', 'data_usage', 'storage', 'cloud', 
        'language', 'public', 'flag', 'location_on', 'map', 'explore', 'find_in_page', 
        'pageview', 'zoom_in', 'zoom_out', 'trending_down', 'trending_flat', 'timeline', 
        'update', 'schedule', 'access_time', 'calendar_today', 'event', 'date_range', 
        'watch_later', 'person', 'people', 'group', 'account_circle', 'face', 
        'person_outline', 'admin_panel_settings', 'manage_accounts', 'settings_applications',
        'logout', 'exit_to_app', 'power_settings_new', 'close', 'menu', 'arrow_back', 
        'arrow_forward', 'chevron_left', 'chevron_right', 'expand_more', 'expand_less', 
        'keyboard_arrow_down', 'keyboard_arrow_up', 'help_outline', 'support_agent', 
        'mail', 'login', 'star', 'favorite', 'bookmark', 'check_circle', 'error', 
        'warning', 'info', 'lightbulb', 'search_off', 'filter_list', 'sort', 'more_vert', 
        'more_horiz', 'refresh', 'sync', 'download', 'upload', 'print', 'share', 'link',
        'edit', 'delete', 'add', 'remove', 'clear', 'done', 'check', 'close'
    );

    /**
     * Get icon for a menu item
     *
     * @param WP_Post $item Menu item data object.
     * @param array   $classes Menu item classes.
     * @return string Icon name.
     */
    private function get_icon( $item, $classes ) {
        // First, check for manual icon in CSS classes
        foreach ( $classes as $class ) {
            if ( in_array( $class, self::$material_icons ) ) {
                return $class;
            }
        }
        
        // Auto-detect from URL
        $url = strtolower( $item->url );
        $title = strtolower( $item->title );
        
        // Check URL against icon mapping
        foreach ( self::$icon_mapping as $pattern => $icon ) {
            if ( strpos( $url, $pattern ) !== false ) {
                return $icon;
            }
        }
        
        // Check title against icon mapping
        foreach ( self::$icon_mapping as $pattern => $icon ) {
            if ( strpos( $title, $pattern ) !== false ) {
                return $icon;
            }
        }
        
        // Default icon
        return 'circle';
    }

    /**
     * Starts the element output.
     *
     * @param string   $output Used to append additional content (passed by reference).
     * @param WP_Post  $item   Menu item data object.
     * @param int      $depth  Depth of menu item.
     * @param stdClass $args   An object of wp_nav_menu() arguments.
     * @param int      $id     Current item ID.
     */
    public function start_el( &$output, $item, $depth = 0, $args = null, $id = 0 ) {
        $indent = ( $depth ) ? str_repeat( "\t", $depth ) : '';

        // Get classes
        $classes = empty( $item->classes ) ? array() : (array) $item->classes;
        
        // Check for active state
        $is_active = in_array( 'current-menu-item', $classes ) || 
                     in_array( 'current-menu-parent', $classes ) ||
                     in_array( 'current-menu-ancestor', $classes );
        
        // Get icon (manual or auto-detected)
        $icon = $this->get_icon( $item, $classes );
        
        // Build class attribute
        $class_names = 'bite-menu-item';
        if ( $is_active ) {
            $class_names .= ' active';
        }

        $output .= $indent . '<li class="' . esc_attr( $class_names ) . '">';

        // Link attributes
        $atts = array();
        $atts['title']  = ! empty( $item->attr_title ) ? $item->attr_title : '';
        $atts['target'] = ! empty( $item->target ) ? $item->target : '';
        $atts['rel']    = ! empty( $item->xfn ) ? $item->xfn : '';
        $atts['href']   = ! empty( $item->url ) ? $item->url : '';

        $attributes = '';
        foreach ( $atts as $attr => $value ) {
            if ( is_scalar( $value ) && '' !== $value && false !== $value ) {
                $value       = ( 'href' === $attr ) ? esc_url( $value ) : esc_attr( $value );
                $attributes .= ' ' . $attr . '="' . $value . '"';
            }
        }

        // Build menu item output
        $item_output  = $args->before ?? '';
        $item_output .= '<a' . $attributes . '>';
        $item_output .= '<span class="bite-menu-icon material-icons">' . esc_html( $icon ) . '</span>';
        $item_output .= '<span class="bite-menu-text">' . esc_html( $item->title ) . '</span>';
        $item_output .= '</a>';
        $item_output .= $args->after ?? '';

        $output .= apply_filters( 'walker_nav_menu_start_el', $item_output, $item, $depth, $args );
    }
}

/**
 * Add admin notice with icon instructions for sidebar menu
 */
function bite_sidebar_menu_admin_notice() {
    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'nav-menus' ) {
        return;
    }
    
    // Check if sidebar menu location is being edited
    if ( ! isset( $_REQUEST['menu'] ) ) {
        return;
    }
    
    $menu_locations = get_nav_menu_locations();
    $sidebar_menu_id = isset( $menu_locations['sidebar-menu'] ) ? $menu_locations['sidebar-menu'] : 0;
    $current_menu_id = absint( $_REQUEST['menu'] );
    
    if ( $sidebar_menu_id !== $current_menu_id ) {
        return;
    }
    ?>
    <div class="notice notice-info is-dismissible">
        <p><strong>Sidebar Menu Icons:</strong> Icons are automatically detected based on page URLs. To manually set an icon, add one of these CSS classes to the menu item:</p>
        <p style="font-size: 12px; color: #666;">
            <code>dashboard</code>, <code>search</code> 🔍, <code>emoji_events</code> 🏆, <code>trending_up</code> 📈, 
            <code>travel_explore</code> 🔎, <code>speed</code> ⚡, <code>settings</code> ⚙️, <code>analytics</code> 📊, 
            <code>person</code> 👤, <code>help_outline</code> ❓, <code>logout</code> 🚪
        </p>
        <p><a href="https://fonts.google.com/icons" target="_blank">View all Material Icons →</a></p>
    </div>
    <?php
}
add_action( 'admin_notices', 'bite_sidebar_menu_admin_notice' );
