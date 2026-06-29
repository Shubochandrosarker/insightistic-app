<?php
/**
 * Category / tag / date archive.
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
			<h1 class="ins-page-title"><?php echo wp_kses_post( get_the_archive_title() ); ?></h1>
			<?php the_archive_description( '<div class="ins-lead">', '</div>' ); ?>
		</header>

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
			<p class="ins-empty"><?php esc_html_e( 'Nothing here yet.', 'insightistic-marketing' ); ?></p>
		<?php endif; ?>
	</div>
</main>
<?php
get_footer();
