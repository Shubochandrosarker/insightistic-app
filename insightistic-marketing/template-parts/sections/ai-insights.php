<?php
/**
 * AI insights section (split, visual-first / reversed).
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cards = array(
	array( 'tag' => 'Risk', 'tone' => 'bad', 'title' => 'Storm Shell Jacket refund risk rising', 'why' => 'Refund rate hit 9.4%, well above the 3.1% store average.', 'do' => 'Add a sizing chart + fit video and tag the SKU for QA before reorder.' ),
	array( 'tag' => 'Opportunity', 'tone' => 'good', 'title' => 'Summit 45L Pack is trending — push it', 'why' => 'Units sold up 22% with the highest attach rate this month.', 'do' => 'Create a “Trail Kit” bundle and feature it on the homepage.' ),
);
?>
<section class="ins-section ins-split ins-split--reverse" aria-labelledby="ins-ai-title">
	<div class="ins-container ins-split__inner">
		<div class="ins-split__text">
			<p class="ins-eyebrow ins-eyebrow--violet"><?php esc_html_e( 'AI business insights', 'insightistic-marketing' ); ?></p>
			<h2 id="ins-ai-title"><?php esc_html_e( 'What changed, why it matters, what to do next', 'insightistic-marketing' ); ?></h2>
			<p class="ins-lead"><?php esc_html_e( 'Insightistic explains your numbers in plain language and recommends the next action — not just another chart to interpret yourself.', 'insightistic-marketing' ); ?></p>
			<a class="ins-link" href="<?php echo esc_url( home_url( '/ai-insights' ) ); ?>"><?php esc_html_e( 'See how AI insights work', 'insightistic-marketing' ); ?> <?php echo insightistic_icon( 'arrow-right', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
		</div>
		<div class="ins-split__visual">
			<div class="ins-insightcards">
				<?php foreach ( $cards as $card ) : ?>
					<article class="ins-insightcard">
						<span class="ins-pill ins-pill--<?php echo esc_attr( $card['tone'] ); ?>"><?php echo esc_html( $card['tag'] ); ?></span>
						<h3><?php echo esc_html( $card['title'] ); ?></h3>
						<p class="ins-insightcard__why"><strong><?php esc_html_e( 'Why:', 'insightistic-marketing' ); ?></strong> <?php echo esc_html( $card['why'] ); ?></p>
						<p class="ins-insightcard__do"><strong><?php esc_html_e( 'Do this:', 'insightistic-marketing' ); ?></strong> <?php echo esc_html( $card['do'] ); ?></p>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
</section>
