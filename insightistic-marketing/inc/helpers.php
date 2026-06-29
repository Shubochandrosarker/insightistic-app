<?php
/**
 * Helpers — app routing, Formistic integration, and editable content data.
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read a theme option (Customizer / settings page) with a default.
 */
function insightistic_option( $key, $default = '' ) {
	$value = get_theme_mod( $key, null );
	if ( null === $value || '' === $value ) {
		return $default;
	}
	return $value;
}

/** Base URL of the SaaS app. */
function insightistic_app_base() {
	return untrailingslashit( insightistic_option( 'insightistic_app_url', 'https://app.insightistic.com' ) );
}

/** Base URL of the marketing site (self), used in schema/canonical fallbacks. */
function insightistic_marketing_base() {
	return untrailingslashit( insightistic_option( 'insightistic_marketing_url', home_url( '/' ) ) );
}

/** Base URL of the Laravel API (informational; never embeds secrets). */
function insightistic_api_url() {
	return untrailingslashit( insightistic_option( 'insightistic_api_url', 'https://api.insightistic.com' ) );
}

/**
 * Safely join the app base with a path. Always run through esc_url() on output.
 *
 * @param string $path e.g. '/login' or '/register?plan=pro'.
 */
function insightistic_get_app_url( $path = '' ) {
	$path = (string) $path;
	if ( '' === $path ) {
		return insightistic_app_base();
	}
	return insightistic_app_base() . '/' . ltrim( $path, '/' );
}

function insightistic_login_url() {
	return insightistic_get_app_url( '/login' );
}

function insightistic_register_url( $plan = '' ) {
	$path = '/register';
	if ( $plan ) {
		$path .= '?plan=' . rawurlencode( $plan );
	}
	return insightistic_get_app_url( $path );
}

function insightistic_dashboard_url() {
	return insightistic_get_app_url( '/dashboard' );
}

/* -------------------------------------------------------------------------
 * Formistic integration (contact / newsletter / lead forms)
 * ---------------------------------------------------------------------- */

/** Is the Formistic plugin active and exposing its shortcode? */
function insightistic_formistic_active() {
	return shortcode_exists( 'formistic_form' ) || function_exists( 'formistic_render_form' );
}

/**
 * Render a Formistic form by logical slug, with a safe fallback.
 * Each logical slug (contact, newsletter, agency, ltd, early_access, support)
 * maps to a Formistic form id, overridable in the Customizer.
 *
 * @param string $slug  Logical form slug.
 * @param string $title Optional heading rendered above the form.
 */
function insightistic_formistic_form( $slug, $title = '' ) {
	$form_id = insightistic_option( 'insightistic_form_' . $slug, $slug );

	echo '<div class="ins-form ins-form--' . esc_attr( $slug ) . '">';

	if ( $title ) {
		echo '<h3 class="ins-form__title">' . esc_html( $title ) . '</h3>';
	}

	if ( insightistic_formistic_active() ) {
		// do_shortcode output is rendered by Formistic, which escapes its own markup.
		echo do_shortcode( '[formistic_form id="' . esc_attr( $form_id ) . '"]' );
	} else {
		echo '<p class="ins-form__fallback">';
		echo esc_html__( 'Formistic is required to display this form. Install and activate the Formistic plugin, then set the form id in Appearance → Customize → Forms.', 'insightistic-marketing' );
		echo '</p>';
	}

	echo '</div>';
}

/* -------------------------------------------------------------------------
 * Editable content data (filterable so a child theme / snippet can override)
 * ---------------------------------------------------------------------- */

/**
 * Pricing plans. Prices are display strings so they can be edited freely.
 */
