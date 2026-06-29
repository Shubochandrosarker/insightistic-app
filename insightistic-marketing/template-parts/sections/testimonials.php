<?php
/**
 * Testimonials section.
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$items = insightistic_testimonials();
if ( empty( $items ) ) {
	return;
}
?>
<section class="ins-section ins-testimonials" aria-labelledby="ins-tst-title">
	<div class="ins-container">
		<div class="ins-section__head">
			<p class="ins-eyebrow"><?php esc_html_e( 'Loved by operators & agencies', 'insightistic-marketing' ); ?></p>
			<h2 id="ins-tst-title"><?php esc_html_e( 'Clarity that earns its place in the stack', 'insightistic-marketing' ); ?></h2>
		</div>
		<div class="ins-grid ins-grid--testimonials">
			<?php foreach ( $items as $item ) : ?>
				<figure class="ins-testimonial">
					<blockquote><?php echo esc_html( $item['quote'] ); ?></blockquote>
					<figcaption>
						<span class="ins-testimonial__name"><?php echo esc_html( $item['name'] ); ?></span>
						<?php if ( ! empty( $item['role'] ) ) : ?>
							<span class="ins-testimonial__role"><?php echo esc_html( $item['role'] ); ?></span>
						<?php endif; ?>
					</figcaption>
				</figure>
			<?php endforeach; ?>
		</div>
	</div>
</section>
