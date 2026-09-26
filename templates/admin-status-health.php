<?php
/**
 * Admin: Status › Health. The plugin state as four cards, and the connection health table.
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

use DiluxOneOffload\Admin;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\Enums\PluginState;

$config        = $config ?? array();
$health        = $health ?? array();
$tracking_rows = (int) ( $tracking_rows ?? 0 );
$screen_urls   = $screen_urls ?? array();

// Get current state
$current_state = ConfigManager::get_state();
$is_configured = ConfigManager::is_configured();
$is_offloading = ConfigManager::is_offloading_enabled();
$plugin_config = $config;

// Health context — when the cloud connection is unhealthy (decrypt failure,
// permission denied, etc.) the cards below switch to "paused" copy so the
// user does not see contradictory states (e.g. "Offloading: Active" while
// the underlying credentials are unreadable). The state machine itself is
// left untouched; we only change how it is *displayed*. The full diagnosis
// and CTA live in the red banner rendered above this template.
$is_paused   = ( $health['status'] ?? '' ) === 'unhealthy';
$pause_cause = (string) ( $health['error_code'] ?? '' );
$pause_label = $is_paused ? Admin::pause_reason_short( $pause_cause ) : '';

$health_status = (string) ( $health['status'] ?? 'unknown' );
$last_check    = (int) ( $health['last_check'] ?? 0 );
$last_success  = (int) ( $health['last_success'] ?? 0 );
$failures      = (int) ( $health['consecutive_failures'] ?? 0 );
$error_source  = (string) ( $health['error_source'] ?? '' );
$ago           = static function ( int $ts ): string {
	if ( $ts <= 0 ) {
		return __( 'never', 'diluxone-offload' );
	}
	/* translators: %s: a human time difference, e.g. "10 minutes" */
	return sprintf( __( '%s ago', 'diluxone-offload' ), human_time_diff( $ts, time() ) );
};
?>

<div class="diluxone-offload-status">
	<div class="status-section">
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
							<a href="<?php echo esc_url( $screen_urls['credentials'] ?? '' ); ?>" class="button button-primary button-small">
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

			<!-- Tracking table -->
			<div class="state-card">
				<div class="state-icon">
					<span class="dashicons dashicons-database"></span>
				</div>
				<div class="state-content">
					<h3><?php esc_html_e( 'Tracking table', 'diluxone-offload' ); ?></h3>
					<p class="state-value">
						<span class="status-indicator status-success"></span>
						<?php
						/* translators: %s: number of rows in the plugin's tracking table */
						echo esc_html( sprintf( _n( '%s file tracked', '%s files tracked', $tracking_rows, 'diluxone-offload' ), number_format_i18n( $tracking_rows ) ) );
						?>
					</p>
					<p class="state-details">
						<?php esc_html_e( 'One row per file the sync has seen: synced, pending or failed.', 'diluxone-offload' ); ?>
					</p>
				</div>
			</div>
		</div>

		<!-- Connection Health -->
		<div class="system-info-section" id="connection-health">
			<h3>
				<span class="dashicons dashicons-heart"></span>
				<?php esc_html_e( 'Connection Health', 'diluxone-offload' ); ?>
			</h3>
			<?php if ( ! $is_configured ) : ?>
				<p class="description"><?php esc_html_e( 'No provider is connected, so there is no connection to check.', 'diluxone-offload' ); ?></p>
			<?php else : ?>
			<div class="info-grid">
				<div class="info-card">
					<table class="info-table">
						<tr>
							<td><?php esc_html_e( 'Status', 'diluxone-offload' ); ?></td>
							<td>
								<?php if ( $health_status === 'healthy' ) : ?>
									<span class="diluxone-offload-pill diluxone-offload-pill--active"><?php esc_html_e( 'Healthy', 'diluxone-offload' ); ?></span>
								<?php elseif ( $health_status === 'unhealthy' ) : ?>
									<span class="diluxone-offload-pill diluxone-offload-pill--pending"><?php echo esc_html( sprintf( /* translators: %s: short reason */ __( 'Unhealthy (%s)', 'diluxone-offload' ), $pause_label ) ); ?></span>
								<?php else : ?>
									<span class="diluxone-offload-pill diluxone-offload-pill--off"><?php esc_html_e( 'Not checked yet', 'diluxone-offload' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Last check', 'diluxone-offload' ); ?></td>
							<td><strong><?php echo esc_html( $ago( $last_check ) ); ?></strong></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Last success', 'diluxone-offload' ); ?></td>
							<td><strong><?php echo esc_html( $ago( $last_success ) ); ?></strong></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Consecutive failures', 'diluxone-offload' ); ?></td>
							<td>
								<strong><?php echo esc_html( number_format_i18n( $failures ) ); ?></strong>
								<?php if ( $failures >= 3 ) : ?>
									<span class="description"><?php esc_html_e( '(uploads refused from 3)', 'diluxone-offload' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<?php if ( $error_source !== '' ) : ?>
						<tr>
							<td><?php esc_html_e( 'Error source', 'diluxone-offload' ); ?></td>
							<td><code><?php echo esc_html( $error_source ); ?></code></td>
						</tr>
						<?php endif; ?>
					</table>
				</div>
				<div class="info-card">
					<h4><?php esc_html_e( 'How it checks', 'diluxone-offload' ); ?></h4>
					<p class="description">
						<?php esc_html_e( 'Every upload and every listing of the container records a success or a failure; a check against the provider runs when a screen opens and the last one is older than five minutes. Three consecutive failures pause new uploads, which are refused rather than written elsewhere, until the next success.', 'diluxone-offload' ); ?>
					</p>
				</div>
			</div>
			<?php endif; ?>
		</div>
	</div>
</div>
