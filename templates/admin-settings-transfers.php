<?php
/**
 * Admin: Settings › Transfers: the size limit and the timeout of every transfer.
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
		<input type="hidden" name="tab" value="transfers">

		<div class="settings-section">
			<h3><?php esc_html_e( 'Transfers', 'diluxone-offload' ); ?></h3>
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
								value="<?php echo esc_attr( (string) ( $config['timeout'] ?? 60 ) ); ?>"
								min="30"
								max="600"
								class="small-text">
						<p class="description">
							<?php esc_html_e( 'How long each upload request to the cloud may take (30-600 seconds). Raise it on a slow host. Downloads wait at least 300 seconds, or this value when it is higher.', 'diluxone-offload' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="excluded_folders"><?php esc_html_e( 'Folders the initial sync leaves out', 'diluxone-offload' ); ?></label>
					</th>
					<td>
						<textarea id="excluded_folders"
								name="excluded_folders"
								rows="4"
								class="large-text code"
								placeholder="backups/&#10;cache/"><?php echo esc_textarea( implode( "\n", (array) ( $config['excluded_folders'] ?? array() ) ) ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'One folder per line, under wp-content/uploads/ (for example backups/ or cache/). The initial sync skips them and lists them on the Sync screen. Files uploaded through the site while offloading is on still go to the cloud.', 'diluxone-offload' ); ?>
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
