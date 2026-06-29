<?php
/**
 * Block patterns — let editors assemble landing pages fast without a page
 * builder. The homepage ships as PHP template parts; these patterns mirror the
 * same sections for use inside the block editor on other pages.
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'init',
	function () {
		if ( ! function_exists( 'register_block_pattern_category' ) ) {
			return;
		}

		register_block_pattern_category( 'insightistic', array(
			'label' => __( 'Insightistic', 'insightistic-marketing' ),
		) );

		$register = '';
		$login    = esc_url( insightistic_login_url() );
		$reg      = esc_url( insightistic_register_url() );

		// App CTA band.
		register_block_pattern(
			'insightistic/app-cta',
			array(
				'title'      => __( 'App CTA band', 'insightistic-marketing' ),
				'categories' => array( 'insightistic' ),
				'content'    => '<!-- wp:group {"className":"ins-pattern-cta","align":"wide","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|60"}}},"backgroundColor":"panel","layout":{"type":"constrained"}} -->'
					. '<div class="wp-block-group alignwide ins-pattern-cta has-panel-background-color has-background" style="padding-top:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--60)">'
					. '<!-- wp:heading {"textAlign":"center","level":2} --><h2 class="wp-block-heading has-text-align-center">Start seeing your store clearly</h2><!-- /wp:heading -->'
					. '<!-- wp:paragraph {"align":"center","textColor":"mint"} --><p class="has-text-align-center has-mint-color has-text-color">14-day free trial · No credit card required · Connect in 3 minutes</p><!-- /wp:paragraph -->'
					. '<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} --><div class="wp-block-buttons">'
					. '<!-- wp:button {"backgroundColor":"brand","textColor":"ink"} --><div class="wp-block-button"><a class="wp-block-button__link has-ink-color has-brand-background-color has-text-color has-background wp-element-button" href="' . $reg . '">Start free trial</a></div><!-- /wp:button -->'
					. '<!-- wp:button {"className":"is-style-outline"} --><div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="' . $login . '">Sign in</a></div><!-- /wp:button -->'
					. '</div><!-- /wp:buttons --></div><!-- /wp:group -->',
			)
		);

		// Feature grid (3 columns).
		register_block_pattern(
			'insightistic/feature-grid',
			array(
				'title'      => __( 'Feature grid', 'insightistic-marketing' ),
				'categories' => array( 'insightistic' ),
				'content'    => '<!-- wp:columns {"align":"wide","className":"ins-pattern-features"} --><div class="wp-block-columns alignwide ins-pattern-features">'
					. str_repeat(
						'<!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Feature</h3><!-- /wp:heading --><!-- wp:paragraph {"textColor":"mint"} --><p class="has-mint-color has-text-color">Describe the outcome this feature delivers for the store owner.</p><!-- /wp:paragraph --></div><!-- /wp:column -->',
						3
					)
					. '</div><!-- /wp:columns -->',
			)
		);

		unset( $register );
	}
);
