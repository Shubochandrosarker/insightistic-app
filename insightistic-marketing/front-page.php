<?php
/**
 * Homepage — the 14-section marketing landing, composed from template parts.
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<main id="main" class="ins-main ins-home">
	<?php
	get_template_part( 'template-parts/sections/hero' );             // 2
	get_template_part( 'template-parts/sections/analytics-preview' ); // 3 — dashboard preview
	get_template_part( 'template-parts/sections/logo-cloud' );        // trust bar
	get_template_part( 'template-parts/sections/problem' );           // 4
	get_template_part( 'template-parts/sections/features' );          // 5
	get_template_part( 'template-parts/sections/woo-insights' );      // 6
	get_template_part( 'template-parts/sections/ai-insights' );       // 7
	get_template_part( 'template-parts/sections/reports' );           // 8
	get_template_part( 'template-parts/sections/connector' );         // 9
	get_template_part( 'template-parts/sections/pricing' );           // 10
	get_template_part( 'template-parts/global/cta-band' );            // 11 — agency / white-label
	get_template_part( 'template-parts/sections/testimonials' );      // social proof
	get_template_part( 'template-parts/sections/faq' );               // 12
	get_template_part( 'template-parts/sections/final-cta' );         // 13
	?>
</main>
<?php
get_footer();
