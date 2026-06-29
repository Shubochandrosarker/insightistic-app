<?php
/**
 * Marketing-site settings page (Appearance → Insightistic).
 *
 * Read-only summary of how the marketing site connects to the SaaS app. Real
 * SaaS owner management lives inside the app, not here. Editable values live in
 * the Customizer; this page just surfaces the current state at a glance.
 *
 * @package Insightistic_Marketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'admin_menu',
	function () {
		add_theme_page(
			__( 'Insightistic', 'insightistic-marketing' ),
			__( 'Insightistic', 'insightistic-marketing' ),
			'edit_theme_options',
			'insightistic-marketing',
			'insightistic_render_settings_page'
		);
	}
);

/**
 * Render the read-only settings overview.
 */
function insightistic_render_settings_page() {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return;
	}

	$formistic = insightistic_formistic_active();

	$rows = array(
		__( 'App URL', 'insightistic-marketing' )        => insightistic_app_base(),
		__( 'API URL', 'insightistic-marketing' )        => insightistic_api_url(),
		__( 'Login URL', 'insightistic-marketing' )      => insightistic_login_url(),
		__( 'Register URL', 'insightistic-marketing' )   => insightistic_register_url(),
		__( 'Dashboard URL', 'insightistic-marketing' )  => insightistic_dashboard_url(),
	);

	$statuses = array(
		__( 'Formistic plugin', 'insightistic-marketing' )       => $formistic,
		__( 'Contact form id set', 'insightistic-marketing' )    => (bool) insightistic_option( 'insightistic_form_contact', 'contact' ),
		__( 'Newsletter form id set', 'insightistic-marketing' ) => (bool) insightistic_option( 'insightistic_form_newsletter', 'newsletter' ),
	);
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Insightistic Marketing', 'insightistic-marketing' ); ?></h1>
		<p><?php esc_html_e( 'This site is the public marketing website. Accounts, billing and the dashboard are handled by the SaaS app — this page only shows how the two connect.', 'insightistic-marketing' ); ?></p>

		<h2><?php esc_html_e( 'App routing', 'insightistic-marketing' ); ?></h2>
		<table class="widefat striped" style="max-width:760px">
			<tbody>
			<?php foreach ( $rows as $label => $value ) : ?>
				<tr>
					<td style="font-weight:600;width:220px"><?php echo esc_html( $label ); ?></td>
					<td><a href="<?php echo esc_url( $value ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $value ); ?></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Integration status', 'insightistic-marketing' ); ?></h2>
		<table class="widefat striped" style="max-width:760px">
			<tbody>
			<?php foreach ( $statuses as $label => $ok ) : ?>
				<tr>
					<td style="font-weight:600;width:220px"><?php echo esc_html( $label ); ?></td>
					<td>
						<?php if ( $ok ) : ?>
							<span style="color:#00a341;font-weight:600">&#10003; <?php esc_html_e( 'Active', 'insightistic-marketing' ); ?></span>
						<?php else : ?>
							<span style="color:#d63638;font-weight:600">&#10007; <?php esc_html_e( 'Not configured', 'insightistic-marketing' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<p style="margin-top:1.2rem">
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'customize.php?autofocus[panel]=insightistic_panel' ) ); ?>">
				<?php esc_html_e( 'Edit URLs, hero & forms in the Customizer', 'insightistic-marketing' ); ?>
			</a>
		</p>
	</div>
	<?php
}
