<?php
/**
 * Admin: Status tab template.
 *
 * Local variables ($current_state, $is_configured, $plugin_config, etc.) are
 * populated by Admin::render_tab_content() in the calling scope. Suppress the
 * prefix sniff for this template:
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 *
 * @package DiluxOneOffload
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DiluxOneOffload\Admin;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\Enums\PluginState;

// Count the plugin's own option rows for the diagnostic panel below. The
// pattern is hardcoded to our own option-name prefix; cache layers don't apply
// since this is a one-shot admin diagnostic page.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Diagnostic-only read; $wpdb->options is the WP-managed table name.
$diluxone_offload_option_count = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'diluxone_offload_' ) . '%'
	)
);

// Get current state
$current_state = ConfigManager::get_state();
$is_configured = ConfigManager::is_configured();
$is_offloading = ConfigManager::is_offloading_enabled();

// Get plugin config for basic info
$plugin_config = ConfigManager::get_config();

// Health context — when the cloud connection is unhealthy (decrypt failure,
// permission denied, etc.) the cards below switch to "paused" copy so the
// user does not see contradictory states (e.g. "Offloading: Active" while
// the underlying credentials are unreadable). The state machine itself is
// left untouched; we only change how it is *displayed*. The full diagnosis
// and CTA live in the red banner rendered above this template.
$health      = ConfigManager::get_connection_health();
$is_paused   = $health['status'] === 'unhealthy';
$pause_cause = (string) ( $health['error_code'] ?? '' );
$pause_label = $is_paused ? Admin::pause_reason_short( $pause_cause ) : '';

?>

<div class="diluxone-offload-status">
	<div class="status-section">
		<!-- Header -->
		<div class="section-header-main">
			<h2><?php esc_html_e( 'System Status', 'diluxone-offload' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'View plugin status and system information.', 'diluxone-offload' ); ?>
			</p>
		</div>

		<!-- Plugin State Cards -->
		<div class="state-cards">
			<!-- Plugin State -->
			<div class="state-card">
				<div class="state-icon">
					<span class="dashicons dashicons-admin-plugins"></span>
				</div>
				<div class="state-content">
					<h3><?php esc_html_e( 'Plugin State', 'diluxone-offload' ); ?></h3>
					<p class="state-value">
						<?php
						$badge_class = 'state-gray';
						switch ( $current_state ) {
							case PluginState::CONFIGURED:
								$badge_class = 'state-blue';
								break;
							case PluginState::SYNCING:
								$badge_class = 'state-yellow';
								break;
							case PluginState::SYNCED:
								$badge_class = 'state-green';
								break;
							case PluginState::OFFLOADING_ACTIVE:
								$badge_class = 'state-purple';
								break;
						}
						if ( $is_paused ) {
							$badge_class .= ' is-paused';
						}
						echo '<span class="state-badge ' . esc_attr( $badge_class ) . '">' . esc_html( PluginState::get_state_name( $current_state ) ) . '</span>';
						?>
					</p>
					<?php if ( $is_paused ) : ?>
					<p class="state-pause-reason" style="margin-top:6px; color:#856404; font-size:12px;">
						<?php
						printf(
							/* translators: %s: short reason, e.g. "credentials unreadable" */

							esc_html__( 'Paused (%s) — see banner above.', 'diluxone-offload' ),
							esc_html( $pause_label )
						);
						?>
					</p>
					<?php endif; ?>
				</div>
			</div>

			<!-- Configuration Status -->
			<div class="state-card">
				<div class="state-icon">
					<span class="dashicons dashicons-admin-settings"></span>
				</div>
				<div class="state-content">
					<h3><?php esc_html_e( 'Configuration', 'diluxone-offload' ); ?></h3>
					<?php
					// Vocabulary intentionally identical to admin-overview.php.
					// Keep these strings in sync with the Overview tab.
					$is_decrypt_failure = $is_paused && $pause_cause === 'decrypt_failed';
					?>
					<p class="state-value">
						<?php if ( $is_configured && ! $is_paused ) : ?>
							<span class="status-indicator status-success"></span>
							<?php esc_html_e( 'Configured', 'diluxone-offload' ); ?>
						<?php elseif ( $is_decrypt_failure ) : ?>
							<span class="status-indicator" style="background:#dba617;"></span>
							<?php esc_html_e( 'Awaiting Re-entry', 'diluxone-offload' ); ?>
						<?php elseif ( $is_configured && $is_paused ) : ?>
							<span class="status-indicator" style="background:#dba617;"></span>
							<?php
							printf(
								/* translators: %s: short reason for the pause */
								esc_html__( 'Paused (%s)', 'diluxone-offload' ),
								esc_html( $pause_label )
							);
							?>
						<?php else : ?>
							<span class="status-indicator status-inactive"></span>
							<?php esc_html_e( 'Not Configured', 'diluxone-offload' ); ?>
						<?php endif; ?>
					</p>
					<?php if ( $is_configured && ! $is_paused && ! empty( $plugin_config['cloud_provider'] ) ) : ?>
						<p class="state-details">
							<?php
							echo wp_kses(
								/* translators: %s: storage provider name (Azure) wrapped in <strong> */
								sprintf( __( 'Provider: %s', 'diluxone-offload' ), '<strong>Azure</strong>' ),
								array( 'strong' => array() )
							);
							?>
						</p>
					<?php elseif ( $is_decrypt_failure ) : ?>
						<p class="state-details" style="color:#856404;">
							<?php esc_html_e( 'Stored credentials cannot be decrypted. See banner above.', 'diluxone-offload' ); ?>
						</p>
						<p class="state-details">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=diluxone-offload&tab=cloud-provider' ) ); ?>" class="button button-primary button-small">
								<?php esc_html_e( 'Re-enter Credentials', 'diluxone-offload' ); ?>
							</a>
						</p>
					<?php elseif ( $is_configured && $is_paused ) : ?>
						<p class="state-details" style="color:#856404;">
							<?php esc_html_e( 'See banner above for details.', 'diluxone-offload' ); ?>
						</p>
					<?php endif; ?>
				</div>
			</div>

			<!-- Offloading Status -->
			<div class="state-card">
				<div class="state-icon">
					<span class="dashicons dashicons-cloud"></span>
				</div>
				<div class="state-content">
					<h3><?php esc_html_e( 'Offloading', 'diluxone-offload' ); ?></h3>
					<p class="state-value">
						<?php if ( $is_offloading && ! $is_paused ) : ?>
							<span class="status-indicator status-success"></span>
							<?php esc_html_e( 'Active', 'diluxone-offload' ); ?>
						<?php elseif ( $is_offloading && $is_paused ) : ?>
							<span class="status-indicator" style="background:#dba617;"></span>
							<?php
							printf(
								/* translators: %s: short reason for the pause */
								esc_html__( 'Paused (%s)', 'diluxone-offload' ),
								esc_html( $pause_label )
							);
							?>
						<?php else : ?>
							<span class="status-indicator status-inactive"></span>
							<?php esc_html_e( 'Inactive', 'diluxone-offload' ); ?>
						<?php endif; ?>
					</p>
					<?php if ( $is_offloading && $is_paused ) : ?>
					<p class="state-details" style="margin-top:6px; color:#856404; font-size:12px;">
						<?php esc_html_e( 'New uploads are refused until the connection recovers.', 'diluxone-offload' ); ?>
					</p>
					<?php endif; ?>
				</div>
			</div>

			<!-- Database Status -->
			<div class="state-card">
				<div class="state-icon">
					<span class="dashicons dashicons-database"></span>
				</div>
				<div class="state-content">
					<h3><?php esc_html_e( 'Database', 'diluxone-offload' ); ?></h3>
					<p class="state-value">
						<span class="status-indicator status-success"></span>
						<?php
						/* translators: %d: number of stored options */
						echo esc_html( sprintf( __( '%d options stored', 'diluxone-offload' ), $diluxone_offload_option_count ) );
						?>
					</p>
				</div>
			</div>
		</div>

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
							<td><code><?php echo esc_html( wp_upload_dir()['basedir'] ); ?></code></td>
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
							<td><strong>Azure Blob Storage</strong></td>
						</tr>
						<?php if ( ! empty( $plugin_config['provider_config']['storage_account'] ) ) : ?>
						<tr>
							<td><?php esc_html_e( 'Storage Account', 'diluxone-offload' ); ?></td>
							<td><strong><?php echo esc_html( $plugin_config['provider_config']['storage_account'] ); ?></strong></td>
						</tr>
						<?php endif; ?>
						<?php if ( ! empty( $plugin_config['provider_config']['container_name'] ) ) : ?>
						<tr>
							<td><?php esc_html_e( 'Container', 'diluxone-offload' ); ?></td>
							<td><strong><?php echo esc_html( $plugin_config['provider_config']['container_name'] ); ?></strong></td>
						</tr>
						<?php endif; ?>
					</table>
				</div>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>
