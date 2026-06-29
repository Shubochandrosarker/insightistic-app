<?php
/**
 * Assets — styles and scripts.
 *
 * Performance rules: no jQuery dependency, no remote fonts, a small amount of
 * vanilla JS loaded with `defer`. The pricing toggle only loads where a pricing
 * table is present.
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'wp_enqueue_scripts',
	function () {
		$ver = INSIGHTISTIC_VERSION;
		$css = INSIGHTISTIC_URI . '/assets/css';
		$js  = INSIGHTISTIC_URI . '/assets/js';

		// Base + blocks + responsive (responsive depends on base so it cascades last).
		wp_enqueue_style( 'insightistic-theme', $css . '/theme.css', array(), $ver );
		wp_enqueue_style( 'insightistic-blocks', $css . '/blocks.css', array( 'insightistic-theme' ), $ver );
		wp_enqueue_style( 'insightistic-responsive', $css . '/responsive.css', array( 'insightistic-theme' ), $ver );

		// Core theme JS (mobile menu, FAQ accordion, header on scroll).
		wp_enqueue_script( 'insightistic-theme', $js . '/theme.js', array(), $ver, true );

		// Pricing toggle only where it's needed.
		if ( is_front_page() || is_page_template( 'page-templates/template-pricing.php' ) || is_page( 'pricing' ) ) {
			wp_enqueue_script( 'insightistic-pricing-toggle', $js . '/pricing-toggle.js', array(), $ver, true );
		}

		if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
			wp_enqueue_script( 'comment-reply' );
		}
	}
);

/**
 * Add `defer` to our front-end scripts (they don't block first paint).
 */
add_filter(
	'script_loader_tag',
	function ( $tag, $handle ) {
		if ( in_array( $handle, array( 'insightistic-theme', 'insightistic-pricing-toggle' ), true ) ) {
			return str_replace( ' src', ' defer src', $tag );
		}
		return $tag;
	},
	10,
	2
);

/**
 * Flip the `no-js` body/html class to `js` as early as possible so CSS can
 * progressively enhance (mobile menu, accordions) without layout shift.
 */
add_action(
	'wp_head',
	function () {
		echo "<script>document.documentElement.classList.remove('no-js');document.documentElement.classList.add('js');</script>\n";
	},
	0
);
