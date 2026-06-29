<?php
/**
 * Single blog post.
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<main id="main" class="ins-main ins-single">
	<div class="ins-container ins-narrow">
		<?php
		while ( have_posts() ) :
			the_post();
			?>
			<?php insightistic_breadcrumbs(); ?>
			<article <?php post_class( 'ins-article' ); ?>>
				<header class="ins-article__header">
					<h1 class="ins-article__title"><?php the_title(); ?></h1>
					<?php insightistic_post_meta(); ?>
				</header>

				<?php if ( has_post_thumbnail() ) : ?>
					<figure class="ins-article__featured"><?php the_post_thumbnail( 'insightistic-wide', array( 'class' => 'ins-rounded' ) ); ?></figure>
				<?php endif; ?>

				<div class="ins-prose">
					<?php
					the_content();
					wp_link_pages(
						array(
							'before' => '<nav class="ins-page-links">' . esc_html__( 'Pages:', 'insightistic-marketing' ),
							'after'  => '</nav>',
						)
					);
					?>
				</div>

				<?php
				$tags = get_the_tag_list( '<ul class="ins-tags"><li>', '</li><li>', '</li></ul>' );
				if ( $tags ) {
					echo wp_kses_post( $tags );
				}
				?>
			</article>

			<?php
			// Related posts (same category).
			$cats = wp_get_post_categories( get_the_ID() );
			if ( ! empty( $cats ) ) {
				$related = new WP_Query(
					array(
						'category__in'        => $cats,
						'post__not_in'        => array( get_the_ID() ),
						'posts_per_page'      => 3,
						'ignore_sticky_posts' => true,
						'no_found_rows'       => true,
					)
				);
				if ( $related->have_posts() ) :
					?>
					<section class="ins-related" aria-label="<?php esc_attr_e( 'Related posts', 'insightistic-marketing' ); ?>">
						<h2 class="ins-related__title"><?php esc_html_e( 'Related reading', 'insightistic-marketing' ); ?></h2>
						<div class="ins-grid ins-grid--posts">
							<?php
							while ( $related->have_posts() ) :
								$related->the_post();
								get_template_part( 'template-parts/content/post-card' );
							endwhile;
							?>
						</div>
					</section>
					<?php
					wp_reset_postdata();
				endif;
			}
			?>

			<?php
			if ( comments_open() || get_comments_number() ) {
				comments_template();
			}
			?>
			<?php
		endwhile;
		?>
	</div>
</main>
<?php
get_footer();
