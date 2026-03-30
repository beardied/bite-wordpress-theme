<?php
/**
 * The header for our theme.
 *
 * This template displays all of the <head> section and everything up to the main content.
 *
 * @package BITE-theme
 */
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
	<meta http-equiv="Pragma" content="no-cache">
	<meta http-equiv="Expires" content="0">
	<link rel="profile" href="https://gmpg.org/xfn/11">
    <?php // Only add admin bar margin for admins ?>
    <?php if ( current_user_can( 'manage_options' ) ) : ?>
        <style type="text/css">
            /* Fix for WordPress admin bar spacing - only for admins */
            html { margin-top: 32px !important; }
            @media screen and (max-width: 782px) {
                html { margin-top: 46px !important; }
            }
        </style>
    <?php endif; ?>
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<div id="page" class="bite-site-wrapper">
	<header id="masthead" class="bite-site-header">
		<div class="bite-header-inner">
			<!-- Logo Section -->
			<div class="bite-logo">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>">
					<?php
					$custom_logo = get_theme_mod( 'bite_logo' );
					$show_site_name = get_theme_mod( 'bite_show_site_name', true );
					
					if ( ! empty( $custom_logo ) ) {
						// Display custom logo
						echo '<img src="' . esc_url( $custom_logo ) . '" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '" class="bite-custom-logo">';
					}
					
					if ( $show_site_name || empty( $custom_logo ) ) {
						// Display site name with tagline from WordPress Customizer
						echo '<div class="bite-branding-text">';
						echo '<span class="bite-branding-name">' . esc_html( get_bloginfo( 'name' ) ) . '</span>';
						$tagline = get_bloginfo( 'description' );
						if ( ! empty( $tagline ) ) {
							echo '<span class="bite-branding-tagline">' . esc_html( $tagline ) . '</span>';
						}
						echo '</div>';
					}
					?>
				</a>
			</div>

			<?php if ( is_user_logged_in() ) : ?>
			<!-- Navigation - Custom WordPress Menu -->
			<nav id="site-navigation" class="bite-main-navigation">
                <?php
                wp_nav_menu( array(
                    'theme_location' => 'header-menu',
                    'container'      => false,
                    'fallback_cb'    => false,
                    'items_wrap'     => '<ul>%3$s</ul>',
                ) );
                ?>
			</nav>

			<!-- User Info Section -->
			<div class="bite-user-info">
				<div class="bite-user-info-top">
					<a class="bite-logout-link" href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>">Log Out</a>
				</div>
				<div class="bite-user-info-bottom">
					<span class="bite-server-time" data-server-timestamp="<?php echo esc_attr( time() ); ?>">
                        <?php
                        // Show the server time in the new format
                        echo esc_html( date( 'd-m-Y H:i:s' ) ) . ' UTC';
                        ?>
                    </span>
				</div>
			</div>
			<?php else : ?>
			<!-- Logged Out: Show Login Button -->
			<div class="bite-user-info">
				<a class="bite-logout-link" href="<?php echo esc_url( wp_login_url() ); ?>">Client Login</a>
			</div>
			<?php endif; ?>
		</div>
	</header>

	<div id="content" class="bite-site-content">
