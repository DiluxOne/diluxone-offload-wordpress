<?php
/**
 * Admin: Overview tab template.
 *
 * Local variables in this template (e.g. $config, $is_configured, $cloud_stats)
 * are populated by Admin::render_tab_content() in the calling scope and are
 * intentionally unprefixed because the include() puts them in the same local
 * scope as this template — they are not globals. Suppress the prefix sniff
 * for the whole template:
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

// Get plugin state and config
$plugin_state = ConfigManager::get_state();
// Single source of truth — same definition used by the Status tab.
// `is_configured()` is FALSE when stored credentials cannot be decrypted
// (the field is cleared post-decrypt). The "looser" `!empty(cloud_provider)`
// check would diverge here and create the kind of cross-tab inconsistency
// we are trying to remove.
$is_configured = ConfigManager::is_configured();
$is_synced     = in_array( $plugin_state, array( PluginState::SYNCED, PluginState::OFFLOADING_ACTIVE ), true );
$is_offloading = $plugin_state === PluginState::OFFLOADING_ACTIVE;
$cloud_stats   = $cloud_stats ?? null;

// Health context (mirrors admin-status.php) — when the cloud connection
// is unhealthy, every card below shows a "paused" sub-state so the user
// doesn't see contradictory greens like "Configured / Active" while the
// banner above reports unreadable credentials. We do NOT mutate the
// underlying state machine here — only the *display* changes.
$health      = ConfigManager::get_connection_health();
$is_paused   = $health['status'] === 'unhealthy';
$pause_cause = (string) ( $health['error_code'] ?? '' );
$pause_label = $is_paused ? Admin::pause_reason_short( $pause_cause ) : '';
?>

<div class="diluxone-offload-overview">
	<!-- Welcome Header -->
	<div class="welcome-header">
		<h2><?php esc_html_e( 'Welcome to DiluxOne Offload', 'diluxone-offload' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Offload your WordPress media files to cloud storage and free up server space.', 'diluxone-offload' ); ?>
			<a href="<?php echo esc_url( 'https://diluxone.com/' ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'More Info', 'diluxone-offload' ); ?>
			</a>
		</p>
	</div>

	<!-- Status Cards Grid -->
	<div class="status-grid">
		<!-- Configuration Status -->
		<?php
		// Visual mode: success (green) only when configured AND not paused.
		// When paused, downgrade to "warning" so the green check doesn't
		// contradict the red banner above the page.
		//
		// NOTE: vocabulary is intentionally identical to the Status tab —
		// "Awaiting Re-entry" for decrypt failures, "Paused (X)" for other
		// paused states. Keep these strings in sync with admin-status.php.
		$config_card_mode   = ( ! $is_configured || $is_paused ) ? 'status-warning' : 'status-success';
		$config_card_icon   = ( ! $is_configured || $is_paused ) ? 'dashicons-warning' : 'dashicons-yes-alt';
		$is_decrypt_failure = $is_paused && $pause_cause === 'decrypt_failed';
		?>
		<div class="status-card <?php echo esc_attr( $config_card_mode ); ?>">
			<div class="status-icon">
				<span class="dashicons <?php echo esc_attr( $config_card_icon ); ?>"></span>
			</div>
			<div class="status-content">
				<h3><?php esc_html_e( 'Configuration', 'diluxone-offload' ); ?></h3>
				<?php if ( $is_configured && ! $is_paused ) : ?>
					<p class="status-label status-active"><?php esc_html_e( 'Configured', 'diluxone-offload' ); ?></p>
					<p class="status-details">
						<?php
						$provider_names   = array(
							'azure' => 'Azure Blob Storage',
						);
						$provider_display = $provider_names[ $config['cloud_provider'] ?? '' ] ?? ucfirst( $config['cloud_provider'] ?? '' );
						echo wp_kses(
							sprintf(
								/* translators: %s: cloud provider name */
								__( 'Provider: <strong>%s</strong>', 'diluxone-offload' ),
								esc_html( $provider_display )
							),
							array( 'strong' => array() )
						);
						?>
					</p>
					<?php $overview_account = (string) ( $config['provider_config']['storage_account'] ?? '' ); ?>
					<?php if ( $overview_account !== '' ) : ?>
						<p class="status-details">
							<?php
							echo wp_kses(
								/* translators: %s: storage account name */
								sprintf( __( 'Account: <strong>%s</strong>', 'diluxone-offload' ), esc_html( $overview_account ) ),
								array( 'strong' => array() )
							);
							?>
						</p>
					<?php endif; ?>
				<?php elseif ( $is_decrypt_failure ) : ?>
					<p class="status-label" style="color:#dba617;"><?php esc_html_e( 'Awaiting Re-entry', 'diluxone-offload' ); ?></p>
					<p class="status-details" style="color:#856404;">
						<?php esc_html_e( 'Stored credentials cannot be decrypted. See banner above.', 'diluxone-offload' ); ?>
					</p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=diluxone-offload&tab=cloud-provider' ) ); ?>" class="button button-primary button-small">
						<?php esc_html_e( 'Re-enter Credentials', 'diluxone-offload' ); ?>
					</a>
				<?php elseif ( $is_configured && $is_paused ) : ?>
					<p class="status-label" style="color:#dba617;">
						<?php
						printf(
							/* translators: %s: short reason for the pause */
							esc_html__( 'Paused (%s)', 'diluxone-offload' ),
							esc_html( $pause_label )
						);
						?>
					</p>
					<p class="status-details" style="color:#856404;">
						<?php esc_html_e( 'See banner above for details.', 'diluxone-offload' ); ?>
					</p>
				<?php else : ?>
					<p class="status-label status-inactive"><?php esc_html_e( 'Not Configured', 'diluxone-offload' ); ?></p>
					<p class="status-details">
						<?php esc_html_e( 'Connect a cloud provider to get started', 'diluxone-offload' ); ?>
					</p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=diluxone-offload&tab=cloud-provider' ) ); ?>" class="button button-primary button-small">
						<?php esc_html_e( 'Configure Now', 'diluxone-offload' ); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>

		<!-- Sync Status -->
		<?php
		$sync_card_mode = ( $is_synced && ! $is_paused ) ? 'status-success' : ( $is_synced && $is_paused ? 'status-warning' : 'status-neutral' );
		?>
		<div class="status-card <?php echo esc_attr( $sync_card_mode ); ?>">
			<div class="status-icon">
				<span class="dashicons <?php echo esc_attr( $is_synced ? 'dashicons-cloud-saved' : 'dashicons-cloud-upload' ); ?>"></span>
			</div>
			<div class="status-content">
				<h3><?php esc_html_e( 'Synchronization', 'diluxone-offload' ); ?></h3>
				<?php if ( $is_synced && ! $is_paused ) : ?>
					<p class="status-label status-active"><?php esc_html_e( 'Synced', 'diluxone-offload' ); ?></p>
					<p class="status-details">
						<?php esc_html_e( 'Your files are in the cloud', 'diluxone-offload' ); ?>
					</p>
				<?php elseif ( $is_synced && $is_paused ) : ?>
					<p class="status-label" style="color:#dba617;">
						<?php
						printf(
							/* translators: %s: short reason for the pause */
							esc_html__( 'Paused (%s)', 'diluxone-offload' ),
							esc_html( $pause_label )
						);
						?>
					</p>
					<p class="status-details">
						<?php esc_html_e( 'Files were synced previously, but the plugin cannot reach the cloud right now.', 'diluxone-offload' ); ?>
					</p>
				<?php else : ?>
					<p class="status-label status-inactive"><?php esc_html_e( 'Not Synced', 'diluxone-offload' ); ?></p>
					<p class="status-details">
						<?php
						if ( $is_configured ) {
							esc_html_e( 'Ready to sync your files', 'diluxone-offload' );
						} else {
							esc_html_e( 'Configure cloud storage first', 'diluxone-offload' );
						}
						?>
					</p>
					<?php if ( $is_configured && ! $is_paused ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=diluxone-offload&tab=sync' ) ); ?>" class="button button-primary button-small">
							<?php esc_html_e( 'Start Sync', 'diluxone-offload' ); ?>
						</a>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>

		<!-- Offloading Status -->
		<?php
		$off_card_mode = ( $is_offloading && ! $is_paused ) ? 'status-success' : ( $is_offloading && $is_paused ? 'status-warning' : 'status-neutral' );
		?>
		<div class="status-card <?php echo esc_attr( $off_card_mode ); ?>">
			<div class="status-icon">
				<span class="dashicons <?php echo esc_attr( $is_offloading ? 'dashicons-superhero' : 'dashicons-database' ); ?>"></span>
			</div>
			<div class="status-content">
				<h3><?php esc_html_e( 'Offloading', 'diluxone-offload' ); ?></h3>
				<?php if ( $is_offloading && ! $is_paused ) : ?>
					<p class="status-label status-active"><?php esc_html_e( 'Active', 'diluxone-offload' ); ?></p>
					<p class="status-details">
						<?php esc_html_e( 'Files served from cloud storage', 'diluxone-offload' ); ?>
					</p>
				<?php elseif ( $is_offloading && $is_paused ) : ?>
					<p class="status-label" style="color:#dba617;">
						<?php
						printf(
							/* translators: %s: short reason for the pause */
							esc_html__( 'Paused (%s)', 'diluxone-offload' ),
							esc_html( $pause_label )
						);
						?>
					</p>
					<p class="status-details">
						<?php esc_html_e( 'New uploads are refused until the connection recovers.', 'diluxone-offload' ); ?>
					</p>
				<?php else : ?>
					<p class="status-label status-inactive"><?php esc_html_e( 'Inactive', 'diluxone-offload' ); ?></p>
					<p class="status-details">
						<?php
						if ( $is_synced ) {
							esc_html_e( 'Files still served locally', 'diluxone-offload' );
						} else {
							esc_html_e( 'Sync files first to enable', 'diluxone-offload' );
						}
						?>
					</p>
				<?php endif; ?>
			</div>
		</div>

		<!-- Plugin State -->
		<div class="status-card status-info">
			<div class="status-icon">
				<span class="dashicons dashicons-info"></span>
			</div>
			<div class="status-content">
				<h3><?php esc_html_e( 'Plugin State', 'diluxone-offload' ); ?></h3>
				<p class="status-label">
					<?php
					$badge_class = 'state-gray';
					$badge_label = $plugin_state;
					switch ( $plugin_state ) {
						case PluginState::NOT_CONFIGURED:
							$badge_class = 'state-gray';
							$badge_label = __( 'Not Configured', 'diluxone-offload' );
							break;
						case PluginState::CONFIGURED:
							$badge_class = 'state-blue';
							$badge_label = __( 'Configured', 'diluxone-offload' );
							break;
						case PluginState::SYNCING:
							$badge_class = 'state-yellow';
							$badge_label = __( 'Syncing', 'diluxone-offload' );
							break;
						case PluginState::SYNCED:
							$badge_class = 'state-green';
							$badge_label = __( 'Synced', 'diluxone-offload' );
							break;
						case PluginState::OFFLOADING_ACTIVE:
							$badge_class = 'state-purple';
							$badge_label = __( 'Offloading Active', 'diluxone-offload' );
							break;
					}
					if ( $is_paused ) {
						$badge_class .= ' is-paused';
					}
					echo '<span class="state-badge ' . esc_attr( $badge_class ) . '">' . esc_html( $badge_label ) . '</span>';
					?>
				</p>
				<?php if ( $is_paused ) : ?>
					<p class="status-details" style="color:#856404;">
						<?php
						printf(
							/* translators: %s: short reason for the pause */
							esc_html__( 'Paused (%s) — see banner above.', 'diluxone-offload' ),
							esc_html( $pause_label )
						);
						?>
					</p>
				<?php else : ?>
					<p class="status-details">
						<?php esc_html_e( 'Current operational mode', 'diluxone-offload' ); ?>
					</p>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<!-- Storage Overview (only if configured) -->
	<?php if ( $is_configured ) : ?>
		<div class="storage-overview-section">
			<h3 style="display: flex; align-items: center; justify-content: space-between;">
				<?php esc_html_e( 'Storage Overview', 'diluxone-offload' ); ?>
				<button type="button" id="refresh-stats-btn" class="button button-small">
					<span class="dashicons dashicons-update" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span>
					<?php esc_html_e( 'Refresh', 'diluxone-offload' ); ?>
				</button>
			</h3>

			<?php
			/*
			 * #stats-loading is an overlay over #stats-content, not a replacement
			 * for it: blurring the real panel keeps the layout stable, so nothing
			 * jumps when the numbers land. Both the first paint on a cold cache
			 * and the Refresh button go through this same state.
			 */
			$is_loading = ( $cloud_stats === null );
			?>
			<div class="diluxone-offload-stats-wrap<?php echo esc_attr( $is_loading ? ' diluxone-offload-loading' : '' ); ?>" aria-busy="<?php echo esc_attr( $is_loading ? 'true' : 'false' ); ?>">
				<div id="stats-loading" class="diluxone-offload-loading-overlay" role="status" aria-live="polite" style="<?php echo esc_attr( $is_loading ? '' : 'display: none;' ); ?>">
					<span class="spinner is-active"></span>
					<p><?php esc_html_e( 'Loading storage statistics…', 'diluxone-offload' ); ?></p>
					<p class="diluxone-offload-loading-hint">
						<?php esc_html_e( 'Reading your cloud container. On large libraries this can take a few seconds.', 'diluxone-offload' ); ?>
					</p>
				</div>

			<div id="stats-content">
				<?php if ( $cloud_stats === null ) : ?>
					<?php
					/*
					 * Nothing cached yet, and fetching here would block the page
					 * for as long as the container listing takes. Render a
					 * placeholder layout instead and let admin-overview.js fill
					 * in the real one.
					 *
					 * Only the storage row: the file-type breakdown is not known
					 * until the stats arrive, so the script renders it.
					 */
					?>
					<div class="diluxone-offload-overview-bars">
						<div class="diluxone-offload-bar-section">
							<div class="diluxone-offload-bar-header">
								<span class="diluxone-offload-bar-title"><?php esc_html_e( 'Storage', 'diluxone-offload' ); ?></span>
								<span class="diluxone-offload-bar-value" id="stat-storage-detail">&mdash;</span>
							</div>
						</div>
					</div>

					<div class="diluxone-offload-files-section">
						<div class="diluxone-offload-files-grid">
							<div class="diluxone-offload-files-count">
								<span class="diluxone-offload-stat-label"><?php esc_html_e( 'Total Files', 'diluxone-offload' ); ?></span>
								<div id="stat-file-count" class="diluxone-offload-stat-value">&mdash;</div>
							</div>
						</div>
					</div>

					<p id="stat-last-updated" class="description" style="margin-top: 10px; text-align: right; font-size: 12px;"></p>
					<?php
				else :
					$cs_data       = $cloud_stats['data'];
					$used_bytes    = $cs_data['storageUsedBytes'] ?? 0;
					$files_by_type = $cs_data['filesByType'] ?? null;
					?>

					<div class="diluxone-offload-overview-bars">
						<div class="diluxone-offload-bar-section">
							<div class="diluxone-offload-bar-header">
								<span class="diluxone-offload-bar-title"><?php esc_html_e( 'Storage', 'diluxone-offload' ); ?></span>
								<span class="diluxone-offload-bar-value" id="stat-storage-detail"><?php echo esc_html( (string) size_format( $used_bytes ) ); ?></span>
							</div>
						</div>
					</div>

					<!-- Files section with pie chart -->
					<div class="diluxone-offload-files-section">
						<div class="diluxone-offload-files-grid">
							<!-- File count -->
							<div class="diluxone-offload-files-count">
								<span class="diluxone-offload-stat-label"><?php esc_html_e( 'Total Files', 'diluxone-offload' ); ?></span>
								<div id="stat-file-count" class="diluxone-offload-stat-value"><?php echo esc_html( number_format_i18n( $cs_data['fileCount'] ?? 0 ) ); ?></div>
							</div>

							<!-- Pie chart (only if filesByType exists from API) -->
							<?php
							if ( $files_by_type !== null ) :
								$total_typed = (int) ( $files_by_type['images'] ?? 0 ) + (int) ( $files_by_type['videos'] ?? 0 ) + (int) ( $files_by_type['audio'] ?? 0 ) + (int) ( $files_by_type['other'] ?? 0 );
								if ( $total_typed > 0 ) :
									$pct_images = round( ( $files_by_type['images'] ?? 0 ) / $total_typed * 100, 1 );
									$pct_videos = round( ( $files_by_type['videos'] ?? 0 ) / $total_typed * 100, 1 );
									$pct_audio  = round( ( $files_by_type['audio'] ?? 0 ) / $total_typed * 100, 1 );
									$pct_other  = round( 100 - $pct_images - $pct_videos - $pct_audio, 1 );
									$s1         = $pct_images;
									$s2         = $s1 + $pct_videos;
									$s3         = $s2 + $pct_audio;
									?>
							<div class="diluxone-offload-pie-container" id="stat-pie-section">
								<div class="diluxone-offload-pie" style="background: conic-gradient(#2271b1 0% <?php echo esc_attr( (string) $s1 ); ?>%, #d63638 <?php echo esc_attr( (string) $s1 ); ?>% <?php echo esc_attr( (string) $s2 ); ?>%, #dba617 <?php echo esc_attr( (string) $s2 ); ?>% <?php echo esc_attr( (string) $s3 ); ?>%, #8c8f94 <?php echo esc_attr( (string) $s3 ); ?>% 100%);"></div>
								<div class="diluxone-offload-pie-legend">
									<div class="diluxone-offload-legend-item"><span class="diluxone-offload-legend-dot" style="background: #2271b1;"></span>
									<?php
										/* translators: 1: number of files, 2: percentage */
										echo esc_html( sprintf( __( 'Images %1$s (%2$s%%)', 'diluxone-offload' ), number_format_i18n( $files_by_type['images'] ?? 0 ), $pct_images ) );
									?>
									</div>
									<div class="diluxone-offload-legend-item"><span class="diluxone-offload-legend-dot" style="background: #d63638;"></span>
									<?php
										/* translators: 1: number of files, 2: percentage */
										echo esc_html( sprintf( __( 'Videos %1$s (%2$s%%)', 'diluxone-offload' ), number_format_i18n( $files_by_type['videos'] ?? 0 ), $pct_videos ) );
									?>
									</div>
									<div class="diluxone-offload-legend-item"><span class="diluxone-offload-legend-dot" style="background: #dba617;"></span>
									<?php
										/* translators: 1: number of files, 2: percentage */
										echo esc_html( sprintf( __( 'Audio %1$s (%2$s%%)', 'diluxone-offload' ), number_format_i18n( $files_by_type['audio'] ?? 0 ), $pct_audio ) );
									?>
									</div>
									<div class="diluxone-offload-legend-item"><span class="diluxone-offload-legend-dot" style="background: #8c8f94;"></span>
									<?php
										/* translators: 1: number of files, 2: percentage */
										echo esc_html( sprintf( __( 'Other %1$s (%2$s%%)', 'diluxone-offload' ), number_format_i18n( $files_by_type['other'] ?? 0 ), $pct_other ) );
									?>
									</div>
								</div>
							</div>
									<?php
							endif;
endif;
							?>
						</div>
					</div>

					<!-- Last updated -->
					<?php
					$checked_at = $cs_data['storageCheckedAt'] ?? null;
					if ( $checked_at ) :
						$timestamp = strtotime( $checked_at );
						$diff      = time() - $timestamp;
						if ( $diff < 60 ) {
							$ago = __( 'just now', 'diluxone-offload' );
						} elseif ( $diff < 3600 ) {
							$minutes = (int) ( $diff / 60 );
							/* translators: %d: number of minutes */
							$ago = sprintf( _n( '%d minute ago', '%d minutes ago', $minutes, 'diluxone-offload' ), $minutes );
						} elseif ( $diff < 86400 ) {
							$hours = (int) ( $diff / 3600 );
							/* translators: %d: number of hours */
							$ago = sprintf( _n( '%d hour ago', '%d hours ago', $hours, 'diluxone-offload' ), $hours );
						} else {
							$ago = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
						}
						?>
					<p id="stat-last-updated" class="description" style="margin-top: 10px; text-align: right; font-size: 12px;">
						<?php
						/* translators: %s: relative time, e.g. "3 minutes ago" */
						echo esc_html( sprintf( __( 'Last updated: %s', 'diluxone-offload' ), $ago ) );
						?>
					</p>
					<?php endif; ?>
				<?php endif; ?>
			</div><!-- /#stats-content -->
			</div><!-- /.diluxone-offload-stats-wrap -->
		</div>
	<?php endif; ?>

	<!-- Quick Actions -->
	<?php if ( $is_configured ) : ?>
		<div class="quick-links-section">
			<h3><?php esc_html_e( 'Quick Actions', 'diluxone-offload' ); ?></h3>
			<div class="quick-links">
				<a href="<?php echo esc_url( 'https://diluxone.com/support' ); ?>" target="_blank" rel="noopener noreferrer" class="quick-link">
					<span class="dashicons dashicons-sos"></span>
					<?php esc_html_e( 'Get Help', 'diluxone-offload' ); ?>
				</a>
				<a href="<?php echo esc_url( 'https://diluxone.com/' ); ?>" target="_blank" rel="noopener noreferrer" class="quick-link">
					<span class="dashicons dashicons-info"></span>
					<?php esc_html_e( 'More Info', 'diluxone-offload' ); ?>
				</a>
			</div>
		</div>
	<?php endif; ?>

	<!-- Getting Started (if not configured) -->
	<?php if ( ! $is_configured ) : ?>
		<div class="getting-started-section">
			<h3><?php esc_html_e( 'Getting Started', 'diluxone-offload' ); ?></h3>
			<ol class="setup-steps">
				<li>
					<strong><?php esc_html_e( 'Configure Cloud Provider', 'diluxone-offload' ); ?></strong>
					<p><?php esc_html_e( 'Choose your cloud provider and enter your credentials', 'diluxone-offload' ); ?></p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=diluxone-offload&tab=cloud-provider' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Go to Cloud Provider', 'diluxone-offload' ); ?>
					</a>
				</li>
				<li>
					<strong><?php esc_html_e( 'Sync Your Files', 'diluxone-offload' ); ?></strong>
					<p><?php esc_html_e( 'Upload your existing media files to the cloud', 'diluxone-offload' ); ?></p>
				</li>
				<li>
					<strong><?php esc_html_e( 'Enable Offloading', 'diluxone-offload' ); ?></strong>
					<p><?php esc_html_e( 'Serve files directly from the cloud', 'diluxone-offload' ); ?></p>
				</li>
			</ol>
		</div>
	<?php endif; ?>
</div>
