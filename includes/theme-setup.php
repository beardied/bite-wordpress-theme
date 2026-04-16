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
 * 3. Hide admin bar for BITE Viewer role.
 */
function bite_hide_admin_bar_for_viewers() {
    if ( current_user_can( 'bite_viewer' ) ) {
        show_admin_bar( false );
    }
}
add_action( 'after_setup_theme', 'bite_hide_admin_bar_for_viewers' );

/**
 * 4. Block WordPress backend access for BITE Viewer role.
 * Redirects them to the dashboard page.
 */
function bite_block_backend_for_viewers() {
    // Don't block AJAX requests
    if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
        return;
    }
    
    // Don't block in admin-ajax.php
    if ( basename( $_SERVER['PHP_SELF'] ) === 'admin-ajax.php' ) {
        return;
    }
    
    // Check if user is bite_viewer trying to access admin
    if ( is_admin() && current_user_can( 'bite_viewer' ) && ! current_user_can( 'edit_posts' ) ) {
        wp_redirect( home_url( '/dashboard/' ) );
        exit;
    }
}
add_action( 'admin_init', 'bite_block_backend_for_viewers' );

/**
 * 5. Redirect BITE Viewers to dashboard on login.
 */
function bite_login_redirect_viewers( $redirect_to, $request, $user ) {
    if ( $user && is_object( $user ) && is_a( $user, 'WP_User' ) ) {
        if ( $user->has_cap( 'bite_viewer' ) && ! $user->has_cap( 'edit_posts' ) ) {
            return home_url( '/dashboard/' );
        }
    }
    return $redirect_to;
}
add_filter( 'login_redirect', 'bite_login_redirect_viewers', 10, 3 );

/**
 * 6. Force login for all pages EXCEPT the landing page.
 *
 * Redirects any user who is not logged in to the WP login page,
 * ensuring the BITE system is private. Public access allowed to landing page.
 */
function bite_force_login() {
    // Don't run if user is logged in
    if ( is_user_logged_in() ) {
        return;
    }
    
    // Always allow access to login/register pages
    $allowed_pages = array( 'wp-login.php', 'wp-register.php' );
    if ( in_array( $GLOBALS['pagenow'], $allowed_pages ) ) {
        return;
    }
    
    // Check if current page is using the landing page template
    if ( is_page_template( 'template-sales-landing.php' ) ) {
        return; // Allow access to landing page
    }
    
    // Check if current page is the contact page
    if ( is_page_template( 'template-contact.php' ) ) {
        return; // Allow access to contact page for requests
    }
    
    // Check if it's a public page (like homepage if set to landing)
    if ( is_front_page() && is_page_template( 'template-sales-landing.php' ) ) {
        return;
    }
    
    // Redirect to login
    wp_redirect( wp_login_url() );
    exit;
}
add_action( 'template_redirect', 'bite_force_login' );


/**
 * 7. Add custom login error message for BITE Viewer role.
 */
function bite_login_message( $message ) {
    if ( empty( $message ) ) {
        return '<p class="message">Welcome to B.I.T.E. (Bulk Insight Tracking Engine)<br>Please log in to access your dashboard.</p>';
    }
    return $message;
}
add_filter( 'login_message', 'bite_login_message' );


/**
 * 8. Enqueue scripts and styles.
 */
