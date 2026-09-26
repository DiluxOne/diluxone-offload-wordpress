<?php
/**
 * Admin: Sync & Offloading › Offloading. Enable offloading once the library is synced; delete the local copies.
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
			<?php esc_html_e( 'Offloading', 'diluxone-offload' ); ?>
		</h3>

		<?php if ( $current_state === 'offloading_active' ) : ?>
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

			<div style="padding: 20px 0 0;">
				<?php if ( ! empty( $stats['deletable_files'] ) ) : ?>
					<!-- Delete Local Files (alternative action) -->
					<div style="flex: 1; background: #f0f6fc; border: 2px solid #0073aa; border-radius: 6px; padding: 15px;">
						<button id="delete-local-files-btn" class="button" style="width: 100%; height: 50px; font-size: 15px; background: #0073aa; border-color: #0073aa; color: #fff;">
							<span class="dashicons dashicons-trash"></span>
							<?php esc_html_e( 'Delete Local Files', 'diluxone-offload' ); ?>
						</button>
						<p class="description" style="margin: 12px 0 0 0; font-size: 13px; line-height: 1.5; color: #555;">
							<?php
							echo wp_kses(
								sprintf(
									/* translators: 1: human-readable disk size (e.g. "200 MB"), 2: number of files */
									__( 'Free up %1$s of disk space by deleting %2$s local files. Files will continue to be served from cloud storage.', 'diluxone-offload' ),
									'<strong>' . esc_html( (string) size_format( $stats['deletable_size'] ) ) . '</strong>',
									'<strong>' . esc_html( number_format_i18n( $stats['deletable_files'] ) ) . '</strong>'
								),
								array( 'strong' => array() )
							);
							?>
						</p>
					</div>
				<?php else : ?>
				<p class="description">
					<?php esc_html_e( 'No local copies left to delete: every synced file lives in the cloud only. New uploads go straight there.', 'diluxone-offload' ); ?>
				</p>
				<?php endif; ?>
			</div>

		<?php elseif ( $current_state === 'synced' && (int) $failed_count === 0 ) : ?>
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

			<div style="padding: 20px 0 0;">
				<div style="margin-bottom: 20px;">
					<div style="background: #e7f5e7; border: 3px solid #46b450; border-radius: 8px; padding: 20px;">
						<button id="enable-offloading-btn" class="button button-primary" data-confirm="true" style="width: 100%; height: 60px; font-size: 16px; background: #46b450; border-color: #46b450;">
							<span class="dashicons dashicons-cloud" style="font-size: 20px;"></span>
							<?php esc_html_e( 'Enable Cloud Storage (Offloading)', 'diluxone-offload' ); ?>
						</button>
						<p class="description" style="margin: 12px 0 0 0; font-size: 13px; line-height: 1.5; color: #155724;">
							<?php esc_html_e( 'Activate offloading to serve all media files directly from cloud storage. New uploads will go straight to the cloud, saving local disk space.', 'diluxone-offload' ); ?>
						</p>
					</div>
				</div>
			</div>

		<?php elseif ( $current_state === 'synced' ) : ?>
			<div class="diluxone-offload-callout diluxone-offload-callout--warn">
				<p style="margin: 0 0 10px 0;">
					<?php
					printf(
						/* translators: %d: number of files that failed to upload */
						esc_html__( 'Synchronization completed but %d files could not be uploaded. Retry them, or clear the list and enable offloading anyway, in the Sync tab.', 'diluxone-offload' ),
						(int) $failed_count
					);
					?>
				</p>
				<a href="<?php echo esc_url( $screen_urls['sync'] ?? '' ); ?>" class="button button-primary"><?php esc_html_e( 'Go to Sync', 'diluxone-offload' ); ?></a>
			</div>

		<?php else : ?>
			<div class="diluxone-offload-callout">
				<p style="margin: 0 0 10px 0;">
					<?php esc_html_e( 'Offloading can be enabled once every file is in the cloud. Run the sync first.', 'diluxone-offload' ); ?>
				</p>
				<a href="<?php echo esc_url( $screen_urls['sync'] ?? '' ); ?>" class="button button-primary"><?php esc_html_e( 'Go to Sync', 'diluxone-offload' ); ?></a>
			</div>
		<?php endif; ?>
	</div>

	<!-- =========================================== -->
	<!-- MODALS -->
	<!-- =========================================== -->

	<!-- ⭐ NEW: Dedicated Delete Local Files Modal -->
	<div id="delete-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 100000;">
		<div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #fff; padding: 30px; border-radius: 8px; max-width: 700px; width: 90%;">
			<h2 style="margin-top: 0; border-bottom: 1px solid #ddd; padding-bottom: 15px;">
				<span class="dashicons dashicons-trash" style="color: #d63638;"></span>
				<span><?php esc_html_e( 'Delete Local Files', 'diluxone-offload' ); ?></span>
			</h2>

			<!-- Loading State -->
			<div id="delete-modal-loading" style="margin: 20px 0; text-align: center; padding: 60px 30px;">
				<div class="spinner is-active" style="float: none; margin: 0 auto 15px;"></div>
				<p style="font-size: 15px;"><strong><?php esc_html_e( 'Calculating files to delete...', 'diluxone-offload' ); ?></strong></p>
				<p style="color: #666;"><?php esc_html_e( 'Scanning local storage. This may take a moment.', 'diluxone-offload' ); ?></p>
			</div>

			<!-- Initial Info -->
			<div id="delete-modal-info" style="display: none; margin: 20px 0;">
				<p style="margin: 0 0 15px 0; font-size: 14px;">
					<?php esc_html_e( 'This will permanently delete ALL files from local storage. Files will remain in your cloud provider and continue to be served from there.', 'diluxone-offload' ); ?>
				</p>

				<div style="background: #f0f0f1; padding: 15px; border-radius: 4px; margin: 15px 0;">
					<div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
						<span style="font-weight: 600;">🗑️ <?php esc_html_e( 'Files to delete:', 'diluxone-offload' ); ?></span>
						<span id="delete-modal-total-files">-</span>
					</div>
					<div style="display: flex; justify-content: space-between;">
						<span style="font-weight: 600;">💾 <?php esc_html_e( 'Space to free:', 'diluxone-offload' ); ?></span>
						<span id="delete-modal-total-size">-</span>
					</div>
				</div>
			</div>

			<!-- Progress -->
			<div id="delete-modal-progress" style="display: none; margin: 20px 0;">
				<div style="background: linear-gradient(135deg, #d63638 0%, #c62d30 100%); color: #fff; padding: 20px; border-radius: 6px; margin-bottom: 20px; text-align: center;">
					<div style="font-size: 28px; font-weight: 700; margin-bottom: 8px;">
						⚠️ <?php esc_html_e( 'DO NOT CLOSE THIS WINDOW', 'diluxone-offload' ); ?> ⚠️
					</div>
					<div style="font-size: 14px; font-weight: 500;">
						<?php esc_html_e( 'Closing will interrupt the deletion process', 'diluxone-offload' ); ?>
					</div>
				</div>

				<p style="text-align: center; font-weight: 600; margin: 15px 0;"><?php esc_html_e( 'Deleting local files...', 'diluxone-offload' ); ?></p>

				<div style="background: #f0f0f1; border-radius: 8px; overflow: hidden; margin: 15px 0;">
					<div id="delete-modal-progress-bar" style="height: 30px; background: linear-gradient(90deg, #d63638 0%, #f56e6e 100%); width: 0%; transition: width 0.3s; display: flex; align-items: center; justify-content: center; color: white; font-weight: 600;">
						<span id="delete-modal-progress-percent">0%</span>
					</div>
				</div>

				<p id="delete-modal-progress-text" style="text-align: center; margin: 10px 0; font-size: 14px; color: #666;">0 / 0 (0%)</p>

				<div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-top: 20px;">
					<div style="background: #f0f0f1; padding: 12px; border-radius: 4px; text-align: center;">
						<div style="font-size: 12px; color: #666; margin-bottom: 4px;"><?php esc_html_e( 'Processed', 'diluxone-offload' ); ?></div>
						<div id="delete-modal-stats-processed" style="font-size: 18px; font-weight: 600;">0</div>
					</div>
					<div style="background: #d4edda; padding: 12px; border-radius: 4px; text-align: center;">
						<div style="font-size: 12px; color: #155724; margin-bottom: 4px;"><?php esc_html_e( 'Successful', 'diluxone-offload' ); ?></div>
						<div id="delete-modal-stats-successful" style="font-size: 18px; font-weight: 600; color: #155724;">0</div>
					</div>
					<div style="background: #f8d7da; padding: 12px; border-radius: 4px; text-align: center;">
						<div style="font-size: 12px; color: #721c24; margin-bottom: 4px;"><?php esc_html_e( 'Failed', 'diluxone-offload' ); ?></div>
						<div id="delete-modal-stats-failed" style="font-size: 18px; font-weight: 600; color: #721c24;">0</div>
					</div>
				</div>
			</div>

			<!-- Summary (completion) -->
			<div id="delete-modal-summary" style="display: none; margin: 20px 0;">
				<!-- Summary will be populated by JavaScript -->
			</div>

			<!-- Footer -->
			<div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">
				<button id="delete-modal-cancel" class="button"><?php esc_html_e( 'Cancel', 'diluxone-offload' ); ?></button>
				<button id="delete-modal-start" class="button button-primary" style="background: #d63638; border-color: #d63638;">
					<span class="dashicons dashicons-trash"></span>
					<span id="delete-modal-start-text"><?php esc_html_e( 'Start Delete', 'diluxone-offload' ); ?></span>
				</button>
			</div>
		</div>
	</div>

	<?php require DILUXONE_OFFLOAD_DIR . 'templates/partials/sync-modal.php'; ?>

</div>
