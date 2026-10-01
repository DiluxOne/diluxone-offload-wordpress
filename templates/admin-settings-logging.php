<?php
/**
 * Admin: Settings › Logging: debug logging.
 *
 * Its data comes in $args from Admin::render_screen_content(), as
 * get_template_part() passes it; the locals below are this file's own, not
 * globals. Suppress the prefix sniff:
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 *
 * @package DiluxOneOffload
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DiluxOneOffload\ConfigManager;

$config = $args['config'] ?? array();
?>

<div class="diluxone-offload-settings">
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'diluxone_offload_save_config' ); ?>
		<input type="hidden" name="action" value="diluxone_offload_save_config">
		<input type="hidden" name="screen" value="settings">
		<input type="hidden" name="tab" value="logging">

		<div class="settings-section">
			<h3><?php esc_html_e( 'Debug & Logging', 'diluxone-offload' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Enable debug logging to troubleshoot issues. Only enable when needed as it may impact performance.', 'diluxone-offload' ); ?>
			</p>

			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Debug Logging', 'diluxone-offload' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
									name="enable_debug_logging"
									value="1"
									<?php checked( $config['debug_enabled'] ?? false ); ?>>
							<?php esc_html_e( 'Enable detailed debug logging', 'diluxone-offload' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Enable this only when troubleshooting issues. May impact performance.', 'diluxone-offload' ); ?>
						</p>
					</td>
				</tr>
			</table>
		</div>

		<div class="settings-section">
			<h3><?php esc_html_e( 'Notifications', 'diluxone-offload' ); ?></h3>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'E-mail', 'diluxone-offload' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
									name="notify_email"
									value="1"
									<?php checked( $config['notify_email'] ?? true ); ?>>
							<?php
							/* translators: %s: the site's administrator e-mail address */
							echo esc_html( sprintf( __( 'E-mail %s when uploads to the cloud pause, and when they resume', 'diluxone-offload' ), (string) get_option( 'admin_email' ) ) );
							?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Uploads pause after three failed attempts in a row to reach the storage (a key that stopped working, the service down). One message when that happens and one when they resume, never one per failure.', 'diluxone-offload' ); ?>
						</p>
					</td>
				</tr>
			</table>
		</div>

		<div class="submit-section">
			<button type="submit" name="submit" id="submit" class="button button-primary">
				<?php esc_html_e( 'Save Settings', 'diluxone-offload' ); ?>
			</button>
		</div>
	</form>
</div>
