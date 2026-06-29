<?php
/**
 * Optional "Testimonial" custom post type used by the testimonials section.
 * The section falls back to sensible defaults when none exist, so the theme
 * works immediately without any content entry.
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'init',
	function () {
		register_post_type(
			'ins_testimonial',
			array(
				'labels'        => array(
					'name'          => __( 'Testimonials', 'insightistic-marketing' ),
					'singular_name' => __( 'Testimonial', 'insightistic-marketing' ),
					'add_new_item'  => __( 'Add Testimonial', 'insightistic-marketing' ),
					'edit_item'     => __( 'Edit Testimonial', 'insightistic-marketing' ),
				),
				'public'        => false,
				'show_ui'       => true,
				'show_in_rest'  => true,
				'menu_icon'     => 'dashicons-format-quote',
				'menu_position' => 26,
				'supports'      => array( 'title', 'editor', 'thumbnail', 'page-attributes' ),
				'has_archive'   => false,
				'rewrite'       => false,
			)
		);
	}
);

/**
 * Return testimonials (CPT entries, else defaults).
 *
 * @return array<int,array{quote:string,name:string,role:string}>
 */
function insightistic_testimonials() {
	$items = array();

	$query = new WP_Query(
		array(
			'post_type'      => 'ins_testimonial',
			'posts_per_page' => 6,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		)
	);

	if ( $query->have_posts() ) {
		while ( $query->have_posts() ) {
			$query->the_post();
			$items[] = array(
				'quote' => wp_strip_all_tags( get_the_content() ),
				'name'  => get_the_title(),
				'role'  => (string) get_post_meta( get_the_ID(), '_ins_role', true ),
			);
		}
		wp_reset_postdata();
	}

	if ( empty( $items ) ) {
		$items = array(
			array( 'quote' => 'We finally see what changed and why — without exporting spreadsheets every Monday. The AI summaries pay for the plan on their own.', 'name' => 'Amelia Cross', 'role' => 'Ops Lead, Northwind Outfitters' ),
			array( 'quote' => 'White-label reports turned our analytics into a product. Clients log in, see their store, and renew.', 'name' => 'Devin Roy', 'role' => 'Founder, Bloom & Co Agency' ),
			array( 'quote' => 'Setup took three minutes. The connector is clean and the data just shows up.', 'name' => 'Marcus Lindqvist', 'role' => 'Owner, Pulse Supplements' ),
		);
	}

	return apply_filters( 'insightistic_testimonials', $items );
}
