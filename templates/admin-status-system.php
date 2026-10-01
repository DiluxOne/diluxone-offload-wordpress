<?php
/**
 * Admin: Status › System. The environment: WordPress, PHP, the plugin build, the provider, the free disk.
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

use DiluxOneOffload\Admin;
use DiluxOneOffload\CloudStreamWrapper;
use DiluxOneOffload\ConfigManager;

$config        = $args['config'] ?? array();
$plugin_config = $config;
$is_configured = ConfigManager::is_configured();

// The uploads directory on this server: while offloading is on, wp_upload_dir()
// answers with the cloud path, which has no disk. The Free disk row is about
// the disk a disconnect would fill (Admin::free_disk(), null when the host
// does not allow reading it).
$diluxone_offload_upload_dir = CloudStreamWrapper::native_upload_basedir();
$diluxone_offload_free_disk  = $args['free_disk'] ?? null;
?>

<div class="diluxone-offload-status">
	<div class="status-section">
		<!-- System Information -->
		<div class="system-info-section">
			<h3>
				<span class="dashicons dashicons-info"></span>
				<?php esc_html_e( 'System Information', 'diluxone-offload' ); ?>
			</h3>

			<div class="info-grid">
				<div class="info-card">
					<h4><?php esc_html_e( 'WordPress', 'diluxone-offload' ); ?></h4>
					<table class="info-table">
						<tr>
							<td><?php esc_html_e( 'Version', 'diluxone-offload' ); ?></td>
							<td><strong><?php echo esc_html( get_bloginfo( 'version' ) ); ?></strong></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Multisite', 'diluxone-offload' ); ?></td>
							<td><strong><?php echo esc_html( is_multisite() ? __( 'Yes', 'diluxone-offload' ) : __( 'No', 'diluxone-offload' ) ); ?></strong></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Upload Directory', 'diluxone-offload' ); ?></td>
							<td><code><?php echo esc_html( $diluxone_offload_upload_dir ); ?></code></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Free disk', 'diluxone-offload' ); ?></td>
							<td>
								<?php if ( is_int( $diluxone_offload_free_disk ) ) : ?>
									<strong><?php echo esc_html( (string) size_format( (int) $diluxone_offload_free_disk ) ); ?></strong>
									<span class="description"><?php esc_html_e( '(what a disconnect can bring back)', 'diluxone-offload' ); ?></span>
								<?php else : ?>
									<span class="diluxone-offload-pill diluxone-offload-pill--off"><?php esc_html_e( 'not available', 'diluxone-offload' ); ?></span>
									<span class="description"><?php esc_html_e( 'The host does not allow reading it.', 'diluxone-offload' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					</table>
				</div>

				<div class="info-card">
					<h4><?php esc_html_e( 'PHP Environment', 'diluxone-offload' ); ?></h4>
					<table class="info-table">
						<tr>
							<td><?php esc_html_e( 'PHP Version', 'diluxone-offload' ); ?></td>
							<td><strong><?php echo esc_html( PHP_VERSION ); ?></strong></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Memory Limit', 'diluxone-offload' ); ?></td>
							<td><strong><?php echo esc_html( ini_get( 'memory_limit' ) ); ?></strong></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Max Upload Size', 'diluxone-offload' ); ?></td>
							<td><strong><?php echo esc_html( (string) size_format( wp_max_upload_size() ) ); ?></strong></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Max Execution Time', 'diluxone-offload' ); ?></td>
							<td><strong><?php echo esc_html( ini_get( 'max_execution_time' ) ); ?>s</strong></td>
						</tr>
					</table>
				</div>

				<div class="info-card">
					<h4><?php esc_html_e( 'Plugin', 'diluxone-offload' ); ?></h4>
					<table class="info-table">
						<tr>
							<td><?php esc_html_e( 'Version', 'diluxone-offload' ); ?></td>
							<td><strong><?php echo esc_html( Admin::get_plugin_version() ); ?></strong></td>
						</tr>
						<?php $diluxone_offload_build = Admin::get_plugin_build(); ?>
						<?php if ( $diluxone_offload_build !== '' ) : ?>
						<tr>
							<td><?php esc_html_e( 'Build', 'diluxone-offload' ); ?></td>
							<td><strong><?php echo esc_html( $diluxone_offload_build ); ?></strong></td>
						</tr>
						<?php endif; ?>
						<tr>
							<td><?php esc_html_e( 'DB Schema Version', 'diluxone-offload' ); ?></td>
							<td><strong><?php echo esc_html( get_option( 'diluxone_offload_db_version', 'N/A' ) ); ?></strong></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Plugin Directory', 'diluxone-offload' ); ?></td>
							<td><code><?php echo esc_html( defined( 'DILUXONE_OFFLOAD_DIR' ) ? DILUXONE_OFFLOAD_DIR : 'N/A' ); ?></code></td>
						</tr>
					</table>
				</div>

				<?php if ( $is_configured && ! empty( $plugin_config['provider_config'] ) ) : ?>
				<div class="info-card">
					<h4><?php esc_html_e( 'Cloud Provider', 'diluxone-offload' ); ?></h4>
					<table class="info-table">
						<tr>
							<td><?php esc_html_e( 'Provider', 'diluxone-offload' ); ?></td>
							<td><strong><?php echo esc_html( \DiluxOneOffload\Factories\CloudStorageFactory::get_provider_label( (string) ( $plugin_config['cloud_provider'] ?? '' ) ) ); ?></strong></td>
						</tr>
						<?php foreach ( ( new \DiluxOneOffload\DTOs\ProviderConfig( (string) ( $plugin_config['cloud_provider'] ?? '' ), (array) $plugin_config['provider_config'] ) )->describe() as $row_label => $row_value ) : ?>
						<tr>
							<td><?php echo esc_html( $row_label ); ?></td>
							<td><strong><?php echo esc_html( $row_value ); ?></strong></td>
						</tr>
						<?php endforeach; ?>
					</table>
				</div>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>
