<?php
/**
 * Template Name: Terms
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<main id="main" class="ins-main ins-legal">
	<div class="ins-container ins-narrow">
		<?php while ( have_posts() ) : the_post(); ?>
			<?php insightistic_breadcrumbs(); ?>
			<header class="ins-page-header">
				<h1 class="ins-page-title"><?php echo esc_html( get_the_title() ? get_the_title() : __( 'Terms', 'insightistic-marketing' ) ); ?></h1>
				<p class="ins-legal__updated">
					<?php
					/* translators: %s: last updated date. */
					printf( esc_html__( 'Last updated %s', 'insightistic-marketing' ), esc_html( get_the_modified_date() ) );
					?>
				</p>
			</header>
			<div class="ins-prose"><?php the_content(); ?></div>
		<?php endwhile; ?>
	</div>
</main>
<?php
get_footer();
