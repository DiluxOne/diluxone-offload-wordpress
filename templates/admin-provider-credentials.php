<?php
/**
 * Admin: Cloud Provider › Credentials. Rotate the access key; delete the provider.
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

$config              = $args['config'] ?? array();
$current_state       = $args['current_state'] ?? 'not_configured';
$is_configured       = $args['is_configured'] ?? false;
$can_delete_provider = $args['can_delete_provider'] ?? false;
$screen_urls         = $args['screen_urls'] ?? array();

$provider_name      = (string) ( $config['cloud_provider'] ?? '' );
$provider_config    = (array) ( $config['provider_config'] ?? array() );
$account_name       = (string) ( $provider_config['storage_account'] ?? '' );
$container_name_val = (string) ( $provider_config['container_name'] ?? '' );
?>

<div class="diluxone-offload-settings" id="provider-credentials">
	<?php if ( ! $is_configured ) : ?>
		<div class="diluxone-offload-callout">
			<p>
				<?php esc_html_e( 'Connect a provider in the Connection tab first. Once one is saved, this is where its key is rotated and where the provider is removed.', 'diluxone-offload' ); ?>
			</p>
			<a href="<?php echo esc_url( $screen_urls['connection'] ?? '' ); ?>" class="button button-primary"><?php esc_html_e( 'Go to Connection', 'diluxone-offload' ); ?></a>
		</div>
	<?php else : ?>
		<!-- Rotate the key -->
		<div class="settings-section" id="update-credentials">
			<h3><?php esc_html_e( 'Update Cloud Provider Credentials', 'diluxone-offload' ); ?></h3>
			<div class="diluxone-offload-callout diluxone-offload-callout--warn">
				<p>
					<strong><?php esc_html_e( 'WARNING:', 'diluxone-offload' ); ?></strong>
					<?php esc_html_e( 'Updating the access key will temporarily interrupt file operations while testing the new connection. Current uploads/downloads may fail.', 'diluxone-offload' ); ?>
				</p>
			</div>

			<table class="form-table">
				<?php if ( 's3' === $provider_name ) : ?>
					<?php
					// What stays: shown, and posted back as it is (data-field).
					$s3_fixed = array(
						's3_preset'     => array( __( 'Service', 'diluxone-offload' ), (string) ( $provider_config['preset'] ?? '' ), \DiluxOneOffload\Providers\S3Presets::label( (string) ( $provider_config['preset'] ?? '' ) ) ),
						's3_bucket'     => array( __( 'Bucket', 'diluxone-offload' ), (string) ( $provider_config['bucket'] ?? '' ), '' ),
						's3_endpoint'   => array( __( 'Endpoint', 'diluxone-offload' ), (string) ( $provider_config['endpoint'] ?? '' ), '' ),
						's3_region'     => array( __( 'Region', 'diluxone-offload' ), (string) ( $provider_config['region'] ?? '' ), '' ),
						's3_public_url' => array( __( 'Public URL', 'diluxone-offload' ), (string) ( $provider_config['public_url'] ?? '' ), '' ),
						's3_object_acl' => array( __( 'Object ACL', 'diluxone-offload' ), ! empty( $provider_config['object_acl'] ) ? '1' : '', ! empty( $provider_config['object_acl'] ) ? __( 'public-read on each upload', 'diluxone-offload' ) : __( 'none', 'diluxone-offload' ) ),
						's3_path_style' => array( __( 'Addressing', 'diluxone-offload' ), ! empty( $provider_config['path_style'] ) ? 'path' : 'virtual', ! empty( $provider_config['path_style'] ) ? __( 'Path-style', 'diluxone-offload' ) : __( 'Virtual-hosted', 'diluxone-offload' ) ),
					);
					foreach ( $s3_fixed as $s3_field => $s3_row ) :
						?>
					<tr>
						<th scope="row"><?php echo esc_html( $s3_row[0] ); ?></th>
						<td><code data-field="<?php echo esc_attr( $s3_field ); ?>" data-value="<?php echo esc_attr( $s3_row[1] ); ?>"><?php echo esc_html( '' !== $s3_row[2] ? $s3_row[2] : $s3_row[1] ); ?></code></td>
					</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><label for="new_access_key_id"><?php esc_html_e( 'Access Key ID', 'diluxone-offload' ); ?></label></th>
						<td>
							<input type="text" id="new_access_key_id" data-field="s3_access_key_id" data-required class="regular-text" autocomplete="off" value="<?php echo esc_attr( (string) ( $provider_config['access_key_id'] ?? '' ) ); ?>">
							<p class="description"><?php esc_html_e( 'A rotation usually gives a new pair: change it if yours did.', 'diluxone-offload' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="new_secret_access_key"><?php esc_html_e( 'New Secret Access Key', 'diluxone-offload' ); ?></label></th>
						<td>
							<input type="password" id="new_secret_access_key" data-field="s3_secret_access_key" data-required data-secret class="large-text" autocomplete="off">
							<p>
								<label><input type="checkbox" id="show_new_account_key"> <?php esc_html_e( 'Show key', 'diluxone-offload' ); ?></label>
							</p>
						</td>
					</tr>
				<?php else : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Storage Account', 'diluxone-offload' ); ?></th>
					<td><code id="credentials_account_name" data-field="account_name"><?php echo esc_html( $account_name ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Container', 'diluxone-offload' ); ?></th>
					<td><code id="credentials_container_name" data-field="container_name"><?php echo esc_html( $container_name_val ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><label for="new_account_key"><?php esc_html_e( 'New Account Key', 'diluxone-offload' ); ?></label></th>
					<td>
						<input type="password" id="new_account_key" data-field="account_key" data-required data-secret class="large-text" autocomplete="off" placeholder="<?php esc_attr_e( 'Enter new access key', 'diluxone-offload' ); ?>">
						<p>
							<label><input type="checkbox" id="show_new_account_key"> <?php esc_html_e( 'Show key', 'diluxone-offload' ); ?></label>
						</p>
					</td>
				</tr>
				<?php endif; ?>
			</table>

			<div class="test-connection-section">
				<button type="button" id="test-new-credentials" class="button button-secondary">
					<span class="dashicons dashicons-admin-links"></span>
					<?php esc_html_e( 'Test Connection', 'diluxone-offload' ); ?>
				</button>
				<div id="new-credentials-result" class="connection-result"></div>
				<p class="description">
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
					<button type="button" id="remove-provider" class="button diluxone-offload-button-danger">
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
		<div id="remove-provider-modal" class="diluxone-offload-modal" hidden>
			<div class="diluxone-offload-modal-overlay"></div>
			<div class="diluxone-offload-modal-content">
				<h3><?php esc_html_e( 'Delete Cloud Provider Configuration', 'diluxone-offload' ); ?></h3>
				<p><?php esc_html_e( 'Are you sure you want to delete your cloud storage configuration?', 'diluxone-offload' ); ?></p>
				<p><?php esc_html_e( 'This will remove all saved credentials and reset the plugin.', 'diluxone-offload' ); ?></p>
				<p><strong><?php esc_html_e( 'This action cannot be undone.', 'diluxone-offload' ); ?></strong></p>
				<div class="modal-buttons">
					<button type="button" id="confirm-delete-provider" class="button button-primary">
						<span class="button-text"><?php esc_html_e( 'Yes, Delete Configuration', 'diluxone-offload' ); ?></span>
						<span class="spinner diluxone-offload-inline-spinner" hidden></span>
					</button>
					<button type="button" class="button button-secondary cancel-remove">
						<?php esc_html_e( 'Cancel', 'diluxone-offload' ); ?>
					</button>
				</div>
			</div>
		</div>
	<?php endif; ?>
</div>
