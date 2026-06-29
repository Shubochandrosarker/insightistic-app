<?php
/**
 * Theme setup — supports, menus, image sizes.
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'after_setup_theme',
	function () {
		load_theme_textdomain( 'insightistic-marketing', INSIGHTISTIC_DIR . '/languages' );

		add_theme_support( 'title-tag' );
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'automatic-feed-links' );
		add_theme_support( 'custom-logo', array(
			'height'      => 40,
			'width'       => 180,
			'flex-height' => true,
			'flex-width'  => true,
		) );
		add_theme_support( 'html5', array(
			'search-form', 'comment-form', 'comment-list', 'gallery',
			'caption', 'style', 'script', 'navigation-widgets',
		) );
		add_theme_support( 'align-wide' );
		add_theme_support( 'responsive-embeds' );
		add_theme_support( 'editor-styles' );
		add_theme_support( 'wp-block-styles' );
		add_editor_style( 'assets/css/theme.css' );

		register_nav_menus( array(
			'primary' => __( 'Primary Menu', 'insightistic-marketing' ),
			'footer'  => __( 'Footer Menu', 'insightistic-marketing' ),
			'legal'   => __( 'Legal Menu', 'insightistic-marketing' ),
		) );

		// Responsive content image sizes.
		add_image_size( 'insightistic-card', 720, 460, true );
		add_image_size( 'insightistic-wide', 1180, 620, true );
		set_post_thumbnail_size( 1180, 620, true );
	}
);

// Content width for embeds/oEmbeds.
add_action(
	'after_setup_theme',
	function () {
		$GLOBALS['content_width'] = 1180;
	},
	0
);

// Add a body class signalling JS-off → keeps the mobile menu usable without JS.
add_filter(
	'body_class',
	function ( $classes ) {
		$classes[] = 'no-js';
		if ( is_front_page() ) {
			$classes[] = 'ins-front';
		}
		return $classes;
	}
);
