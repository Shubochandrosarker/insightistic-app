<?php
/**
 * Comments template.
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( post_password_required() ) {
	return;
}
?>
<section class="ins-comments" id="comments">
	<?php if ( have_comments() ) : ?>
		<h2 class="ins-comments__title">
			<?php
			$count = get_comments_number();
			printf(
				/* translators: %s: comment count. */
				esc_html( _n( '%s comment', '%s comments', $count, 'insightistic-marketing' ) ),
				esc_html( number_format_i18n( $count ) )
			);
			?>
		</h2>
		<ol class="ins-comments__list">
			<?php
			wp_list_comments(
				array(
					'style'      => 'ol',
					'short_ping' => true,
					'avatar_size' => 40,
				)
			);
			?>
		</ol>
		<?php the_comments_pagination(); ?>
	<?php endif; ?>

	<?php
	comment_form(
		array(
			'class_submit' => 'ins-btn ins-btn--brand',
			'title_reply'  => esc_html__( 'Leave a comment', 'insightistic-marketing' ),
		)
	);
	?>
</section>
