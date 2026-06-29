<?php
/**
 * Agency / white-label CTA band (homepage section 11).
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="ins-section ins-ctaband" aria-labelledby="ins-ctaband-title">
	<div class="ins-container">
		<div class="ins-ctaband__card">
			<div class="ins-ctaband__text">
				<p class="ins-eyebrow"><?php esc_html_e( 'For agencies', 'insightistic-marketing' ); ?></p>
				<h2 id="ins-ctaband-title"><?php esc_html_e( 'Turn client reporting into a white-label product', 'insightistic-marketing' ); ?></h2>
				<p class="ins-lead"><?php esc_html_e( 'Your logo, your colors, your domain. Give every client a branded analytics dashboard with clear reports and AI insights — then resell it.', 'insightistic-marketing' ); ?></p>
			</div>
			<div class="ins-ctaband__actions">
				<a class="ins-btn ins-btn--brand ins-btn--lg" href="<?php echo esc_url( insightistic_register_url( 'agency' ) ); ?>"><?php esc_html_e( 'Start Agency trial', 'insightistic-marketing' ); ?></a>
				<a class="ins-btn ins-btn--ghost ins-btn--lg" href="<?php echo esc_url( home_url( '/agency' ) ); ?>"><?php esc_html_e( 'See agency features', 'insightistic-marketing' ); ?></a>
			</div>
		</div>
	</div>
</section>
