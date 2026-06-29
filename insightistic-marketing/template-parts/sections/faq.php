<?php
/**
 * FAQ section — accessible <details> accordion + FAQPage schema.
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$faqs = insightistic_faqs();
if ( empty( $faqs ) ) {
	return;
}

// FAQPage structured data (helps SEO; safe even alongside an SEO plugin).
$schema = array(
	'@context'   => 'https://schema.org',
	'@type'      => 'FAQPage',
	'mainEntity' => array(),
);
foreach ( $faqs as $faq ) {
	$schema['mainEntity'][] = array(
		'@type'          => 'Question',
		'name'           => wp_strip_all_tags( $faq['q'] ),
		'acceptedAnswer' => array(
			'@type' => 'Answer',
			'text'  => wp_strip_all_tags( $faq['a'] ),
		),
	);
}
?>
<section class="ins-section ins-faq" aria-labelledby="ins-faq-title">
	<div class="ins-container ins-narrow">
		<div class="ins-section__head">
			<p class="ins-eyebrow"><?php esc_html_e( 'FAQ', 'insightistic-marketing' ); ?></p>
			<h2 id="ins-faq-title"><?php esc_html_e( 'Questions, answered', 'insightistic-marketing' ); ?></h2>
		</div>

		<div class="ins-accordion">
			<?php foreach ( $faqs as $i => $faq ) : ?>
				<details class="ins-accordion__item"<?php echo 0 === $i ? ' open' : ''; ?>>
					<summary class="ins-accordion__q"><?php echo esc_html( $faq['q'] ); ?></summary>
					<div class="ins-accordion__a"><p><?php echo esc_html( $faq['a'] ); ?></p></div>
				</details>
			<?php endforeach; ?>
		</div>
	</div>
</section>
<script type="application/ld+json"><?php echo wp_json_encode( $schema ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — JSON-encoded structured data. ?></script>
