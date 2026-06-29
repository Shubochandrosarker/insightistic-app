<?php
/**
 * Search results.
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
			<h1 class="ins-page-title">
				<?php
				/* translators: %s: search query. */
				printf( esc_html__( 'Results for “%s”', 'insightistic-marketing' ), esc_html( get_search_query() ) );
				?>
			</h1>
			<div class="ins-search-again"><?php get_search_form(); ?></div>
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
			<p class="ins-empty"><?php esc_html_e( 'No results found. Try a different search.', 'insightistic-marketing' ); ?></p>
		<?php endif; ?>
	</div>
</main>
<?php
get_footer();
