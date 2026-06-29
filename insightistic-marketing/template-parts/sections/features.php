<?php
/**
 * Feature grid section.
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$features = insightistic_features();
?>
<section class="ins-section ins-features" aria-labelledby="ins-features-title">
	<div class="ins-container">
		<div class="ins-section__head">
			<p class="ins-eyebrow"><?php esc_html_e( 'Everything in one dashboard', 'insightistic-marketing' ); ?></p>
			<h2 id="ins-features-title"><?php esc_html_e( 'One clean view of the whole business', 'insightistic-marketing' ); ?></h2>
		</div>
		<div class="ins-grid ins-grid--features">
			<?php
			foreach ( $features as $feature ) {
				get_template_part( 'template-parts/cards/feature-card', null, $feature );
			}
			?>
		</div>
	</div>
</section>
