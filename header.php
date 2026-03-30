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
	<link rel="profile" href="https://gmpg.org/xfn/11">
    <?php // Do not output the admin bar for BITE Viewers, but do for Admins ?>
    <?php if ( ! current_user_can( 'bite_viewer' ) ) : ?>
        <style type="text/css">
            /* Simple fix to prevent content jumping when admin bar is present */
            html { margin-top: 32px !important; }
            * html body { margin-top: 32px !important; }
            @media screen and (max-width: 782px) {
                html { margin-top: 46px !important; }
                * html body { margin-top: 46px !important; }
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
			<div class="bite-logo">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>">BITE Dashboard</a>
			</div>

			<nav id="site-navigation" class="bite-main-navigation">
                <ul>
                    <li><a href="<?php echo esc_url( home_url( '/' ) ); ?>" title="View the main keyword dashboard">Dashboard</a></li>
                    
                    <?php
                    $nav_pages = array(
                        'Opportunity Finder' => 'Find keywords that one site ranks for, but another site is missing',
                        'Global Champions'   => 'Find the top performing keywords across all sites in a single niche',
                        'Emerging Trends'    => 'Find keywords with rapid changes in impressions or clicks',
                        'Keyword Explorer'   => 'Explore all keyword variations in your database',
                        'CTR Efficiency Report' => 'Compare the CTR of Discoverable vs. Anonymized keywords'
                    );

                    foreach ( $nav_pages as $page_title => $tooltip ) {
                        $page = get_posts( array(
                            'post_type'   => 'page',
                            'title'       => $page_title,
                            'post_status' => 'publish',
                            'numberposts' => 1,
                        ) );
                        if ( ! empty( $page ) ) {
                            echo '<li><a href="' . esc_url( get_permalink( $page[0]->ID ) ) . '" title="' . esc_attr( $tooltip ) . '">' . esc_html( $page_title ) . '</a></li>';
                        }
                    }
                    ?>
                    
                    <?php
                    // Only show 'Manage Sites' link to admins
                    if ( current_user_can( 'manage_options' ) ) {
                        echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=bite-admin-main' ) ) . '" title="Manage sites, niches, and system settings">Manage Sites (Admin)</a></li>';
                    }
                    ?>
                </ul>
			</nav>

			<div class="bite-user-info">
				<span class="bite-server-time">
                    <?php
                    // Show the server time in the new format
                    echo 'Server Time: ' . esc_html( date( 'd-m-Y H:i:s' ) ) . ' (UTC)';
                    ?>
                </span>
				<a class="bite-logout-link" href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>">Log Out</a>
			</div>
		</div>
	</header>

	<div id="content" class="bite-site-content">