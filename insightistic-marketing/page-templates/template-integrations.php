<?php
/**
 * Template Name: Integrations
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$integrations = array(
	array( 'icon' => 'plug', 'title' => 'WooCommerce', 'text' => 'Orders, products, customers and refunds — the core of your analytics.' ),
	array( 'icon' => 'globe', 'title' => 'WordPress', 'text' => 'Site health, versions and the connector plugin that ties it together.' ),
	array( 'icon' => 'bar-chart', 'title' => 'Stripe Billing', 'text' => 'Subscription billing and plan management for your Insightistic account.' ),
	array( 'icon' => 'mail', 'title' => 'Email reports', 'text' => 'Scheduled report delivery to you and your clients.' ),
	array( 'icon' => 'sparkles', 'title' => 'AI providers', 'text' => 'OpenAI-compatible insights, with a free rule-based fallback.' ),
	array( 'icon' => 'shield', 'title' => 'Secure connector', 'text' => 'Signed, read-only sync — no customer card data leaves your store.' ),
);

get_header();
?>
<main id="main" class="ins-main ins-page-template">
	<?php while ( have_posts() ) : the_post(); ?>
		<header class="ins-section ins-page-hero">
			<div class="ins-container ins-narrow ins-center">
				<p class="ins-eyebrow"><?php esc_html_e( 'Integrations', 'insightistic-marketing' ); ?></p>
				<h1 class="ins-page-hero__title"><?php echo esc_html( get_the_title() ? get_the_title() : __( 'Works with the stack you already run', 'insightistic-marketing' ) ); ?></h1>
				<?php if ( has_excerpt() ) : ?><p class="ins-lead"><?php echo esc_html( get_the_excerpt() ); ?></p><?php endif; ?>
			</div>
		</header>
		<?php if ( trim( get_the_content() ) ) : ?><div class="ins-container ins-narrow ins-prose"><?php the_content(); ?></div><?php endif; ?>
	<?php endwhile; ?>

	<section class="ins-section">
		<div class="ins-container">
			<div class="ins-grid ins-grid--features">
				<?php
				foreach ( $integrations as $integration ) {
					get_template_part( 'template-parts/cards/feature-card', null, $integration );
				}
				?>
			</div>
		</div>
	</section>

	<?php
	get_template_part( 'template-parts/sections/connector' );
	get_template_part( 'template-parts/sections/final-cta' );
	?>
</main>
<?php
get_footer();
