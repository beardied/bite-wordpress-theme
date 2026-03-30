<?php
/**
 * Custom Login Page Styling
 *
 * Styles the WordPress login page to match the BITE theme branding.
 *
 * @package BITE-theme
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Enqueue custom login styles
 */
function bite_login_styles() {
    // Get logo from customizer
    $custom_logo = get_theme_mod( 'bite_logo' );
    $site_name = get_bloginfo( 'name' );
    $tagline = get_bloginfo( 'description' );
    
    // Material Icons
    // Use self-hosted Material Icons font
    wp_enqueue_style(
        'bite-material-icons',
        get_template_directory_uri() . '/assets/css/fonts.css',
        array(),
        filemtime( get_template_directory() . '/assets/css/fonts.css' )
    );
    
    // Custom inline styles
    $custom_css = '
        /* Login Page Body */
        body.login {
            background: linear-gradient(135deg, #1A1A2E 0%, #16213E 50%, #0F3460 100%);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        
        /* Login Container */
        body.login #login {
            width: 400px;
            padding: 40px;
        }
        
        /* Login Form */
        body.login form {
            background: #ffffff;
            border: none;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 40px;
        }
        
        /* Logo Area */
        body.login h1 {
            margin-bottom: 20px;
        }
        
        body.login h1 a {
            background-image: none !important;
            background-size: contain;
            width: 100%;
            height: auto;
            text-indent: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            color: #ffffff;
        }
        
        /* Custom Logo Image */
        .bite-login-logo {
            max-height: 80px;
            max-width: 200px;
            margin-bottom: 15px;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.3));
        }
        
        /* Site Title */
        .bite-login-site-title {
            font-size: 2em;
            font-weight: 700;
            color: #E85D04;
            margin: 0 0 5px 0;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }
        
        /* Tagline */
        .bite-login-tagline {
            font-size: 1em;
            color: rgba(255, 255, 255, 0.8);
            font-weight: 400;
            margin: 0;
        }
        
        /* Form Labels */
        body.login label {
            color: #1A1A2E;
            font-weight: 600;
            font-size: 0.95em;
            margin-bottom: 8px;
            display: block;
        }
        
        /* Input Fields */
        body.login input[type="text"],
        body.login input[type="password"] {
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 12px 15px;
            font-size: 1em;
            color: #1A1A2E;
            transition: all 0.2s ease;
        }
        
        body.login input[type="text"]:focus,
        body.login input[type="password"]:focus {
            border-color: #E85D04;
            box-shadow: 0 0 0 3px rgba(232, 93, 4, 0.15);
            outline: none;
        }
        
        /* Remember Me Checkbox */
        body.login .forgetmenot label {
            font-weight: 400;
            color: #6c757d;
            font-size: 0.9em;
        }
        
        /* Submit Button */
        body.login .button-primary {
            background: linear-gradient(135deg, #E85D04 0%, #e55f22 100%);
            border: none;
            border-radius: 8px;
            color: #ffffff;
            font-weight: 600;
            font-size: 1em;
            padding: 12px 24px;
            height: auto;
            box-shadow: 0 4px 12px rgba(232, 93, 4, 0.3);
            transition: all 0.2s ease;
        }
        
        body.login .button-primary:hover {
            background: linear-gradient(135deg, #e55f22 0%, #F48C06 100%);
            box-shadow: 0 6px 16px rgba(232, 93, 4, 0.4);
            transform: translateY(-1px);
        }
        
        body.login .button-primary:active {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(232, 93, 4, 0.3);
        }
        
        /* Links (Lost password, etc.) */
        body.login #nav,
        body.login #backtoblog {
            text-align: center;
        }
        
        body.login #nav a,
        body.login #backtoblog a {
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s ease;
        }
        
        body.login #nav a:hover,
        body.login #backtoblog a:hover {
            color: #E85D04;
        }
        
        /* Error Messages */
        body.login #login_error,
        body.login .message {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            color: #856404;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        body.login #login_error {
            background: #f8d7da;
            border-color: #f5c6cb;
            color: #721c24;
        }
        
        /* Privacy Policy Link */
        body.login .privacy-policy-page-link {
            text-align: center;
            margin-top: 20px;
        }
        
        body.login .privacy-policy-page-link a {
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.85em;
        }
        
        /* Language Switcher */
        body.login #language-switcher {
            margin-top: 20px;
            text-align: center;
        }
        
        body.login .language-switcher {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #ffffff;
            border-radius: 6px;
            padding: 8px 12px;
        }
        
        /* Powered by text - hide it */
        body.login .powered-by {
            display: none;
        }
        
        /* Mobile Responsive */
        @media screen and (max-width: 480px) {
            body.login #login {
                width: 90%;
                padding: 20px;
            }
            
            body.login form {
                padding: 25px;
            }
            
            .bite-login-site-title {
                font-size: 1.5em;
            }
        }
    ';
    
    wp_add_inline_style( 'login', $custom_css );
}
add_action( 'login_enqueue_scripts', 'bite_login_styles' );

/**
 * Custom login logo URL
 */
function bite_login_logo_url() {
    return home_url();
}
add_filter( 'login_headerurl', 'bite_login_logo_url' );

/**
 * Custom login logo title
 */
function bite_login_logo_title() {
    return get_bloginfo( 'name' );
}
add_filter( 'login_headertext', 'bite_login_logo_title' );

/**
 * Custom login header with logo, site title and tagline
 */
function bite_login_header() {
    $custom_logo = get_theme_mod( 'bite_logo' );
    $site_name = get_bloginfo( 'name' );
    $tagline = get_bloginfo( 'description' );
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            var loginH1 = document.querySelector('h1 a');
            if (loginH1) {
                loginH1.innerHTML = '';
                
                <?php if ( ! empty( $custom_logo ) ) : ?>
                    var logoImg = document.createElement('img');
                    logoImg.src = '<?php echo esc_url( $custom_logo ); ?>';
                    logoImg.alt = '<?php echo esc_attr( $site_name ); ?>';
                    logoImg.className = 'bite-login-logo';
                    loginH1.appendChild(logoImg);
                <?php endif; ?>
                
                var siteTitle = document.createElement('div');
                siteTitle.className = 'bite-login-site-title';
                siteTitle.textContent = '<?php echo esc_js( $site_name ); ?>';
                loginH1.appendChild(siteTitle);
                
                <?php if ( ! empty( $tagline ) ) : ?>
                    var tagline = document.createElement('div');
                    tagline.className = 'bite-login-tagline';
                    tagline.textContent = '<?php echo esc_js( $tagline ); ?>';
                    loginH1.appendChild(tagline);
                <?php endif; ?>
            }
        });
    </script>
    <?php
}
add_action( 'login_footer', 'bite_login_header' );
