<?php
/**
 * Branded reports section (split).
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="ins-section ins-split" aria-labelledby="ins-reports-title">
	<div class="ins-container ins-split__inner">
		<div class="ins-split__text">
			<p class="ins-eyebrow"><?php esc_html_e( 'Email & PDF reports', 'insightistic-marketing' ); ?></p>
			<h2 id="ins-reports-title"><?php esc_html_e( 'Branded reports your clients actually read', 'insightistic-marketing' ); ?></h2>
			<p class="ins-lead"><?php esc_html_e( 'Automated weekly and monthly reports — client-ready PDFs with your logo, brand color, charts and AI recommendations, delivered by email.', 'insightistic-marketing' ); ?></p>
			<ul class="ins-checklist">
				<li><?php echo insightistic_icon( 'check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Weekly & monthly automation', 'insightistic-marketing' ); ?></li>
				<li><?php echo insightistic_icon( 'check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Your logo, colors and footer', 'insightistic-marketing' ); ?></li>
				<li><?php echo insightistic_icon( 'check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Send to clients or download as PDF', 'insightistic-marketing' ); ?></li>
			</ul>
			<a class="ins-link" href="<?php echo esc_url( home_url( '/reports' ) ); ?>"><?php esc_html_e( 'See report examples', 'insightistic-marketing' ); ?> <?php echo insightistic_icon( 'arrow-right', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
		</div>
		<div class="ins-split__visual">
			<div class="ins-reportcard">
				<div class="ins-reportcard__head">
					<span class="ins-reportcard__logo"></span>
					<span><?php esc_html_e( 'Weekly Performance — Northwind', 'insightistic-marketing' ); ?></span>
					<span class="ins-pill ins-pill--good"><?php esc_html_e( 'Weekly', 'insightistic-marketing' ); ?></span>
				</div>
				<div class="ins-reportcard__metric">
					<span><?php esc_html_e( 'Revenue this week', 'insightistic-marketing' ); ?></span>
					<strong>$18,420 <em>+12%</em></strong>
				</div>
				<div class="ins-reportcard__bars" aria-hidden="true">
					<span style="height:40%"></span><span style="height:55%"></span><span style="height:48%"></span>
					<span style="height:68%"></span><span style="height:60%"></span><span style="height:82%"></span><span style="height:74%"></span>
				</div>
				<div class="ins-reportcard__actions">
					<span class="ins-btn ins-btn--ghost ins-btn--sm"><?php esc_html_e( 'Send', 'insightistic-marketing' ); ?></span>
					<span class="ins-btn ins-btn--brand ins-btn--sm"><?php esc_html_e( 'PDF', 'insightistic-marketing' ); ?></span>
				</div>
			</div>
		</div>
	</div>
</section>
