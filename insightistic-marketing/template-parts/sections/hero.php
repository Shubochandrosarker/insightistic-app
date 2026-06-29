<?php
/**
 * Hero section.
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$eyebrow  = insightistic_option( 'insightistic_hero_eyebrow', __( 'AI Business Analytics', 'insightistic-marketing' ) );
$title    = insightistic_option( 'insightistic_hero_title', __( 'AI Business Analytics for WordPress & WooCommerce', 'insightistic-marketing' ) );
$subtitle = insightistic_option( 'insightistic_hero_subtitle', __( 'Understand revenue, products, customers, reports and store health from one clean dashboard built for WordPress-powered businesses.', 'insightistic-marketing' ) );
?>
<section class="ins-section ins-hero" aria-labelledby="ins-hero-title">
	<div class="ins-container ins-hero__inner">
		<p class="ins-eyebrow"><?php echo esc_html( $eyebrow ); ?></p>
		<h1 id="ins-hero-title" class="ins-hero__title"><?php echo esc_html( $title ); ?></h1>
		<p class="ins-hero__subtitle"><?php echo esc_html( $subtitle ); ?></p>

		<div class="ins-hero__actions">
			<a class="ins-btn ins-btn--brand ins-btn--lg" href="<?php echo esc_url( insightistic_register_url() ); ?>">
				<?php esc_html_e( 'Start Free Trial', 'insightistic-marketing' ); ?>
				<?php echo insightistic_icon( 'arrow-right', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</a>
			<a class="ins-btn ins-btn--ghost ins-btn--lg" href="<?php echo esc_url( home_url( '/pricing' ) ); ?>">
				<?php esc_html_e( 'View Pricing', 'insightistic-marketing' ); ?>
			</a>
		</div>

		<ul class="ins-hero__stats" aria-label="<?php esc_attr_e( 'Highlights', 'insightistic-marketing' ); ?>">
			<li><strong>3 min</strong><span><?php esc_html_e( 'to connect', 'insightistic-marketing' ); ?></span></li>
			<li><strong>14 days</strong><span><?php esc_html_e( 'free trial', 'insightistic-marketing' ); ?></span></li>
			<li><strong>White-label</strong><span><?php esc_html_e( 'for agencies', 'insightistic-marketing' ); ?></span></li>
		</ul>

		<p class="ins-hero__note"><?php esc_html_e( 'No credit card · Cancel anytime · WordPress & WooCommerce native', 'insightistic-marketing' ); ?></p>
	</div>
</section>
