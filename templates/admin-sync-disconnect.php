<?php
/**
 * Admin: Sync & Offloading › Disconnect. Bring every file back from the cloud and turn offloading off.
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

// All data is prepared by Admin::render_screen_content() and read from $args — no business logic in templates
$current_state   = $args['current_state'] ?? 'not_configured';
$sync_progress   = $args['sync_progress'] ?? array();
$stats           = $args['stats'] ?? array();
$failed_files    = $args['failed_files'] ?? array();
$failed_count    = (int) ( $args['failed_count'] ?? 0 );
$has_files_in_db = $args['has_files_in_db'] ?? false;
$synced_count    = (int) ( $args['synced_count'] ?? 0 );
$pending_count   = (int) ( $args['pending_count'] ?? 0 );
$counts          = $args['counts'] ?? array();
$free_disk       = $args['free_disk'] ?? null;
$screen_urls     = $args['screen_urls'] ?? array();
$cloud_only      = (int) ( $counts['cloud_only'] ?? 0 );
$cloud_only_size = (int) ( $counts['cloud_only_size'] ?? 0 );
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

	<div class="card diluxone-offload-status-card">
		<h3><?php esc_html_e( 'Disconnect from Cloud', 'diluxone-offload' ); ?></h3>

		<?php if ( $current_state === 'offloading_active' ) : ?>
			<?php
			$diluxone_offload_bignums(
				array(
					array( __( 'Files to bring back', 'diluxone-offload' ), number_format_i18n( $cloud_only ), __( 'known to the tracking table; exact after the scan', 'diluxone-offload' ) ),
					array( __( 'Size to bring back', 'diluxone-offload' ), size_format( $cloud_only_size ), __( 'as recorded when they were uploaded', 'diluxone-offload' ) ),
					array(
						__( 'Free disk here', 'diluxone-offload' ),
						is_int( $free_disk ) ? size_format( $free_disk ) : __( 'not available', 'diluxone-offload' ),
						is_int( $free_disk ) ? ( $free_disk >= $cloud_only_size ? __( 'enough for what is in the cloud', 'diluxone-offload' ) : __( 'less than what is in the cloud: free some first', 'diluxone-offload' ) ) : __( 'the host does not allow reading it', 'diluxone-offload' ),
					),
				)
			);
			?>
			<div class="diluxone-offload-action">
				<button id="disconnect-from-cloud-btn" class="button button-primary button-hero diluxone-offload-button-danger">
					<span class="dashicons dashicons-download"></span>
					<?php esc_html_e( 'Disconnect from Cloud', 'diluxone-offload' ); ?>
				</button>
				<p class="description">
					<?php esc_html_e( 'Download all files from cloud storage back to local storage and disable offloading. This is a full reverse sync operation.', 'diluxone-offload' ); ?>
				</p>
			</div>

			<?php if ( defined( 'DILUXONE_OFFLOAD_DEV_MODE' ) && DILUXONE_OFFLOAD_DEV_MODE ) : ?>
				<div class="notice notice-warning inline">
					<p><strong><?php esc_html_e( 'DEV MODE', 'diluxone-offload' ); ?></strong>
						<?php esc_html_e( 'Skip file download and jump directly to configured state. Assumes local already has all files. For development/testing only.', 'diluxone-offload' ); ?></p>
					<p>
						<button id="dev-disconnect-without-sync-btn" class="button">
							<span class="dashicons dashicons-controls-skipforward"></span>
							<?php esc_html_e( 'Disconnect Without Sync', 'diluxone-offload' ); ?>
						</button>
					</p>
				</div>
			<?php endif; ?>

		<?php else : ?>
			<div class="diluxone-offload-callout">
				<p><?php esc_html_e( 'Offloading is not active, so there is nothing to bring back: the files are already on this server. To forget the provider and its credentials, delete it in Cloud Provider › Credentials.', 'diluxone-offload' ); ?></p>
				<p><a href="<?php echo esc_url( $screen_urls['credentials'] ?? '' ); ?>" class="button"><?php esc_html_e( 'Cloud Provider › Credentials', 'diluxone-offload' ); ?></a></p>
			</div>
		<?php endif; ?>
	</div>

	<!-- Disconnect from Cloud: confirm, scan, options, progress, outcome -->
	<div id="disconnect-modal" class="diluxone-offload-modal" hidden>
		<div class="diluxone-offload-modal__dialog" role="dialog" aria-modal="true">
			<div id="disconnect-confirm-view">
				<h2>
					<span class="dashicons dashicons-download"></span>
					<?php esc_html_e( 'Disconnect from Cloud Provider', 'diluxone-offload' ); ?>
				</h2>
				<div class="notice notice-warning inline">
					<p>
						<strong><?php esc_html_e( 'Warning:', 'diluxone-offload' ); ?></strong>
						<?php esc_html_e( 'This action will download all files from cloud storage back to local storage and disable offloading.', 'diluxone-offload' ); ?>
					</p>
				</div>
				<p><strong><?php esc_html_e( 'What will happen:', 'diluxone-offload' ); ?></strong></p>
				<ul class="ul-disc">
					<li><?php esc_html_e( 'All files will be downloaded from cloud to local storage (reverse sync)', 'diluxone-offload' ); ?></li>
					<li><?php esc_html_e( 'Offloading will be disabled automatically', 'diluxone-offload' ); ?></li>
					<li><?php esc_html_e( 'Files in cloud will remain untouched (no deletion)', 'diluxone-offload' ); ?></li>
					<li><?php esc_html_e( 'This process may take time depending on the number of files', 'diluxone-offload' ); ?></li>
				</ul>
				<div class="diluxone-offload-modal__footer">
					<button type="button" class="button close-disconnect-modal"><?php esc_html_e( 'Cancel', 'diluxone-offload' ); ?></button>
					<button type="button" id="confirm-disconnect" class="button button-primary diluxone-offload-button-danger"><?php esc_html_e( 'Yes, Disconnect & Download', 'diluxone-offload' ); ?></button>
				</div>
			</div>

			<div id="disconnect-scanning-view" class="diluxone-offload-waiting" hidden>
				<div class="spinner is-active"></div>
				<h3><?php esc_html_e( 'Scanning Cloud Storage...', 'diluxone-offload' ); ?></h3>
				<p><?php esc_html_e( 'Finding all files in the cloud. This may take a moment.', 'diluxone-offload' ); ?></p>
			</div>

			<div id="disconnect-options-view" hidden>
				<h2>
					<span class="dashicons dashicons-download"></span>
					<?php esc_html_e( 'Download Files from Cloud', 'diluxone-offload' ); ?>
				</h2>
				<div id="disconnect-stats"></div>
				<div class="diluxone-offload-modal__footer">
					<button type="button" class="button close-disconnect-modal"><?php esc_html_e( 'Cancel', 'diluxone-offload' ); ?></button>
					<button type="button" id="start-disconnect" class="button button-primary diluxone-offload-button-danger"><?php esc_html_e( 'Start Download', 'diluxone-offload' ); ?></button>
				</div>
			</div>

			<div id="disconnect-progress-view" hidden>
				<h2>
					<span class="dashicons dashicons-download"></span>
					<?php esc_html_e( 'Downloading Files...', 'diluxone-offload' ); ?>
				</h2>
				<div class="notice notice-error inline">
					<p>
						<strong><?php esc_html_e( 'DO NOT CLOSE THIS WINDOW', 'diluxone-offload' ); ?></strong>
						<?php esc_html_e( 'Closing this window will cancel the download', 'diluxone-offload' ); ?>
					</p>
				</div>
				<p id="disconnect-progress-label" class="diluxone-offload-progress-line"><strong><?php esc_html_e( 'Downloading files from cloud...', 'diluxone-offload' ); ?></strong></p>
				<div id="disconnect-progress-details">
					<div class="diluxone-offload-meter diluxone-offload-meter--tall" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
						<span id="disconnect-progress-bar" class="diluxone-offload-meter__part--done" style="width: 0%"></span>
					</div>
					<p id="disconnect-progress-text" class="diluxone-offload-progress-line">0 / 0 (0%)</p>
				</div>
				<div class="diluxone-offload-tiles">
					<div class="diluxone-offload-tile">
						<div class="diluxone-offload-tile__k"><?php esc_html_e( 'Downloaded', 'diluxone-offload' ); ?></div>
						<div id="disconnect-stats-downloaded" class="diluxone-offload-tile__v">0</div>
					</div>
					<div class="diluxone-offload-tile diluxone-offload-tile--ok">
						<div class="diluxone-offload-tile__k"><?php esc_html_e( 'Successful', 'diluxone-offload' ); ?></div>
						<div id="disconnect-stats-successful" class="diluxone-offload-tile__v">0</div>
					</div>
					<div class="diluxone-offload-tile">
						<div class="diluxone-offload-tile__k"><?php esc_html_e( 'Remaining', 'diluxone-offload' ); ?></div>
						<div id="disconnect-stats-remaining" class="diluxone-offload-tile__v">0</div>
					</div>
				</div>
				<div class="diluxone-offload-modal__footer">
					<button type="button" id="cancel-disconnect" class="button">
						<span class="dashicons dashicons-no-alt"></span>
						<?php esc_html_e( 'Cancel Download', 'diluxone-offload' ); ?>
					</button>
				</div>
			</div>

			<div id="disconnect-success-view" class="diluxone-offload-outcome diluxone-offload-outcome--ok" hidden>
				<span class="dashicons dashicons-yes-alt"></span>
				<h3><?php esc_html_e( 'Disconnected Successfully!', 'diluxone-offload' ); ?></h3>
				<p><?php esc_html_e( 'All files downloaded and offloading disabled', 'diluxone-offload' ); ?></p>
			</div>

			<div id="disconnect-error-view" class="diluxone-offload-outcome diluxone-offload-outcome--failed" hidden>
				<span class="dashicons dashicons-dismiss"></span>
				<h3><?php esc_html_e( 'Error', 'diluxone-offload' ); ?></h3>
				<p id="disconnect-error-message"></p>
				<div class="notice notice-warning inline">
					<p>
						<strong><?php esc_html_e( 'You can force disconnect without downloading files:', 'diluxone-offload' ); ?></strong>
						<?php esc_html_e( 'Files stored in the cloud will NOT be downloaded back to your server. Only files already available locally will remain accessible. This action cannot be undone.', 'diluxone-offload' ); ?>
					</p>
				</div>
				<div class="diluxone-offload-buttons">
					<button type="button" class="button close-disconnect-modal"><?php esc_html_e( 'Close', 'diluxone-offload' ); ?></button>
					<button type="button" id="force-disconnect-btn" class="button button-primary diluxone-offload-button-danger"><?php esc_html_e( 'Force Disconnect Without Sync', 'diluxone-offload' ); ?></button>
				</div>
			</div>
		</div>
	</div>

	<?php require DILUXONE_OFFLOAD_DIR . 'templates/partials/sync-modal.php'; ?>

</div>