function bite_enqueue_scripts() {
    // Google Fonts - Material Icons
    wp_enqueue_style(
        'material-icons',
        'https://fonts.googleapis.com/icon?family=Material+Icons',
        array(),
        null
    );
    
    // Google Fonts - Inter
    wp_enqueue_style(
        'google-fonts-inter',
        'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap',
        array(),
        null
    );

    // Enqueue CSS Variables and main stylesheet on front-end
    if ( ! is_admin() ) {
        // CSS Variables first
        wp_enqueue_style(
            'bite-theme-style-vars',
            get_template_directory_uri() . '/assets/css/variables.css',
            array(),
            filemtime( get_template_directory() . '/assets/css/variables.css' )
        );
        
        // Main theme stylesheet
        wp_enqueue_style(
            'bite-theme-style',
            get_stylesheet_uri(),
            array( 'bite-theme-style-vars' ),
            filemtime( get_stylesheet_directory() . '/style.css' )
        );
        
        // Login page styles
        if ( in_array( $GLOBALS['pagenow'], array( 'wp-login.php', 'wp-register.php' ) ) ) {
            wp_enqueue_style(
                'bite-login-style',
                get_template_directory_uri() . '/assets/css/login-style.css',
                array( 'bite-theme-style-vars' ),
                filemtime( get_template_directory() . '/assets/css/login-style.css' )
            );
        }
        
        // Sidebar menu styles for dashboard pages
        if ( is_page_template( 'template-dashboard.php' ) || 
             is_page_template( 'template-data-view.php' ) ||
             is_page_template( 'template-opportunity-finder.php' ) ||
             is_page_template( 'template-global-champions.php' ) ||
             is_page_template( 'template-emerging-trends.php' ) ||
             is_page_template( 'template-keyword-explorer.php' ) ||
             is_page_template( 'template-ctr-efficiency.php' ) ) {
            wp_enqueue_style(
                'bite-dashboard-sidebar',
                get_template_directory_uri() . '/dashboard-sidebar.css',
                array( 'bite-theme-style-vars' ),
                filemtime( get_template_directory() . '/dashboard-sidebar.css' )
            );
            
            // jQuery UI Datepicker CSS and JS for date range pickers
            wp_enqueue_style( 'jquery-ui-css', 'https://code.jquery.com/ui/1.13.2/themes/smoothness/jquery-ui.css', array(), '1.13.2' );
            wp_enqueue_script( 'jquery-ui-datepicker' );
            
            // Chart.js for charts
            wp_enqueue_script( 'chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', array(), '4.4.1', true );
            
            // DataTables for sortable tables
            wp_enqueue_style( 'datatables-css', 'https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css', array(), '1.13.7' );
            wp_enqueue_script( 'datatables-js', 'https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js', array( 'jquery' ), '1.13.7', true );
            
            // BITE custom JS (depends on Chart.js and DataTables)
            wp_enqueue_script( 
                'bite-js', 
                get_template_directory_uri() . '/js/bite.js', 
                array( 'jquery', 'chart-js', 'datatables-js', 'jquery-ui-datepicker' ), 
                filemtime( get_template_directory() . '/js/bite.js' ), 
                true 
            );
        }
        
        // Reviews page styles
        if ( is_page_template( 'template-reviews.php' ) ) {
            wp_enqueue_style(
                'bite-reviews-style',
                get_template_directory_uri() . '/assets/css/reviews-page.css',
                array( 'bite-theme-style-vars' ),
                filemtime( get_template_directory() . '/assets/css/reviews-page.css' )
            );
        }
        
        // Contact page styles
        if ( is_page_template( 'template-contact.php' ) ) {
            wp_enqueue_style(
                'bite-contact-style',
                get_template_directory_uri() . '/assets/css/contact-page.css',
                array( 'bite-theme-style-vars' ),
                filemtime( get_template_directory() . '/assets/css/contact-page.css' )
            );
        }
        
        // Default page template styles
        if ( is_page_template( 'template-default-page.php' ) ) {
            wp_enqueue_style(
                'bite-default-page-style',
                get_template_directory_uri() . '/assets/css/default-page.css',
                array( 'bite-theme-style-vars' ),
                filemtime( get_template_directory() . '/assets/css/default-page.css' )
            );
        }
        
        // Landing page styles
        if ( is_page_template( 'template-sales-landing.php' ) ) {
            wp_enqueue_style(
                'bite-landing-style',
                get_template_directory_uri() . '/landing-page.css',
                array( 'bite-theme-style-vars' ),
                filemtime( get_template_directory() . '/landing-page.css' )
            );
        }
    }

    // Admin styles
    if ( is_admin() ) {
        // CSS Variables for admin - load on all admin pages
        wp_enqueue_style(
            'bite-theme-style-vars',
            get_template_directory_uri() . '/assets/css/variables.css',
            array(),
            filemtime( get_template_directory() . '/assets/css/variables.css' )
        );
        
        // Load admin styles on BITE admin pages AND user profile/edit pages (for plan field)
        $screen = get_current_screen();
        $load_admin_styles = false;
        
        if ( $screen ) {
            // BITE admin pages
            if ( strpos( $screen->id, 'bite-admin' ) !== false ) {
                $load_admin_styles = true;
            }
            // User profile pages (for plan dropdown)
            if ( in_array( $screen->id, array( 'profile', 'user-edit' ) ) ) {
                $load_admin_styles = true;
            }
        }
        
        if ( $load_admin_styles ) {
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
        'sidebar-menu' => __( 'Sidebar Menu (Dashboard Tools)', 'bite-theme' ),
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


/**
 * 11. Add BITE Plan field to user profile (admin only).
 */
function bite_user_plan_profile_field( $user ) {
    // Only show for administrators
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    
    $plan = get_user_meta( $user->ID, 'bite_plan', true );
    $plan = $plan ?: 'solo'; // Default to solo
    ?>
    <h2><?php _e( 'BITE Plan', 'bite-theme' ); ?></h2>
    <table class="form-table">
        <tr>
            <th><label for="bite_plan"><?php _e( 'Subscription Plan', 'bite-theme' ); ?></label></th>
            <td>
                <select name="bite_plan" id="bite_plan">
                    <option value="hosting" <?php selected( $plan, 'hosting' ); ?>><?php _e( 'OrangeWidow Hosting (Unlimited from hosting)', 'bite-theme' ); ?></option>
                    <option value="solo" <?php selected( $plan, 'solo' ); ?>><?php _e( 'Solo (3 websites)', 'bite-theme' ); ?></option>
                    <option value="pro" <?php selected( $plan, 'pro' ); ?>><?php _e( 'Pro (10 websites)', 'bite-theme' ); ?></option>
                    <option value="agency" <?php selected( $plan, 'agency' ); ?>><?php _e( 'Agency (25 websites)', 'bite-theme' ); ?></option>
                    <option value="enterprise" <?php selected( $plan, 'enterprise' ); ?>><?php _e( 'Enterprise (Unlimited)', 'bite-theme' ); ?></option>
                </select>
                <p class="description">
                    <?php _e( 'Determines how many sites the user can add to their dashboard.', 'bite-theme' ); ?>
                </p>
            </td>
        </tr>
    </table>
    <?php
}
add_action( 'show_user_profile', 'bite_user_plan_profile_field' );
add_action( 'edit_user_profile', 'bite_user_plan_profile_field' );

/**
 * 12. Save BITE Plan field.
 */
function bite_save_user_plan_field( $user_id ) {
    // Check permissions
    if ( ! current_user_can( 'edit_user', $user_id ) || ! current_user_can( 'manage_options' ) ) {
        return false;
    }
    
    if ( isset( $_POST['bite_plan'] ) ) {
        update_user_meta( $user_id, 'bite_plan', sanitize_text_field( $_POST['bite_plan'] ) );
    }
    
    return true;
}
add_action( 'personal_options_update', 'bite_save_user_plan_field' );
add_action( 'edit_user_profile_update', 'bite_save_user_plan_field' );

/**
 * 13. Get user's BITE plan.
 *
 * @param int $user_id User ID (defaults to current user)
 * @return string Plan type: hosting, solo, pro, agency, enterprise
 */
function bite_get_user_plan( $user_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    
    $plan = get_user_meta( $user_id, 'bite_plan', true );
    return $plan ?: 'solo'; // Default to solo
}

/**
 * 14. Get maximum sites allowed for user's plan.
 *
 * @param int $user_id User ID (defaults to current user)
 * @return int Maximum number of sites (0 for unlimited)
 */
function bite_get_user_site_limit( $user_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    
    $plan = bite_get_user_plan( $user_id );
    
    $limits = array(
        'hosting'    => 0,      // Unlimited (from hosting)
        'solo'       => 3,
        'pro'        => 10,
        'agency'     => 25,
        'enterprise' => 0,      // Unlimited
    );
    
    return isset( $limits[ $plan ] ) ? $limits[ $plan ] : 3;
}

/**
 * 15. Check if user can add more sites.
 *
 * @param int $user_id User ID (defaults to current user)
 * @return bool True if user can add more sites
 */
function bite_user_can_add_site( $user_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    
    $limit = bite_get_user_site_limit( $user_id );
    
    // Unlimited
    if ( $limit === 0 ) {
        return true;
    }
    
    // Count user's sites
    $user_sites = bite_get_user_sites( $user_id );
    $current_count = count( $user_sites );
    
    return $current_count < $limit;
}

/**
 * 16. Get count of sites user can still add.
 *
 * @param int $user_id User ID (defaults to current user)
 * @return int Number of sites user can still add (-1 for unlimited)
 */
function bite_get_user_remaining_sites( $user_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    
    $limit = bite_get_user_site_limit( $user_id );
    
    // Unlimited
    if ( $limit === 0 ) {
        return -1;
    }
    
    $user_sites = bite_get_user_sites( $user_id );
    $current_count = count( $user_sites );
    
    return max( 0, $limit - $current_count );
}
