<?php
/**
 * Feature card. Pass via: get_template_part(..., null, array('icon','title','text')).
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ins_icon  = isset( $args['icon'] ) ? $args['icon'] : 'check';
$ins_title = isset( $args['title'] ) ? $args['title'] : '';
$ins_text  = isset( $args['text'] ) ? $args['text'] : '';
?>
<article class="ins-feature">
	<span class="ins-feature__icon"><?php echo insightistic_icon( $ins_icon, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
	<h3 class="ins-feature__title"><?php echo esc_html( $ins_title ); ?></h3>
	<p class="ins-feature__text"><?php echo esc_html( $ins_text ); ?></p>
</article>
