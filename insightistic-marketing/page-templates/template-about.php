<?php
/**
 * Template Name: About
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<main id="main" class="ins-main ins-page-template">
	<?php while ( have_posts() ) : the_post(); ?>
		<header class="ins-section ins-page-hero">
			<div class="ins-container ins-narrow ins-center">
				<p class="ins-eyebrow"><?php esc_html_e( 'About', 'insightistic-marketing' ); ?></p>
				<h1 class="ins-page-hero__title"><?php echo esc_html( get_the_title() ? get_the_title() : __( 'Business clarity for WordPress-powered teams', 'insightistic-marketing' ) ); ?></h1>
				<?php if ( has_excerpt() ) : ?><p class="ins-lead"><?php echo esc_html( get_the_excerpt() ); ?></p><?php endif; ?>
			</div>
		</header>
		<div class="ins-container ins-narrow ins-prose">
			<?php
			if ( trim( get_the_content() ) ) {
				the_content();
			} else {
				echo '<p>' . esc_html__( 'Insightistic helps WordPress and WooCommerce businesses understand revenue, products, customers, reports and store health from one clean dashboard — with AI that explains what changed and what to do next.', 'insightistic-marketing' ) . '</p>';
			}
			?>
		</div>
	<?php endwhile; ?>
	<?php
	get_template_part( 'template-parts/sections/features' );
	get_template_part( 'template-parts/sections/final-cta' );
	?>
</main>
<?php
get_footer();
