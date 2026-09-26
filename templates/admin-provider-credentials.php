<?php
/**
 * Admin: Cloud Provider › Credentials. Rotate the access key; delete the provider.
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

$config              = $config ?? array();
$current_state       = $current_state ?? 'not_configured';
$is_configured       = $is_configured ?? false;
$can_delete_provider = $can_delete_provider ?? false;
$screen_urls         = $screen_urls ?? array();

$account_name       = (string) ( $config['provider_config']['storage_account'] ?? $config['account_name'] ?? '' );
$container_name_val = (string) ( $config['provider_config']['container_name'] ?? $config['container_name'] ?? '' );
?>

<div class="diluxone-offload-settings" id="provider-credentials">
	<?php if ( ! $is_configured ) : ?>
		<div class="diluxone-offload-callout">
			<p style="margin: 0 0 10px 0;">
				<?php esc_html_e( 'Connect a provider in the Connection tab first. Once one is saved, this is where its key is rotated and where the provider is removed.', 'diluxone-offload' ); ?>
			</p>
			<a href="<?php echo esc_url( $screen_urls['connection'] ?? '' ); ?>" class="button button-primary"><?php esc_html_e( 'Go to Connection', 'diluxone-offload' ); ?></a>
		</div>
	<?php else : ?>
		<!-- Rotate the key -->
		<div class="settings-section" id="update-credentials">
			<h3><?php esc_html_e( 'Update Cloud Provider Credentials', 'diluxone-offload' ); ?></h3>
			<div class="diluxone-offload-callout diluxone-offload-callout--warn">
				<p style="margin: 0;">
					<strong><?php esc_html_e( 'WARNING:', 'diluxone-offload' ); ?></strong>
					<?php esc_html_e( 'Updating the access key will temporarily interrupt file operations while testing the new connection. Current uploads/downloads may fail.', 'diluxone-offload' ); ?>
				</p>
			</div>

			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Storage Account', 'diluxone-offload' ); ?></th>
					<td><code id="credentials_account_name"><?php echo esc_html( $account_name ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Container', 'diluxone-offload' ); ?></th>
					<td><code id="credentials_container_name"><?php echo esc_html( $container_name_val ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><label for="new_account_key"><?php esc_html_e( 'New Account Key', 'diluxone-offload' ); ?></label></th>
					<td>
						<input type="password" id="new_account_key" class="large-text" autocomplete="off" placeholder="<?php esc_attr_e( 'Enter new access key', 'diluxone-offload' ); ?>">
						<p>
							<label><input type="checkbox" id="show_new_account_key"> <?php esc_html_e( 'Show key', 'diluxone-offload' ); ?></label>
						</p>
					</td>
				</tr>
			</table>

			<div class="test-connection-section">
				<button type="button" id="test-new-credentials" class="button button-secondary">
					<span class="dashicons dashicons-admin-links"></span>
					<?php esc_html_e( 'Test Connection', 'diluxone-offload' ); ?>
				</button>
				<div id="new-credentials-result" class="connection-result" style="display: block;"></div>
				<p class="description" style="margin-top: 8px;">
					<?php esc_html_e( 'You must test the connection before saving.', 'diluxone-offload' ); ?>
				</p>
			</div>

			<div class="submit-section">
				<button type="button" id="save-new-credentials" class="button button-primary" disabled>
					<?php esc_html_e( 'Save New Key', 'diluxone-offload' ); ?>
				</button>
			</div>
		</div>

		<!-- Delete the provider -->
		<div class="settings-section" id="delete-provider">
			<h3><?php esc_html_e( 'Delete Cloud Provider', 'diluxone-offload' ); ?></h3>
			<?php if ( $can_delete_provider ) : ?>
				<p class="description">
					<?php esc_html_e( 'Forgets the saved credentials and the sync state, and returns the plugin to Not Configured. The files already in the cloud are not touched.', 'diluxone-offload' ); ?>
				</p>
				<p>
					<button type="button" id="remove-provider" class="button button-secondary">
						<?php esc_html_e( 'Delete Cloud Provider', 'diluxone-offload' ); ?>
					</button>
				</p>
			<?php else : ?>
				<p class="description">
					<?php esc_html_e( 'While offloading is active the provider cannot be deleted: the library is served from the cloud. Disconnect first, which brings every file back to this server.', 'diluxone-offload' ); ?>
				</p>
				<p>
					<a href="<?php echo esc_url( $screen_urls['disconnect'] ?? '' ); ?>" class="button"><?php esc_html_e( 'Sync & Offloading › Disconnect', 'diluxone-offload' ); ?></a>
				</p>
			<?php endif; ?>
		</div>

		<!-- Remove Provider Modal -->
		<div id="remove-provider-modal" class="diluxone-offload-modal" style="display: none;">
			<div class="diluxone-offload-modal-overlay"></div>
			<div class="diluxone-offload-modal-content">
				<h3><?php esc_html_e( 'Delete Cloud Provider Configuration', 'diluxone-offload' ); ?></h3>
				<p><?php esc_html_e( 'Are you sure you want to delete your cloud storage configuration?', 'diluxone-offload' ); ?></p>
				<p><?php esc_html_e( 'This will remove all saved credentials and reset the plugin.', 'diluxone-offload' ); ?></p>
				<p><strong><?php esc_html_e( 'This action cannot be undone.', 'diluxone-offload' ); ?></strong></p>
				<div class="modal-buttons">
					<button type="button" id="confirm-delete-provider" class="button button-primary">
						<span class="button-text"><?php esc_html_e( 'Yes, Delete Configuration', 'diluxone-offload' ); ?></span>
						<span class="spinner" style="display: none; float: none; margin: 0 0 0 8px;"></span>
					</button>
					<button type="button" class="button button-secondary cancel-remove" style="margin-left: 10px;">
						<?php esc_html_e( 'Cancel', 'diluxone-offload' ); ?>
					</button>
				</div>
			</div>
		</div>
	<?php endif; ?>
</div>
