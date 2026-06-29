<?php
/**
 * Customizer — site-owner editable options (URLs, hero copy, forms, social).
 * Everything is sanitized on save; nothing here stores secrets.
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'customize_register',
	function ( WP_Customize_Manager $wp_customize ) {

		$panel = 'insightistic_panel';
		$wp_customize->add_panel( $panel, array(
			'title'    => __( 'Insightistic', 'insightistic-marketing' ),
			'priority' => 20,
		) );

		// --- App & API URLs -------------------------------------------------
		$wp_customize->add_section( 'insightistic_app', array(
			'title' => __( 'App & API URLs', 'insightistic-marketing' ),
			'panel' => $panel,
		) );

		$urls = array(
			'insightistic_app_url'       => array( 'https://app.insightistic.com', __( 'SaaS app URL', 'insightistic-marketing' ) ),
			'insightistic_api_url'       => array( 'https://api.insightistic.com', __( 'API URL (informational)', 'insightistic-marketing' ) ),
			'insightistic_marketing_url' => array( 'https://insightistic.com', __( 'Marketing site URL', 'insightistic-marketing' ) ),
		);
		foreach ( $urls as $id => $cfg ) {
			$wp_customize->add_setting( $id, array(
				'default'           => $cfg[0],
				'sanitize_callback' => 'esc_url_raw',
				'transport'         => 'refresh',
			) );
			$wp_customize->add_control( $id, array(
				'label'   => $cfg[1],
				'section' => 'insightistic_app',
				'type'    => 'url',
			) );
		}

		// --- Hero copy ------------------------------------------------------
		$wp_customize->add_section( 'insightistic_hero', array(
			'title' => __( 'Hero', 'insightistic-marketing' ),
			'panel' => $panel,
		) );

		$hero = array(
			'insightistic_hero_eyebrow'  => array( 'AI Business Analytics', 'text', __( 'Eyebrow', 'insightistic-marketing' ) ),
			'insightistic_hero_title'    => array( 'AI Business Analytics for WordPress & WooCommerce', 'text', __( 'Title', 'insightistic-marketing' ) ),
			'insightistic_hero_subtitle' => array( 'Understand revenue, products, customers, reports and store health from one clean dashboard built for WordPress-powered businesses.', 'textarea', __( 'Subtitle', 'insightistic-marketing' ) ),
		);
		foreach ( $hero as $id => $cfg ) {
			$wp_customize->add_setting( $id, array(
				'default'           => $cfg[0],
				'sanitize_callback' => 'textarea' === $cfg[1] ? 'sanitize_textarea_field' : 'sanitize_text_field',
				'transport'         => 'refresh',
			) );
			$wp_customize->add_control( $id, array(
				'label'   => $cfg[2],
				'section' => 'insightistic_hero',
				'type'    => $cfg[1],
			) );
		}

		// --- Formistic form ids --------------------------------------------
		$wp_customize->add_section( 'insightistic_forms', array(
			'title'       => __( 'Forms (Formistic)', 'insightistic-marketing' ),
			'panel'       => $panel,
			'description' => __( 'Map each form area to a Formistic form id.', 'insightistic-marketing' ),
		) );

		foreach ( array( 'contact', 'newsletter', 'agency', 'ltd', 'early_access', 'support' ) as $slug ) {
			$id = 'insightistic_form_' . $slug;
			$wp_customize->add_setting( $id, array(
				'default'           => $slug,
				'sanitize_callback' => 'sanitize_text_field',
				'transport'         => 'refresh',
			) );
			$wp_customize->add_control( $id, array(
				/* translators: %s: form slug. */
				'label'   => sprintf( __( '%s form id', 'insightistic-marketing' ), ucfirst( str_replace( '_', ' ', $slug ) ) ),
				'section' => 'insightistic_forms',
				'type'    => 'text',
			) );
		}

		// --- Footer & social -----------------------------------------------
		$wp_customize->add_section( 'insightistic_footer', array(
			'title' => __( 'Footer & social', 'insightistic-marketing' ),
			'panel' => $panel,
		) );

		$wp_customize->add_setting( 'insightistic_footer_tagline', array(
			'default'           => 'AI business analytics for WordPress & WooCommerce.',
			'sanitize_callback' => 'sanitize_text_field',
		) );
		$wp_customize->add_control( 'insightistic_footer_tagline', array(
			'label'   => __( 'Footer tagline', 'insightistic-marketing' ),
			'section' => 'insightistic_footer',
			'type'    => 'text',
		) );

		foreach ( array( 'twitter' => 'X / Twitter', 'linkedin' => 'LinkedIn', 'youtube' => 'YouTube', 'github' => 'GitHub' ) as $slug => $label ) {
			$id = 'insightistic_social_' . $slug;
			$wp_customize->add_setting( $id, array(
				'default'           => '',
				'sanitize_callback' => 'esc_url_raw',
			) );
			$wp_customize->add_control( $id, array(
				/* translators: %s: social network name. */
				'label'   => sprintf( __( '%s URL', 'insightistic-marketing' ), $label ),
				'section' => 'insightistic_footer',
				'type'    => 'url',
			) );
		}
	}
);
