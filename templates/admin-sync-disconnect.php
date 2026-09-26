<?php
/**
 * Admin: Sync & Offloading › Disconnect. Bring every file back from the cloud and turn offloading off.
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

// All data is prepared by Admin::render_screen_content() — no business logic in templates
$current_state   = $current_state ?? 'not_configured';
$sync_progress   = $sync_progress ?? array();
$stats           = $stats ?? array();
$failed_files    = $failed_files ?? array();
$failed_count    = (int) ( $failed_count ?? 0 );
$has_files_in_db = $has_files_in_db ?? false;
$synced_count    = (int) ( $synced_count ?? 0 );
$pending_count   = (int) ( $pending_count ?? 0 );
$screen_urls     = $screen_urls ?? array();
?>

<div class="diluxone-offload-sync-container">
	<!-- Global notification container -->
	<div id="diluxone-offload-notification" style="display: none; margin: 15px 0; padding: 12px 15px; border-radius: 4px; border-left: 4px solid;"></div>

	<div class="card diluxone-offload-status-card" style="max-width: 900px; margin-bottom: 20px;">
		<h3 style="margin-top: 0; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e0e0e0;">
			<?php esc_html_e( 'Disconnect from Cloud', 'diluxone-offload' ); ?>
		</h3>

		<?php if ( $current_state === 'offloading_active' ) : ?>
			<div style="padding: 0 0 10px;">
					<!-- Disconnect from Cloud (primary action) -->
					<div style="flex: 1; background: #fff3f3; border: 2px solid #dc3545; border-radius: 6px; padding: 15px;">
						<button id="disconnect-from-cloud-btn" class="button" style="width: 100%; height: 50px; font-size: 15px; background: #dc3545; border-color: #dc3545; color: #fff;">
							<span class="dashicons dashicons-download"></span>
							<?php esc_html_e( 'Disconnect from Cloud', 'diluxone-offload' ); ?>
						</button>
						<p class="description" style="margin: 12px 0 0 0; font-size: 13px; line-height: 1.5; color: #555;">
							<?php esc_html_e( 'Download all files from cloud storage back to local storage and disable offloading. This is a full reverse sync operation.', 'diluxone-offload' ); ?>
						</p>
					</div>
			</div>

				<?php if ( defined( 'DILUXONE_OFFLOAD_DEV_MODE' ) && DILUXONE_OFFLOAD_DEV_MODE ) : ?>
				<!-- DEV MODE: Disconnect Without Sync -->
				<div style="margin-top: 15px;">
					<div style="background: #fff3e0; border: 2px solid #ff9800; border-radius: 6px; padding: 15px;">
						<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
							<span class="dashicons dashicons-warning" style="color: #ff9800; font-size: 20px; width: 20px; height: 20px;"></span>
							<strong style="color: #e65100; font-size: 13px;"><?php esc_html_e( 'DEV MODE', 'diluxone-offload' ); ?></strong>
						</div>
						<button id="dev-disconnect-without-sync-btn" class="button" style="width: 100%; height: 45px; font-size: 14px; background: #ff9800; border-color: #e65100; color: #fff;">
							<span class="dashicons dashicons-controls-skipforward" style="margin-top: 3px;"></span>
							<?php esc_html_e( 'Disconnect Without Sync', 'diluxone-offload' ); ?>
						</button>
						<p class="description" style="margin: 10px 0 0 0; font-size: 12px; line-height: 1.5; color: #795548;">
							<?php esc_html_e( 'Skip file download and jump directly to configured state. Assumes local already has all files. For development/testing only.', 'diluxone-offload' ); ?>
						</p>
					</div>
				</div>
				<?php endif; ?>

		<?php else : ?>
			<div class="diluxone-offload-callout">
				<p style="margin: 0 0 10px 0;">
					<?php esc_html_e( 'Offloading is not active, so there is nothing to bring back: the files are already on this server. To forget the provider and its credentials, delete it in Cloud Provider › Credentials.', 'diluxone-offload' ); ?>
				</p>
				<a href="<?php echo esc_url( $screen_urls['credentials'] ?? '' ); ?>" class="button"><?php esc_html_e( 'Cloud Provider › Credentials', 'diluxone-offload' ); ?></a>
			</div>
		<?php endif; ?>
	</div>

	<!-- =========================================== -->
	<!-- MODALS -->
	<!-- =========================================== -->

	<!-- Disconnect from Cloud Confirmation Modal -->
	<div id="disconnect-modal" class="diluxone-offload-modal" style="display: none;">
		<div class="diluxone-offload-modal-overlay"></div>
		<div class="diluxone-offload-modal-content" style="max-width: 700px;">
			<!-- Initial confirmation view -->
			<div id="disconnect-confirm-view">
				<h3 style="margin-top: 0; color: #dc3545; border-bottom: 2px solid #dc3545; padding-bottom: 10px;">
					<span class="dashicons dashicons-download" style="font-size: 24px;"></span>
					<?php esc_html_e( 'Disconnect from Cloud Provider', 'diluxone-offload' ); ?>
				</h3>

				<div style="background: #f8d7da; border-left: 4px solid #dc3545; padding: 12px; margin: 15px 0; border-radius: 4px;">
					<p style="margin: 0; color: #721c24; font-size: 14px;">
						<strong>⚠️ <?php esc_html_e( 'Warning:', 'diluxone-offload' ); ?></strong>
						<?php esc_html_e( 'This action will download all files from cloud storage back to local storage and disable offloading.', 'diluxone-offload' ); ?>
					</p>
				</div>

				<div style="margin: 20px 0;">
					<p style="margin: 0 0 10px 0; font-size: 14px; line-height: 1.6;">
						<strong><?php esc_html_e( 'What will happen:', 'diluxone-offload' ); ?></strong>
					</p>
					<ul style="margin: 0 0 15px 20px; font-size: 14px; line-height: 1.8; color: #721c24;">
						<li><?php esc_html_e( 'All files will be downloaded from cloud to local storage (reverse sync)', 'diluxone-offload' ); ?></li>
						<li><?php esc_html_e( 'Offloading will be disabled automatically', 'diluxone-offload' ); ?></li>
						<li><?php esc_html_e( 'Files in cloud will remain untouched (no deletion)', 'diluxone-offload' ); ?></li>
						<li><?php esc_html_e( 'This process may take time depending on the number of files', 'diluxone-offload' ); ?></li>
					</ul>
				</div>

				<div style="margin-top: 25px; padding-top: 15px; border-top: 1px solid #ddd; text-align: right;">
					<button type="button" class="button button-secondary close-disconnect-modal" style="margin-right: 10px;">
						<?php esc_html_e( 'Cancel', 'diluxone-offload' ); ?>
					</button>
					<button type="button" id="confirm-disconnect" class="button" style="background: #dc3545; border-color: #dc3545; color: #fff;">
						<?php esc_html_e( 'Yes, Disconnect & Download', 'diluxone-offload' ); ?>
					</button>
				</div>
			</div>

			<!-- Scanning view -->
			<div id="disconnect-scanning-view" style="display: none; text-align: center; padding: 60px 20px;">
				<div class="spinner is-active" style="float: none; width: 40px; height: 40px; margin: 0 auto 20px;"></div>
				<h3 style="margin: 0 0 10px 0; font-size: 20px; font-weight: 600; color: #2271b1;">
					<?php esc_html_e( 'Scanning Cloud Storage...', 'diluxone-offload' ); ?>
				</h3>
				<p style="margin: 0; font-size: 15px; color: #666;">
					<?php esc_html_e( 'Finding all files in Azure. This may take a moment.', 'diluxone-offload' ); ?>
				</p>
			</div>

			<!-- Download options view -->
			<div id="disconnect-options-view" style="display: none;">
				<h3 style="margin-top: 0; color: #dc3545; border-bottom: 2px solid #dc3545; padding-bottom: 10px;">
					<span class="dashicons dashicons-download" style="font-size: 24px;"></span>
					<?php esc_html_e( 'Download Files from Cloud', 'diluxone-offload' ); ?>
				</h3>

				<div id="disconnect-stats" style="background: #f0f0f1; padding: 15px; border-radius: 4px; margin: 15px 0;">
					<!-- Stats will be populated via JS -->
				</div>

				<div style="margin-top: 25px; padding-top: 15px; border-top: 1px solid #ddd; text-align: right;">
					<button type="button" class="button button-secondary close-disconnect-modal" style="margin-right: 10px;">
						<?php esc_html_e( 'Cancel', 'diluxone-offload' ); ?>
					</button>
					<button type="button" id="start-disconnect" class="button button-primary" style="background: #dc3545; border-color: #dc3545;">
						<?php esc_html_e( 'Start Download', 'diluxone-offload' ); ?>
					</button>
				</div>
			</div>

			<!-- Progress view -->
			<div id="disconnect-progress-view" style="display: none; padding: 20px;">
				<h3 style="margin-top: 0; color: #2271b1; border-bottom: 2px solid #2271b1; padding-bottom: 10px;">
					<span class="dashicons dashicons-download" style="font-size: 24px;"></span>
					<?php esc_html_e( 'Downloading Files...', 'diluxone-offload' ); ?>
				</h3>

				<!-- Warning (same shape as the sync modal) -->
				<div style="background: linear-gradient(135deg, #d63638 0%, #c62d30 100%); color: #fff; padding: 20px; border-radius: 6px; margin-bottom: 20px; text-align: center; box-shadow: 0 2px 8px rgba(214, 54, 56, 0.3); border: 2px solid #d63638;">
					<div style="font-size: 28px; font-weight: 700; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 1px;">
						⚠️ <?php esc_html_e( 'DO NOT CLOSE THIS WINDOW', 'diluxone-offload' ); ?> ⚠️
					</div>
					<div style="font-size: 14px; font-weight: 500; opacity: 0.95;">
						<?php esc_html_e( 'Closing this window will cancel the download', 'diluxone-offload' ); ?>
					</div>
				</div>

				<p id="disconnect-progress-label" style="text-align: center; font-weight: 600; margin: 15px 0;"><?php esc_html_e( 'Downloading files from cloud...', 'diluxone-offload' ); ?></p>

				<div id="disconnect-progress-details" style="margin: 20px 0;">
					<!-- Progress bar (same shape as the sync modal) -->
					<div style="background: #f0f0f1; border-radius: 8px; overflow: hidden; margin: 15px 0;">
						<div id="disconnect-progress-bar" class="progress-fill" style="height: 30px; background: linear-gradient(90deg, #0073aa 0%, #005177 100%); width: 0%; transition: width 0.3s; display: flex; align-items: center; justify-content: center; color: white; font-weight: 600;">
							<span id="disconnect-progress-percent">0%</span>
						</div>
					</div>

					<div id="disconnect-progress-text" style="margin-top: 10px; text-align: center; font-size: 14px; color: #666;">
						0 / 0 files (0%)
					</div>
				</div>

				<!-- Statistics (same shape as the sync modal) -->
				<div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-top: 20px;">
					<div style="background: #f0f0f1; padding: 12px; border-radius: 4px; text-align: center;">
						<div style="font-size: 12px; color: #666; margin-bottom: 4px;"><?php esc_html_e( 'Downloaded', 'diluxone-offload' ); ?></div>
						<div id="disconnect-stats-downloaded" style="font-size: 18px; font-weight: 600;">0</div>
					</div>
					<div style="background: #d4edda; padding: 12px; border-radius: 4px; text-align: center;">
						<div style="font-size: 12px; color: #155724; margin-bottom: 4px;"><?php esc_html_e( 'Successful', 'diluxone-offload' ); ?></div>
						<div id="disconnect-stats-successful" style="font-size: 18px; font-weight: 600; color: #155724;">0</div>
					</div>
					<div style="background: #fff3cd; padding: 12px; border-radius: 4px; text-align: center;">
						<div style="font-size: 12px; color: #856404; margin-bottom: 4px;"><?php esc_html_e( 'Remaining', 'diluxone-offload' ); ?></div>
						<div id="disconnect-stats-remaining" style="font-size: 18px; font-weight: 600; color: #856404;">0</div>
					</div>
				</div>

				<!-- Cancel button (same shape as the sync modal) -->
				<div style="margin-top: 25px; padding-top: 15px; border-top: 1px solid #ddd; text-align: right;">
					<button type="button" id="cancel-disconnect" class="button button-secondary">
						<span class="dashicons dashicons-no-alt"></span>
						<?php esc_html_e( 'Cancel Download', 'diluxone-offload' ); ?>
					</button>
				</div>
			</div>

			<!-- Success view -->
			<div id="disconnect-success-view" style="display: none; text-align: center; padding: 60px 20px;">
				<div style="width: 80px; height: 80px; margin: 0 auto 20px; background: #46b450; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
					<span class="dashicons dashicons-yes" style="font-size: 50px; color: #fff; width: 50px; height: 50px;"></span>
				</div>
				<h3 style="margin: 0 0 10px 0; font-size: 20px; font-weight: 600; color: #46b450;">
					<?php esc_html_e( 'Disconnected Successfully!', 'diluxone-offload' ); ?>
				</h3>
				<p style="margin: 0; font-size: 15px; color: #666;">
					<?php esc_html_e( 'All files downloaded and offloading disabled', 'diluxone-offload' ); ?>
				</p>
			</div>

			<!-- Error view with Force Disconnect option -->
			<div id="disconnect-error-view" style="display: none; text-align: center; padding: 40px 20px;">
				<div style="width: 80px; height: 80px; margin: 0 auto 20px; background: #dc3545; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
					<span class="dashicons dashicons-no" style="font-size: 50px; color: #fff; width: 50px; height: 50px;"></span>
				</div>
				<h3 style="margin: 0 0 10px 0; font-size: 20px; font-weight: 600; color: #dc3545;">
					<?php esc_html_e( 'Error', 'diluxone-offload' ); ?>
				</h3>
				<p id="disconnect-error-message" style="margin: 0 0 15px 0; font-size: 15px; color: #666;"></p>

				<!-- Force disconnect warning + button -->
				<div style="background: #fff3cd; border: 1px solid #ffecb5; border-radius: 6px; padding: 15px; margin: 15px 0; text-align: left;">
					<p style="margin: 0 0 8px 0; font-weight: 600; color: #856404;">
						<?php esc_html_e( 'You can force disconnect without downloading files:', 'diluxone-offload' ); ?>
					</p>
					<p style="margin: 0; font-size: 13px; color: #856404;">
						<?php esc_html_e( 'Files stored in the cloud will NOT be downloaded back to your server. Only files already available locally will remain accessible. This action cannot be undone.', 'diluxone-offload' ); ?>
					</p>
				</div>

				<div style="display: flex; gap: 10px; justify-content: center; margin-top: 20px;">
					<button type="button" class="button button-secondary close-disconnect-modal">
						<?php esc_html_e( 'Close', 'diluxone-offload' ); ?>
					</button>
					<button type="button" id="force-disconnect-btn" class="button" style="background: #d63638; border-color: #d63638; color: #fff;">
						<?php esc_html_e( 'Force Disconnect Without Sync', 'diluxone-offload' ); ?>
					</button>
				</div>
			</div>
		</div>
	</div>

	<?php require DILUXONE_OFFLOAD_DIR . 'templates/partials/sync-modal.php'; ?>

</div>
