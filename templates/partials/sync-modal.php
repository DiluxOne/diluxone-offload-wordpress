<?php
/**
 * Admin: the sync modal shared by the Sync & Offloading tabs (sync, retry, resync, enable, disconnect flows).
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

?>
	<!-- The sync modal, shared by the Sync, Offloading and Disconnect tabs -->
	<div id="sync-modal" class="diluxone-offload-modal" hidden>
		<div class="diluxone-offload-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="sync-modal-title">

			<!-- Filled by the script: the choices before a sync, a transfer of control -->
			<div id="sync-container" hidden></div>

			<!-- Filled by the script: the summaries of the retry and resync flows -->
			<div id="sync-modal-summary"></div>

			<div id="sync-modal-content">
				<h2 id="sync-modal-title">
					<span id="sync-modal-icon" class="dashicons dashicons-cloud-upload"></span>
					<span id="sync-modal-title-text"><?php esc_html_e( 'Sync Files to Cloud', 'diluxone-offload' ); ?></span>
				</h2>

				<div id="sync-modal-progress" class="diluxone-offload-modal__section" hidden>
					<div class="notice notice-error inline">
						<p>
							<strong><?php esc_html_e( 'DO NOT CLOSE THIS WINDOW', 'diluxone-offload' ); ?></strong>
							<?php esc_html_e( 'Closing this window will cancel the synchronization', 'diluxone-offload' ); ?>
						</p>
					</div>

					<p id="sync-modal-progress-label" class="diluxone-offload-progress-line"><strong><?php esc_html_e( 'Syncing files...', 'diluxone-offload' ); ?></strong></p>

					<div class="diluxone-offload-meter diluxone-offload-meter--tall" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
						<span id="sync-modal-progress-bar" class="diluxone-offload-meter__part--done" style="width: 0%"></span>
					</div>

					<p id="sync-modal-progress-text" class="diluxone-offload-progress-line">0 / 0 (0%)</p>

					<div class="diluxone-offload-tiles">
						<div class="diluxone-offload-tile">
							<div class="diluxone-offload-tile__k"><?php esc_html_e( 'Processed', 'diluxone-offload' ); ?></div>
							<div id="sync-modal-stats-processed" class="diluxone-offload-tile__v">0</div>
						</div>
						<div class="diluxone-offload-tile diluxone-offload-tile--ok">
							<div class="diluxone-offload-tile__k"><?php esc_html_e( 'Successful', 'diluxone-offload' ); ?></div>
							<div id="sync-modal-stats-successful" class="diluxone-offload-tile__v">0</div>
						</div>
						<div class="diluxone-offload-tile diluxone-offload-tile--failed">
							<div class="diluxone-offload-tile__k"><?php esc_html_e( 'Failed', 'diluxone-offload' ); ?></div>
							<div id="sync-modal-stats-failed" class="diluxone-offload-tile__v">0</div>
						</div>
					</div>
				</div>

				<div class="diluxone-offload-modal__footer">
					<button id="sync-modal-cancel" class="button"><?php esc_html_e( 'Cancel', 'diluxone-offload' ); ?></button>
				</div>
			</div>

		</div>
	</div>
