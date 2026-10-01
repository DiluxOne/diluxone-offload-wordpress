<?php
/**
 * Admin: Cloud Provider › Connection. The provider form before a provider is saved; the connection, read-only, afterwards.
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

$config        = $args['config'] ?? array();
$current_state = $args['current_state'] ?? 'not_configured';
$is_configured = $args['is_configured'] ?? false;
$health        = $args['health'] ?? array();
$timestamps    = $args['timestamps'] ?? array();
$screen_urls   = $args['screen_urls'] ?? array();
$connected_at  = (int) ( $timestamps['connected_at'] ?? 0 );

$provider_name         = $config['cloud_provider'] ?? '';
$provider_display_name = \DiluxOneOffload\Factories\CloudStorageFactory::get_provider_label( $provider_name );
$provider_rows         = ( new \DiluxOneOffload\DTOs\ProviderConfig( $provider_name, (array) ( $config['provider_config'] ?? array() ) ) )->describe();
$account_name          = (string) ( $config['provider_config']['storage_account'] ?? '' );
$container_name_val    = (string) ( $config['provider_config']['container_name'] ?? '' );
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
								<option value="s3" <?php selected( $config['cloud_provider'] ?? '', 's3' ); ?>>
									<?php esc_html_e( 'S3-compatible storage (Amazon S3, Cloudflare R2, Backblaze B2, Google Cloud Storage, Hetzner, Scaleway, OVHcloud, MinIO, …)', 'diluxone-offload' ); ?>
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
			<div class="settings-section provider-config" id="azure-config"<?php echo esc_attr( ( $config['cloud_provider'] ?? '' ) === 'azure' ? '' : ' hidden' ); ?>>
				<h3><?php esc_html_e( 'Azure Blob Storage', 'diluxone-offload' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Enter your Azure Storage credentials.', 'diluxone-offload' ); ?></p>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="account_name"><?php esc_html_e( 'Storage Account Name', 'diluxone-offload' ); ?></label></th>
						<td>
							<input type="text" id="account_name" name="account_name"
									value="<?php echo esc_attr( $account_name ); ?>"
									class="regular-text" required data-required>
							<p class="description"><?php esc_html_e( 'Your storage account name (3-24 lowercase characters and numbers only).', 'diluxone-offload' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="account_key"><?php esc_html_e( 'Account Key', 'diluxone-offload' ); ?></label></th>
						<td>
							<input type="password" id="account_key" name="account_key"
									value=""
									class="large-text" required data-required autocomplete="off">
							<p class="description"><?php esc_html_e( 'Primary or secondary access key from your storage account.', 'diluxone-offload' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="container_name"><?php esc_html_e( 'Container Name', 'diluxone-offload' ); ?></label></th>
						<td>
							<input type="text" id="container_name" name="container_name"
									value="<?php echo esc_attr( $container_name_val ); ?>"
									class="regular-text" required data-required>
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
					<p class="test-status-message description">
						<?php esc_html_e( 'You must test the connection successfully before saving credentials.', 'diluxone-offload' ); ?>
					</p>
				</div>
			</div>

			<!-- S3-compatible Config -->
			<div class="settings-section provider-config" id="s3-config"<?php echo esc_attr( ( $config['cloud_provider'] ?? '' ) === 's3' ? '' : ' hidden' ); ?>>
				<h3><?php esc_html_e( 'S3-compatible storage', 'diluxone-offload' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Pick the service; it fills in the endpoint and the public URL, and you can change both.', 'diluxone-offload' ); ?></p>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="s3_preset"><?php esc_html_e( 'Service', 'diluxone-offload' ); ?></label></th>
						<td>
							<select id="s3_preset" name="s3_preset" class="regular-text" data-required>
								<?php foreach ( \DiluxOneOffload\Providers\S3Presets::all() as $s3_key => $s3_preset ) : ?>
									<option value="<?php echo esc_attr( $s3_key ); ?>"><?php echo esc_html( $s3_preset['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<div class="diluxone-offload-s3-hints">
								<p class="description" data-preset="aws" hidden><?php esc_html_e( 'AWS console › IAM › Users › Security credentials › Create access key, for a user allowed to put, get, delete and list objects in the bucket. The bucket must allow anonymous read of objects (Block Public Access off and a bucket policy granting s3:GetObject); Test Connection checks it.', 'diluxone-offload' ); ?></p>
								<p class="description" data-preset="r2" hidden><?php esc_html_e( 'Cloudflare dashboard › R2 › Manage API tokens: a token with Object Read & Write on the bucket. The endpoint is https://<account id>.r2.cloudflarestorage.com; the public URL is the bucket\'s r2.dev address or your custom domain, with public access enabled on the bucket.', 'diluxone-offload' ); ?></p>
								<p class="description" data-preset="b2" hidden><?php esc_html_e( 'Backblaze › Application Keys: a key with read and write access to the bucket. The region is in the bucket\'s S3 endpoint (for example us-west-004). The bucket must be public; Test Connection checks it.', 'diluxone-offload' ); ?></p>
								<p class="description" data-preset="spaces" hidden><?php esc_html_e( 'DigitalOcean › API › Spaces Keys. The region is the Space\'s datacenter (for example nyc3). Anyone must be able to read the Space\'s files; Test Connection checks it. The Space\'s CDN endpoint can be the public URL.', 'diluxone-offload' ); ?></p>
								<p class="description" data-preset="wasabi" hidden><?php esc_html_e( 'Wasabi console › Access Keys. The region is the bucket\'s (for example us-east-1). Anyone must be able to read the bucket\'s objects; Test Connection checks it.', 'diluxone-offload' ); ?></p>
								<p class="description" data-preset="gcs" hidden><?php esc_html_e( 'Google Cloud console › Cloud Storage › Settings › Interoperability: an HMAC key for a service account with access to the bucket. The bucket must grant allUsers the Storage Object Viewer role.', 'diluxone-offload' ); ?></p>
								<p class="description" data-preset="hetzner" hidden><?php esc_html_e( 'Hetzner Console › your project › Security › S3 Credentials. The region is the bucket\'s location (fsn1, nbg1 or hel1). Anyone must be able to read the bucket\'s objects: a public bucket, or Object ACL under Advanced; Test Connection checks it.', 'diluxone-offload' ); ?></p>
								<p class="description" data-preset="linode" hidden><?php esc_html_e( 'Akamai Cloud Manager › Object Storage › Access Keys, with read and write in the bucket\'s region. The region is the first part of the bucket\'s hostname (for example us-east-1 or us-ord-10). Newer endpoints ignore per-object ACLs: let anyone read the bucket with a bucket policy; Test Connection checks it.', 'diluxone-offload' ); ?></p>
								<p class="description" data-preset="vultr" hidden><?php esc_html_e( 'Vultr console › Object Storage › your subscription: its S3 credentials and hostname. The region is the first part of the hostname (for example ewr1). Anyone must be able to read the objects: a bucket policy, or Object ACL under Advanced; Test Connection checks it.', 'diluxone-offload' ); ?></p>
								<p class="description" data-preset="scaleway" hidden><?php esc_html_e( 'Scaleway console › IAM › API keys, with the bucket\'s project as the preferred project for Object Storage. The region is the bucket\'s (fr-par, nl-ams, pl-waw or it-mil). Anyone must be able to read the objects: a bucket policy, or Object ACL under Advanced; Test Connection checks it.', 'diluxone-offload' ); ?></p>
								<p class="description" data-preset="ovh" hidden><?php esc_html_e( 'OVHcloud Control Panel › Public Cloud › Object Storage › Object Storage users: a user linked to the bucket, then View credentials. The region is the bucket\'s, in lower case (for example gra). Anyone must be able to read the objects: turn on Object ACL under Advanced; Test Connection checks it.', 'diluxone-offload' ); ?></p>
								<p class="description" data-preset="idrive" hidden><?php esc_html_e( 'IDrive e2 dashboard › Access Keys: a key for the bucket\'s region with read and write. If your dashboard shows an endpoint of your own, type it instead of the one filled in. The public URL is the bucket\'s Public Bucket URL (Bucket summary); public buckets must be enabled on the account. Test Connection checks it.', 'diluxone-offload' ); ?></p>
								<p class="description" data-preset="custom" hidden><?php esc_html_e( 'Any other server that speaks the S3 API (MinIO, Ceph, …): type the endpoint, the region it expects and the public URL browsers load objects from.', 'diluxone-offload' ); ?></p>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="s3_region"><?php esc_html_e( 'Region', 'diluxone-offload' ); ?></label></th>
						<td>
							<input type="text" id="s3_region" name="s3_region" value="" class="regular-text" required data-required>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="s3_endpoint"><?php esc_html_e( 'Endpoint', 'diluxone-offload' ); ?></label></th>
						<td>
							<input type="url" id="s3_endpoint" name="s3_endpoint" value="" class="large-text" required data-required>
							<p class="description diluxone-offload-s3-http-warning" hidden><?php esc_html_e( 'This endpoint is plain http: requests, and the signature of your key, travel unencrypted. Use it only on a private network.', 'diluxone-offload' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="s3_bucket"><?php esc_html_e( 'Bucket', 'diluxone-offload' ); ?></label></th>
						<td>
							<input type="text" id="s3_bucket" name="s3_bucket" value="" class="regular-text" required data-required>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="s3_access_key_id"><?php esc_html_e( 'Access Key ID', 'diluxone-offload' ); ?></label></th>
						<td>
							<input type="text" id="s3_access_key_id" name="s3_access_key_id" value="" class="regular-text" required data-required autocomplete="off">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="s3_secret_access_key"><?php esc_html_e( 'Secret Access Key', 'diluxone-offload' ); ?></label></th>
						<td>
							<input type="password" id="s3_secret_access_key" name="s3_secret_access_key" value="" class="large-text" required data-required autocomplete="off">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="s3_public_url"><?php esc_html_e( 'Public URL', 'diluxone-offload' ); ?></label></th>
						<td>
							<input type="url" id="s3_public_url" name="s3_public_url" value="" class="large-text" required data-required>
							<p class="description"><?php esc_html_e( 'Where browsers load your media from. Use your CDN or custom domain here if you have one.', 'diluxone-offload' ); ?></p>
						</td>
					</tr>
				</table>
				<details class="diluxone-offload-s3-advanced">
					<summary><?php esc_html_e( 'Advanced', 'diluxone-offload' ); ?></summary>
					<table class="form-table">
						<tr class="diluxone-offload-s3-acl-row">
							<th scope="row"><?php esc_html_e( 'Object ACL', 'diluxone-offload' ); ?></th>
							<td>
								<label><input type="checkbox" id="s3_object_acl" name="s3_object_acl" value="1"> <?php esc_html_e( 'Make each upload public (public-read ACL)', 'diluxone-offload' ); ?></label>
								<p class="description"><?php esc_html_e( 'Only for buckets where public read is set per object. Amazon S3 buckets created since April 2023 have ACLs disabled and refuse it; there, make the bucket readable with a bucket policy instead.', 'diluxone-offload' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="s3_path_style"><?php esc_html_e( 'Addressing', 'diluxone-offload' ); ?></label></th>
							<td>
								<select id="s3_path_style" name="s3_path_style">
									<option value="path"><?php esc_html_e( 'Path-style (endpoint/bucket/key)', 'diluxone-offload' ); ?></option>
									<option value="virtual"><?php esc_html_e( 'Virtual-hosted (bucket.endpoint/key)', 'diluxone-offload' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Set by the service; editable only under Custom.', 'diluxone-offload' ); ?></p>
							</td>
						</tr>
					</table>
				</details>
				<div class="test-connection-section">
					<button type="button" class="button button-secondary test-connection-btn">
						<span class="dashicons dashicons-admin-links"></span>
						<?php esc_html_e( 'Test Connection', 'diluxone-offload' ); ?>
					</button>
					<div class="connection-result"></div>
					<p class="test-status-message description">
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
				<?php foreach ( $provider_rows as $row_label => $row_value ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $row_label ); ?></th>
					<td><code><?php echo esc_html( $row_value ); ?></code></td>
				</tr>
				<?php endforeach; ?>
				<?php if ( $connected_at > 0 ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Connected since', 'diluxone-offload' ); ?></th>
					<td><?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $connected_at ), (string) get_option( 'date_format' ) ) ); ?> <span class="description">(<?php echo esc_html( sprintf( /* translators: %s: a human time difference, e.g. "10 minutes" */ __( '%s ago', 'diluxone-offload' ), human_time_diff( $connected_at, time() ) ) ); ?>)</span></td>
				</tr>
				<?php endif; ?>
			</table>
			<?php if ( $current_state === 'configured' ) : ?>
			<div class="diluxone-offload-callout">
				<p>
					<?php esc_html_e( 'Your cloud provider is configured. Start syncing your media files to the cloud.', 'diluxone-offload' ); ?>
				</p>
				<a href="<?php echo esc_url( add_query_arg( 'auto-start', '1', $screen_urls['sync'] ?? '' ) ); ?>" class="button button-primary">
					<span class="dashicons dashicons-cloud-upload"></span>
					<?php esc_html_e( 'Sync Files to Cloud', 'diluxone-offload' ); ?>
				</a>
			</div>
			<?php endif; ?>
			<p class="diluxone-offload-provider-actions">
				<a href="<?php echo esc_url( ( $screen_urls['credentials'] ?? '' ) . '#update-credentials' ); ?>" class="button">
					<span class="dashicons dashicons-admin-network"></span>
					<?php esc_html_e( 'Rotate the key', 'diluxone-offload' ); ?>
				</a>
				<a href="<?php echo esc_url( ( $screen_urls['credentials'] ?? '' ) . '#delete-provider' ); ?>" class="button diluxone-offload-button-danger">
					<span class="dashicons dashicons-trash"></span>
					<?php esc_html_e( 'Delete Cloud Provider', 'diluxone-offload' ); ?>
				</a>
			</p>
		</div>
	<?php endif; ?>
</div>
