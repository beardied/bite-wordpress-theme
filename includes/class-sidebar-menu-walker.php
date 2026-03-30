<?php
/**
 * Custom Walker for Sidebar Menu
 *
 * Adds Material Icons support to WordPress menu items.
 * Add the icon name (e.g., "search", "dashboard") to the CSS Classes field in the menu editor.
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
        
        // Find icon class (material icon names are single words like "search", "dashboard", etc.)
        $icon = 'circle'; // Default icon
        $material_icons = array(
            'dashboard', 'search', 'emoji_events', 'trending_up', 'travel_explore', 
            'speed', 'settings', 'home', 'analytics', 'bar_chart', 'pie_chart',
            'show_chart', 'insert_chart', 'assessment', 'insights', 'data_usage',
            'storage', 'cloud', 'language', 'public', 'flag', 'location_on',
            'map', 'explore', 'find_in_page', 'pageview', 'zoom_in', 'zoom_out',
            'trending_down', 'trending_flat', 'timeline', 'update', 'schedule',
            'access_time', 'calendar_today', 'event', 'date_range', 'watch_later',
            'person', 'people', 'group', 'account_circle', 'face', 'person_outline',
            'admin_panel_settings', 'manage_accounts', 'settings_applications',
            'logout', 'exit_to_app', 'power_settings_new', 'close', 'menu',
            'arrow_back', 'arrow_forward', 'chevron_left', 'chevron_right',
            'expand_more', 'expand_less', 'keyboard_arrow_down', 'keyboard_arrow_up'
        );
        
        foreach ( $classes as $class ) {
            if ( in_array( $class, $material_icons ) ) {
                $icon = $class;
                break;
            }
        }
        
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
