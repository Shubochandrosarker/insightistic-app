<?php
/**
 * Template Name: WooCommerce Analytics
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
				<p class="ins-eyebrow"><?php esc_html_e( 'WooCommerce analytics', 'insightistic-marketing' ); ?></p>
				<h1 class="ins-page-hero__title"><?php echo esc_html( get_the_title() ? get_the_title() : __( 'Cleaner WooCommerce analytics, calculated daily', 'insightistic-marketing' ) ); ?></h1>
				<?php if ( has_excerpt() ) : ?><p class="ins-lead"><?php echo esc_html( get_the_excerpt() ); ?></p><?php endif; ?>
			</div>
		</header>
		<?php if ( trim( get_the_content() ) ) : ?><div class="ins-container ins-narrow ins-prose"><?php the_content(); ?></div><?php endif; ?>
	<?php endwhile; ?>
	<?php
	get_template_part( 'template-parts/sections/woo-insights' );
	get_template_part( 'template-parts/sections/analytics-preview' );
	get_template_part( 'template-parts/sections/features' );
	get_template_part( 'template-parts/sections/final-cta' );
	?>
</main>
<?php
get_footer();
