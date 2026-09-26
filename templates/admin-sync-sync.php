<?php
/**
 * Admin: Sync & Offloading › Sync. The initial sync: start, continue, complete, retry, reset.
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

	<!-- =========================================== -->
	<!-- CARD 1: CURRENT STATUS -->
	<!-- =========================================== -->
	<div class="card diluxone-offload-status-card" style="max-width: 900px; margin-bottom: 20px;">
		<h3 style="margin-top: 0; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e0e0e0;">
			<?php esc_html_e( 'Current Status', 'diluxone-offload' ); ?>
		</h3>

		<?php if ( $current_state === 'configured' ) : ?>
			<!-- STATUS: CONFIGURED -->
			<?php if ( $has_files_in_db ) : ?>
				<!-- Sync started but not completed -->
				<div style="background: #fff3cd; border-left: 4px solid #f0b849; padding: 12px 15px; margin-bottom: 20px; display: flex; align-items: flex-start; gap: 12px;">
					<span class="dashicons dashicons-update" style="color: #f0b849; font-size: 24px; flex-shrink: 0; margin-top: 2px;"></span>
					<div>
						<strong style="color: #856404; font-size: 14px;">
							<?php esc_html_e( 'Sync Not Completed', 'diluxone-offload' ); ?>
						</strong>
						<br>
						<span style="color: #856404; font-size: 13px;">
							<?php esc_html_e( 'A previous synchronization was interrupted. You can continue from where it left off or start fresh.', 'diluxone-offload' ); ?>
						</span>
					</div>
				</div>
			<?php else : ?>
				<!-- Fresh configuration, no sync started -->
				<div style="background: #d1ecf1; border-left: 4px solid #0073aa; padding: 12px 15px; margin-bottom: 20px; display: flex; align-items: flex-start; gap: 12px;">
					<span class="dashicons dashicons-admin-settings" style="color: #0073aa; font-size: 24px; flex-shrink: 0; margin-top: 2px;"></span>
					<div>
						<strong style="color: #0c5460; font-size: 14px;">
							<?php esc_html_e( 'Cloud Provider Configured', 'diluxone-offload' ); ?>
						</strong>
						<br>
						<span style="color: #0c5460; font-size: 13px;">
							<?php esc_html_e( 'Your cloud provider is configured and ready. Click "Start Sync" below to upload your media files to the cloud.', 'diluxone-offload' ); ?>
						</span>
					</div>
				</div>
			<?php endif; ?>

		<?php elseif ( $current_state === 'synced' ) : ?>
			<?php if ( $failed_count > 0 ) : ?>
				<!-- STATUS: SYNCED WITH ERRORS (any files not synced) -->
				<div style="background: #fff3cd; border-left: 4px solid #f0b849; padding: 12px 15px; margin-bottom: 20px; display: flex; align-items: flex-start; gap: 12px;">
					<span class="dashicons dashicons-warning" style="color: #f0b849; font-size: 24px; flex-shrink: 0; margin-top: 2px;"></span>
					<div>
						<strong style="color: #856404; font-size: 14px;">
							<?php esc_html_e( 'Synced with Errors', 'diluxone-offload' ); ?>
						</strong>
						<br>
						<span style="color: #856404; font-size: 13px;">
							<?php
							printf(
								/* translators: %d: number of files that failed to upload */
								esc_html__( 'Synchronization completed but %d files could not be uploaded. You can retry the failed files or proceed with offloading.', 'diluxone-offload' ),
								(int) $failed_count
							);
							?>
						</span>
					</div>
				</div>
			<?php else : ?>
				<!-- STATUS: SYNCED COMPLETED -->
				<div style="background: #d4edda; border-left: 4px solid #46b450; padding: 12px 15px; margin-bottom: 20px; display: flex; align-items: flex-start; gap: 12px;">
					<span class="dashicons dashicons-yes-alt" style="color: #46b450; font-size: 24px; flex-shrink: 0; margin-top: 2px;"></span>
					<div>
						<strong style="color: #155724; font-size: 14px;">
							<?php esc_html_e( 'Synced Successfully', 'diluxone-offload' ); ?>
						</strong>
						<br>
						<span style="color: #155724; font-size: 13px;">
							<?php esc_html_e( 'All your media files have been uploaded to the cloud. You can now enable offloading to serve files directly from cloud storage.', 'diluxone-offload' ); ?>
						</span>
					</div>
				</div>
			<?php endif; ?>

		<?php elseif ( $current_state === 'offloading_active' ) : ?>
			<!-- STATUS: OFFLOADING ACTIVE -->
			<div style="background: #cce5ff; border-left: 4px solid #2196f3; padding: 12px 15px; margin-bottom: 20px; display: flex; align-items: flex-start; gap: 12px;">
				<span class="dashicons dashicons-cloud" style="color: #2196f3; font-size: 24px; flex-shrink: 0; margin-top: 2px;"></span>
				<div>
					<strong style="color: #004085; font-size: 14px;">
						<?php esc_html_e( 'Offloading Active', 'diluxone-offload' ); ?>
					</strong>
					<br>
					<span style="color: #004085; font-size: 13px;">
						<?php esc_html_e( 'Your media files are being served directly from cloud storage. Local uploads are automatically synced to the cloud.', 'diluxone-offload' ); ?>
					</span>
				</div>
			</div>

		<?php elseif ( $current_state === 'syncing' ) : ?>
			<!-- STATUS: SYNCING -->
			<div style="background: #e7f3ff; border-left: 4px solid #0073aa; padding: 12px 15px; margin-bottom: 20px; display: flex; align-items: flex-start; gap: 12px;">
				<span class="dashicons dashicons-update" style="color: #0073aa; font-size: 24px; flex-shrink: 0; margin-top: 2px;"></span>
				<div>
					<strong style="color: #004085; font-size: 14px;">
						<?php esc_html_e( 'Sync in Progress', 'diluxone-offload' ); ?>
					</strong>
					<br>
					<span style="color: #004085; font-size: 13px;">
						<?php esc_html_e( 'File upload is in progress. Do not close this page until synchronization is complete.', 'diluxone-offload' ); ?>
					</span>
				</div>
			</div>

		<?php endif; ?>
	</div>

	<!-- =========================================== -->
	<!-- CARD 2: ACTIONS -->
	<!-- =========================================== -->
	<div class="card diluxone-offload-actions-card" style="max-width: 900px;">
		<h3 style="margin-top: 0; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e0e0e0;">
			<?php esc_html_e( 'Actions', 'diluxone-offload' ); ?>
		</h3>

		<?php if ( $current_state === 'configured' ) : ?>
			<!-- ACTIONS: START SYNC / CONTINUE SYNC -->
			<?php
			// Continuation detection uses data prepared by the controller
			$is_continuation = ( $synced_count > 0 || $pending_count > 0 );
			?>

			<?php if ( $is_continuation ) : ?>
				<!-- ACTIONS: SYNC NOT COMPLETED (has files in DB) -->
				<div style="padding: 20px 0;">
					<?php if ( $pending_count > 0 ) : ?>
						<!-- Case 1: Incomplete sync (has pending files) -->
						<!-- Main action buttons side by side -->
						<div style="margin-bottom: 10px; display: flex; gap: 15px; align-items: flex-start;">
							<!-- Continue Sync button (primary action) -->
							<div style="flex: 1; background: #f0f6fc; border: 2px solid #0073aa; border-radius: 6px; padding: 15px;">
								<button id="start-sync-btn" class="button button-primary" style="width: 100%; height: 50px; font-size: 15px; background: #0073aa; border-color: #0073aa;">
									<span class="dashicons dashicons-cloud-upload"></span>
									<?php esc_html_e( 'Continue Sync', 'diluxone-offload' ); ?>
								</button>
								<p class="description" style="margin: 12px 0 0 0; font-size: 13px; line-height: 1.5; color: #555;">
									<?php
									printf(
										/* translators: 1: number of files already synced, 2: number of files pending */
										esc_html__( 'Resume synchronization. You have %1$d files already synced and %2$d files pending. The system will scan and detect any new or modified files.', 'diluxone-offload' ),
										(int) $synced_count,
										(int) $pending_count
									);
									?>
								</p>
							</div>

							<!-- Reset button (alternative action) -->
							<div style="flex: 1; background: #f9f9f9; border: 2px solid #ddd; border-radius: 6px; padding: 15px;">
								<button id="cancel-all-sync-btn" class="button" style="width: 100%; height: 50px; font-size: 15px; background: #dc3545; border-color: #dc3545; color: #fff;">
									<span class="dashicons dashicons-no-alt"></span>
									<?php esc_html_e( 'Reset Sync', 'diluxone-offload' ); ?>
								</button>
								<p class="description" style="margin: 12px 0 0 0; font-size: 13px; line-height: 1.5; color: #555;">
									<?php esc_html_e( 'Cancel the entire sync process and return to configured state. This will discard ALL progress including successfully uploaded files.', 'diluxone-offload' ); ?>
								</p>
							</div>
						</div>
					<?php else : ?>
						<!-- Case 2: Sync complete (pending=0, all synced) -->
						<div style="margin-bottom: 10px; display: flex; gap: 15px; align-items: flex-start;">
							<!-- Complete Sync button (primary action) -->
							<div style="flex: 1; background: #e7f5e7; border: 2px solid #46b450; border-radius: 6px; padding: 15px;">
								<button id="start-sync-btn" class="button button-primary" style="width: 100%; height: 50px; font-size: 15px; background: #46b450; border-color: #46b450;">
									<span class="dashicons dashicons-yes-alt"></span>
									<?php esc_html_e( 'Complete Sync', 'diluxone-offload' ); ?>
								</button>
								<p class="description" style="margin: 12px 0 0 0; font-size: 13px; line-height: 1.5; color: #555;">
									<?php
									printf(
										/* translators: %d: number of files already synced */
										esc_html__( 'All %d files are synced! Click to scan for any new/modified files and complete the sync process to enable offloading.', 'diluxone-offload' ),
										(int) $synced_count
									);
									?>
								</p>
							</div>

							<!-- Reset button (alternative action) -->
							<div style="flex: 1; background: #f9f9f9; border: 2px solid #ddd; border-radius: 6px; padding: 15px;">
								<button id="cancel-all-sync-btn" class="button" style="width: 100%; height: 50px; font-size: 15px; background: #dc3545; border-color: #dc3545; color: #fff;">
									<span class="dashicons dashicons-no-alt"></span>
									<?php esc_html_e( 'Reset Sync', 'diluxone-offload' ); ?>
								</button>
								<p class="description" style="margin: 12px 0 0 0; font-size: 13px; line-height: 1.5; color: #555;">
									<?php esc_html_e( 'Discard all sync progress and start from scratch.', 'diluxone-offload' ); ?>
								</p>
							</div>
						</div>
					<?php endif; ?>

				</div>

			<?php else : ?>
				<!-- ACTIONS: START SYNC (fresh configuration) -->
				<div style="padding: 20px 0;">
					<button id="start-sync-btn" class="button button-primary button-hero" style="margin-bottom: 15px;">
						<span class="dashicons dashicons-cloud-upload" style="margin-top: 5px;"></span>
						<?php esc_html_e( 'Start Sync', 'diluxone-offload' ); ?>
					</button>

					<p class="description" style="margin: 15px 0 0 0; font-size: 14px; line-height: 1.6;">
						<?php esc_html_e( 'This will scan your local media library and upload all files to cloud storage. Files already present in the cloud will be automatically skipped to save time and bandwidth.', 'diluxone-offload' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( defined( 'DILUXONE_OFFLOAD_DEV_MODE' ) && DILUXONE_OFFLOAD_DEV_MODE ) : ?>
			<!-- DEV MODE: Enable Without Sync -->
			<div style="margin-top: 20px; padding-top: 20px; border-top: 2px dashed #ff9800;">
				<div style="background: #fff3e0; border: 2px solid #ff9800; border-radius: 6px; padding: 15px;">
					<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
						<span class="dashicons dashicons-warning" style="color: #ff9800; font-size: 20px; width: 20px; height: 20px;"></span>
						<strong style="color: #e65100; font-size: 13px;"><?php esc_html_e( 'DEV MODE', 'diluxone-offload' ); ?></strong>
					</div>
					<button id="dev-enable-without-sync-btn" class="button" style="width: 100%; height: 45px; font-size: 14px; background: #ff9800; border-color: #e65100; color: #fff;">
						<span class="dashicons dashicons-controls-skipforward" style="margin-top: 3px;"></span>
						<?php esc_html_e( 'Enable Without Sync', 'diluxone-offload' ); ?>
					</button>
					<p class="description" style="margin: 10px 0 0 0; font-size: 12px; line-height: 1.5; color: #795548;">
						<?php esc_html_e( 'Skip file upload and jump directly to offloading mode. Assumes cloud already has all files. For development/testing only.', 'diluxone-offload' ); ?>
					</p>
				</div>
			</div>
			<?php endif; ?>


		<?php elseif ( $current_state === 'synced' && $failed_count > 0 ) : ?>
			<!-- ACTIONS: SYNCED WITH ERRORS -->
			<div style="padding: 20px 0;">
				<!-- Main action buttons side by side -->
				<div style="margin-bottom: 10px; display: flex; gap: 15px; align-items: flex-start;">
					<!-- Retry button (primary action) -->
					<div style="flex: 1; background: #f0f6fc; border: 2px solid #0073aa; border-radius: 6px; padding: 15px;">
						<button class="retry-failed-btn button button-primary" style="width: 100%; height: 50px; font-size: 15px; background: #0073aa; border-color: #0073aa;">
							<span class="dashicons dashicons-update"></span>
							<?php esc_html_e( 'Retry Failed Files', 'diluxone-offload' ); ?>
						</button>
						<p class="description" style="margin: 12px 0 0 0; font-size: 13px; line-height: 1.5; color: #555;">
							<?php
							printf(
								/* translators: %d: number of files that failed to upload */
								esc_html__( 'Attempt to upload the %d failed files again. Successfully uploaded files remain in cloud storage.', 'diluxone-offload' ),
								(int) $failed_count
							);
							?>
						</p>
					</div>

					<!-- Enable offloading button (alternative action) -->
					<div style="flex: 1; background: #f9f9f9; border: 2px solid #ddd; border-radius: 6px; padding: 15px;">
						<button id="discard-and-enable-static-btn" class="button" style="width: 100%; height: 50px; font-size: 15px; background: #46b450; border-color: #46b450; color: #fff;">
							<span class="dashicons dashicons-yes"></span>
							<?php esc_html_e( 'Clear Failed & Enable', 'diluxone-offload' ); ?>
						</button>
						<p class="description" style="margin: 12px 0 0 0; font-size: 13px; line-height: 1.5; color: #555;">
							<?php esc_html_e( 'Discard failed files list and enable offloading. Failed files will remain in local storage only.', 'diluxone-offload' ); ?>
						</p>
					</div>
				</div>

				<!-- View failed files link (small, below buttons) -->
				<div style="margin-bottom: 30px; margin-top: 15px;">
					<button class="view-failed-btn button button-link" style="text-decoration: none; padding: 0; height: auto; font-size: 13px; color: #0073aa;">
						<span class="dashicons dashicons-visibility" style="font-size: 13px; margin-top: 2px;"></span>
						<?php esc_html_e( 'View Failed Files', 'diluxone-offload' ); ?>
					</button>
				</div>

				<!-- Separator and Cancel Sync button (red, separated) -->
				<div style="padding-top: 25px; border-top: 2px solid #e0e0e0; margin-top: 10px;">
					<button id="cancel-all-sync-btn" class="button" style="background: #dc3545; border-color: #dc3545; color: #fff; padding: 8px 20px;">
						<span class="dashicons dashicons-no-alt"></span>
						<?php esc_html_e( 'Cancel Sync & Reset', 'diluxone-offload' ); ?>
					</button>
					<p class="description" style="margin: 10px 0 0 0; font-size: 13px; color: #666;">
						<?php esc_html_e( 'Cancel the entire sync process and return to configured state. This will discard ALL progress including successfully uploaded files.', 'diluxone-offload' ); ?>
					</p>
				</div>
			</div>

		<?php elseif ( $current_state === 'synced' && (int) $failed_count === 0 ) : ?>
			<!-- ACTIONS: SYNCED COMPLETED (NO ERRORS, NO PENDINGS) -->
			<div style="padding: 20px 0;">
				<div class="diluxone-offload-callout" style="margin-bottom: 20px;">
					<p style="margin: 0 0 10px 0;">
						<?php esc_html_e( 'Every file is in the cloud. Enable offloading to serve the library from there.', 'diluxone-offload' ); ?>
					</p>
					<a href="<?php echo esc_url( $screen_urls['offloading'] ?? '' ); ?>" class="button button-primary">
						<?php esc_html_e( 'Go to Offloading', 'diluxone-offload' ); ?>
					</a>
				</div>

				<!-- Secondary actions side by side -->
				<div style="display: flex; gap: 15px; margin-bottom: 20px;">
					<!-- Resync button -->
					<div style="flex: 1; background: #f0f6fc; border: 2px solid #ddd; border-radius: 6px; padding: 15px;">
						<button class="resync-all-btn button button-secondary" style="width: 100%; height: 45px; font-size: 14px;">
							<span class="dashicons dashicons-backup"></span>
							<?php esc_html_e( 'Resync All Files', 'diluxone-offload' ); ?>
						</button>
						<p class="description" style="margin: 12px 0 0 0; font-size: 12px; line-height: 1.5; color: #555;">
							<?php esc_html_e( 'Compare local files with cloud storage and resynchronize everything from scratch.', 'diluxone-offload' ); ?>
						</p>
					</div>

					<!-- Reset button -->
					<div style="flex: 1; background: #f9f9f9; border: 2px solid #ddd; border-radius: 6px; padding: 15px;">
						<button id="cancel-all-sync-btn" class="button" style="width: 100%; height: 45px; font-size: 14px; background: #dc3545; border-color: #dc3545; color: #fff;">
							<span class="dashicons dashicons-no-alt"></span>
							<?php esc_html_e( 'Reset Sync', 'diluxone-offload' ); ?>
						</button>
						<p class="description" style="margin: 12px 0 0 0; font-size: 12px; line-height: 1.5; color: #555;">
							<?php esc_html_e( 'Cancel sync and return to configured state. This will discard all progress.', 'diluxone-offload' ); ?>
						</p>
					</div>
				</div>
			</div>

		<?php elseif ( $current_state === 'syncing' ) : ?>
			<!-- ACTIONS: SYNCING -->
			<div style="background: #e7f3ff; border-left: 4px solid #0073aa; padding: 12px 15px; display: flex; align-items: center; gap: 12px;">
				<span class="dashicons dashicons-info" style="color: #0073aa; font-size: 24px; flex-shrink: 0;"></span>
				<div>
					<span style="color: #004085; font-size: 13px;">
						<?php esc_html_e( 'Sync controls are available in the modal dialog above.', 'diluxone-offload' ); ?>
					</span>
				</div>
			</div>

		<?php elseif ( $current_state === 'offloading_active' ) : ?>
			<!-- ACTIONS: OFFLOADING ACTIVE — the sync is done; local copies and the disconnect have their own tabs -->
			<div style="padding: 20px 0;">
				<div class="diluxone-offload-callout">
					<p style="margin: 0 0 10px 0;">
						<?php esc_html_e( 'The library is in the cloud and new uploads go straight there. Local copies are managed in Offloading; bringing the files back is Disconnect.', 'diluxone-offload' ); ?>
					</p>
					<a href="<?php echo esc_url( $screen_urls['offloading'] ?? '' ); ?>" class="button"><?php esc_html_e( 'Offloading', 'diluxone-offload' ); ?></a>
					<a href="<?php echo esc_url( $screen_urls['disconnect'] ?? '' ); ?>" class="button"><?php esc_html_e( 'Disconnect', 'diluxone-offload' ); ?></a>
				</div>

				<?php if ( $failed_count > 0 ) : ?>
				<!-- Failed Files Section (in offloading_active state) -->
				<div style="margin-top: 25px; padding-top: 20px; border-top: 1px solid #e0e0e0;">
					<div style="background: #fff3cd; border-left: 4px solid #f0b849; padding: 20px; border-radius: 6px;">
						<p style="margin: 0 0 15px 0; font-weight: 600; color: #856404; font-size: 15px;">
							<span class="dashicons dashicons-warning" style="font-size: 20px; vertical-align: middle; margin-right: 5px;"></span>
							<?php
							/* translators: %d: number of files that failed to sync */
							printf( esc_html__( '%d files failed to sync', 'diluxone-offload' ), (int) $failed_count );
							?>
						</p>
						<div style="display: flex; gap: 10px; flex-wrap: wrap;">
							<button class="retry-failed-btn button button-primary">
								<span class="dashicons dashicons-update"></span>
								<?php esc_html_e( 'Retry Failed Files', 'diluxone-offload' ); ?>
							</button>
							<button class="view-failed-btn button button-secondary">
								<span class="dashicons dashicons-visibility"></span>
								<?php esc_html_e( 'View Failed Files', 'diluxone-offload' ); ?>
							</button>
							<button class="clear-failed-btn button button-secondary">
								<span class="dashicons dashicons-dismiss"></span>
								<?php esc_html_e( 'Clear List', 'diluxone-offload' ); ?>
							</button>
						</div>
					</div>
				</div>
				<?php endif; ?>
			</div>

		<?php endif; ?>
	</div>

	<!-- =========================================== -->
	<!-- MODALS -->
	<!-- =========================================== -->

	<!-- Failed Files Modal -->
	<div id="failed-files-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 100000;">
		<div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #fff; padding: 30px; border-radius: 8px; max-width: 800px; max-height: 80vh; overflow-y: auto; width: 90%;">
			<h2 style="margin-top: 0;"><?php esc_html_e( 'Failed Files', 'diluxone-offload' ); ?> (<?php echo (int) $failed_count; ?>)</h2>
			<div style="max-height: 400px; overflow-y: auto; margin: 20px 0;">
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width: 50%;"><?php esc_html_e( 'File', 'diluxone-offload' ); ?></th>
							<th style="width: 10%;"><?php esc_html_e( 'Attempts', 'diluxone-offload' ); ?></th>
							<th style="width: 40%;"><?php esc_html_e( 'Error', 'diluxone-offload' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $failed_files as $failed ) : ?>
							<tr>
								<td><code style="font-size: 11px;"><?php echo esc_html( basename( $failed['file'] ?? '' ) ); ?></code></td>
								<td><?php echo esc_html( $failed['errors'] ?? '0' ); ?></td>
								<td style="font-size: 11px;"><?php echo esc_html( $failed['error_message'] ?? 'Unknown error' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<button id="close-failed-modal" class="button button-primary"><?php esc_html_e( 'Close', 'diluxone-offload' ); ?></button>
		</div>
	</div>

	<!-- Clear Failed & Enable Offloading Confirmation Modal -->
	<div id="clear-and-enable-modal" class="diluxone-offload-modal" style="display: none;">
		<div class="diluxone-offload-modal-overlay"></div>
		<div class="diluxone-offload-modal-content" style="max-width: 550px;">
			<!-- Initial confirmation view -->
			<div id="clear-enable-confirm-view">
				<h3 style="margin-top: 0; color: #46b450; border-bottom: 2px solid #46b450; padding-bottom: 10px;">
					<span class="dashicons dashicons-yes" style="font-size: 24px;"></span>
					<?php esc_html_e( 'Clear Failed Files & Enable Offloading', 'diluxone-offload' ); ?>
				</h3>

				<div style="background: #fff3cd; border-left: 4px solid #f0b849; padding: 12px; margin: 15px 0; border-radius: 4px;">
					<p style="margin: 0; color: #856404; font-size: 14px;">
						<strong>⚠️ <?php esc_html_e( 'Important:', 'diluxone-offload' ); ?></strong>
						<?php esc_html_e( 'This action will discard the list of failed files and enable cloud storage offloading.', 'diluxone-offload' ); ?>
					</p>
				</div>

				<div style="margin: 20px 0;">
					<p style="margin: 0 0 10px 0; font-size: 14px; line-height: 1.6;">
						<strong><?php esc_html_e( 'What will happen:', 'diluxone-offload' ); ?></strong>
					</p>
					<ul style="margin: 0 0 15px 20px; font-size: 14px; line-height: 1.8;">
						<li>
						<?php
							/* translators: %d: number of failed files to remove */
							printf( esc_html__( 'The %d failed files will be removed from the sync queue', 'diluxone-offload' ), (int) $failed_count );
						?>
						</li>
						<li><?php esc_html_e( 'Failed files will remain in local storage only (not in cloud)', 'diluxone-offload' ); ?></li>
						<li><?php esc_html_e( 'Successfully uploaded files will continue to be served from cloud', 'diluxone-offload' ); ?></li>
						<li><?php esc_html_e( 'Offloading will be enabled for future uploads', 'diluxone-offload' ); ?></li>
					</ul>
				</div>

				<div style="margin-top: 25px; padding-top: 15px; border-top: 1px solid #ddd; text-align: right;">
					<button type="button" class="button button-secondary close-clear-enable-modal" style="margin-right: 10px;">
						<?php esc_html_e( 'Cancel', 'diluxone-offload' ); ?>
					</button>
					<button type="button" id="confirm-clear-and-enable" class="button button-primary" style="background: #46b450; border-color: #46b450;">
						<?php esc_html_e( 'Yes, Clear & Enable', 'diluxone-offload' ); ?>
					</button>
				</div>
			</div>

			<!-- Processing view -->
			<div id="clear-enable-processing-view" style="display: none; text-align: center; padding: 60px 20px;">
				<div class="spinner is-active" style="float: none; width: 40px; height: 40px; margin: 0 auto 20px;"></div>
				<h3 style="margin: 0 0 10px 0; font-size: 20px; font-weight: 600; color: #2271b1;">
					<?php esc_html_e( 'Processing...', 'diluxone-offload' ); ?>
				</h3>
				<p style="margin: 0; font-size: 15px; color: #666;">
					<?php esc_html_e( 'Clearing failed files and enabling offloading', 'diluxone-offload' ); ?>
				</p>
			</div>

			<!-- Success view -->
			<div id="clear-enable-success-view" style="display: none; text-align: center; padding: 60px 20px;">
				<div style="width: 80px; height: 80px; margin: 0 auto 20px; background: #46b450; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
					<span class="dashicons dashicons-yes" style="font-size: 50px; color: #fff; width: 50px; height: 50px;"></span>
				</div>
				<h3 style="margin: 0 0 10px 0; font-size: 20px; font-weight: 600; color: #46b450;">
					<?php esc_html_e( 'Successfully Enabled!', 'diluxone-offload' ); ?>
				</h3>
				<p style="margin: 0; font-size: 15px; color: #666;">
					<?php esc_html_e( 'Failed files cleared and offloading activated', 'diluxone-offload' ); ?>
				</p>
			</div>

			<!-- Error view -->
			<div id="clear-enable-error-view" style="display: none; text-align: center; padding: 60px 20px;">
				<div style="width: 80px; height: 80px; margin: 0 auto 20px; background: #dc3545; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
					<span class="dashicons dashicons-no" style="font-size: 50px; color: #fff; width: 50px; height: 50px;"></span>
				</div>
				<h3 style="margin: 0 0 10px 0; font-size: 20px; font-weight: 600; color: #dc3545;">
					<?php esc_html_e( 'Error', 'diluxone-offload' ); ?>
				</h3>
				<p id="clear-enable-error-message" style="margin: 0 0 20px 0; font-size: 15px; color: #666;"></p>
				<button type="button" class="button button-primary close-clear-enable-modal">
					<?php esc_html_e( 'Close', 'diluxone-offload' ); ?>
				</button>
			</div>
		</div>
	</div>

	<!-- Cancel Sync & Reset Confirmation Modal -->
	<div id="cancel-sync-modal" class="diluxone-offload-modal" style="display: none;">
		<div class="diluxone-offload-modal-overlay"></div>
		<div class="diluxone-offload-modal-content" style="max-width: 550px;">
			<h3 style="margin-top: 0; color: #dc3545; border-bottom: 2px solid #dc3545; padding-bottom: 10px;">
				<span class="dashicons dashicons-warning" style="font-size: 24px;"></span>
				<?php esc_html_e( 'Cancel Sync & Reset', 'diluxone-offload' ); ?>
			</h3>

			<div style="background: #f8d7da; border-left: 4px solid #dc3545; padding: 12px; margin: 15px 0; border-radius: 4px;">
				<p style="margin: 0; color: #721c24; font-size: 14px;">
					<strong>⚠️ <?php esc_html_e( 'Warning:', 'diluxone-offload' ); ?></strong>
					<?php esc_html_e( 'This action will completely reset the synchronization and cannot be undone.', 'diluxone-offload' ); ?>
				</p>
			</div>

			<div style="margin: 20px 0;">
				<p style="margin: 0 0 10px 0; font-size: 14px; line-height: 1.6;">
					<strong><?php esc_html_e( 'What will happen:', 'diluxone-offload' ); ?></strong>
				</p>
				<ul style="margin: 0 0 15px 20px; font-size: 14px; line-height: 1.8; color: #721c24;">
					<li><?php esc_html_e( 'ALL sync progress will be discarded (including successfully uploaded files)', 'diluxone-offload' ); ?></li>
					<li><?php esc_html_e( 'The plugin will return to CONFIGURED state', 'diluxone-offload' ); ?></li>
					<li><?php esc_html_e( 'Files uploaded to cloud will remain there but won\'t be tracked', 'diluxone-offload' ); ?></li>
					<li><?php esc_html_e( 'You will need to sync again from scratch if you want to use offloading', 'diluxone-offload' ); ?></li>
				</ul>
			</div>

			<div style="margin-top: 25px; padding-top: 15px; border-top: 1px solid #ddd; text-align: right;">
				<button type="button" class="button button-secondary close-cancel-sync-modal" style="margin-right: 10px;">
					<?php esc_html_e( 'No, Keep Progress', 'diluxone-offload' ); ?>
				</button>
				<button type="button" id="confirm-cancel-sync" class="button" style="background: #dc3545; border-color: #dc3545; color: #fff;">
					<?php esc_html_e( 'Yes, Reset Everything', 'diluxone-offload' ); ?>
				</button>
			</div>
		</div>
	</div>

	<?php require DILUXONE_OFFLOAD_DIR . 'templates/partials/sync-modal.php'; ?>

</div>
