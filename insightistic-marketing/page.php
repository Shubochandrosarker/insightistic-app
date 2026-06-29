<?php
/**
 * Default page template.
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<main id="main" class="ins-main ins-page-single">
	<div class="ins-container ins-narrow">
		<?php
		while ( have_posts() ) :
			the_post();
			?>
			<?php insightistic_breadcrumbs(); ?>
			<header class="ins-page-header">
				<h1 class="ins-page-title"><?php the_title(); ?></h1>
			</header>

			<?php if ( has_post_thumbnail() ) : ?>
				<div class="ins-page-featured"><?php the_post_thumbnail( 'insightistic-wide', array( 'class' => 'ins-rounded' ) ); ?></div>
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
