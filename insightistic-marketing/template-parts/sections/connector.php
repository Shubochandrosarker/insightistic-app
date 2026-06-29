<?php
/**
 * WordPress connector section (steps + visual).
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$steps = array(
	array( 'n' => '1', 'title' => __( 'Create your account', 'insightistic-marketing' ), 'text' => __( 'Sign up and start a 14-day free trial — no credit card.', 'insightistic-marketing' ) ),
	array( 'n' => '2', 'title' => __( 'Add a site & copy the token', 'insightistic-marketing' ), 'text' => __( 'Add your store in the dashboard and copy the secure connector token.', 'insightistic-marketing' ) ),
	array( 'n' => '3', 'title' => __( 'Install the connector plugin', 'insightistic-marketing' ), 'text' => __( 'Install the Insightistic connector, paste the API URL and token, connect.', 'insightistic-marketing' ) ),
	array( 'n' => '4', 'title' => __( 'Run the first sync', 'insightistic-marketing' ), 'text' => __( 'Orders, products and customers import securely — and your dashboard fills in.', 'insightistic-marketing' ) ),
);
?>
<section class="ins-section ins-connector" aria-labelledby="ins-connector-title">
	<div class="ins-container">
		<div class="ins-section__head">
			<p class="ins-eyebrow"><?php esc_html_e( 'WordPress connector', 'insightistic-marketing' ); ?></p>
			<h2 id="ins-connector-title"><?php esc_html_e( 'Connect in 3 minutes, securely', 'insightistic-marketing' ); ?></h2>
			<p class="ins-lead ins-center"><?php esc_html_e( 'A lightweight plugin syncs your store over a signed connection. No customer card data ever leaves WordPress.', 'insightistic-marketing' ); ?></p>
		</div>
		<ol class="ins-steps">
			<?php foreach ( $steps as $step ) : ?>
				<li class="ins-step">
					<span class="ins-step__num"><?php echo esc_html( $step['n'] ); ?></span>
					<h3 class="ins-step__title"><?php echo esc_html( $step['title'] ); ?></h3>
					<p class="ins-step__text"><?php echo esc_html( $step['text'] ); ?></p>
				</li>
			<?php endforeach; ?>
		</ol>
		<div class="ins-center">
			<a class="ins-btn ins-btn--brand ins-btn--lg" href="<?php echo esc_url( insightistic_register_url() ); ?>"><?php esc_html_e( 'Start connecting', 'insightistic-marketing' ); ?></a>
		</div>
	</div>
</section>
