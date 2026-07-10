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
	array( 'n' => '1', 'title' => __( 'Create your account', 'insightistic-marketing' ), 'text' => __( 'Sign up free — no credit card, free forever.', 'insightistic-marketing' ) ),
	array( 'n' => '2', 'title' => __( 'Paste your license key', 'insightistic-marketing' ), 'text' => __( 'Already running the free Insightistic plugin? Paste the key from your dashboard into Insightistic → License — that\'s the whole setup.', 'insightistic-marketing' ) ),
	array( 'n' => '3', 'title' => __( 'Watch your dashboard fill in', 'insightistic-marketing' ), 'text' => __( 'Orders, products, customers and site health sync automatically — no separate connector plugin needed.', 'insightistic-marketing' ) ),
);
?>
<section class="ins-section ins-connector" aria-labelledby="ins-connector-title">
	<div class="ins-container">
		<div class="ins-section__head">
			<p class="ins-eyebrow"><?php esc_html_e( 'Getting connected', 'insightistic-marketing' ); ?></p>
			<h2 id="ins-connector-title"><?php esc_html_e( 'Connect in 3 minutes, securely', 'insightistic-marketing' ); ?></h2>
			<p class="ins-lead ins-center"><?php esc_html_e( 'Every sync runs over a signed connection — no customer card data ever leaves WordPress. Don\'t run the Insightistic plugin on this site? A standalone connector plugin covers that case too.', 'insightistic-marketing' ); ?></p>
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
