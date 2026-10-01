<?php
/**
 * Admin: Sync & Offloading › Offloading. Enable offloading once the library is synced; delete the local copies.
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
$timestamps      = $args['timestamps'] ?? array();
$cloud_host      = (string) ( $args['cloud_host'] ?? '' );
$screen_urls     = $args['screen_urls'] ?? array();
$local_copies    = (int) ( $counts['local'] ?? 0 );
$local_size      = (int) ( $counts['local_size'] ?? 0 );
$cloud_only      = (int) ( $counts['cloud_only'] ?? 0 );
$cloud_only_size = (int) ( $counts['cloud_only_size'] ?? 0 );
$since           = (int) ( $timestamps['offloading_since'] ?? 0 );
?>

<?php
// The figures every state of this screen can show, from the tracking table.
// A fourth item marks a value that is a hostname, not a number: set smaller, and
// a long one wraps after its dots rather than mid-label. A label longer than an
// Azure account name can be (24), such as Cloudflare R2's pub-<32 hex>, keeps
// its start and end around an ellipsis; the whole name is in the tooltip.
$diluxone_offload_host    = static function ( string $host ): string {
	$labels = explode( '.', $host );
	foreach ( $labels as $i => $label ) {
		if ( strlen( $label ) > 24 ) {
			$labels[ $i ] = substr( $label, 0, 10 ) . "\u{2026}" . substr( $label, -6 );
		}
	}
	return implode( '.<wbr>', array_map( 'esc_html', $labels ) );
};
$diluxone_offload_bignums = static function ( array $items ) use ( $diluxone_offload_host ): void {
	echo '<div class="diluxone-offload-bignums">';
	foreach ( $items as $item ) {
		echo '<div class="diluxone-offload-bignum">';
		echo '<div class="diluxone-offload-bignum__k">' . esc_html( $item[0] ) . '</div>';
		if ( ! empty( $item[3] ) ) {
			echo '<div class="diluxone-offload-bignum__v diluxone-offload-bignum__v--text" title="' . esc_attr( $item[1] ) . '">' . wp_kses( $diluxone_offload_host( $item[1] ), array( 'wbr' => array() ) ) . '</div>';
		} else {
			echo '<div class="diluxone-offload-bignum__v">' . esc_html( $item[1] ) . '</div>';
		}
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
		<h3><?php esc_html_e( 'Offloading', 'diluxone-offload' ); ?></h3>

		<?php if ( $current_state === 'offloading_active' ) : ?>
			<?php
			$diluxone_offload_bignums(
				array(
					array( __( 'Served from', 'diluxone-offload' ), $cloud_host !== '' ? $cloud_host : __( 'the cloud', 'diluxone-offload' ), __( 'every media URL WordPress hands out', 'diluxone-offload' ), true ),
					array( __( 'Local copies', 'diluxone-offload' ), number_format_i18n( $local_copies ), size_format( $local_size ) . ' ' . __( 'still on this server', 'diluxone-offload' ) ),
					array( __( 'Not on this server', 'diluxone-offload' ), number_format_i18n( $cloud_only ), size_format( $cloud_only_size ) . ' ' . __( 'in the cloud only', 'diluxone-offload' ) ),
					array( __( 'Offloading since', 'diluxone-offload' ), $since > 0 ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $since ), (string) get_option( 'date_format' ) ) : '—', $since > 0 ? $diluxone_offload_ago( $since ) : '' ),
				)
			);
			// Every file is served from the cloud; the bar says how many also
			// keep a copy here (striped: disk this server can give back) and
			// how many live only in the cloud (solid). It is never empty: a
			// fresh offloading reads as all striped, and turns solid as the
			// local copies are deleted.
			$diluxone_offload_files = $local_copies + $cloud_only;
			if ( $diluxone_offload_files > 0 ) :
				$diluxone_offload_cloud_pct = round( $cloud_only / $diluxone_offload_files * 100, 2 );
				?>
				<div class="diluxone-offload-bar-head">
					<span>
						<?php
						/* translators: %s: number of files */
						echo esc_html( sprintf( _n( 'Where your %s file is', 'Where your %s files are', $diluxone_offload_files, 'diluxone-offload' ), number_format_i18n( $diluxone_offload_files ) ) );
						?>
					</span>
					<strong>
						<?php
						echo esc_html(
							0 === $local_copies
								? __( 'All in the cloud only', 'diluxone-offload' )
								/* translators: %s: number of files that still have a copy on this server */
								: sprintf( _n( 'All in the cloud · %s still has a copy here', 'All in the cloud · %s still have a copy here', $local_copies, 'diluxone-offload' ), number_format_i18n( $local_copies ) )
						);
						?>
					</strong>
				</div>
				<div class="diluxone-offload-meter" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: 1: files only in the cloud, 2: files also on this server */ __( '%1$s only in the cloud, %2$s also on this server', 'diluxone-offload' ), number_format_i18n( $cloud_only ), number_format_i18n( $local_copies ) ) ); ?>">
					<?php if ( $cloud_only > 0 ) : ?>
						<span class="diluxone-offload-meter__part--cloud" style="width: <?php echo esc_attr( (string) $diluxone_offload_cloud_pct ); ?>%"></span>
					<?php endif; ?>
					<?php if ( $local_copies > 0 ) : ?>
						<span class="diluxone-offload-meter__part--local"></span>
					<?php endif; ?>
				</div>
				<ul class="diluxone-offload-legend">
					<li><i class="diluxone-offload-meter__part--cloud"></i>
						<?php
						/* translators: 1: number of files, 2: their size */
						echo esc_html( sprintf( _n( 'Only in the cloud · %1$s file · %2$s', 'Only in the cloud · %1$s files · %2$s', $cloud_only, 'diluxone-offload' ), number_format_i18n( $cloud_only ), size_format( $cloud_only_size ) ) );
						?>
					</li>
					<li><i class="diluxone-offload-meter__part--local"></i>
						<?php
						/* translators: 1: number of files, 2: disk they take on this server */
						echo esc_html( sprintf( _n( 'In the cloud and on this server · %1$s file · %2$s you can free', 'In the cloud and on this server · %1$s files · %2$s you can free', $local_copies, 'diluxone-offload' ), number_format_i18n( $local_copies ), size_format( $local_size ) ) );
						?>
					</li>
				</ul>
				<p class="description"><?php esc_html_e( 'Every file is served from the cloud already. The striped part is disk this server can give back with Delete Local Files; the bar turns solid as it does.', 'diluxone-offload' ); ?></p>
			<?php endif; ?>

			<div class="notice notice-info inline">
				<p>
					<strong><?php esc_html_e( 'Offloading Active', 'diluxone-offload' ); ?></strong>
					<?php esc_html_e( 'Your media files are being served directly from cloud storage. Local uploads are automatically synced to the cloud.', 'diluxone-offload' ); ?>
				</p>
			</div>

			<?php if ( ! empty( $stats['deletable_files'] ) ) : ?>
				<div class="diluxone-offload-action">
					<button id="delete-local-files-btn" class="button button-primary button-hero">
						<span class="dashicons dashicons-trash"></span>
						<?php esc_html_e( 'Delete Local Files', 'diluxone-offload' ); ?>
					</button>
					<p class="description">
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

		<?php elseif ( $current_state === 'synced' && (int) $failed_count === 0 ) : ?>
			<div class="notice notice-success inline">
				<p>
					<strong><?php esc_html_e( 'Synced Successfully', 'diluxone-offload' ); ?></strong>
					<?php esc_html_e( 'All your media files have been uploaded to the cloud. You can now enable offloading to serve files directly from cloud storage.', 'diluxone-offload' ); ?>
				</p>
			</div>

			<div class="diluxone-offload-action">
				<button id="enable-offloading-btn" class="button button-primary button-hero" data-confirm="true">
					<span class="dashicons dashicons-cloud"></span>
					<?php esc_html_e( 'Enable Cloud Storage (Offloading)', 'diluxone-offload' ); ?>
				</button>
				<p class="description">
					<?php esc_html_e( 'Activate offloading to serve all media files directly from cloud storage. New uploads will go straight to the cloud, saving local disk space.', 'diluxone-offload' ); ?>
				</p>
			</div>

		<?php elseif ( $current_state === 'synced' ) : ?>
			<div class="diluxone-offload-callout diluxone-offload-callout--warn">
				<p>
					<?php
					printf(
						/* translators: %d: number of files that failed to upload */
						esc_html__( 'Synchronization completed but %d files could not be uploaded. Retry them, or clear the list and enable offloading anyway, in the Sync tab.', 'diluxone-offload' ),
						(int) $failed_count
					);
					?>
				</p>
				<p><a href="<?php echo esc_url( $screen_urls['sync'] ?? '' ); ?>" class="button button-primary"><?php esc_html_e( 'Go to Sync', 'diluxone-offload' ); ?></a></p>
			</div>

		<?php else : ?>
			<div class="diluxone-offload-callout">
				<p><?php esc_html_e( 'Offloading can be enabled once every file is in the cloud. Run the sync first.', 'diluxone-offload' ); ?></p>
				<p><a href="<?php echo esc_url( $screen_urls['sync'] ?? '' ); ?>" class="button button-primary"><?php esc_html_e( 'Go to Sync', 'diluxone-offload' ); ?></a></p>
			</div>
		<?php endif; ?>
	</div>

	<!-- Delete Local Files: its own modal -->
	<div id="delete-modal" class="diluxone-offload-modal" hidden>
		<div class="diluxone-offload-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="delete-modal-title">
			<h2 id="delete-modal-title">
				<span class="dashicons dashicons-trash"></span>
				<span><?php esc_html_e( 'Delete Local Files', 'diluxone-offload' ); ?></span>
			</h2>

			<div id="delete-modal-loading" class="diluxone-offload-waiting">
				<div class="spinner is-active"></div>
				<h3><?php esc_html_e( 'Calculating files to delete...', 'diluxone-offload' ); ?></h3>
				<p><?php esc_html_e( 'Scanning local storage. This may take a moment.', 'diluxone-offload' ); ?></p>
			</div>

			<div id="delete-modal-info" class="diluxone-offload-modal__section" hidden>
				<p><?php esc_html_e( 'This will permanently delete ALL files from local storage. Files will remain in your cloud provider and continue to be served from there.', 'diluxone-offload' ); ?></p>
				<dl class="diluxone-offload-kv">
					<div><dt><?php esc_html_e( 'Files to delete:', 'diluxone-offload' ); ?></dt><dd id="delete-modal-total-files">-</dd></div>
					<div><dt><?php esc_html_e( 'Space to free:', 'diluxone-offload' ); ?></dt><dd id="delete-modal-total-size">-</dd></div>
				</dl>
			</div>

			<div id="delete-modal-progress" class="diluxone-offload-modal__section" hidden>
				<div class="notice notice-error inline">
					<p>
						<strong><?php esc_html_e( 'DO NOT CLOSE THIS WINDOW', 'diluxone-offload' ); ?></strong>
						<?php esc_html_e( 'Closing will interrupt the deletion process', 'diluxone-offload' ); ?>
					</p>
				</div>
				<p class="diluxone-offload-progress-line"><strong><?php esc_html_e( 'Deleting local files...', 'diluxone-offload' ); ?></strong></p>
				<div class="diluxone-offload-meter diluxone-offload-meter--tall" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
					<span id="delete-modal-progress-bar" class="diluxone-offload-meter__part--danger" style="width: 0%"></span>
				</div>
				<p id="delete-modal-progress-text" class="diluxone-offload-progress-line">0 / 0 (0%)</p>
				<div class="diluxone-offload-tiles">
					<div class="diluxone-offload-tile">
						<div class="diluxone-offload-tile__k"><?php esc_html_e( 'Processed', 'diluxone-offload' ); ?></div>
						<div id="delete-modal-stats-processed" class="diluxone-offload-tile__v">0</div>
					</div>
					<div class="diluxone-offload-tile diluxone-offload-tile--ok">
						<div class="diluxone-offload-tile__k"><?php esc_html_e( 'Successful', 'diluxone-offload' ); ?></div>
						<div id="delete-modal-stats-successful" class="diluxone-offload-tile__v">0</div>
					</div>
					<div class="diluxone-offload-tile diluxone-offload-tile--failed">
						<div class="diluxone-offload-tile__k"><?php esc_html_e( 'Failed', 'diluxone-offload' ); ?></div>
						<div id="delete-modal-stats-failed" class="diluxone-offload-tile__v">0</div>
					</div>
				</div>
			</div>

			<div id="delete-modal-summary" class="diluxone-offload-modal__section" hidden></div>

			<div class="diluxone-offload-modal__footer">
				<button id="delete-modal-cancel" class="button"><?php esc_html_e( 'Cancel', 'diluxone-offload' ); ?></button>
				<button id="delete-modal-start" class="button button-primary diluxone-offload-button-danger">
					<span class="dashicons dashicons-trash"></span>
					<span id="delete-modal-start-text"><?php esc_html_e( 'Start Delete', 'diluxone-offload' ); ?></span>
				</button>
			</div>
		</div>
	</div>

	<?php require DILUXONE_OFFLOAD_DIR . 'templates/partials/sync-modal.php'; ?>

</div>
