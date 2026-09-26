<?php
/**
 * Admin: Cloud Provider › Connection. The provider form before a provider is saved; the connection, read-only, afterwards.
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

$config        = $config ?? array();
$current_state = $current_state ?? 'not_configured';
$is_configured = $is_configured ?? false;
$health        = $health ?? array();
$screen_urls   = $screen_urls ?? array();

$provider_name         = $config['cloud_provider'] ?? '';
$provider_display_name = $provider_name === 'azure' ? __( 'Microsoft Azure Blob Storage', 'diluxone-offload' ) : '';
$account_name          = (string) ( $config['provider_config']['storage_account'] ?? $config['account_name'] ?? '' );
$container_name_val    = (string) ( $config['provider_config']['container_name'] ?? $config['container_name'] ?? '' );
$is_unhealthy          = ( $health['status'] ?? '' ) === 'unhealthy';
?>

<div class="diluxone-offload-settings">
	<?php if ( ! $is_configured ) : ?>
		<!-- ====================================================================
			STATE: NOT CONFIGURED — Full configuration form
			==================================================================== -->
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'diluxone_offload_save_config' ); ?>
			<input type="hidden" name="action" value="diluxone_offload_save_config">
			<input type="hidden" name="screen" value="cloud-provider">
			<input type="hidden" name="tab" value="connection">

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
									value="<?php echo esc_attr( $account_name ); ?>"
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
									value="<?php echo esc_attr( $container_name_val ); ?>"
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
			STATE: CONFIGURED+ — The connection, read-only
			==================================================================== -->
		<div class="settings-section" id="provider-info">
			<h3>
				<?php esc_html_e( 'Cloud Storage Provider', 'diluxone-offload' ); ?>
				<?php if ( $is_unhealthy ) : ?>
					<span class="diluxone-offload-pill diluxone-offload-pill--pending"><?php esc_html_e( 'Unhealthy', 'diluxone-offload' ); ?></span>
				<?php else : ?>
					<span class="diluxone-offload-pill diluxone-offload-pill--active"><?php esc_html_e( 'Connected', 'diluxone-offload' ); ?></span>
				<?php endif; ?>
			</h3>
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
				<tr>
					<th scope="row"><?php esc_html_e( 'Media served from', 'diluxone-offload' ); ?></th>
					<td><code><?php echo esc_html( sprintf( 'https://%s.blob.core.windows.net/%s/', $account_name, $container_name_val ) ); ?></code></td>
				</tr>
				<?php endif; ?>
			</table>
			<?php if ( $current_state === 'configured' ) : ?>
			<div class="diluxone-offload-callout">
				<p style="margin: 0 0 10px 0;">
					<?php esc_html_e( 'Your cloud provider is configured. Start syncing your media files to the cloud.', 'diluxone-offload' ); ?>
				</p>
				<a href="<?php echo esc_url( add_query_arg( 'auto-start', '1', $screen_urls['sync'] ?? '' ) ); ?>" class="button button-primary">
					<span class="dashicons dashicons-cloud-upload" style="vertical-align: middle;"></span>
					<?php esc_html_e( 'Sync Files to Cloud', 'diluxone-offload' ); ?>
				</a>
			</div>
			<?php endif; ?>
			<p class="description" style="margin-top: 15px;">
				<?php esc_html_e( 'To rotate the access key or to remove the provider, use the Credentials tab.', 'diluxone-offload' ); ?>
				<a href="<?php echo esc_url( $screen_urls['credentials'] ?? '' ); ?>"><?php esc_html_e( 'Credentials', 'diluxone-offload' ); ?></a>
			</p>
		</div>
	<?php endif; ?>
</div>
