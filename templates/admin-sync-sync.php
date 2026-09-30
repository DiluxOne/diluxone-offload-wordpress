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

use DiluxOneOffload\Admin;

// All data is prepared by Admin::render_screen_content() — no business logic in templates
$current_state   = $current_state ?? 'not_configured';
$sync_progress   = $sync_progress ?? array();
$stats           = $stats ?? array();
$failed_files    = $failed_files ?? array();
$failed_count    = (int) ( $failed_count ?? 0 );
$has_files_in_db = $has_files_in_db ?? false;
$synced_count    = (int) ( $synced_count ?? 0 );
$pending_count   = (int) ( $pending_count ?? 0 );
$counts          = $counts ?? array();
$last_upload     = $last_upload ?? null;
$skipped         = $skipped ?? null;
$screen_urls     = $screen_urls ?? array();
$cloud_only      = (int) ( $counts['cloud_only'] ?? 0 );
$local_copies    = (int) ( $counts['local'] ?? 0 );
$failed_shown    = count( $failed_files );
?>

<?php
// The figures every state of this screen can show, from the tracking table.
$diluxone_offload_bignums = static function ( array $items ): void {
	echo '<div class="diluxone-offload-bignums">';
	foreach ( $items as $item ) {
		echo '<div class="diluxone-offload-bignum">';
		echo '<div class="diluxone-offload-bignum__k">' . esc_html( $item[0] ) . '</div>';
		echo '<div class="diluxone-offload-bignum__v">' . esc_html( $item[1] ) . '</div>';
		if ( $item[2] !== '' ) {
			echo '<div class="diluxone-offload-bignum__d">' . esc_html( $item[2] ) . '</div>';
		}
		echo '</div>';
	}
	echo '</div>';
};
$diluxone_offload_ago     = static function ( int $ts ): string {
	/* translators: %s: a human time difference, e.g. "10 minutes" */
	return $ts > 0 ? sprintf( __( '%s ago', 'diluxone-offload' ), human_time_diff( $ts, time() ) ) : __( 'never', 'diluxone-offload' );
};
?>

