<?php
/**
 * Blog index / fallback archive.
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<main id="main" class="ins-main ins-blog">
	<div class="ins-container">
		<?php insightistic_breadcrumbs(); ?>

		<header class="ins-page-header">
			<h1 class="ins-page-title"><?php echo esc_html( single_post_title( '', false ) ? single_post_title( '', false ) : __( 'Blog', 'insightistic-marketing' ) ); ?></h1>
			<p class="ins-lead"><?php esc_html_e( 'Playbooks and product notes for WordPress & WooCommerce operators.', 'insightistic-marketing' ); ?></p>
		</header>

		<div class="ins-blog__layout">
			<div class="ins-blog__main">
				<?php if ( have_posts() ) : ?>
					<div class="ins-grid ins-grid--posts">
						<?php
						while ( have_posts() ) :
							the_post();
							get_template_part( 'template-parts/content/post-card' );
						endwhile;
						?>
					</div>
					<?php insightistic_pagination(); ?>
				<?php else : ?>
					<p class="ins-empty"><?php esc_html_e( 'No posts yet — check back soon.', 'insightistic-marketing' ); ?></p>
				<?php endif; ?>
			</div>

			<aside class="ins-blog__sidebar" aria-label="<?php esc_attr_e( 'Blog sidebar', 'insightistic-marketing' ); ?>">
				<?php if ( is_active_sidebar( 'blog-sidebar' ) ) : ?>
					<?php dynamic_sidebar( 'blog-sidebar' ); ?>
				<?php else : ?>
					<div class="widget ins-sidebar-cta">
						<h3 class="widget-title"><?php esc_html_e( 'Try Insightistic', 'insightistic-marketing' ); ?></h3>
						<p><?php esc_html_e( 'Connect your store and get AI business insights in minutes.', 'insightistic-marketing' ); ?></p>
						<a class="ins-btn ins-btn--brand ins-btn--block" href="<?php echo esc_url( insightistic_register_url() ); ?>"><?php esc_html_e( 'Start free trial', 'insightistic-marketing' ); ?></a>
						<div class="ins-sidebar-newsletter"><?php insightistic_formistic_form( 'newsletter' ); ?></div>
					</div>
				<?php endif; ?>
			</aside>
		</div>
	</div>
</main>
<?php
get_footer();
