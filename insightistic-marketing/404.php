<?php
/**
 * 404 — not found.
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<main id="main" class="ins-main ins-404">
	<div class="ins-container ins-narrow ins-center">
		<p class="ins-eyebrow"><?php esc_html_e( 'Error 404', 'insightistic-marketing' ); ?></p>
		<h1 class="ins-404__title"><?php esc_html_e( 'That page wandered off', 'insightistic-marketing' ); ?></h1>
		<p class="ins-lead"><?php esc_html_e( 'The page you’re looking for doesn’t exist or has moved. Try a search, or head back home.', 'insightistic-marketing' ); ?></p>
		<div class="ins-404__search"><?php get_search_form(); ?></div>
		<div class="ins-hero__actions ins-center">
			<a class="ins-btn ins-btn--brand ins-btn--lg" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Back to home', 'insightistic-marketing' ); ?></a>
			<a class="ins-btn ins-btn--ghost ins-btn--lg" href="<?php echo esc_url( home_url( '/pricing' ) ); ?>"><?php esc_html_e( 'See pricing', 'insightistic-marketing' ); ?></a>
		</div>
	</div>
</main>
<?php
get_footer();
