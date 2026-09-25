<?php
/**
 * Admin: Cloud Provider tab template.
 *
 * Local variables ($config, $is_configured, etc.) are populated by
 * Admin::render_tab_content() in the calling scope. Suppress the prefix sniff:
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 *
 * @package DiluxOneOffload
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// All data is prepared by Admin::render_tab_content() — no business logic in templates
$current_state   = $template_data['current_state'] ?? 'not_configured';
$is_configured   = $template_data['is_configured'] ?? false;
$has_files_in_db = $template_data['has_files_in_db'] ?? false;
?>

<div class="diluxone-offload-settings">
	<?php
	// Delete Provider button visible only before offloading is active (disconnect first via Sync tab)
	$can_delete_provider = in_array( $current_state, array( 'configured', 'syncing', 'synced' ), true );

	// Mask credentials for read-only display
	$provider_name         = $config['cloud_provider'] ?? '';
	$provider_display_name = '';
	$account_name          = '';
	$container_name_val    = '';
	if ( $provider_name === 'azure' ) {
		$provider_display_name = 'Microsoft Azure Blob Storage';
		$account_name          = $config['provider_config']['storage_account'] ?? $config['account_name'] ?? '';
		$container_name_val    = $config['provider_config']['container_name'] ?? $config['container_name'] ?? '';
	}

	?>

	<?php if ( ! $is_configured ) : ?>
		<!-- ====================================================================
			STATE: NOT CONFIGURED — Full configuration form
			==================================================================== -->
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'diluxone_offload_save_config' ); ?>
			<input type="hidden" name="action" value="diluxone_offload_save_config">
			<input type="hidden" name="redirect_tab" value="cloud-provider">

			<!-- Cloud Provider Selection -->
			<div class="settings-section">
				<h3><?php esc_html_e( 'Cloud Provider Configuration', 'diluxone-offload' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Select your cloud storage provider and configure the connection settings.', 'diluxone-offload' ); ?>
				</p>

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="cloud_provider"><?php esc_html_e( 'Cloud Storage Provider', 'diluxone-offload' ); ?></label>
						</th>
						<td>
							<select id="cloud_provider" name="cloud_provider" class="regular-text">
								<option value=""><?php esc_html_e( 'Select a provider...', 'diluxone-offload' ); ?></option>
								<option value="azure" <?php selected( $config['cloud_provider'] ?? '', 'azure' ); ?>>
									<?php esc_html_e( 'Microsoft Azure Blob Storage', 'diluxone-offload' ); ?>
								</option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Choose your preferred cloud storage provider. Configuration options will appear below.', 'diluxone-offload' ); ?>
							</p>
						</td>
					</tr>
				</table>
			</div>

			<!-- Azure Config -->
			<div class="settings-section provider-config" id="azure-config" style="<?php echo esc_attr( ( $config['cloud_provider'] ?? '' ) === 'azure' ? '' : 'display: none;' ); ?>">
				<h3><?php esc_html_e( 'Azure Blob Storage', 'diluxone-offload' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Enter your Azure Storage credentials.', 'diluxone-offload' ); ?></p>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="account_name"><?php esc_html_e( 'Storage Account Name', 'diluxone-offload' ); ?></label></th>
						<td>
							<input type="text" id="account_name" name="account_name"
									value="<?php echo esc_attr( $config['provider_config']['storage_account'] ?? $config['account_name'] ?? '' ); ?>"
									class="regular-text" required>
							<p class="description"><?php esc_html_e( 'Your storage account name (3-24 lowercase characters and numbers only).', 'diluxone-offload' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="account_key"><?php esc_html_e( 'Account Key', 'diluxone-offload' ); ?></label></th>
						<td>
							<input type="password" id="account_key" name="account_key"
									value=""
									class="large-text" required autocomplete="off">
							<p class="description"><?php esc_html_e( 'Primary or secondary access key from your storage account.', 'diluxone-offload' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="container_name"><?php esc_html_e( 'Container Name', 'diluxone-offload' ); ?></label></th>
						<td>
							<input type="text" id="container_name" name="container_name"
									value="<?php echo esc_attr( $config['provider_config']['container_name'] ?? $config['container_name'] ?? '' ); ?>"
									class="regular-text" required>
							<p class="description"><?php esc_html_e( 'Container name for storing your media files.', 'diluxone-offload' ); ?></p>
						</td>
					</tr>
				</table>
				<div class="test-connection-section">
					<button type="button" class="button button-secondary test-connection-btn">
						<span class="dashicons dashicons-admin-links"></span>
						<?php esc_html_e( 'Test Connection', 'diluxone-offload' ); ?>
					</button>
					<div class="connection-result"></div>
					<p class="test-status-message description" style="margin-top: 8px; color: #d63638; font-weight: 600;">
						<?php esc_html_e( 'You must test the connection successfully before saving credentials.', 'diluxone-offload' ); ?>
					</p>
				</div>
			</div>

			<!-- Save button -->
			<div class="submit-section">
				<button type="submit" name="submit" id="submit" class="button button-primary" disabled>
					<?php esc_html_e( 'Save Cloud Provider', 'diluxone-offload' ); ?>
				</button>
			</div>
		</form>

	<?php else : ?>
		<!-- ====================================================================
			STATE: CONFIGURED+ — Read-only info + Stats + Actions
			==================================================================== -->

		<!-- Section 1: Provider Info (read-only) -->
		<div class="settings-section" id="provider-info">
			<h3><?php esc_html_e( 'Cloud Storage Provider', 'diluxone-offload' ); ?></h3>
			<table class="form-table diluxone-offload-provider-info">
				<tr>
					<th scope="row"><?php esc_html_e( 'Provider', 'diluxone-offload' ); ?></th>
					<td><strong><?php echo esc_html( $provider_display_name ); ?></strong></td>
				</tr>
				<?php if ( $provider_name === 'azure' ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Storage Account', 'diluxone-offload' ); ?></th>
					<td><code><?php echo esc_html( $account_name ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Container', 'diluxone-offload' ); ?></th>
					<td><code><?php echo esc_html( $container_name_val ); ?></code></td>
				</tr>
				<?php endif; ?>
			</table>
			<?php if ( $current_state === 'configured' ) : ?>
			<div style="margin-top: 15px; padding: 15px; background: #f0f6fc; border-left: 4px solid #2271b1; border-radius: 4px;">
				<p style="margin: 0 0 10px 0;">
					<?php esc_html_e( 'Your cloud provider is configured. Start syncing your media files to the cloud.', 'diluxone-offload' ); ?>
				</p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=diluxone-offload&tab=sync-offloading&auto-start=1' ) ); ?>" class="button button-primary">
					<span class="dashicons dashicons-cloud-upload" style="vertical-align: middle;"></span>
					<?php esc_html_e( 'Sync Files to Cloud', 'diluxone-offload' ); ?>
				</a>
			</div>
			<?php endif; ?>
		</div>

		<!-- Section 2: Actions -->
		<div class="settings-section">
			<h3><?php esc_html_e( 'Configuration', 'diluxone-offload' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Update your credentials or remove the cloud provider configuration.', 'diluxone-offload' ); ?>
			</p>
			<div style="display: flex; gap: 10px; margin-top: 15px;">
				<button type="button" class="button button-secondary update-credentials-trigger">
					<span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
					<?php esc_html_e( 'Update Key', 'diluxone-offload' ); ?>
				</button>
				<?php if ( $can_delete_provider ) : ?>
				<button type="button" id="remove-provider" class="button button-secondary" style="color: #d63638; border-color: #d63638;">
					<?php esc_html_e( 'Delete Cloud Provider', 'diluxone-offload' ); ?>
				</button>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>

	<!-- Remove Provider Modal -->
	<div id="remove-provider-modal" class="diluxone-offload-modal" style="display: none;">
		<div class="diluxone-offload-modal-overlay"></div>
		<div class="diluxone-offload-modal-content">
			<h3><?php esc_html_e( 'Delete Cloud Provider Configuration', 'diluxone-offload' ); ?></h3>
			<p><?php esc_html_e( 'Are you sure you want to delete your cloud storage configuration?', 'diluxone-offload' ); ?></p>
			<p><?php esc_html_e( 'This will remove all saved credentials and reset the plugin.', 'diluxone-offload' ); ?></p>
			<p><strong><?php esc_html_e( 'This action cannot be undone.', 'diluxone-offload' ); ?></strong></p>
			<div class="modal-buttons">
				<button type="button" id="confirm-delete-provider" class="button" style="background: #d63638; border-color: #d63638; color: #fff;">
					<span class="button-text"><?php esc_html_e( 'Yes, Delete Configuration', 'diluxone-offload' ); ?></span>
					<span class="spinner" style="display: none; float: none; margin: 0 0 0 8px;"></span>
				</button>
				<button type="button" class="button button-secondary cancel-remove" style="margin-left: 10px;">
					<?php esc_html_e( 'Cancel', 'diluxone-offload' ); ?>
				</button>
			</div>
		</div>
	</div>

	<!-- Update Credentials Modal -->
	<div id="update-credentials-modal" class="diluxone-offload-modal" style="display: none;">
		<div class="diluxone-offload-modal-overlay"></div>
		<div class="diluxone-offload-modal-content update-credentials-modal">
			<h3>
				<?php esc_html_e( 'Update Cloud Provider Credentials', 'diluxone-offload' ); ?>
				<button type="button" class="modal-close" style="float: right; background: none; border: none; font-size: 24px; cursor: pointer; line-height: 1;">&times;</button>
			</h3>

			<div style="background: #fff3cd; border-left: 4px solid #f0b849; padding: 12px; margin: 15px 0; border-radius: 4px;">
				<p style="margin: 0; color: #856404;">
					<strong><?php esc_html_e( 'WARNING:', 'diluxone-offload' ); ?></strong>
					<?php esc_html_e( 'Updating the access key will temporarily interrupt file operations while testing the new connection. Current uploads/downloads may fail.', 'diluxone-offload' ); ?>
				</p>
			</div>

			<!-- Azure fields -->
			<div id="modal-azure-fields">
				<div style="background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; padding: 12px; margin: 15px 0;">
					<p style="margin: 0 0 8px 0; font-size: 13px; color: #666;">
						<strong><?php esc_html_e( 'Storage Account:', 'diluxone-offload' ); ?></strong>
						<span id="modal_account_name" style="color: #333; font-family: monospace;">
							<?php echo esc_html( $config['provider_config']['storage_account'] ?? $config['account_name'] ?? '' ); ?>
						</span>
					</p>
					<p style="margin: 0; font-size: 13px; color: #666;">
						<strong><?php esc_html_e( 'Container:', 'diluxone-offload' ); ?></strong>
						<span id="modal_container_name" style="color: #333; font-family: monospace;">
							<?php echo esc_html( $config['provider_config']['container_name'] ?? $config['container_name'] ?? '' ); ?>
						</span>
					</p>
				</div>

				<table class="form-table" style="margin-top: 15px;">
					<tr>
						<th scope="row">
							<label for="modal_account_key"><?php esc_html_e( 'New Account Key', 'diluxone-offload' ); ?></label>
						</th>
						<td>
							<input type="password"
									id="modal_account_key"
									value=""
									class="large-text"
									required
									placeholder="<?php esc_attr_e( 'Enter new access key', 'diluxone-offload' ); ?>">
						</td>
					</tr>
				</table>
			</div>

			<table class="form-table">
				<tr>
					<th scope="row"></th>
					<td>
						<label style="display: inline-block;">
							<input type="checkbox" id="modal_show_key">
							<?php esc_html_e( 'Show key', 'diluxone-offload' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"></th>
					<td>
						<button type="button" id="modal-test-connection" class="button button-secondary">
							<span class="dashicons dashicons-admin-links"></span>
							<?php esc_html_e( 'Test Connection', 'diluxone-offload' ); ?>
						</button>
					</td>
				</tr>
				<tr>
					<th scope="row"></th>
					<td>
						<div id="modal-connection-result" style="margin-top: 10px; display: block !important; visibility: visible !important;"></div>
					</td>
				</tr>
			</table>

			<div class="modal-buttons" style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd;">
				<p class="description" style="float: left; margin: 8px 0;">
					<?php esc_html_e( 'You must test the connection before saving.', 'diluxone-offload' ); ?>
				</p>
				<button type="button" class="button button-secondary modal-close">
					<?php esc_html_e( 'Cancel', 'diluxone-offload' ); ?>
				</button>
				<button type="button" id="modal-save-credentials" class="button button-primary" disabled style="margin-left: 10px;">
					<?php esc_html_e( 'Save', 'diluxone-offload' ); ?>
				</button>
			</div>
		</div>
	</div>
</div>
