<?php
/**
 * Final CTA section.
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="ins-section ins-finalcta" aria-labelledby="ins-finalcta-title">
	<div class="ins-container ins-narrow ins-center">
		<h2 id="ins-finalcta-title"><?php esc_html_e( 'Stop guessing. Start seeing the business clearly.', 'insightistic-marketing' ); ?></h2>
		<p class="ins-lead"><?php esc_html_e( 'Connect your WordPress or WooCommerce store and let AI explain what changed, why it matters, and what to do next.', 'insightistic-marketing' ); ?></p>
		<div class="ins-hero__actions ins-center">
			<a class="ins-btn ins-btn--brand ins-btn--lg" href="<?php echo esc_url( insightistic_register_url() ); ?>"><?php esc_html_e( 'Start Free Trial', 'insightistic-marketing' ); ?> <?php echo insightistic_icon( 'arrow-right', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
			<a class="ins-btn ins-btn--ghost ins-btn--lg" href="<?php echo esc_url( insightistic_login_url() ); ?>"><?php esc_html_e( 'Sign in', 'insightistic-marketing' ); ?></a>
		</div>
		<p class="ins-hero__note"><?php esc_html_e( '14-day free trial · No credit card · Cancel anytime', 'insightistic-marketing' ); ?></p>
	</div>
</section>
