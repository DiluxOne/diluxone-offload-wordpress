<?php
/**
 * Admin: Settings › Serving: how the media is handed out once it is in the cloud.
 *
 * Local variables are populated by Admin::render_screen_content() in the
 * calling scope. Suppress the prefix sniff:
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 *
 * @package DiluxOneOffload
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DiluxOneOffload\ConfigManager;

$config = $config ?? array();
?>

<div class="diluxone-offload-settings">
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'diluxone_offload_save_config' ); ?>
		<input type="hidden" name="action" value="diluxone_offload_save_config">
		<input type="hidden" name="screen" value="settings">
		<input type="hidden" name="tab" value="serving">

		<div class="settings-section">
			<h3><?php esc_html_e( 'Serving', 'diluxone-offload' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Settings that control how cloud-offloaded media is served to the front-end.', 'diluxone-offload' ); ?>
			</p>

			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Cloud URL Scheme', 'diluxone-offload' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
									name="force_https_on_cloud"
									value="1"
									<?php checked( $config['force_https_on_cloud'] ?? true ); ?>>
							<?php esc_html_e( 'Force HTTPS for cloud storage URLs', 'diluxone-offload' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Re-applies https:// to URLs WordPress emits for the cloud storage. Needed when the site is served over plain http (typical in local dev): WP downgrades them to http and Azure rejects them with HTTP 400. Leave enabled unless you know what you are doing.', 'diluxone-offload' ); ?>
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
