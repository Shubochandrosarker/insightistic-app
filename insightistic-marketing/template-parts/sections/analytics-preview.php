<?php
/**
 * SaaS dashboard preview visual (homepage section 3).
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$metrics = insightistic_preview_metrics();
?>
<section class="ins-section ins-preview" aria-label="<?php esc_attr_e( 'Dashboard preview', 'insightistic-marketing' ); ?>">
	<div class="ins-container">
		<div class="ins-browser">
			<div class="ins-browser__bar">
				<span class="ins-browser__dot"></span><span class="ins-browser__dot"></span><span class="ins-browser__dot"></span>
				<span class="ins-browser__url">app.insightistic.com/overview</span>
			</div>
			<div class="ins-browser__body">
				<div class="ins-preview__metrics">
					<?php
					foreach ( $metrics as $metric ) {
						get_template_part( 'template-parts/cards/metric-card', null, $metric );
					}
					?>
				</div>
				<div class="ins-preview__lower">
					<figure class="ins-preview__chart" aria-hidden="true">
						<figcaption><?php esc_html_e( 'Revenue · last 30 days', 'insightistic-marketing' ); ?></figcaption>
						<svg viewBox="0 0 600 200" preserveAspectRatio="none" role="presentation">
							<defs>
								<linearGradient id="insArea" x1="0" y1="0" x2="0" y2="1">
									<stop offset="0%" stop-color="#00C04B" stop-opacity="0.35"/>
									<stop offset="100%" stop-color="#00C04B" stop-opacity="0"/>
								</linearGradient>
							</defs>
							<path d="M0,165 C40,160 70,150 110,150 150,150 170,130 210,128 250,126 270,110 310,100 350,90 380,78 420,60 460,45 490,40 540,26 570,20 590,16 600,12 L600,200 L0,200 Z" fill="url(#insArea)"/>
							<path d="M0,165 C40,160 70,150 110,150 150,150 170,130 210,128 250,126 270,110 310,100 350,90 380,78 420,60 460,45 490,40 540,26 570,20 590,16 600,12" fill="none" stroke="#00C04B" stroke-width="2.5"/>
						</svg>
					</figure>
					<div class="ins-preview__insight">
						<span class="ins-eyebrow ins-eyebrow--violet"><?php esc_html_e( 'AI insight', 'insightistic-marketing' ); ?></span>
						<p><?php esc_html_e( 'Returning customers slowed 24% — send a win-back to the 30–90 day cohort.', 'insightistic-marketing' ); ?></p>
					</div>
				</div>
			</div>
		</div>
	</div>
</section>
