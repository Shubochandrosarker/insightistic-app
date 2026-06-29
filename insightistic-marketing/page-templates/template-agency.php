<?php
/**
 * Template Name: Agency
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$agency_features = array(
	array( 'icon' => 'globe', 'title' => 'White-label dashboard', 'text' => 'Your logo, brand colors and custom domain on a client-ready dashboard.' ),
	array( 'icon' => 'file-text', 'title' => 'Branded PDF reports', 'text' => 'Automated, on-brand reports your clients receive every week or month.' ),
	array( 'icon' => 'users', 'title' => 'Client viewer access', 'text' => 'Give each client a read-only view scoped to just their own store.' ),
	array( 'icon' => 'shield', 'title' => 'Team roles', 'text' => 'Owner, admin, analyst and client-viewer roles keep access tidy.' ),
);

get_header();
?>
<main id="main" class="ins-main ins-page-template ins-agency">
	<?php while ( have_posts() ) : the_post(); ?>
		<header class="ins-section ins-page-hero">
			<div class="ins-container ins-narrow ins-center">
				<p class="ins-eyebrow"><?php esc_html_e( 'For agencies', 'insightistic-marketing' ); ?></p>
				<h1 class="ins-page-hero__title"><?php echo esc_html( get_the_title() ? get_the_title() : __( 'A white-label analytics product you can resell', 'insightistic-marketing' ) ); ?></h1>
				<?php if ( has_excerpt() ) : ?><p class="ins-lead"><?php echo esc_html( get_the_excerpt() ); ?></p><?php endif; ?>
				<div class="ins-hero__actions ins-center">
					<a class="ins-btn ins-btn--brand ins-btn--lg" href="<?php echo esc_url( insightistic_register_url( 'agency' ) ); ?>"><?php esc_html_e( 'Start Agency trial', 'insightistic-marketing' ); ?></a>
				</div>
			</div>
		</header>
		<?php if ( trim( get_the_content() ) ) : ?><div class="ins-container ins-narrow ins-prose"><?php the_content(); ?></div><?php endif; ?>
	<?php endwhile; ?>

	<section class="ins-section">
		<div class="ins-container">
			<div class="ins-grid ins-grid--features">
				<?php
				foreach ( $agency_features as $agency_feature ) {
					get_template_part( 'template-parts/cards/feature-card', null, $agency_feature );
				}
				?>
			</div>
		</div>
	</section>

	<?php get_template_part( 'template-parts/sections/pricing' ); ?>

	<section class="ins-section">
		<div class="ins-container ins-narrow">
			<div class="ins-section__head"><h2><?php esc_html_e( 'Talk to us about agency plans', 'insightistic-marketing' ); ?></h2></div>
			<?php insightistic_formistic_form( 'agency' ); ?>
		</div>
	</section>

	<?php get_template_part( 'template-parts/sections/final-cta' ); ?>
</main>
<?php
get_footer();
