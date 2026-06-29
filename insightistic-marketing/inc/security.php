<?php
/**
 * Sensible hardening for a public marketing site. Nothing here changes how
 * WordPress core works for editors; it only trims information disclosure and
 * surfaces that could be abused on a brochure site.
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Remove the WordPress version from <head> and feeds (info disclosure).
remove_action( 'wp_head', 'wp_generator' );
add_filter( 'the_generator', '__return_empty_string' );

// Strip version query strings from theme assets is handled at enqueue time;
// keep core asset versions for cache-busting on updates.

// Disable XML-RPC pingbacks (common abuse vector) without breaking the API
// entirely for those who need it via plugins.
add_filter( 'xmlrpc_methods', function ( $methods ) {
	unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
	return $methods;
} );
add_filter( 'wp_headers', function ( $headers ) {
	unset( $headers['X-Pingback'] );
	return $headers;
} );

// Don't advertise the REST user enumeration endpoint to anonymous visitors.
add_filter( 'rest_endpoints', function ( $endpoints ) {
	if ( ! is_user_logged_in() ) {
		unset( $endpoints['/wp/v2/users'] );
		unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
	}
	return $endpoints;
} );

// Block author=N enumeration via query string.
add_action( 'template_redirect', function () {
	if ( ! is_admin() && isset( $_GET['author'] ) && ! is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}
} );

// Conservative, plugin-friendly security headers (won't fight a CDN/proxy).
add_filter( 'wp_headers', function ( $headers ) {
	$headers['X-Content-Type-Options'] = 'nosniff';
	$headers['Referrer-Policy']        = 'strict-origin-when-cross-origin';
	$headers['X-Frame-Options']        = 'SAMEORIGIN';
	return $headers;
} );
