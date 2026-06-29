<?php
/**
 * Template Name: Contact
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<main id="main" class="ins-main ins-page-template ins-contact">
	<?php while ( have_posts() ) : the_post(); ?>
		<header class="ins-section ins-page-hero">
			<div class="ins-container ins-narrow ins-center">
				<p class="ins-eyebrow"><?php esc_html_e( 'Contact', 'insightistic-marketing' ); ?></p>
				<h1 class="ins-page-hero__title"><?php echo esc_html( get_the_title() ? get_the_title() : __( 'Talk to the Insightistic team', 'insightistic-marketing' ) ); ?></h1>
				<?php if ( has_excerpt() ) : ?><p class="ins-lead"><?php echo esc_html( get_the_excerpt() ); ?></p><?php endif; ?>
			</div>
		</header>

		<section class="ins-section">
			<div class="ins-container ins-contact__grid">
				<div class="ins-contact__form">
					<?php if ( trim( get_the_content() ) ) : ?>
						<div class="ins-prose"><?php the_content(); ?></div>
					<?php endif; ?>
					<?php insightistic_formistic_form( 'contact', __( 'Send us a message', 'insightistic-marketing' ) ); ?>
					<p class="ins-consent"><?php esc_html_e( 'By submitting this form, you agree to our', 'insightistic-marketing' ); ?> <a href="<?php echo esc_url( home_url( '/privacy-policy' ) ); ?>"><?php esc_html_e( 'Privacy Policy', 'insightistic-marketing' ); ?></a>.</p>
				</div>
				<aside class="ins-contact__aside">
					<div class="ins-panel-card">
						<h3><?php esc_html_e( 'Already a customer?', 'insightistic-marketing' ); ?></h3>
						<p><?php esc_html_e( 'Sign in to your dashboard or open a support request from inside the app.', 'insightistic-marketing' ); ?></p>
						<a class="ins-btn ins-btn--ghost ins-btn--block" href="<?php echo esc_url( insightistic_login_url() ); ?>"><?php esc_html_e( 'Sign in', 'insightistic-marketing' ); ?></a>
						<a class="ins-btn ins-btn--brand ins-btn--block" href="<?php echo esc_url( insightistic_register_url() ); ?>"><?php esc_html_e( 'Start free trial', 'insightistic-marketing' ); ?></a>
					</div>
				</aside>
			</div>
		</section>
	<?php endwhile; ?>
</main>
<?php
get_footer();
