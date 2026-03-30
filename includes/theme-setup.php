<?php
/**
 * Core Theme Setup: Roles, Redirects, and Security.
 *
 * @package BITE-theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * 1. Create the "BITE Viewer" Custom User Role on theme activation.
 */
function bite_activate_theme() {
    // Add the custom role
    add_role(
        'bite_viewer',
        __( 'BITE Viewer' ),
        array(
            'read' => true, // Basic read capability
        )
    );
}
add_action( 'after_switch_theme', 'bite_activate_theme' );

/**
 * 2. Remove the "BITE Viewer" role on theme deactivation.
 */
function bite_deactivate_theme() {
    remove_role( 'bite_viewer' );
}
add_action( 'switch_theme', 'bite_deactivate_theme' );


/**
 * 3. Force login for all pages EXCEPT the landing page.
 *
 * Redirects any user who is not logged in to the WP login page,
 * ensuring the BITE system is private. Public access allowed to landing page.
 */
function bite_force_login() {
    // Allow access to login/register pages and the landing page template
    if ( ! is_user_logged_in() ) {
        $allowed_pages = array( 'wp-login.php', 'wp-register.php' );
        
        // Check if current page is using the landing page template
        if ( is_page_template( 'template-sales-landing.php' ) ) {
            return; // Allow access to landing page
        }
        
        if ( ! in_array( $GLOBALS['pagenow'], $allowed_pages ) ) {
            wp_redirect( wp_login_url() );
            exit;
        }
    }
}
add_action( 'template_redirect', 'bite_force_login' );

/**
 * 4. Hide the WP Admin Bar for "BITE Viewer" role.
 */
function bite_hide_admin_bar_for_viewer( $show ) {
    if ( current_user_can( 'bite_viewer' ) ) {
        return false; // Do not show admin bar
    }
    return $show; // Show for all other users (like admin)
}
add_action( 'show_admin_bar', 'bite_hide_admin_bar_for_viewer' );

/**
 * 5. Redirect "BITE Viewer" away from the WP Admin Dashboard.
 *
 * If a BITE Viewer tries to access /wp-admin/, send them to the homepage,
 * which will be our BITE dashboard.
 */
function bite_redirect_viewer_from_admin() {
    if ( current_user_can( 'bite_viewer' ) && is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
        wp_redirect( home_url() );
        exit;
    }
}
add_action( 'admin_init', 'bite_redirect_viewer_from_admin' );

/**
 * 6. Enqueue theme scripts and styles.
 */
function bite_enqueue_scripts() {
    
    // Check if we are on the front-end (our dashboard)
    if ( ! is_admin() ) {
        
        // Enqueue the main stylesheet with cache busting
        wp_enqueue_style(
            'bite-theme-style',
            get_stylesheet_uri(),
            array(),
            filemtime( get_stylesheet_directory() . '/style.css' ) // Auto cache bust based on file modification time
        );

        // Enqueue landing page CSS if on landing page
        if ( is_page_template( 'template-sales-landing.php' ) ) {
            wp_enqueue_style(
                'bite-landing-style',
                get_template_directory_uri() . '/landing-page.css',
                array(),
                filemtime( get_template_directory() . '/landing-page.css' )
            );
        }

        // --- jQuery & Datepicker ---
        wp_enqueue_script( 'jquery' );
        wp_enqueue_script( 'jquery-ui-datepicker' );
        wp_enqueue_style(
            'jquery-ui-css',
            '//ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css'
        );

        // --- Chart.js ---
        wp_enqueue_script(
            'chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js',
            array(),
            '4.4.3',
            true
        );

        // --- DataTables.js (for sortable tables) ---
        wp_enqueue_script(
            'datatables-js',
            'https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js',
            array( 'jquery' ),
            '1.13.6',
            true
        );
        wp_enqueue_style(
            'datatables-css',
            'https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css'
        );

        // --- Our Custom JS File ---
        wp_enqueue_script(
            'bite-theme-js',
            get_template_directory_uri() . '/js/bite.js',
            array( 'jquery', 'jquery-ui-datepicker', 'chart-js', 'datatables-js' ),
            filemtime( get_template_directory() . '/js/bite.js' ), // Auto cache bust
            true // Load in footer
        );
    
    } else {
        // We are on an admin page.
        $screen = get_current_screen();
        if ( $screen && strpos( $screen->id, 'bite-admin' ) !== false ) {
            
            wp_enqueue_style(
                'bite-theme-style-vars',
                get_stylesheet_uri(),
                array(),
                filemtime( get_stylesheet_directory() . '/style.css' )
            );

            wp_enqueue_style(
                'bite-admin-style',
                get_template_directory_uri() . '/admin-style.css',
                array( 'bite-theme-style-vars' ),
                filemtime( get_template_directory() . '/admin-style.css' )
            );
        }
    }
}
add_action( 'wp_enqueue_scripts', 'bite_enqueue_scripts' ); // Front-end
add_action( 'admin_enqueue_scripts', 'bite_enqueue_scripts' ); // Back-end

