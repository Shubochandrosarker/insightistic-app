<?php
/**
 * Template tags — reusable, escaped output helpers.
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inline, stroke-based SVG icons (lucide-style). Returns sanitized SVG markup.
 * No icon font, no external request — keeps the site fast.
 *
 * @param string $name Icon slug.
 * @param int    $size Pixel size.
 */
function insightistic_icon( $name, $size = 22 ) {
	$paths = array(
		'trending-up' => '<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>',
		'sparkles'    => '<path d="M12 3l1.9 4.6L18.5 9.5 13.9 11.4 12 16l-1.9-4.6L5.5 9.5l4.6-1.9L12 3z"/><path d="M19 14l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8.8-2z"/>',
		'package'     => '<path d="M16.5 9.4 7.5 4.2"/><path d="M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.3 7 12 12 20.7 7"/><line x1="12" y1="22" x2="12" y2="12"/>',
		'users'       => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/>',
		'file-text'   => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
		'globe'       => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
		'plug'        => '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8z"/>',
		'activity'    => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
		'arrow-right' => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
		'check'       => '<polyline points="20 6 9 17 4 12"/>',
		'shield'      => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
		'bar-chart'   => '<line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/>',
		'mail'        => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
		'menu'        => '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>',
		'x'           => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
	);

	$inner = isset( $paths[ $name ] ) ? $paths[ $name ] : $paths['check'];
	$size  = (int) $size;

	$svg = sprintf(
		'<svg class="ins-icon" width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%2$s</svg>',
		$size,
		$inner
	);

	// Allow only the SVG primitives we emit.
	return wp_kses(
		$svg,
		array(
			'svg'      => array( 'class' => true, 'width' => true, 'height' => true, 'viewbox' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'aria-hidden' => true, 'focusable' => true ),
			'path'     => array( 'd' => true ),
			'circle'   => array( 'cx' => true, 'cy' => true, 'r' => true ),
			'rect'     => array( 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true ),
			'line'     => array( 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true ),
			'polyline' => array( 'points' => true ),
			'polygon'  => array( 'points' => true ),
		)
	);
}

/**
 * Schema-ready breadcrumb trail.
 */
function insightistic_breadcrumbs() {
	if ( is_front_page() ) {
		return;
	}

	echo '<nav class="ins-breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'insightistic-marketing' ) . '">';
	echo '<a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Home', 'insightistic-marketing' ) . '</a>';

	if ( is_singular( 'post' ) ) {
		$cats = get_the_category();
		if ( ! empty( $cats ) ) {
			echo ' <span aria-hidden="true">/</span> <a href="' . esc_url( get_category_link( $cats[0]->term_id ) ) . '">' . esc_html( $cats[0]->name ) . '</a>';
		}
		echo ' <span aria-hidden="true">/</span> <span aria-current="page">' . esc_html( get_the_title() ) . '</span>';
	} elseif ( is_page() ) {
		echo ' <span aria-hidden="true">/</span> <span aria-current="page">' . esc_html( get_the_title() ) . '</span>';
	} elseif ( is_category() || is_archive() ) {
		echo ' <span aria-hidden="true">/</span> <span aria-current="page">' . esc_html( wp_strip_all_tags( get_the_archive_title() ) ) . '</span>';
	} elseif ( is_search() ) {
		echo ' <span aria-hidden="true">/</span> <span aria-current="page">' . esc_html__( 'Search results', 'insightistic-marketing' ) . '</span>';
	}

	echo '</nav>';
}

/** Post byline (date + author + category) for blog templates. */
function insightistic_post_meta() {
	echo '<div class="ins-post-meta">';
	printf(
		'<time class="ins-post-date" datetime="%1$s">%2$s</time>',
		esc_attr( get_the_date( DATE_W3C ) ),
		esc_html( get_the_date() )
	);
	echo ' <span aria-hidden="true">·</span> <span class="ins-post-author">' . esc_html( get_the_author() ) . '</span>';
	$cats = get_the_category_list( ', ' );
	if ( $cats ) {
		echo ' <span aria-hidden="true">·</span> <span class="ins-post-cats">' . wp_kses_post( $cats ) . '</span>';
	}
	echo '</div>';
}

/** Accessible numeric pagination. */
function insightistic_pagination() {
	the_posts_pagination(
		array(
			'mid_size'           => 1,
			'prev_text'          => esc_html__( 'Previous', 'insightistic-marketing' ),
			'next_text'          => esc_html__( 'Next', 'insightistic-marketing' ),
			'screen_reader_text' => esc_html__( 'Posts navigation', 'insightistic-marketing' ),
			'class'              => 'ins-pagination',
		)
	);
}
