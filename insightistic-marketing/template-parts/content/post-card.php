<?php
/**
 * Blog post card (used in index / archive / search).
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<article id="post-<?php the_ID(); ?>" <?php post_class( 'ins-postcard' ); ?>>
	<a class="ins-postcard__media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
		<?php if ( has_post_thumbnail() ) : ?>
			<?php the_post_thumbnail( 'insightistic-card', array( 'loading' => 'lazy', 'class' => 'ins-postcard__img' ) ); ?>
		<?php else : ?>
			<span class="ins-postcard__placeholder"></span>
		<?php endif; ?>
	</a>
	<div class="ins-postcard__body">
		<?php
		$cats = get_the_category();
		if ( ! empty( $cats ) ) :
			?>
			<a class="ins-postcard__cat" href="<?php echo esc_url( get_category_link( $cats[0]->term_id ) ); ?>"><?php echo esc_html( $cats[0]->name ); ?></a>
		<?php endif; ?>
		<h2 class="ins-postcard__title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
		<p class="ins-postcard__excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 22 ) ); ?></p>
		<div class="ins-postcard__meta">
			<time datetime="<?php echo esc_attr( get_the_date( DATE_W3C ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
			<span aria-hidden="true">·</span>
			<span><?php echo esc_html( get_the_author() ); ?></span>
		</div>
	</div>
</article>
