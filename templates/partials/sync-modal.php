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
	<!-- ⭐ Unified Sync Modal (for both Sync and Disconnect) - OUTSIDE CONDITIONAL -->
	<div id="sync-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 100000;">
		<div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #fff; padding: 30px; border-radius: 8px; max-width: 700px; width: 90%;">

			<!-- ⭐ Dynamic Container (for new double-validation flow) -->
			<div id="sync-container" style="display: none;"></div>

			<!-- ⭐ Dynamic Summary (for Retry/Resync flows) -->
			<div id="sync-modal-summary"></div>

			<!-- Main Sync Modal Content (title, config, progress, buttons) -->
			<div id="sync-modal-content">
				<h2 id="sync-modal-title" style="margin-top: 0; border-bottom: 1px solid #ddd; padding-bottom: 15px;">
					<span id="sync-modal-icon" class="dashicons dashicons-cloud-upload"></span>
					<span id="sync-modal-title-text"><?php esc_html_e( 'Sync Files to Cloud', 'diluxone-offload' ); ?></span>
				</h2>

			<!-- Progress Step -->
			<div id="sync-modal-progress" style="display: none; margin: 20px 0;">
				<!-- WARNING -->
				<div style="background: linear-gradient(135deg, #d63638 0%, #c62d30 100%); color: #fff; padding: 20px; border-radius: 6px; margin-bottom: 20px; text-align: center; box-shadow: 0 2px 8px rgba(214, 54, 56, 0.3); border: 2px solid #d63638;">
					<div style="font-size: 28px; font-weight: 700; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 1px;">
						⚠️ <?php esc_html_e( 'DO NOT CLOSE THIS WINDOW', 'diluxone-offload' ); ?> ⚠️
					</div>
					<div style="font-size: 14px; font-weight: 500; opacity: 0.95;">
						<?php esc_html_e( 'Closing this window will cancel the synchronization', 'diluxone-offload' ); ?>
					</div>
				</div>

				<p style="text-align: center; font-weight: 600; margin: 15px 0;" id="sync-modal-progress-label"><?php esc_html_e( 'Syncing files...', 'diluxone-offload' ); ?></p>

				<div style="background: #f0f0f1; border-radius: 8px; overflow: hidden; margin: 15px 0;">
					<div id="sync-modal-progress-bar" style="height: 30px; background: linear-gradient(90deg, #0073aa 0%, #005177 100%); width: 0%; transition: width 0.3s; display: flex; align-items: center; justify-content: center; color: white; font-weight: 600;">
						<span id="sync-modal-progress-percent">0%</span>
					</div>
				</div>

				<p id="sync-modal-progress-text" style="text-align: center; margin: 10px 0; font-size: 14px; color: #666;">0 / 0 (0%)</p>

				<div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-top: 20px;">
					<div style="background: #f0f0f1; padding: 12px; border-radius: 4px; text-align: center;">
						<div style="font-size: 12px; color: #666; margin-bottom: 4px;"><?php esc_html_e( 'Processed', 'diluxone-offload' ); ?></div>
						<div id="sync-modal-stats-processed" style="font-size: 18px; font-weight: 600;">0</div>
					</div>
					<div style="background: #d4edda; padding: 12px; border-radius: 4px; text-align: center;">
						<div style="font-size: 12px; color: #155724; margin-bottom: 4px;"><?php esc_html_e( 'Successful', 'diluxone-offload' ); ?></div>
						<div id="sync-modal-stats-successful" style="font-size: 18px; font-weight: 600; color: #155724;">0</div>
					</div>
					<div style="background: #f8d7da; padding: 12px; border-radius: 4px; text-align: center;">
						<div style="font-size: 12px; color: #721c24; margin-bottom: 4px;"><?php esc_html_e( 'Failed', 'diluxone-offload' ); ?></div>
						<div id="sync-modal-stats-failed" style="font-size: 18px; font-weight: 600; color: #721c24;">0</div>
					</div>
				</div>
			</div>

				<!-- Footer Buttons -->
				<div style="border-top: 1px solid #ddd; padding-top: 15px; margin-top: 20px; text-align: right;">
					<button id="sync-modal-cancel" class="button button-secondary" style="margin-right: 10px;">
						<?php esc_html_e( 'Cancel', 'diluxone-offload' ); ?>
					</button>
				</div>
			</div>
			<!-- End #sync-modal-content -->

		</div>
	</div>
