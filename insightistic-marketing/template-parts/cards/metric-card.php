<?php
/**
 * Metric card. Pass via: get_template_part(..., null, array('label','value','delta')).
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ins_label = isset( $args['label'] ) ? $args['label'] : '';
$ins_value = isset( $args['value'] ) ? $args['value'] : '';
$ins_delta = isset( $args['delta'] ) ? $args['delta'] : '';
?>
<div class="ins-metric">
	<span class="ins-metric__label"><?php echo esc_html( $ins_label ); ?></span>
	<span class="ins-metric__value"><?php echo esc_html( $ins_value ); ?></span>
	<?php if ( $ins_delta ) : ?>
		<span class="ins-metric__delta"><?php echo esc_html( $ins_delta ); ?></span>
	<?php endif; ?>
</div>