<div class="diluxone-offload-sync-container">
	<!-- Global notification container -->
	<div id="diluxone-offload-notification" class="notice inline" hidden></div>

	<!-- =========================================== -->
	<!-- CARD 1: CURRENT STATUS -->
	<!-- =========================================== -->
	<div class="card diluxone-offload-status-card">
		<h3><?php esc_html_e( 'Current Status', 'diluxone-offload' ); ?></h3>

		<?php if ( $current_state === 'configured' ) : ?>
			<!-- STATUS: CONFIGURED -->
			<?php if ( $has_files_in_db ) : ?>
				<!-- Sync started but not completed -->
				<div class="notice notice-warning inline">
				<p>
					<strong><?php esc_html_e( 'Sync Not Completed', 'diluxone-offload' ); ?></strong>
					<?php esc_html_e( 'A previous synchronization was interrupted. You can continue from where it left off or start fresh.', 'diluxone-offload' ); ?>
				</p>
			</div>
			<?php else : ?>
				<!-- Fresh configuration, no sync started -->
				<div class="notice notice-info inline">
				<p>
					<strong><?php esc_html_e( 'Cloud Provider Configured', 'diluxone-offload' ); ?></strong>
					<?php esc_html_e( 'Your cloud provider is configured and ready. Click "Start Sync" below to upload your media files to the cloud.', 'diluxone-offload' ); ?>
				</p>
			</div>
			<?php endif; ?>

		<?php elseif ( $current_state === 'synced' ) : ?>
			<?php if ( $failed_count > 0 ) : ?>
				<!-- STATUS: SYNCED WITH ERRORS (any files not synced) -->
				<div class="notice notice-warning inline">
				<p>
					<strong><?php esc_html_e( 'Synced with Errors', 'diluxone-offload' ); ?></strong>
					<?php
							printf(
								/* translators: %d: number of files that failed to upload */
								esc_html__( 'Synchronization completed but %d files could not be uploaded. You can retry the failed files or proceed with offloading.', 'diluxone-offload' ),
								(int) $failed_count
							);
					?>
				</p>
			</div>
			<?php else : ?>
				<!-- STATUS: SYNCED COMPLETED -->
				<div class="notice notice-success inline">
				<p>
					<strong><?php esc_html_e( 'Synced Successfully', 'diluxone-offload' ); ?></strong>
					<?php esc_html_e( 'All your media files have been uploaded to the cloud. You can now enable offloading to serve files directly from cloud storage.', 'diluxone-offload' ); ?>
				</p>
			</div>
			<?php endif; ?>

		<?php elseif ( $current_state === 'offloading_active' ) : ?>
			<!-- STATUS: OFFLOADING ACTIVE -->
			<div class="notice notice-info inline">
				<p>
					<strong><?php esc_html_e( 'Offloading Active', 'diluxone-offload' ); ?></strong>
					<?php esc_html_e( 'Your media files are being served directly from cloud storage. Local uploads are automatically synced to the cloud.', 'diluxone-offload' ); ?>
				</p>
			</div>

		<?php elseif ( $current_state === 'syncing' ) : ?>
			<!-- STATUS: SYNCING -->
			<div class="notice notice-info inline">
				<p>
					<strong><?php esc_html_e( 'Sync in Progress', 'diluxone-offload' ); ?></strong>
					<?php esc_html_e( 'File upload is in progress. Do not close this page until synchronization is complete.', 'diluxone-offload' ); ?>
				</p>
			</div>

		<?php endif; ?>

		<?php if ( in_array( $current_state, array( 'synced', 'offloading_active' ), true ) || $synced_count > 0 ) : ?>
			<?php
			$diluxone_offload_bignums(
				array(
					array( __( 'Synced', 'diluxone-offload' ), number_format_i18n( $synced_count ), __( 'files in the cloud', 'diluxone-offload' ) ),
					array( __( 'Local copies left', 'diluxone-offload' ), number_format_i18n( $local_copies ), __( 'synced files still on this server', 'diluxone-offload' ) ),
					array( __( 'Not on this server', 'diluxone-offload' ), number_format_i18n( $cloud_only ), __( 'in the cloud only', 'diluxone-offload' ) ),
					array(
						__( 'Last upload', 'diluxone-offload' ),
						$last_upload ? $diluxone_offload_ago( $last_upload['time'] ) : __( 'none yet', 'diluxone-offload' ),
						$last_upload ? basename( $last_upload['path'] ) . ' · ' . size_format( $last_upload['size'] ) : __( 'through the site while offloading is on', 'diluxone-offload' ),
					),
				)
			);
			// The library as the sync sees it: in the cloud (the accent), still to
			// upload (empty), failed (red, so a stuck file shows before the end).
			$diluxone_offload_total = $synced_count + $pending_count;
			if ( $diluxone_offload_total > 0 ) :
				$diluxone_offload_failed  = min( (int) ( $counts['errored'] ?? 0 ), $pending_count );
				$diluxone_offload_waiting = $pending_count - $diluxone_offload_failed;
				$diluxone_offload_pct     = (int) floor( $synced_count / $diluxone_offload_total * 100 );
				?>
				<div class="diluxone-offload-bar-head">
					<span><?php esc_html_e( 'Library in the cloud', 'diluxone-offload' ); ?></span>
					<strong>
						<?php
						echo esc_html(
							0 === $pending_count
								? '100%'
								/* translators: 1: files in the cloud, 2: files in the library, 3: percentage */
								: sprintf( __( '%1$s of %2$s · %3$s%%', 'diluxone-offload' ), number_format_i18n( $synced_count ), number_format_i18n( $diluxone_offload_total ), $diluxone_offload_pct )
						);
						?>
					</strong>
				</div>
				<div class="diluxone-offload-meter" role="progressbar" aria-valuenow="<?php echo esc_attr( (string) $diluxone_offload_pct ); ?>" aria-valuemin="0" aria-valuemax="100">
					<span class="diluxone-offload-meter__part--done" style="width: <?php echo esc_attr( (string) round( $synced_count / $diluxone_offload_total * 100, 2 ) ); ?>%"></span>
					<?php if ( $diluxone_offload_failed > 0 ) : ?>
						<span class="diluxone-offload-meter__part--failed" style="width: <?php echo esc_attr( (string) max( 0.5, round( $diluxone_offload_failed / $diluxone_offload_total * 100, 2 ) ) ); ?>%"></span>
					<?php endif; ?>
				</div>
				<?php if ( $pending_count > 0 ) : ?>
					<ul class="diluxone-offload-legend">
						<li><i class="diluxone-offload-meter__part--done"></i>
							<?php
							/* translators: %s: number of files */
							echo esc_html( sprintf( __( 'In the cloud · %s', 'diluxone-offload' ), number_format_i18n( $synced_count ) ) );
							?>
						</li>
						<li><i></i>
							<?php
							/* translators: %s: number of files */
							echo esc_html( sprintf( __( 'Still to upload · %s', 'diluxone-offload' ), number_format_i18n( $diluxone_offload_waiting ) ) );
							?>
						</li>
						<?php if ( $diluxone_offload_failed > 0 ) : ?>
							<li><i class="diluxone-offload-meter__part--failed"></i>
								<?php
								/* translators: %s: number of files */
								echo esc_html( sprintf( __( 'Failed · %s', 'diluxone-offload' ), number_format_i18n( $diluxone_offload_failed ) ) );
								?>
							</li>
						<?php endif; ?>
					</ul>
				<?php endif; ?>
				<p class="description">
					<?php
					if ( 0 === $pending_count ) {
						esc_html_e( 'Nothing pending.', 'diluxone-offload' );
					}
					if ( $skipped && $skipped['total'] > 0 ) {
						echo ' ';
						printf(
							/* translators: 1: number of files, 2: how long ago the scan ran */
							esc_html( _n( '%1$s file was left out by the last scan (%2$s).', '%1$s files were left out by the last scan (%2$s).', $skipped['total'], 'diluxone-offload' ) ),
							esc_html( number_format_i18n( $skipped['total'] ) ),
							esc_html( $diluxone_offload_ago( $skipped['time'] ) )
						);
					}
					?>
				</p>
			<?php endif; ?>

			<?php if ( $skipped && $skipped['total'] > 0 ) : ?>
				<details class="diluxone-offload-skipped">
					<summary><?php esc_html_e( 'Skipped by the last scan', 'diluxone-offload' ); ?></summary>
					<p class="description"><?php esc_html_e( 'Left out on purpose: empty files, cache folders, files over the size limit, paths too long for the tracking table. As of the last scan; a new sync scans again.', 'diluxone-offload' ); ?></p>
					<?php foreach ( $skipped['reasons'] as $reason => $entry ) : ?>
						<p><strong><?php echo esc_html( Admin::skip_reason_label( (string) $reason ) ); ?></strong> · <?php echo esc_html( number_format_i18n( $entry['count'] ) ); ?></p>
						<?php if ( $entry['paths'] !== array() ) : ?>
						<ul class="diluxone-offload-skipped__list">
							<?php foreach ( $entry['paths'] as $skipped_path ) : ?>
								<li><code><?php echo esc_html( $skipped_path ); ?></code></li>
							<?php endforeach; ?>
							<?php if ( $entry['count'] > count( $entry['paths'] ) ) : ?>
								<li class="description"><?php echo esc_html( sprintf( /* translators: %s: number of files not listed */ __( 'and %s more', 'diluxone-offload' ), number_format_i18n( $entry['count'] - count( $entry['paths'] ) ) ) ); ?></li>
							<?php endif; ?>
						</ul>
						<?php endif; ?>
					<?php endforeach; ?>
				</details>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<!-- =========================================== -->
	<!-- CARD 2: ACTIONS -->
	<!-- =========================================== -->
	<div class="card diluxone-offload-actions-card">
		<h3><?php esc_html_e( 'Actions', 'diluxone-offload' ); ?></h3>

		<?php if ( $current_state === 'configured' ) : ?>
			<!-- ACTIONS: START SYNC / CONTINUE SYNC -->
			<?php
			// Continuation detection uses data prepared by the controller
			$is_continuation = ( $synced_count > 0 || $pending_count > 0 );
			?>

			<?php if ( $is_continuation ) : ?>
				<!-- ACTIONS: SYNC NOT COMPLETED (has files in DB) -->
				<div>
					<?php if ( $pending_count > 0 ) : ?>
						<!-- Case 1: Incomplete sync (has pending files) -->
						<!-- Main action buttons side by side -->
						<div class="diluxone-offload-actions-row">
							<!-- Continue Sync button (primary action) -->
							<div class="diluxone-offload-action">
								<button id="start-sync-btn" class="button button-primary button-hero">
									<span class="dashicons dashicons-cloud-upload"></span>
									<?php esc_html_e( 'Continue Sync', 'diluxone-offload' ); ?>
								</button>
								<p class="description">
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
							<div class="diluxone-offload-action">
								<button id="cancel-all-sync-btn" class="button button-primary diluxone-offload-button-danger button-hero">
									<span class="dashicons dashicons-no-alt"></span>
									<?php esc_html_e( 'Reset Sync', 'diluxone-offload' ); ?>
								</button>
								<p class="description">
									<?php esc_html_e( 'Cancel the entire sync process and return to configured state. This will discard ALL progress including successfully uploaded files.', 'diluxone-offload' ); ?>
								</p>
							</div>
						</div>
					<?php else : ?>
						<!-- Case 2: Sync complete (pending=0, all synced) -->
						<div class="diluxone-offload-actions-row">
							<!-- Complete Sync button (primary action) -->
							<div class="diluxone-offload-action">
								<button id="start-sync-btn" class="button button-primary button-hero">
									<span class="dashicons dashicons-yes-alt"></span>
									<?php esc_html_e( 'Complete Sync', 'diluxone-offload' ); ?>
								</button>
								<p class="description">
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
							<div class="diluxone-offload-action">
								<button id="cancel-all-sync-btn" class="button button-primary diluxone-offload-button-danger button-hero">
									<span class="dashicons dashicons-no-alt"></span>
									<?php esc_html_e( 'Reset Sync', 'diluxone-offload' ); ?>
								</button>
								<p class="description">
									<?php esc_html_e( 'Discard all sync progress and start from scratch.', 'diluxone-offload' ); ?>
								</p>
							</div>
						</div>
					<?php endif; ?>

				</div>

			<?php else : ?>
				<!-- ACTIONS: START SYNC (fresh configuration) -->
				<?php
				// Before the first sync the script lists this site's prefix in the
				// container or bucket; when something is already there, this callout
				// says what and holds Start Sync until the owner picks a way on.
				?>
				<div id="diluxone-offload-target" class="diluxone-offload-callout diluxone-offload-callout--warn" hidden>
					<p id="diluxone-offload-target-found"></p>
					<p class="description"><?php esc_html_e( 'Continue and the first sync uploads this site\'s media next to them: a file with the same name is replaced by this site\'s, the rest stay, and a later Disconnect would bring them back too. Empty it and only this site\'s folder is deleted; nothing else in the container or bucket is touched.', 'diluxone-offload' ); ?></p>
					<p class="diluxone-offload-target-actions">
						<button type="button" id="diluxone-offload-target-continue" class="button"><?php esc_html_e( 'Continue with these files', 'diluxone-offload' ); ?></button>
						<button type="button" id="diluxone-offload-target-empty-open" class="button diluxone-offload-button-danger"><?php esc_html_e( 'Empty it first', 'diluxone-offload' ); ?></button>
						<a href="<?php echo esc_url( ( $screen_urls['credentials'] ?? '' ) . '#delete-provider' ); ?>" class="button button-link" id="diluxone-offload-target-change">
							<?php
							// Named as the provider names it: Azure keeps blobs in containers, the S3 family in buckets.
							if ( 'azure' === ( \DiluxOneOffload\ConfigManager::get_config()['cloud_provider'] ?? '' ) ) {
								esc_html_e( 'Change container', 'diluxone-offload' );
							} else {
								esc_html_e( 'Change bucket', 'diluxone-offload' );
							}
							?>
						</a>
					</p>
					<div id="diluxone-offload-target-empty" hidden>
						<p>
							<label for="diluxone-offload-target-confirm">
								<?php
								printf(
									/* translators: %s: the name of the container or bucket */
									esc_html__( 'Type %s to delete everything under this site\'s folder in it:', 'diluxone-offload' ),
									'<code>' . esc_html( \DiluxOneOffload\SyncManager::target_name() ) . '</code>'
								);
								?>
							</label>
							<input type="text" id="diluxone-offload-target-confirm" class="regular-text" autocomplete="off">
							<button type="button" id="diluxone-offload-target-empty-go" class="button diluxone-offload-button-danger" disabled><?php esc_html_e( 'Delete them', 'diluxone-offload' ); ?></button>
						</p>
					</div>
					<p id="diluxone-offload-target-status" class="description" aria-live="polite"></p>
				</div>

				<div>
					<button id="start-sync-btn" class="button button-primary button-hero">
						<span class="dashicons dashicons-cloud-upload"></span>
						<?php esc_html_e( 'Start Sync', 'diluxone-offload' ); ?>
					</button>

					<p class="description">
						<?php esc_html_e( 'This will scan your local media library and upload all files to cloud storage. Files already present in the cloud will be automatically skipped to save time and bandwidth.', 'diluxone-offload' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( defined( 'DILUXONE_OFFLOAD_DEV_MODE' ) && DILUXONE_OFFLOAD_DEV_MODE ) : ?>
			<!-- DEV MODE: Enable Without Sync -->
			<div class="notice notice-warning inline">
				<p><strong><?php esc_html_e( 'DEV MODE', 'diluxone-offload' ); ?></strong>
					<?php esc_html_e( 'Skip file upload and jump directly to offloading mode. Assumes cloud already has all files. For development/testing only.', 'diluxone-offload' ); ?></p>
				<p>
					<button id="dev-enable-without-sync-btn" class="button">
						<span class="dashicons dashicons-controls-skipforward"></span>
						<?php esc_html_e( 'Enable Without Sync', 'diluxone-offload' ); ?>
					</button>
				</p>
			</div>
			<?php endif; ?>


		<?php elseif ( $current_state === 'synced' && $failed_count > 0 ) : ?>
			<!-- ACTIONS: SYNCED WITH ERRORS -->
			<div>
				<!-- Main action buttons side by side -->
				<div class="diluxone-offload-actions-row">
					<!-- Retry button (primary action) -->
					<div class="diluxone-offload-action">
						<button class="retry-failed-btn button button-primary button-hero">
							<span class="dashicons dashicons-update"></span>
							<?php esc_html_e( 'Retry Failed Files', 'diluxone-offload' ); ?>
						</button>
						<p class="description">
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
					<div class="diluxone-offload-action">
						<button id="discard-and-enable-static-btn" class="button button-hero">
							<span class="dashicons dashicons-yes"></span>
							<?php esc_html_e( 'Clear Failed & Enable', 'diluxone-offload' ); ?>
						</button>
						<p class="description">
							<?php esc_html_e( 'Discard failed files list and enable offloading. Failed files will remain in local storage only.', 'diluxone-offload' ); ?>
						</p>
					</div>
				</div>

				<!-- View failed files link (small, below buttons) -->
				<p>
					<button class="view-failed-btn button button-link">
						<span class="dashicons dashicons-visibility"></span>
						<?php esc_html_e( 'View Failed Files', 'diluxone-offload' ); ?>
					</button>
				</p>

				<!-- Separated: cancel the whole sync -->
				<div class="diluxone-offload-danger-zone">
					<button id="cancel-all-sync-btn" class="button button-primary diluxone-offload-button-danger">
						<span class="dashicons dashicons-no-alt"></span>
						<?php esc_html_e( 'Cancel Sync & Reset', 'diluxone-offload' ); ?>
					</button>
					<p class="description">
						<?php esc_html_e( 'Cancel the entire sync process and return to configured state. This will discard ALL progress including successfully uploaded files.', 'diluxone-offload' ); ?>
					</p>
				</div>
			</div>

		<?php elseif ( $current_state === 'synced' && (int) $failed_count === 0 ) : ?>
			<!-- ACTIONS: SYNCED COMPLETED (NO ERRORS, NO PENDINGS) -->
			<div>
				<div class="diluxone-offload-callout">
					<p><?php esc_html_e( 'Every file is in the cloud. Enable offloading to serve the library from there.', 'diluxone-offload' ); ?></p>
					<p><a href="<?php echo esc_url( $screen_urls['offloading'] ?? '' ); ?>" class="button button-primary"><?php esc_html_e( 'Go to Offloading', 'diluxone-offload' ); ?></a></p>
				</div>

				<!-- Secondary actions side by side -->
				<div class="diluxone-offload-actions-row">
					<!-- Resync button -->
					<div class="diluxone-offload-action">
						<button class="resync-all-btn button button-secondary button-hero">
							<span class="dashicons dashicons-backup"></span>
							<?php esc_html_e( 'Resync All Files', 'diluxone-offload' ); ?>
						</button>
						<p class="description">
							<?php esc_html_e( 'Compare local files with cloud storage and resynchronize everything from scratch.', 'diluxone-offload' ); ?>
						</p>
					</div>

					<!-- Reset button -->
					<div class="diluxone-offload-action">
						<button id="cancel-all-sync-btn" class="button button-primary diluxone-offload-button-danger button-hero">
							<span class="dashicons dashicons-no-alt"></span>
							<?php esc_html_e( 'Reset Sync', 'diluxone-offload' ); ?>
						</button>
						<p class="description">
							<?php esc_html_e( 'Cancel sync and return to configured state. This will discard all progress.', 'diluxone-offload' ); ?>
						</p>
					</div>
				</div>
			</div>

		<?php elseif ( $current_state === 'syncing' ) : ?>
			<!-- ACTIONS: SYNCING -->
			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'Sync controls are available in the modal dialog above.', 'diluxone-offload' ); ?></p>
			</div>

		<?php elseif ( $current_state === 'offloading_active' ) : ?>
			<!-- ACTIONS: OFFLOADING ACTIVE — the sync is done; local copies and the disconnect have their own tabs -->
			<div>
				<div class="diluxone-offload-callout">
					<p><?php esc_html_e( 'The library is in the cloud and new uploads go straight there. Local copies are managed in Offloading; bringing the files back is Disconnect.', 'diluxone-offload' ); ?></p>
					<p>
						<a href="<?php echo esc_url( $screen_urls['offloading'] ?? '' ); ?>" class="button"><?php esc_html_e( 'Offloading', 'diluxone-offload' ); ?></a>
						<a href="<?php echo esc_url( $screen_urls['disconnect'] ?? '' ); ?>" class="button"><?php esc_html_e( 'Disconnect', 'diluxone-offload' ); ?></a>
					</p>
				</div>

				<?php if ( $failed_count > 0 ) : ?>
				<!-- Failed Files Section (in offloading_active state) -->
				<div class="notice notice-warning inline diluxone-offload-spaced">
					<p><strong>
						<?php
						/* translators: %d: number of files that failed to sync */
						printf( esc_html__( '%d files failed to sync', 'diluxone-offload' ), (int) $failed_count );
						?>
					</strong></p>
					<p class="diluxone-offload-buttons-left">
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
					</p>
				</div>
				<?php endif; ?>
			</div>

		<?php endif; ?>
	</div>

	<!-- =========================================== -->
	<!-- MODALS -->
	<!-- =========================================== -->

	<!-- Failed Files -->
	<div id="failed-files-modal" class="diluxone-offload-modal" hidden>
		<div class="diluxone-offload-modal__dialog diluxone-offload-modal__dialog--wide" role="dialog" aria-modal="true" aria-labelledby="failed-files-modal-title">
			<h2 id="failed-files-modal-title"><?php esc_html_e( 'Failed Files', 'diluxone-offload' ); ?> (<?php echo (int) $failed_count; ?>)</h2>
			<div class="diluxone-offload-scroll">
				<table class="wp-list-table widefat fixed striped diluxone-offload-failed-table">
					<thead>
						<tr>
							<th class="column-file"><?php esc_html_e( 'File', 'diluxone-offload' ); ?></th>
							<th class="column-attempts"><?php esc_html_e( 'Attempts', 'diluxone-offload' ); ?></th>
							<th class="column-error"><?php esc_html_e( 'Error', 'diluxone-offload' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $failed_files as $failed ) : ?>
							<tr>
								<td class="column-file"><code><?php echo esc_html( basename( $failed['file'] ?? '' ) ); ?></code></td>
								<td class="column-attempts"><?php echo esc_html( $failed['errors'] ?? '0' ); ?></td>
								<td class="column-error"><?php echo esc_html( $failed['error_message'] ?? __( 'Unknown error', 'diluxone-offload' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php if ( $failed_count > $failed_shown ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: 1: number of rows shown, 2: total number of failed files */
					esc_html__( 'The first %1$s of %2$s, the ones with most attempts first. Retry Failed Files takes all of them.', 'diluxone-offload' ),
					esc_html( number_format_i18n( $failed_shown ) ),
					esc_html( number_format_i18n( $failed_count ) )
				);
				?>
			</p>
			<?php endif; ?>
			<div class="diluxone-offload-modal__footer">
				<button id="close-failed-modal" class="button button-primary"><?php esc_html_e( 'Close', 'diluxone-offload' ); ?></button>
			</div>
		</div>
	</div>

	<!-- Clear Failed & Enable Offloading -->
	<div id="clear-and-enable-modal" class="diluxone-offload-modal" hidden>
		<div class="diluxone-offload-modal__dialog diluxone-offload-modal__dialog--narrow" role="dialog" aria-modal="true">
			<div id="clear-enable-confirm-view">
				<h2>
					<span class="dashicons dashicons-yes"></span>
					<?php esc_html_e( 'Clear Failed Files & Enable Offloading', 'diluxone-offload' ); ?>
				</h2>
				<div class="notice notice-warning inline">
					<p>
						<strong><?php esc_html_e( 'Important:', 'diluxone-offload' ); ?></strong>
						<?php esc_html_e( 'This action will discard the list of failed files and enable cloud storage offloading.', 'diluxone-offload' ); ?>
					</p>
				</div>
				<p><strong><?php esc_html_e( 'What will happen:', 'diluxone-offload' ); ?></strong></p>
				<ul class="ul-disc">
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
				<div class="diluxone-offload-modal__footer">
					<button type="button" class="button close-clear-enable-modal"><?php esc_html_e( 'Cancel', 'diluxone-offload' ); ?></button>
					<button type="button" id="confirm-clear-and-enable" class="button button-primary"><?php esc_html_e( 'Yes, Clear & Enable', 'diluxone-offload' ); ?></button>
				</div>
			</div>

			<div id="clear-enable-processing-view" class="diluxone-offload-waiting" hidden>
				<div class="spinner is-active"></div>
				<h3><?php esc_html_e( 'Processing...', 'diluxone-offload' ); ?></h3>
				<p><?php esc_html_e( 'Clearing failed files and enabling offloading', 'diluxone-offload' ); ?></p>
			</div>

			<div id="clear-enable-success-view" class="diluxone-offload-outcome diluxone-offload-outcome--ok" hidden>
				<span class="dashicons dashicons-yes-alt"></span>
				<h3><?php esc_html_e( 'Successfully Enabled!', 'diluxone-offload' ); ?></h3>
				<p><?php esc_html_e( 'Failed files cleared and offloading activated', 'diluxone-offload' ); ?></p>
			</div>

			<div id="clear-enable-error-view" class="diluxone-offload-outcome diluxone-offload-outcome--failed" hidden>
				<span class="dashicons dashicons-dismiss"></span>
				<h3><?php esc_html_e( 'Error', 'diluxone-offload' ); ?></h3>
				<p id="clear-enable-error-message"></p>
				<div class="diluxone-offload-buttons">
					<button type="button" class="button button-primary close-clear-enable-modal"><?php esc_html_e( 'Close', 'diluxone-offload' ); ?></button>
				</div>
			</div>
		</div>
	</div>

	<!-- Cancel Sync & Reset -->
	<div id="cancel-sync-modal" class="diluxone-offload-modal" hidden>
		<div class="diluxone-offload-modal__dialog diluxone-offload-modal__dialog--narrow" role="dialog" aria-modal="true">
			<h2>
				<span class="dashicons dashicons-warning"></span>
				<?php esc_html_e( 'Cancel Sync & Reset', 'diluxone-offload' ); ?>
			</h2>
			<div class="notice notice-error inline">
				<p>
					<strong><?php esc_html_e( 'Warning:', 'diluxone-offload' ); ?></strong>
					<?php esc_html_e( 'This action will completely reset the synchronization and cannot be undone.', 'diluxone-offload' ); ?>
				</p>
			</div>
			<p><strong><?php esc_html_e( 'What will happen:', 'diluxone-offload' ); ?></strong></p>
			<ul class="ul-disc">
				<li><?php esc_html_e( 'ALL sync progress will be discarded (including successfully uploaded files)', 'diluxone-offload' ); ?></li>
				<li><?php esc_html_e( 'The plugin will return to CONFIGURED state', 'diluxone-offload' ); ?></li>
				<li><?php esc_html_e( 'Files uploaded to cloud will remain there but won\'t be tracked', 'diluxone-offload' ); ?></li>
				<li><?php esc_html_e( 'You will need to sync again from scratch if you want to use offloading', 'diluxone-offload' ); ?></li>
			</ul>
			<div class="diluxone-offload-modal__footer">
				<button type="button" class="button close-cancel-sync-modal"><?php esc_html_e( 'No, Keep Progress', 'diluxone-offload' ); ?></button>
				<button type="button" id="confirm-cancel-sync" class="button button-primary diluxone-offload-button-danger"><?php esc_html_e( 'Yes, Reset Everything', 'diluxone-offload' ); ?></button>
			</div>
		</div>
	</div>

	<?php require DILUXONE_OFFLOAD_DIR . 'templates/partials/sync-modal.php'; ?>

</div>
