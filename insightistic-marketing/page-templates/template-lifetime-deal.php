<?php
/**
 * Template Name: Lifetime Deal
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<main id="main" class="ins-main ins-page-template ins-ltd">
	<?php while ( have_posts() ) : the_post(); ?>
		<header class="ins-section ins-page-hero">
			<div class="ins-container ins-narrow ins-center">
				<p class="ins-eyebrow"><?php esc_html_e( 'Lifetime deal', 'insightistic-marketing' ); ?></p>
				<h1 class="ins-page-hero__title"><?php echo esc_html( get_the_title() ? get_the_title() : __( 'Founding-agency lifetime access', 'insightistic-marketing' ) ); ?></h1>
				<?php if ( has_excerpt() ) : ?><p class="ins-lead"><?php echo esc_html( get_the_excerpt() ); ?></p><?php endif; ?>
			</div>
		</header>
		<div class="ins-container ins-narrow ins-prose">
			<?php
			if ( trim( get_the_content() ) ) {
				the_content();
			} else {
				echo '<p>' . esc_html__( 'A limited lifetime offer for founding agencies and early operators. Request access below and we’ll be in touch with availability and details.', 'insightistic-marketing' ) . '</p>';
			}
			?>
		</div>
	<?php endwhile; ?>

	<section class="ins-section">
		<div class="ins-container ins-narrow">
			<div class="ins-section__head"><h2><?php esc_html_e( 'Request lifetime access', 'insightistic-marketing' ); ?></h2></div>
			<?php insightistic_formistic_form( 'ltd' ); ?>
			<p class="ins-consent"><?php esc_html_e( 'By submitting this form, you agree to our', 'insightistic-marketing' ); ?> <a href="<?php echo esc_url( home_url( '/privacy-policy' ) ); ?>"><?php esc_html_e( 'Privacy Policy', 'insightistic-marketing' ); ?></a>.</p>
		</div>
	</section>

	<?php get_template_part( 'template-parts/sections/faq' ); ?>
</main>
<?php
get_footer();