/**
 * 7. Add Customizer support for Logo.
 */
function bite_customize_register( $wp_customize ) {
    // Logo Setting - Adds to Site Identity section
    $wp_customize->add_setting( 'bite_logo', array(
        'default'           => '',
        'sanitize_callback' => 'esc_url_raw',
        'transport'         => 'refresh',
    ) );

    // Logo Control - Placed in Site Identity section
    $wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, 'bite_logo', array(
        'label'       => __( 'Header Logo Image', 'bite-theme' ),
        'description' => __( 'Upload a logo to display next to your site title. Recommended: 65px height, transparent PNG.', 'bite-theme' ),
        'section'     => 'title_tagline', // Site Identity section
        'settings'    => 'bite_logo',
        'priority'    => 9, // Just after site icon
    ) ) );

    // Show Site Name Setting
    $wp_customize->add_setting( 'bite_show_site_name', array(
        'default'           => true,
        'sanitize_callback' => 'wp_validate_boolean',
        'transport'         => 'refresh',
    ) );

    // Show Site Name Control
    $wp_customize->add_control( 'bite_show_site_name', array(
        'label'       => __( 'Show Site Title & Tagline with Logo', 'bite-theme' ),
        'description' => __( 'If checked, the site title and tagline will be displayed next to the logo.', 'bite-theme' ),
        'section'     => 'title_tagline', // Site Identity section
        'type'        => 'checkbox',
        'priority'    => 10,
    ) );
}
add_action( 'customize_register', 'bite_customize_register' );

/**
 * 8. Register Navigation Menus.
 */
function bite_register_menus() {
    register_nav_menus( array(
        'header-menu'  => __( 'Header Menu', 'bite-theme' ),
        'footer-left'  => __( 'Footer Left Menu', 'bite-theme' ),
        'footer-right' => __( 'Footer Right Menu', 'bite-theme' ),
    ) );
}
add_action( 'after_setup_theme', 'bite_register_menus' );


/**
 * 9. Redirect users to Dashboard after login.
 */
function bite_login_redirect( $redirect_to, $request, $user ) {
    // If user is logged in and not admin
    if ( isset( $user->ID ) && ! is_wp_error( $user ) ) {
        // Get the dashboard page
        $dashboard_page = get_page_by_path( 'dashboard' );
        
        if ( $dashboard_page ) {
            return get_permalink( $dashboard_page->ID );
        }
        
        // Fallback to home if dashboard page doesn't exist
        return home_url( '/' );
    }
    
    return $redirect_to;
}
add_filter( 'login_redirect', 'bite_login_redirect', 10, 3 );

/**
 * 10. Also redirect on wp_login action (backup method).
 */
function bite_after_login_redirect( $user_login, $user ) {
    // Don't redirect if doing ajax or admin
    if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
        return;
    }
    
    if ( is_admin() ) {
        return;
    }
    
    // Get the dashboard page
    $dashboard_page = get_page_by_path( 'dashboard' );
    
    if ( $dashboard_page ) {
        wp_redirect( get_permalink( $dashboard_page->ID ) );
        exit;
    }
}
add_action( 'wp_login', 'bite_after_login_redirect', 10, 2 );
