<?php
/**
 * Admin: Settings › Serving: how the media is handed out once it is in the cloud.
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

// The infrequent-access class exists on Azure (the Cool tier) and on the S3
// services whose preset says so (Amazon S3, Cloudflare R2).
$provider         = (string) ( $config['cloud_provider'] ?? '' );
$offers_class     = 'azure' === $provider || ( 's3' === $provider && \DiluxOneOffload\Providers\S3Presets::offers_infrequent( (string) ( $config['provider_config']['preset'] ?? '' ) ) );
$storage_class    = (string) ( $config['storage_class'] ?? 'standard' );
$infrequent_label = 'azure' === $provider
	? __( 'Cool tier (cheaper to store, charged per read)', 'diluxone-offload' )
	: __( 'Infrequent access, STANDARD_IA (cheaper to store, charged per read)', 'diluxone-offload' );
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
							<?php esc_html_e( 'Re-applies https:// to URLs WordPress emits for the cloud storage. Needed when the site is served over plain http (typical in local dev): WP downgrades them to http and storage services reject them. Leave enabled unless you know what you are doing.', 'diluxone-offload' ); ?>
						</p>
					</td>
				</tr>
			</table>
		</div>

		<div class="settings-section">
			<h3><?php esc_html_e( 'New uploads', 'diluxone-offload' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Applied to every file uploaded from now on. Files already in the cloud keep what they were stored with.', 'diluxone-offload' ); ?>
			</p>

			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Browser caching', 'diluxone-offload' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
									name="cache_control_enabled"
									value="1"
									<?php checked( $config['cache_control_enabled'] ?? true ); ?>>
							<?php esc_html_e( 'Store a Cache-Control header with each new upload', 'diluxone-offload' ); ?>
						</label>
						<p>
							<label for="cache_control" class="screen-reader-text"><?php esc_html_e( 'Cache-Control value', 'diluxone-offload' ); ?></label>
							<input type="text"
									id="cache_control"
									name="cache_control"
									class="regular-text code"
									maxlength="200"
									value="<?php echo esc_attr( (string) ( $config['cache_control'] ?? \DiluxOneOffload\DTOs\PluginSettings::DEFAULT_CACHE_CONTROL ) ); ?>">
						</p>
						<p class="description">
							<?php esc_html_e( 'Browsers and CDNs keep the file instead of asking the storage again: fewer requests, faster pages. The default, public, max-age=604800, is one week. A file you replace under the same name may show its old version for that long.', 'diluxone-offload' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="storage_class"><?php esc_html_e( 'Storage class', 'diluxone-offload' ); ?></label></th>
					<td>
						<?php if ( $offers_class ) : ?>
							<select id="storage_class" name="storage_class">
								<option value="standard" <?php selected( $storage_class, 'standard' ); ?>><?php esc_html_e( 'Standard (the service\'s default)', 'diluxone-offload' ); ?></option>
								<option value="infrequent" <?php selected( $storage_class, 'infrequent' ); ?>><?php echo esc_html( $infrequent_label ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'The cheaper class costs less per GB stored, but every read is charged and a file is billed for a minimum time (30 days) even if deleted sooner. Worth it for an archive few people open; not for a site whose images are viewed all day. Never an archive class: media must be readable at once.', 'diluxone-offload' ); ?>
							</p>
							<?php if ( 'azure' === $provider ) : ?>
								<p class="description">
									<?php esc_html_e( 'On Azure the Cool tier needs a general-purpose v2 or Blob storage account: a v1 or premium account refuses the uploads, and after three refusals uploads pause.', 'diluxone-offload' ); ?>
								</p>
							<?php endif; ?>
						<?php else : ?>
							<input type="hidden" name="storage_class" value="<?php echo esc_attr( $storage_class ); ?>">
							<p class="description">
								<?php esc_html_e( 'Offered for Azure Blob Storage, Amazon S3 and Cloudflare R2. Your storage service stores new uploads in its default class.', 'diluxone-offload' ); ?>
							</p>
						<?php endif; ?>
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
