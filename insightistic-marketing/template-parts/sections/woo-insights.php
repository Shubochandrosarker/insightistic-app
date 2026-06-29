<?php
/**
 * WooCommerce analytics section (split: text + visual).
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="ins-section ins-split" aria-labelledby="ins-woo-title">
	<div class="ins-container ins-split__inner">
		<div class="ins-split__text">
			<p class="ins-eyebrow"><?php esc_html_e( 'WooCommerce analytics', 'insightistic-marketing' ); ?></p>
			<h2 id="ins-woo-title"><?php esc_html_e( 'Revenue, orders and refunds — calculated daily', 'insightistic-marketing' ); ?></h2>
			<p class="ins-lead"><?php esc_html_e( 'Insightistic reads your WooCommerce data and turns it into clear, current numbers — so you open the dashboard and immediately know how the store is doing.', 'insightistic-marketing' ); ?></p>
			<ul class="ins-checklist">
				<li><?php echo insightistic_icon( 'check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Gross & net revenue, AOV, discounts and refunds', 'insightistic-marketing' ); ?></li>
				<li><?php echo insightistic_icon( 'check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Order trends and payment-method breakdown', 'insightistic-marketing' ); ?></li>
				<li><?php echo insightistic_icon( 'check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'New vs returning revenue and repeat-rate', 'insightistic-marketing' ); ?></li>
			</ul>
			<a class="ins-link" href="<?php echo esc_url( home_url( '/woocommerce-analytics' ) ); ?>"><?php esc_html_e( 'Explore WooCommerce analytics', 'insightistic-marketing' ); ?> <?php echo insightistic_icon( 'arrow-right', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
		</div>
		<div class="ins-split__visual">
			<div class="ins-panel-card">
				<div class="ins-preview__metrics ins-preview__metrics--2">
					<?php
					foreach ( array(
						array( 'label' => 'Gross revenue', 'value' => '$48,920', 'delta' => '+12.4%' ),
						array( 'label' => 'Net revenue', 'value' => '$45,370', 'delta' => '+11.0%' ),
						array( 'label' => 'Avg order value', 'value' => '$38.10', 'delta' => '-2.0%' ),
						array( 'label' => 'Refunds', 'value' => '$1,210', 'delta' => '+0.4%' ),
					) as $metric ) {
						get_template_part( 'template-parts/cards/metric-card', null, $metric );
					}
					?>
				</div>
			</div>
		</div>
	</div>
</section>