function insightistic_pricing_plans() {
	$plans = array(
		array(
			'slug'      => 'starter',
			'name'      => 'Starter',
			'tagline'   => 'For small WooCommerce stores finding their footing.',
			'monthly'   => '$19',
			'yearly'    => '$15',
			'features'  => array( '1 connected store', 'Revenue & order analytics', 'Weekly AI summary', 'Email reports', '14-day free trial' ),
			'featured'  => false,
		),
		array(
			'slug'      => 'pro',
			'name'      => 'Pro',
			'tagline'   => 'For growing WooCommerce businesses that want answers.',
			'monthly'   => '$49',
			'yearly'    => '$39',
			'features'  => array( 'Up to 3 stores', 'Full AI business insights', 'Branded PDF reports', 'Product & customer intelligence', 'Priority support' ),
			'featured'  => true,
		),
		array(
			'slug'      => 'agency',
			'name'      => 'Agency',
			'tagline'   => 'For agencies managing multiple client stores.',
			'monthly'   => '$129',
			'yearly'    => '$99',
			'features'  => array( 'Unlimited stores', 'White-label dashboard & domain', 'Client viewer access', 'Team roles', 'All Pro features' ),
			'featured'  => false,
		),
	);

	return apply_filters( 'insightistic_pricing_plans', $plans );
}

/** Feature cards shown on the homepage + features page. */
function insightistic_features() {
	$features = array(
		array( 'icon' => 'trending-up', 'title' => 'Revenue analytics', 'text' => 'Revenue, orders, AOV and refunds — calculated daily and ready the moment you log in.' ),
		array( 'icon' => 'sparkles', 'title' => 'AI business insights', 'text' => 'Plain-language explanations of what changed, why it matters, and what to do next.' ),
		array( 'icon' => 'package', 'title' => 'Product intelligence', 'text' => 'Spot winners, slow movers, low stock and high-refund SKUs at a glance.' ),
		array( 'icon' => 'users', 'title' => 'Customer insights', 'text' => 'New vs returning, at-risk segments and lifetime value across every store.' ),
		array( 'icon' => 'file-text', 'title' => 'Branded reports', 'text' => 'Automated weekly and monthly PDF reports delivered to you and your clients.' ),
		array( 'icon' => 'globe', 'title' => 'White-label workspace', 'text' => 'Your logo, your colors, your domain — a branded analytics product to resell.' ),
		array( 'icon' => 'plug', 'title' => 'WordPress connector', 'text' => 'A secure plugin syncs orders, products and customers — no card data ever leaves your store.' ),
		array( 'icon' => 'activity', 'title' => 'Site health & sync logs', 'text' => 'Connector heartbeat, sync status and data integrity, monitored continuously.' ),
	);

	return apply_filters( 'insightistic_features', $features );
}

/** Homepage / FAQ page questions. */
function insightistic_faqs() {
	$faqs = array(
		array( 'q' => 'What is Insightistic?', 'a' => 'Insightistic is an AI-powered business analytics platform for WordPress and WooCommerce websites. It helps store owners, agencies and teams understand revenue, orders, products, customers and AI-powered business insights from one clean dashboard.' ),
		array( 'q' => 'Is Insightistic only for WooCommerce?', 'a' => 'Insightistic is focused on WooCommerce analytics and WordPress-powered businesses. WooCommerce stores get the most value because Insightistic can analyze revenue, orders, products, customers and refunds.' ),
		array( 'q' => 'How does a store connect?', 'a' => 'Create an account, add a site in the dashboard, copy the connector token, install the Insightistic connector plugin on your WordPress site, paste the API URL and token, connect, and run the first sync.' ),
		array( 'q' => 'Is my data secure?', 'a' => 'Yes. The connector uses signed, secure communication and only sends the business analytics data needed. It does not collect or store customer payment card details.' ),
		array( 'q' => 'Can agencies use Insightistic?', 'a' => 'Yes. Agencies can manage multiple client stores, generate branded reports, monitor performance and provide client-facing dashboards with white-label options.' ),
		array( 'q' => 'Do you offer a free trial?', 'a' => 'Yes — every plan starts with a 14-day free trial, no credit card required. Start at app.insightistic.com/register.' ),
	);

	return apply_filters( 'insightistic_faqs', $faqs );
}

/** Overview-style metric cards for the dashboard preview visual. */
function insightistic_preview_metrics() {
	return apply_filters( 'insightistic_preview_metrics', array(
		array( 'label' => 'Revenue', 'value' => '$48,920', 'delta' => '+12.4%' ),
		array( 'label' => 'Orders', 'value' => '1,284', 'delta' => '+8.1%' ),
		array( 'label' => 'New customers', 'value' => '612', 'delta' => '+18%' ),
		array( 'label' => 'AI health', 'value' => '86', 'delta' => '+4 pts' ),
	) );
}
