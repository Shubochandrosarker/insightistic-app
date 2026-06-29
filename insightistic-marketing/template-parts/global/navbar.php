<?php
/**
 * Sticky, mobile-friendly site navigation.
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<header class="ins-header" id="ins-header">
	<div class="ins-container ins-header__inner">

		<div class="ins-header__brand">
			<?php if ( has_custom_logo() ) : ?>
				<?php the_custom_logo(); ?>
			<?php else : ?>
				<a class="ins-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
					<span class="ins-logo__dot" aria-hidden="true"></span>
					<span class="ins-logo__text"><?php bloginfo( 'name' ); ?></span>
				</a>
			<?php endif; ?>
		</div>

		<nav class="ins-nav" aria-label="<?php esc_attr_e( 'Primary', 'insightistic-marketing' ); ?>">
			<?php
			if ( has_nav_menu( 'primary' ) ) {
				wp_nav_menu(
					array(
						'theme_location' => 'primary',
						'container'      => false,
						'menu_class'     => 'ins-menu',
						'depth'          => 2,
						'fallback_cb'    => false,
					)
				);
			} else {
				echo '<ul class="ins-menu">';
				$fallback = array(
					'/features'  => __( 'Features', 'insightistic-marketing' ),
					'/pricing'   => __( 'Pricing', 'insightistic-marketing' ),
					'/agency'    => __( 'Agency', 'insightistic-marketing' ),
					'/blog'      => __( 'Blog', 'insightistic-marketing' ),
				);
				foreach ( $fallback as $path => $label ) {
					printf( '<li><a href="%s">%s</a></li>', esc_url( home_url( $path ) ), esc_html( $label ) );
				}
				echo '</ul>';
			}
			?>
		</nav>

		<div class="ins-header__actions">
			<a class="ins-btn ins-btn--ghost ins-header__login" href="<?php echo esc_url( insightistic_login_url() ); ?>">
				<?php esc_html_e( 'Sign in', 'insightistic-marketing' ); ?>
			</a>
			<a class="ins-btn ins-btn--brand" href="<?php echo esc_url( insightistic_register_url() ); ?>">
				<?php esc_html_e( 'Start free trial', 'insightistic-marketing' ); ?>
			</a>
		</div>

		<button class="ins-burger" id="ins-burger" aria-expanded="false" aria-controls="ins-mobile" aria-label="<?php esc_attr_e( 'Open menu', 'insightistic-marketing' ); ?>">
			<?php echo insightistic_icon( 'menu', 24 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — pre-escaped by insightistic_icon(). ?>
			<?php echo insightistic_icon( 'x', 24 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</button>
	</div>

	<!-- Mobile slide-down panel -->
	<div class="ins-mobile" id="ins-mobile" hidden>
		<nav aria-label="<?php esc_attr_e( 'Mobile', 'insightistic-marketing' ); ?>">
			<?php
			if ( has_nav_menu( 'primary' ) ) {
				wp_nav_menu(
					array(
						'theme_location' => 'primary',
						'container'      => false,
						'menu_class'     => 'ins-mobile__menu',
						'depth'          => 2,
						'fallback_cb'    => false,
					)
				);
			}
			?>
		</nav>
		<div class="ins-mobile__actions">
			<a class="ins-btn ins-btn--ghost" href="<?php echo esc_url( insightistic_login_url() ); ?>"><?php esc_html_e( 'Sign in', 'insightistic-marketing' ); ?></a>
			<a class="ins-btn ins-btn--brand" href="<?php echo esc_url( insightistic_register_url() ); ?>"><?php esc_html_e( 'Start free trial', 'insightistic-marketing' ); ?></a>
		</div>
	</div>
</header>
