<?php
/**
 * The footer for our theme.
 *
 * This template closes all open HTML tags from header.php.
 *
 * @package BITE-theme
 */
?>
	</div><footer id="colophon" class="bite-site-footer">
		<div class="bite-footer-inner">
			<!-- Footer Left Menu -->
			<nav class="bite-footer-menu bite-footer-menu-left">
				<?php
				wp_nav_menu( array(
					'theme_location' => 'footer-left',
					'container'      => false,
					'fallback_cb'    => false,
					'items_wrap'     => '<ul>%3$s</ul>',
				) );
				?>
			</nav>
			
			<!-- Copyright Center -->
			<p class="bite-footer-copyright">&copy; <?php echo esc_html( date( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?></p>
			
			<!-- Footer Right Menu -->
			<nav class="bite-footer-menu bite-footer-menu-right">
				<?php
				wp_nav_menu( array(
					'theme_location' => 'footer-right',
					'container'      => false,
					'fallback_cb'    => false,
					'items_wrap'     => '<ul>%3$s</ul>',
				) );
				?>
			</nav>
		</div>
	</footer>
</div><?php wp_footer(); ?>

</body>
</html>