<?php
/**
 * Footer: brand + newsletter + widget columns + legal row.
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ins_socials = array(
	'twitter'  => 'X / Twitter',
	'linkedin' => 'LinkedIn',
	'youtube'  => 'YouTube',
	'github'   => 'GitHub',
);
?>
<footer class="ins-footer" role="contentinfo">
	<div class="ins-container">

		<div class="ins-footer__top">
			<div class="ins-footer__brand">
				<a class="ins-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
					<span class="ins-logo__dot" aria-hidden="true"></span>
					<span class="ins-logo__text"><?php bloginfo( 'name' ); ?></span>
				</a>
				<p class="ins-footer__tagline">
					<?php echo esc_html( insightistic_option( 'insightistic_footer_tagline', __( 'AI business analytics for WordPress & WooCommerce.', 'insightistic-marketing' ) ) ); ?>
				</p>

				<div class="ins-footer__newsletter">
					<h3><?php esc_html_e( 'Get product updates', 'insightistic-marketing' ); ?></h3>
					<?php insightistic_formistic_form( 'newsletter' ); ?>
				</div>
			</div>

			<div class="ins-footer__cols">
				<?php if ( is_active_sidebar( 'footer-1' ) ) : ?>
					<div class="ins-footer__col"><?php dynamic_sidebar( 'footer-1' ); ?></div>
				<?php endif; ?>
				<?php if ( is_active_sidebar( 'footer-2' ) ) : ?>
					<div class="ins-footer__col"><?php dynamic_sidebar( 'footer-2' ); ?></div>
				<?php endif; ?>
				<?php if ( is_active_sidebar( 'footer-3' ) ) : ?>
					<div class="ins-footer__col"><?php dynamic_sidebar( 'footer-3' ); ?></div>
				<?php endif; ?>

				<?php if ( ! is_active_sidebar( 'footer-1' ) && ! is_active_sidebar( 'footer-2' ) && ! is_active_sidebar( 'footer-3' ) ) : ?>
					<div class="ins-footer__col">
						<h3><?php esc_html_e( 'Product', 'insightistic-marketing' ); ?></h3>
						<ul>
							<li><a href="<?php echo esc_url( home_url( '/features' ) ); ?>"><?php esc_html_e( 'Features', 'insightistic-marketing' ); ?></a></li>
							<li><a href="<?php echo esc_url( home_url( '/pricing' ) ); ?>"><?php esc_html_e( 'Pricing', 'insightistic-marketing' ); ?></a></li>
							<li><a href="<?php echo esc_url( home_url( '/integrations' ) ); ?>"><?php esc_html_e( 'Integrations', 'insightistic-marketing' ); ?></a></li>
							<li><a href="<?php echo esc_url( home_url( '/agency' ) ); ?>"><?php esc_html_e( 'For agencies', 'insightistic-marketing' ); ?></a></li>
						</ul>
					</div>
					<div class="ins-footer__col">
						<h3><?php esc_html_e( 'Company', 'insightistic-marketing' ); ?></h3>
						<ul>
							<li><a href="<?php echo esc_url( home_url( '/about' ) ); ?>"><?php esc_html_e( 'About', 'insightistic-marketing' ); ?></a></li>
							<li><a href="<?php echo esc_url( home_url( '/blog' ) ); ?>"><?php esc_html_e( 'Blog', 'insightistic-marketing' ); ?></a></li>
							<li><a href="<?php echo esc_url( home_url( '/contact' ) ); ?>"><?php esc_html_e( 'Contact', 'insightistic-marketing' ); ?></a></li>
						</ul>
					</div>
					<div class="ins-footer__col">
						<h3><?php esc_html_e( 'App', 'insightistic-marketing' ); ?></h3>
						<ul>
							<li><a href="<?php echo esc_url( insightistic_login_url() ); ?>"><?php esc_html_e( 'Sign in', 'insightistic-marketing' ); ?></a></li>
							<li><a href="<?php echo esc_url( insightistic_register_url() ); ?>"><?php esc_html_e( 'Start free trial', 'insightistic-marketing' ); ?></a></li>
							<li><a href="<?php echo esc_url( insightistic_dashboard_url() ); ?>"><?php esc_html_e( 'Dashboard', 'insightistic-marketing' ); ?></a></li>
						</ul>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<div class="ins-footer__bottom">
			<p class="ins-footer__copy">
				&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?> · <?php esc_html_e( 'by Wordpressistic', 'insightistic-marketing' ); ?>
			</p>

			<?php
			if ( has_nav_menu( 'legal' ) ) {
				wp_nav_menu(
					array(
						'theme_location' => 'legal',
						'container'      => false,
						'menu_class'     => 'ins-footer__legal',
						'depth'          => 1,
						'fallback_cb'    => false,
					)
				);
			} else {
				echo '<ul class="ins-footer__legal">';
				printf( '<li><a href="%s">%s</a></li>', esc_url( home_url( '/privacy-policy' ) ), esc_html__( 'Privacy', 'insightistic-marketing' ) );
				printf( '<li><a href="%s">%s</a></li>', esc_url( home_url( '/terms' ) ), esc_html__( 'Terms', 'insightistic-marketing' ) );
				echo '</ul>';
			}
			?>

			<div class="ins-footer__social">
				<?php
				foreach ( $ins_socials as $slug => $label ) {
					$url = insightistic_option( 'insightistic_social_' . $slug, '' );
					if ( $url ) {
						printf(
							'<a href="%1$s" target="_blank" rel="noopener" aria-label="%2$s">%2$s</a>',
							esc_url( $url ),
							esc_html( $label )
						);
					}
				}
				?>
			</div>
		</div>
	</div>
</footer>
