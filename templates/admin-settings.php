<?php
/**
 * Admin Settings tab — global plugin settings.
 *
 * @package DiluxOneOffload
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DiluxOneOffload\ConfigManager;

// Variables populated by Admin::render_tab_content() via extract( $template_data ).
// Initialise defensively so static analysis sees a definite type and a stray
// direct include cannot crash on undefined indexes.
$config = $config ?? array(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Local template variable populated by extract( $template_data ); not a true global.
?>

<div class="diluxone-offload-settings">
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'diluxone_offload_save_config' ); ?>
		<input type="hidden" name="action" value="diluxone_offload_save_config">
		<input type="hidden" name="redirect_tab" value="settings">

		<!-- ========================================================================
			Upload Settings - ALWAYS EDITABLE
			======================================================================== -->
		<div class="settings-section">
			<h3><?php esc_html_e( 'Upload Settings', 'diluxone-offload' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Configure how files are uploaded to cloud storage.', 'diluxone-offload' ); ?>
			</p>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="max_file_size"><?php esc_html_e( 'Maximum File Size (MB)', 'diluxone-offload' ); ?></label>
					</th>
					<td>
						<input type="number"
								id="max_file_size"
								name="max_file_size"
								value="<?php echo esc_attr( (string) round( ( $config['max_file_size'] ?? 20971520 ) / 1048576 ) ); ?>"
								min="1"
								max="500"
								class="small-text">
						<p class="description">
							<?php esc_html_e( 'Files larger than this are skipped by the initial sync (1-500 MB).', 'diluxone-offload' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="timeout"><?php esc_html_e( 'Transfer Timeout (seconds)', 'diluxone-offload' ); ?></label>
					</th>
					<td>
						<input type="number"
								id="timeout"
								name="timeout"
								value="<?php echo esc_attr( $config['timeout'] ); ?>"
								min="30"
								max="600"
								class="small-text">
						<p class="description">
							<?php esc_html_e( 'How long each upload request to the cloud may take (30-600 seconds). Raise it on a slow host. Downloads wait at least 300 seconds, or this value when it is higher.', 'diluxone-offload' ); ?>
						</p>
					</td>
				</tr>
			</table>
		</div>

		<!-- ========================================================================
			Offloading Settings - controls how cloud-offloaded media is served
			======================================================================== -->
		<div class="settings-section">
			<h3><?php esc_html_e( 'Offloading Settings', 'diluxone-offload' ); ?></h3>
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

		<!-- ========================================================================
			Debug & Logging - ALWAYS EDITABLE
			======================================================================== -->
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

		<!-- ========================================================================
			Submit Section
			======================================================================== -->
		<div class="submit-section">
			<button type="submit"
					name="submit"
					id="submit"
					class="button button-primary">
				<?php esc_html_e( 'Save Settings', 'diluxone-offload' ); ?>
			</button>
		</div>
	</form>
</div>
