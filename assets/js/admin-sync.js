jQuery(document).ready(function($) {

	// ⭐ FIX: Use event delegation for Cancel button to work with dynamically created content
	$(document).on('click', '#sync-modal-cancel', function() {

		if ($('#sync-modal-progress').is(':visible')) {
			// Cancel ongoing sync
			isSyncCancelled = true;

			// Show cancelling message
			$('#sync-modal-progress-label').text(DiluxOneOffloadSync.i18n.cancelling_sync);
			$('#sync-modal-cancel').prop('disabled', true).css('opacity', '0.5');

			// ⭐ IMPORTANT: Only reset state to configured if NOT in download mode
			// In download mode (disconnect), we should stay in "synced" state
			if (currentSyncMode === 'download') {
				// Just reload without changing state
				setTimeout(function() {
					location.reload();
				}, 500);
			} else {
				// Upload mode - reset state to configured
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'diluxone_offload_reset_state_to_configured',
						nonce: diluxOneOffloadAdmin.nonce
					},
					success: function(response) {
						// Reload page to show correct UI
						location.reload();
					},
					error: function() {
						console.error('[DiluxOne Offload Sync] Failed to reset state');
						location.reload();
					}
				});
			}
		} else {
			$('#sync-modal').hide();
		}
	});

	// ⭐ Professional notification system (no alert popups)
	function showNotification(message, type = 'info') {
		const $notification = $('#diluxone-offload-notification');
		const colors = {
			'success': { bg: '#d4edda', border: '#46b450', color: '#155724' },
			'error': { bg: '#f8d7da', border: '#dc3545', color: '#721c24' },
			'warning': { bg: '#fff3cd', border: '#ffc107', color: '#856404' },
			'info': { bg: '#e7f3ff', border: '#0073aa', color: '#004085' }
		};

		const style = colors[type] || colors.info;
		$notification.css({
			'background-color': style.bg,
			'border-left-color': style.border,
			'color': style.color,
			'display': 'block'
		}).html(message);

		// Auto-hide success messages after 5 seconds
		if (type === 'success' || type === 'info') {
			setTimeout(() => $notification.fadeOut(), 5000);
		}
	}

	function hideNotification() {
		$('#diluxone-offload-notification').fadeOut();
	}

	// ══════════════════════════════════════════════════════════
	// Recursion-based sync: each batch schedules the next
	// ══════════════════════════════════════════════════════════
	let isSyncCancelled = false;
	let retryCount = 0;
	const maxRetries = 6;

	// ══════════════════════════════════════════════════════════
	// ⭐ NEW: Unified Modal for Sync and Disconnect
	// ══════════════════════════════════════════════════════════
	let currentSyncMode = 'upload'; // 'upload' or 'download'

	// Multi-tab coordination: a session id for this tab (persists across
	// page refresh). The server hands the sync to whichever tab holds it, so
	// it comes from the browser's cryptographic generator, not Math.random.
	let tabSessionId = sessionStorage.getItem('diluxone_offload_tab_session_id');
	if (!tabSessionId) {
		const bytes = new Uint8Array(12);
		window.crypto.getRandomValues(bytes);
		tabSessionId = 'sync_' + Date.now() + '_' + Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
		sessionStorage.setItem('diluxone_offload_tab_session_id', tabSessionId);
	}
	let currentSyncState = 'no_sync'; // 'active', 'inactive', 'terminated', 'no_sync'
	let stateCheckInterval = null;
	let activePollingInterval = null;


	// ⭐ Pass PHP state to JavaScript
	const pluginState = DiluxOneOffloadSync.data.current_state;

	// ⭐ NEW: Check on page load if there's an active sync in another tab
	function checkInitialSyncState() {

		// Track if we opened the modal (so we can close it later)
		let initialCheckOpenedModal = false;

		// Show loading spinner in modal if state is syncing
		if (pluginState === 'syncing') {
			showLoadingState();
			initialCheckOpenedModal = true;
		}

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'diluxone_offload_get_sync_state',
				nonce: diluxOneOffloadAdmin.nonce,
				session_id: tabSessionId
			},
			success: function(response) {
				if (response.success) {
					const data = response.data;
					const state = data.state;


					if (state === 'active') {
						// ⭐ FIX: This tab is active (could be after refresh with sessionStorage)
						currentSyncState = 'active';

						// ⭐ FIX: Request current progress FIRST, keep loading spinner until received
						updateProgressFromServer(function() {
							// Callback: Progress received, now show progress modal
							$('#sync-container').hide();
							$('#sync-modal-content').show(); // ⭐ FIX PROBLEMA 3: Show main content container
							$('#sync-modal-progress').show();
							$('#sync-modal').show(); // Keep modal visible

							// Resume processing batches
							processSyncBatch();
						});
					} else if (state === 'inactive') {
						// Another tab is running the sync
						currentSyncState = 'inactive';
						showInactiveTabUI(data.sync_meta);
						startStateMonitoring();
					} else if (state === 'terminated') {
						// Sync just finished
						currentSyncState = 'terminated';
						// Only hide modal if WE opened it
						if (initialCheckOpenedModal) {
							hideLoadingState();
						}
						// Could show completion screen
					} else {
						// No sync active
						currentSyncState = 'no_sync';
						// Only hide modal if WE opened it
						if (initialCheckOpenedModal) {
							hideLoadingState();
						}
					}
				}
			},
			error: function(xhr, status, error) {
				// Only hide modal if WE opened it
				if (initialCheckOpenedModal) {
					hideLoadingState();
				}
				console.error('[DiluxOne Offload Multi-Tab] Error checking initial state:', error);
			}
		});
	}

	// Show loading state in modal (same as sync calculation)
	function showLoadingState(title = 'Analyzing Sync Status', message = 'Checking for active synchronization...') {
		$('#sync-modal').show();
		$('#sync-modal-content').hide();
		$('#sync-modal-summary').hide();
		$('#sync-modal-progress').hide();

		const loadingHtml = '<div id="diluxone-offload-loading-spinner" style="text-align: center; padding: 60px 20px;">' +
			'<div class="spinner is-active" style="float: none; margin: 0 auto 20px; width: 40px; height: 40px;"></div>' +
			'<h3 style="margin: 0 0 12px 0; color: #2271b1; font-size: 20px; font-weight: 600;">' + title + '</h3>' +
			'<p style="color: #666; font-size: 15px; margin: 0;">' + message + '</p>' +
			'</div>';

		// ⭐ #sync-container now exists in HTML (line 537), no need to create it
		$('#sync-container').html(loadingHtml).show();
	}

	// Hide loading state modal
	function hideLoadingState() {
		$('#sync-modal').hide();
		$('#sync-container').empty();
	}

	// Show notification message
	function showNotice(message, type = 'info') {
		const colors = {
			'success': { bg: '#d4edda', border: '#46b450', text: '#155724' },
			'error': { bg: '#f8d7da', border: '#dc3232', text: '#721c24' },
			'warning': { bg: '#fff3cd', border: '#f0b849', text: '#856404' },
			'info': { bg: '#d1ecf1', border: '#0073aa', text: '#0c5460' }
		};

		const color = colors[type] || colors['info'];

		const $notice = $('<div class="diluxone-offload-notice" style="margin: 15px 0; padding: 12px 15px; border-radius: 4px; border-left: 4px solid ' + color.border + '; background: ' + color.bg + '; color: ' + color.text + ';">' +
			message +
			'</div>');

		$('#diluxone-offload-notification').html($notice).show();

		// Auto-hide after 5 seconds
		setTimeout(function() {
			$('#diluxone-offload-notification').fadeOut();
		}, 5000);
	}

	// Run initial check on page load (always, for multi-tab coordination)
	// The spinner inside checkInitialSyncState() will only show if pluginState === 'syncing'
	checkInitialSyncState();

	// ⭐ REFACTORED: Start sync process with DOUBLE VALIDATION
	// Called when user clicks Start/Continue/Retry sync buttons
	function startSyncProcess(fromScratch, retryFailed) {
		retryFailed = retryFailed || false;


		// Show loading state with unified look & feel
		showLoadingState('Validating Action', 'Calculating files to sync...');

		// First call: pre-check + calculate (confirmed=0).
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'diluxone_offload_start_sync',
				nonce: diluxOneOffloadAdmin.nonce,
				session_id: tabSessionId,
				confirmed: 0, // Pre-check.
				retry_failed: retryFailed ? 1 : 0
			},
			success: function(response) {

				if (!response.success) {
					$('#sync-modal').hide();
					showNotice('Error: ' + (response.data || 'Unknown error'), 'error');
					return;
				}

				// Check 1: did validation fail?
				if (response.data.validation_failed) {
					console.warn('[DiluxOne Offload Sync] Validation FAILED on pre-check:', response.data.reason);
					handleValidationError(response.data.reason, response.data.details);
					return;
				}

				// Check 2: does it need confirmation?
				if (response.data.requires_confirmation) {
					// Show the options modal (Continue / From Scratch).
					showSyncOptionsModal(response.data.data, fromScratch, retryFailed);
					return;
				}

				// Should never get here.
				console.error('[DiluxOne Offload Sync] Unexpected response:', response);
				$('#sync-modal').hide();
				showNotice('Unexpected response from server', 'error');
			},
			error: function(xhr, status, error) {
				console.error('[DiluxOne Offload Sync] AJAX error on pre-check:', error);
				$('#sync-modal').hide();
				showNotice('Connection error. Please try again.', 'error');
			}
		});
	}

	// ⭐ NEW: Show sync options modal (Continue/From Scratch)
	function showSyncOptionsModal(data, fromScratch, retryFailed) {

		// Nothing pending and something synced: skip the modal and go straight
		// to the Enable Offloading screen — there is nothing left to upload.
		if (data.pending_files === 0 && data.synced_files > 0) {

			// Prepare the modal to show the result.
			$('#sync-modal').show();
			$('#sync-container').hide();
			$('#sync-modal-content').show();
			$('#sync-modal-summary').hide();
				$('#sync-modal-progress').hide();
	
			// Hand the pre-check numbers straight to onSyncComplete.
			onSyncComplete({
				status: 'completed',
				total_files: data.synced_files,
				successful_uploads: data.synced_files,
				failed_uploads: 0,
				processed_files: data.synced_files
			});
			return;
		}

		var summaryHtml = '<div class="sync-summary" style="position: relative;">';

		// Close button (X) at top-right
		summaryHtml += '<button id="close-sync-options-btn" style="position: absolute; top: -10px; right: -10px; background: #d63638; color: white; border: none; border-radius: 50%; width: 30px; height: 30px; cursor: pointer; font-size: 18px; line-height: 1; padding: 0; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.2);" title="' + DiluxOneOffloadSync.i18n.close + '">&times;</button>';

		summaryHtml += '<h3 style="margin: 0 0 15px 0;">☁️ ' + DiluxOneOffloadSync.i18n.upload_summary + '</h3>';
		summaryHtml += '<div class="summary-stats" style="background: #f5f5f5; padding: 15px; border-radius: 5px; margin-bottom: 20px;">';
		summaryHtml += '<div style="margin-bottom: 8px;">';
		summaryHtml += '<strong>' + DiluxOneOffloadSync.i18n.total_files + '</strong> ' + data.total_files.toLocaleString() + ' (' + data.total_size_formatted + ')';
		summaryHtml += '</div>';
		summaryHtml += '<div style="margin-bottom: 8px; color: #0a0;">';
		summaryHtml += '<strong>' + DiluxOneOffloadSync.i18n.already_uploaded + '</strong> ' + data.synced_files.toLocaleString() + ' (' + data.synced_size_formatted + ') ✅';
		summaryHtml += '</div>';

		if (data.new_files > 0) {
			summaryHtml += '<div style="margin-bottom: 8px; color: #f90;">';
			summaryHtml += '<strong>' + DiluxOneOffloadSync.i18n.new_files + '</strong> ' + data.new_files.toLocaleString() + ' (' + data.new_files_size_formatted + ') 💛';
			summaryHtml += '</div>';
		}

		if (data.pending_files > 0) {
			summaryHtml += '<div style="color: #c60;">';
			summaryHtml += '<strong>' + DiluxOneOffloadSync.i18n.pending + '</strong> ' + data.pending_files.toLocaleString() + ' (' + data.pending_size_formatted + ') 🎈';
			summaryHtml += '</div>';
		}

		summaryHtml += '</div>';

		// Performance selector
		summaryHtml += '<div style="margin: 20px 0; padding: 15px; background: #e7f3ff; border-left: 4px solid #2196f3; border-radius: 4px;">';
		summaryHtml += '<label for="upload-concurrency-select" style="display: block; margin-bottom: 10px; font-weight: 600;">';
		summaryHtml += '⚡ ' + DiluxOneOffloadSync.i18n.upload_performance;
		summaryHtml += '</label>';
		summaryHtml += '<select id="upload-concurrency-select" class="regular-text" style="width: 100%; padding: 8px;">';
		summaryHtml += '<option value="5" selected>' + DiluxOneOffloadSync.i18n.balanced_5_parallel + '</option>';
		summaryHtml += '<option value="20">' + DiluxOneOffloadSync.i18n.fast_20_parallel + '</option>';
		summaryHtml += '<option value="40">' + DiluxOneOffloadSync.i18n.intensive_40_parallel + '</option>';
		summaryHtml += '</select>';
		summaryHtml += '</div>';

		// Buttons
		var continueDisabled = (data.synced_files === 0 || data.pending_files === 0);
		summaryHtml += '<div class="button-group" style="display: flex; gap: 15px; margin-top: 20px;">';

		if (!continueDisabled) {
			// Case 1: Has pending files - show Continue Upload
			summaryHtml += '<button id="continue-upload-btn" class="button button-primary button-large" style="flex: 1; padding: 15px;">';
			summaryHtml += '<span class="dashicons dashicons-controls-play"></span> ' + DiluxOneOffloadSync.i18n.continue_upload;
			summaryHtml += '</button>';

			summaryHtml += '<button id="scratch-upload-btn" class="button button-secondary button-large" style="flex: 1; padding: 15px;">';
			summaryHtml += '<span class="dashicons dashicons-update"></span> ' + DiluxOneOffloadSync.i18n.upload_from_scratch;
			summaryHtml += '</button>';
		} else if (data.pending_files === 0 && data.synced_files > 0) {
			// Case 2: All synced (pending=0) - show Complete Sync button
			summaryHtml += '<button id="continue-upload-btn" class="button button-primary button-large" style="flex: 1; padding: 15px; background: #46b450; border-color: #46b450;">';
			summaryHtml += '<span class="dashicons dashicons-yes-alt"></span> ' + DiluxOneOffloadSync.i18n.scan_and_complete_sync;
			summaryHtml += '</button>';

			summaryHtml += '<button id="scratch-upload-btn" class="button button-secondary button-large" style="flex: 1; padding: 15px;">';
			summaryHtml += '<span class="dashicons dashicons-update"></span> ' + DiluxOneOffloadSync.i18n.upload_from_scratch;
			summaryHtml += '</button>';
		} else {
			// Case 3: Starting fresh (synced=0) - show Upload from Scratch only
			summaryHtml += '<button id="scratch-upload-btn" class="button button-primary button-large" style="flex: 1; padding: 15px;">';
			summaryHtml += '<span class="dashicons dashicons-update"></span> ' + DiluxOneOffloadSync.i18n.upload_from_scratch;
			summaryHtml += '</button>';
		}

		summaryHtml += '</div>';
		summaryHtml += '</div>';

		// ⭐ CRITICAL: Ensure modal and container are visible
		$('#sync-modal').show();
		$('#sync-container').html(summaryHtml).show();
		$('#sync-modal-content').hide();
		$('#sync-modal-summary').hide();
		$('#sync-modal-progress').hide();


		// Attach handlers
		$('#continue-upload-btn').off('click').on('click', function() {
			executeSyncConfirmed(false, retryFailed); // Continue mode
		});

		$('#scratch-upload-btn').off('click').on('click', function() {
			executeSyncConfirmed(true, retryFailed); // From scratch
		});

		$('#close-sync-options-btn').off('click').on('click', function() {
			$('#sync-modal').hide();
			$('#sync-container').empty();
		});
	}

	// Execute the sync after the user confirms (second call).
	function executeSyncConfirmed(fromScratch, retryFailed) {
		const concurrency = parseInt($('#upload-concurrency-select').val()) || 5;


		// Show progress modal
		$('#sync-modal-content').show();
		$('#sync-container').hide();
		$('#sync-modal-summary').hide();
		$('#sync-modal-progress').show();

		// Reset state
		isSyncCancelled = false;
		retryCount = 0;
		currentSyncState = 'active';

		// Reset progress UI
		$('#sync-modal-progress-bar').css('width', '0%');
		$('#sync-modal-progress-text').text('0 / 0 (0%)');
		$('#sync-modal-progress-percent').text('0%');
		$('#sync-modal-stats-processed').text('0');
		$('#sync-modal-stats-successful').text('0');
		$('#sync-modal-stats-failed').text('0');

		// Second call: execution-check + execute (confirmed=1).
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'diluxone_offload_start_sync',
				nonce: diluxOneOffloadAdmin.nonce,
				session_id: tabSessionId,
				confirmed: 1, // Execution-check.
				concurrency: concurrency,
				from_scratch: fromScratch ? 1 : 0,
				retry_failed: retryFailed ? 1 : 0
			},
			success: function(response) {
				if (!response.success) {
					$('#sync-modal').hide();
					showNotice('Error: ' + (response.data || 'Unknown error'), 'error');
					return;
				}

				// Validate again: another tab may have started a sync while the
				// user was reading the modal.
				if (response.data.validation_failed) {
					console.warn('[DiluxOne Offload Sync] Validation FAILED on execution-check:', response.data.reason);
					handleValidationError(response.data.reason, response.data.details);
					return;
				}

				// The action ran.
				if (response.data.action_executed) {
					processSyncBatch();
				} else {
					console.error('[DiluxOne Offload Sync] Unexpected response:', response);
					$('#sync-modal').hide();
					showNotice('Unexpected response from server', 'error');
				}
			},
			error: function(xhr, status, error) {
				console.error('[DiluxOne Offload Sync] AJAX error on execution:', error);
				$('#sync-modal').hide();
				showNotice('Connection error. Please try again.', 'error');
			}
		});
	}

	// ⭐ NEW: Unified validation error handler
	function handleValidationError(reason, details) {

		switch(reason) {
			case 'sync_active_in_another_tab':
				showInactiveTabUI(details.sync_meta);
				startStateMonitoring();
				break;

			case 'sync_already_active':
				console.warn('[DiluxOne Offload Validation] Sync already active');
				$('#sync-modal').hide();
				showNotice('Sync is already active. Please wait or refresh the page.', 'warning');
				setTimeout(() => location.reload(), 2000);
				break;

			case 'state_conflict':
				console.warn('[DiluxOne Offload Validation] Plugin state conflict');
				$('#sync-modal').hide();
				showNotice('Plugin state conflict. Refreshing page...', 'warning');
				setTimeout(() => location.reload(), 1000);
				break;

			case 'files_not_synced':
				const stats = details;
				const failedCount = stats.failed_count || 0;
				const pendingCount = stats.pending_count || 0;
				let errorMsg = 'Cannot proceed: ';
				if (failedCount > 0) errorMsg += failedCount + ' failed files';
				if (failedCount > 0 && pendingCount > 0) errorMsg += ' and ';
				if (pendingCount > 0) errorMsg += pendingCount + ' pending files';
				errorMsg += '. Please resolve errors first.';

				$('#sync-modal').hide();
				showNotice(errorMsg, 'error');
				break;

			default:
				console.error('[DiluxOne Offload Validation] Unknown error:', reason);
				$('#sync-modal').hide();
				showNotice('Operation not allowed: ' + reason, 'error');
		}
	}

	// Start/Continue Sync button: goes through the two-step validation flow.
	$('#start-sync-btn').on('click', function() {
		currentSyncMode = 'upload';

		// Configure modal for upload
		$('#sync-modal-icon').removeClass('dashicons-download').addClass('dashicons-cloud-upload');
		$('#sync-modal-title-text').text(DiluxOneOffloadSync.i18n.sync_files_to_cloud);

		// ⭐ NEW: Call unified startSyncProcess (with double validation)
		startSyncProcess(false, false); // fromScratch=false, retryFailed=false
	});

	// ⭐ RECURSION: Process batch and immediately call next
	function processSyncBatch() {
		if (isSyncCancelled) {
			return;
		}

		// ⭐ Check if this tab still owns the sync before processing
		if (currentSyncState !== 'active') {
			return;
		}

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'diluxone_offload_process_batch',
				nonce: diluxOneOffloadAdmin.nonce,
				session_id: tabSessionId // ⭐ NEW: Send tab session ID
			},
			success: function(response) {
				retryCount = 0; // Reset error counter on success

				if (response.success) {
					const data = response.data;

					// ⭐ NEW: Check if session was lost
					if (data.status === 'session_lost') {
						currentSyncState = 'inactive';

						// Start monitoring state instead of processing
						startStateMonitoring();
						return;
					}

					// Update UI
					updateSyncProgress(data);

					// ⭐ Check if completed
					if (data.status === 'completed') {
						onSyncComplete(data);
					} else {
						// ⭐ IMMEDIATE RECURSION (no delay)
						processSyncBatch();
					}
				} else {
					// ⭐ FIXED: Handle different error response formats
					const errorMsg = response.data?.message || response.data || 'Unknown error';
					console.error('[DiluxOne Offload Sync] Batch error:', errorMsg);
					showNotification(DiluxOneOffloadSync.i18n.error_processing_batch + ' ' + errorMsg, 'error');
				}
			},
			error: function(xhr, status, error) {
				// ⭐ EXPONENTIAL BACKOFF on errors
				retryCount++;

				if (retryCount > maxRetries) {
					showNotification(DiluxOneOffloadSync.i18n.max_retries_exceeded_sync_stopped_please, 'error');
					console.error('[DiluxOne Offload Sync] Max retries exceeded');
					return;
				}

				// Calculate exponential backoff delay
				const backoff = Math.floor(Math.pow(retryCount, 2.5) * 1000);
				// Retry 1: 1s, 2: 5.6s, 3: 15.5s, 4: 37s, 5: 78s, 6: 156s

				console.warn('[DiluxOne Offload Sync] Error (attempt ' + retryCount + '/' + maxRetries + '). Retrying in ' + (backoff/1000) + 's...');
				console.error('[DiluxOne Offload Sync] Error details:', status, error);

				// Say so in the modal, where the user is looking.
				const label = $('#sync-modal-progress-label');
				const resting = label.text();
				label.text('⚠️ Connection error. Retrying in ' + (backoff/1000) + 's... (attempt ' + retryCount + '/' + maxRetries + ')');

				setTimeout(function() {
					label.text(resting);
					processSyncBatch(); // Retry
				}, backoff);
			}
		});
	}

	// ⭐ NEW: Multi-tab state monitoring
	function startStateMonitoring() {

		// Clear any existing interval
		if (stateCheckInterval) {
			clearInterval(stateCheckInterval);
		}

		// Poll state every 5 seconds
		stateCheckInterval = setInterval(checkSyncState, 5000);

		// Check immediately
		checkSyncState();
	}

	function stopStateMonitoring() {
		if (stateCheckInterval) {
			clearInterval(stateCheckInterval);
			stateCheckInterval = null;
		}
	}

	function checkSyncState() {
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'diluxone_offload_get_sync_state',
				nonce: diluxOneOffloadAdmin.nonce,
				session_id: tabSessionId
			},
			success: function(response) {
				if (response.success) {
					const data = response.data;
					const state = data.state;


					if (state === 'no_sync') {
						// No sync active anymore (cancelled or error)
						currentSyncState = 'no_sync';
						stopStateMonitoring();
						$('#sync-modal').hide();

						// ⭐ FIX: Reload page to show updated state (CONFIGURED)
						setTimeout(function() {
							location.reload();
						}, 1000);
					} else if (state === 'expired') {
						// ⭐ NEW: Session expired due to inactivity (timeout)
						currentSyncState = 'no_sync';
						stopStateMonitoring();
						$('#sync-modal').hide();

						showNotice('Sync session expired due to inactivity. The page will reload...', 'warning');

						// Reload page after 2 seconds
						setTimeout(function() {
							location.reload();
						}, 2000);
					} else if (state === 'terminated') {
						// Sync completed
						currentSyncState = 'terminated';
						stopStateMonitoring();
						onSyncComplete(data.sync_meta);
					} else if (state === 'active') {
						// This tab regained control somehow (shouldn't happen normally)
						currentSyncState = 'active';
						stopStateMonitoring();
						processSyncBatch();
					} else if (state === 'inactive') {
						// Another tab is still active - show "Continue Here" UI
						showInactiveTabUI(data.sync_meta);
					}
				}
			},
			error: function(xhr, status, error) {
				console.error('[DiluxOne Offload Multi-Tab] Error checking state:', error);
			}
		});
	}

	function showInactiveTabUI(syncMeta) {
		// Show modal with "Continue Here" button
		$('#sync-modal').show();
		$('#sync-modal-content').hide(); // ⭐ FIX PROBLEMA 1: Hide main content to avoid duplication
		$('#sync-modal-progress').hide();

		// Create inactive tab UI
		const percentage = syncMeta.percentage || 0;
		const processed = syncMeta.processed_files || 0;
		const total = syncMeta.total_files || 0;

		let inactiveHtml = '<div style="padding: 30px; text-align: center;">';
		inactiveHtml += '<div style="font-size: 48px; margin-bottom: 15px;">⏸️</div>';
		inactiveHtml += '<h3 style="margin: 0 0 10px 0; color: #856404;">Sync Active in Another Tab</h3>';
		inactiveHtml += '<p style="color: #666; margin-bottom: 20px;">Another browser tab is currently processing the sync.</p>';

		// Progress info
		inactiveHtml += '<div style="background: #f5f5f5; padding: 15px; border-radius: 5px; margin-bottom: 20px;">';
		inactiveHtml += '<div style="margin-bottom: 8px;"><strong>Progress:</strong> ' + processed + ' / ' + total + ' files (' + percentage.toFixed(1) + '%)</div>';
		inactiveHtml += '</div>';

		inactiveHtml += '<button id="continue-here-btn" class="button button-primary button-large" style="padding: 15px 30px; font-size: 14px;">';
		inactiveHtml += '<span class="dashicons dashicons-controls-play" style="margin-right: 5px;"></span>';
		inactiveHtml += 'Continue Here';
		inactiveHtml += '</button>';

		inactiveHtml += '<p class="description" style="margin-top: 15px; color: #666;">This will move the sync to this tab.</p>';
		inactiveHtml += '</div>';

		// ⭐ #sync-container now exists in HTML (line 537), no need to create it
		$('#sync-container').html(inactiveHtml).show();

		// Attach event handler
		$('#continue-here-btn').on('click', function() {
			takeControl();
		});
	}

	function takeControl() {

		// ⭐ FIX: Show "Transferring control..." loading state
		const transferringHtml = '<div style="padding: 40px; text-align: center;">' +
			'<div class="spinner is-active" style="float: none; margin: 0 auto 20px; width: 40px; height: 40px;"></div>' +
			'<h3 style="margin: 0 0 12px 0; color: #2271b1; font-size: 20px; font-weight: 600;">Transferring Control</h3>' +
			'<p style="color: #666; font-size: 15px; margin: 0;">Taking over synchronization from other tab...</p>' +
			'</div>';

		$('#sync-container').html(transferringHtml).show();
		$('#sync-modal-content').hide();

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'diluxone_offload_take_control',
				nonce: diluxOneOffloadAdmin.nonce,
				session_id: tabSessionId
			},
			success: function(response) {
				if (response.success) {

					// Set this tab as active
					currentSyncState = 'active';

					// Stop state monitoring
					stopStateMonitoring();

					// ⭐ FIX: Request current progress FIRST, then hide transferring UI
					updateProgressFromServer(function() {
						// Callback: Progress received, now show modal
						$('#sync-container').hide();
						$('#sync-modal-content').show(); // ⭐ FIX PROBLEMA 2: Show main content container
						$('#sync-modal-progress').show();

						// Start processing batches
						processSyncBatch();
					});
				} else {
					alert(DiluxOneOffloadSync.i18n.failed_to_take_control + ' ' + (response.data || DiluxOneOffloadSync.i18n.unknown_error));
					// Restore inactive UI
					$('#sync-container').empty();
					showInactiveTabUI(response.data.sync_meta || {});
				}
			},
			error: function(xhr, status, error) {
				alert(DiluxOneOffloadSync.i18n.connection_error_taking_control);
				console.error('[DiluxOne Offload Multi-Tab] Take control error:', error);
				// Restore inactive UI
				$('#sync-container').empty();
			}
		});
	}

	// ⭐ FIX PROBLEMA 5: Request current progress from server (lightweight, no processing)
	function updateProgressFromServer(callback) {
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'diluxone_offload_get_sync_state',
				nonce: diluxOneOffloadAdmin.nonce,
				session_id: tabSessionId
			},
			success: function(response) {
				if (response.success && response.data.sync_meta) {
					// Update UI with current progress
					const syncMeta = response.data.sync_meta;
					updateSyncProgress({
						percentage: syncMeta.percentage || 0,
						processed_files: syncMeta.processed_files || 0,
						total_files: syncMeta.total_files || 0,
						successful_uploads: syncMeta.successful_uploads || 0,
						failed_uploads: syncMeta.failed_uploads || 0
					});
				}
				// Call callback when done (success or no data)
				if (callback) callback();
			},
			error: function(xhr, status, error) {
				console.error('[DiluxOne Offload Multi-Tab] Error fetching progress:', error);
				// Call callback even on error
				if (callback) callback();
			}
		});
	}

	function updateSyncProgress(data) {
		const percentage = data.percentage || 0;
		const processed = data.processed_files || 0;
		const total = data.total_files || 0;
		const successful = data.successful_uploads || processed;
		const failed = data.failed_uploads || 0;

		// Update modal progress
		$('#sync-modal-progress-bar').css('width', percentage + '%');
		$('#sync-modal-progress-text').text(processed.toLocaleString() + ' / ' + total.toLocaleString() + ' files');
		$('#sync-modal-progress-percent').text(Math.round(percentage) + '%');
		$('#sync-modal-stats-processed').text(processed.toLocaleString());
		$('#sync-modal-stats-successful').text(successful.toLocaleString());
		$('#sync-modal-stats-failed').text(failed.toLocaleString());

	}
	
	function onSyncComplete(data) {
		// Hide progress and title, show completion summary
		$('#sync-modal-progress').hide();
		$('#sync-modal-content').hide(); // ⭐ Hide entire content container (including title)
		$('#sync-container').hide(); // ⭐ FIX: Hide "Sync Active in Another Tab" content from inactive tab

		const processed = data.processed_files || 0;
		const total = data.total_files || 0;
		const successful = data.successful_uploads || 0;
		const failed = data.failed_uploads || 0;

		// ⭐ DEBUG: Log data to understand what's happening

		// Build completion summary
		let summaryHtml = '<div class="sync-summary" style="text-align: center; padding: 20px;">';

		// ⭐ FIXED LOGIC: Consider successful if we have successful uploads OR if total equals successful
		const isSuccess = (data.status === 'completed' && failed === 0) || (successful > 0 && failed === 0) || (total > 0 && successful === total);

		if (isSuccess) {
			// ✅ All successful
			summaryHtml += '<div style="font-size: 64px; margin-bottom: 20px;">✅</div>';
			summaryHtml += '<h3 style="color: #46b450; margin: 0 0 10px 0;">' + DiluxOneOffloadSync.i18n.sync_completed_successfully + '</h3>';
			summaryHtml += '<p style="font-size: 16px; color: #666; margin: 10px 0;">';
			summaryHtml += DiluxOneOffloadSync.i18n.all_files_have_been_synced_to;
			summaryHtml += '</p>';
		} else if (failed > 0) {
			// ⚠️ Some failures
			summaryHtml += '<div style="font-size: 64px; margin-bottom: 20px;">⚠️</div>';
			summaryHtml += '<h3 style="color: #f0b849; margin: 0 0 10px 0;">' + DiluxOneOffloadSync.i18n.sync_completed_with_errors + '</h3>';
			summaryHtml += '<p style="font-size: 16px; color: #666; margin: 10px 0;">';
			summaryHtml += DiluxOneOffloadSync.i18n.some_files_could_not_be_synced;
			summaryHtml += '</p>';
		} else {
			// ❌ Failed completely (no successful uploads and no clear completion status)
			summaryHtml += '<div style="font-size: 64px; margin-bottom: 20px;">❌</div>';
			summaryHtml += '<h3 style="color: #d63638; margin: 0 0 10px 0;">' + DiluxOneOffloadSync.i18n.sync_failed + '</h3>';
			summaryHtml += '<p style="font-size: 16px; color: #666; margin: 10px 0;">';
			summaryHtml += (data.message || DiluxOneOffloadSync.i18n.unknown_error);
			summaryHtml += '</p>';
		}

		// Stats
		summaryHtml += '<div style="background: #f5f5f5; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: left;">';
		summaryHtml += '<div style="margin-bottom: 10px;"><strong>' + DiluxOneOffloadSync.i18n.total_files + '</strong> ' + total.toLocaleString() + '</div>';
		summaryHtml += '<div style="margin-bottom: 10px; color: #46b450;"><strong>' + DiluxOneOffloadSync.i18n.successful + '</strong> ' + successful.toLocaleString() + '</div>';
		if (failed > 0) {
			summaryHtml += '<div style="color: #d63638;"><strong>' + DiluxOneOffloadSync.i18n.failed + '</strong> ' + failed.toLocaleString() + '</div>';
		}
		summaryHtml += '</div>';

		// ⭐ Action buttons - different based on result
		summaryHtml += '<div style="margin-top: 20px; display: flex; gap: 10px; justify-content: center;">';

		if (isSuccess) {
			// ✅ SUCCESS: Offer to enable offloading or continue later
			summaryHtml += '<button id="enable-offloading-btn" class="button button-primary" style="padding: 10px 30px; font-size: 16px;">';
			summaryHtml += DiluxOneOffloadSync.i18n.enable_offloading;
			summaryHtml += '</button>';
			summaryHtml += '<button id="later-btn" class="button button-secondary" style="padding: 10px 30px; font-size: 16px;">';
			summaryHtml += DiluxOneOffloadSync.i18n.later;
			summaryHtml += '</button>';
		} else if (failed > 0) {
			// ⚠️ WITH ERRORS: Just accept and reload
			summaryHtml += '<button id="accept-errors-btn" class="button button-primary" style="padding: 10px 30px; font-size: 16px;">';
			summaryHtml += DiluxOneOffloadSync.i18n.accept;
			summaryHtml += '</button>';
		} else {
			// ❌ FAILED: Just close
			summaryHtml += '<button id="sync-complete-close-btn" class="button button-primary" style="padding: 10px 30px; font-size: 16px;">';
			summaryHtml += DiluxOneOffloadSync.i18n.close;
			summaryHtml += '</button>';
		}

		summaryHtml += '</div>';
		summaryHtml += '</div>';

		$('#sync-modal-summary').html(summaryHtml).show();

		// Mark as completed on server (sets state to SYNCED). The buttons
		// below reload the page, so they wait for this to land first.
		if (data.status === 'completed') {
			markSyncComplete = $.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'diluxone_offload_mark_sync_complete',
					nonce: diluxOneOffloadAdmin.nonce
				}
			});
		}
	}

	// The request that records a finished sync; resolved (or failed) before
	// any button that reloads the page is honoured.
	var markSyncComplete = null;

	function reloadAfterSyncComplete() {
		$.when(markSyncComplete).always(function() {
			window.location.reload();
		});
	}

	// ═══════════════════════════════════════════════════════
	// ⭐ GLOBAL Button Handlers - Must be at document.ready level
	// ═══════════════════════════════════════════════════════

	// "Enable Offloading" button - Works for BOTH modal and static page buttons
	$(document).on('click', '#enable-offloading-btn', function(e) {
		e.preventDefault();
		e.stopPropagation();
		const $btn = $(this);

		// The request that records the finished sync may still be in flight;
		// activation must not race it.
		if (markSyncComplete && markSyncComplete.state() === 'pending') {
			$.when(markSyncComplete).always(() => $btn.trigger('click'));
			return;
		}

		// ⭐ VALIDATION: Check for failed files before enabling offloading
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'diluxone_offload_get_failed_files_count',
				nonce: diluxOneOffloadAdmin.nonce
			},
			success: function(validationResponse) {
				if (validationResponse.success) {
					const failedCount = validationResponse.data.failed_count || 0;
					const pendingCount = validationResponse.data.pending_count || 0;

					// Block if there are failed or pending files
					if (failedCount > 0 || pendingCount > 0) {
						let errorMsg = DiluxOneOffloadSync.i18n.cannot_enable_offloading;
						if (failedCount > 0) {
							errorMsg += failedCount + ' ' + DiluxOneOffloadSync.i18n.failed_files;
							if (pendingCount > 0) errorMsg += ' ' + DiluxOneOffloadSync.i18n.and + ' ';
						}
						if (pendingCount > 0) {
							errorMsg += pendingCount + ' ' + DiluxOneOffloadSync.i18n.pending_files;
						}
						errorMsg += '. ' + DiluxOneOffloadSync.i18n.please_resolve_errors_first_using_clear;

						showNotification(errorMsg, 'error');
						return;
					}

					// Validation passed, proceed with offloading
					proceedWithOffloading($btn);
				} else {
					// Validation endpoint failed, proceed anyway (fail-open)
					console.warn('[DiluxOne Offload] Failed files validation failed, proceeding anyway');
					proceedWithOffloading($btn);
				}
			},
			error: function() {
				// Network error, proceed anyway (fail-open)
				console.warn('[DiluxOne Offload] Failed files validation error, proceeding anyway');
				proceedWithOffloading($btn);
			}
		});
	});

	// ⭐ Helper function to activate offloading (extracted for reuse)
	function proceedWithOffloading($btn) {
		// Check if confirmation is needed (static page button has data-confirm="true")
		if ($btn.data('confirm') === true) {
			// Show custom notification instead of ugly browser confirm
			showNotification(DiluxOneOffloadSync.i18n.enabling_cloud_storage_offloading, 'info');
		}

		// Disable button and show loading state
		$btn.prop('disabled', true);
		const originalHtml = $btn.html();
		$btn.html(DiluxOneOffloadSync.i18n.enabling);

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'diluxone_offload_activate_offloading',
				nonce: diluxOneOffloadAdmin.offloadingNonce
			},
			success: function(response) {
				if (response.success) {
					showNotification('✅ ' + DiluxOneOffloadSync.i18n.offloading_enabled_successfully, 'success');
					setTimeout(() => window.location.reload(), 1000);
				} else {
					showNotification('Error: ' + (response.data || 'Unknown error'), 'error');
					$btn.prop('disabled', false).html(originalHtml);
				}
			},
			error: function(xhr, status, error) {
				showNotification(DiluxOneOffloadSync.i18n.connection_error, 'error');
				$btn.prop('disabled', false).html(originalHtml);
			}
		});
	}

	// "Later" button (modal)
	$(document).on('click', '#later-btn', function() {
		reloadAfterSyncComplete();
	});

	// "Accept" button (modal with errors): the sync is complete, with failures
	// recorded, and the request that says so must land before the reload.
	$(document).on('click', '#accept-errors-btn', function() {
		reloadAfterSyncComplete();
	});

	// Close button (modal failure)
	$(document).on('click', '#sync-complete-close-btn', function() {
		reloadAfterSyncComplete();
	});

	// ⭐ NEW: Retry failed files button - Opens modal like regular sync
	$(document).on('click', '.retry-failed-btn', function() {
		const button = $(this);
		button.prop('disabled', true);

		// Show loading modal
		$('#sync-modal-content').hide();
		$('#sync-modal-summary').html('<div style="text-align: center; padding: 40px;"><span class="spinner is-active" style="float: none; margin: 0 auto;"></span><p style="margin-top: 20px;">Calculating failed files...</p></div>');
		$('#sync-modal').show();

		// Get stats for failed files only
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'diluxone_offload_calculate_sync',
				retry_failed: 1, // ⭐ NEW: Only count failed files
				nonce: diluxOneOffloadAdmin.nonce
			},
			success: function(response) {
				button.prop('disabled', false);

				if (response.success && response.data) {
					const data = response.data;

					// Build retry summary
					var summaryHtml = '<div class="sync-summary">';
					summaryHtml += '<h3 style="margin: 0 0 15px 0;">🔄 ' + DiluxOneOffloadSync.i18n.retry_failed_files + '</h3>';
					summaryHtml += '<div class="summary-stats" style="background: #fff3cd; padding: 15px; border-radius: 5px; margin-bottom: 20px; border-left: 4px solid #ffc107;">';

					// ⭐ Calculate total files to retry (old failed + new files found)
					var total_to_retry = data.pending_files + data.new_files;
					var total_size_to_retry = data.pending_size + data.new_files_size;

					summaryHtml += '<div style="margin-bottom: 8px; color: #856404;">';
					// ⭐ Create a helper function to format size in JavaScript
					function formatSize(bytes) {
						if (bytes === 0) return '0 B';
						var k = 1024;
						var sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
						var i = Math.floor(Math.log(bytes) / Math.log(k));
						return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i];
					}
					summaryHtml += '<strong>' + DiluxOneOffloadSync.i18n.failed_files_to_retry + '</strong> ' + total_to_retry.toLocaleString() + ' (' + formatSize(total_size_to_retry) + ')';
					summaryHtml += '</div>';

					// ⭐ Show breakdown if there are new files
					if (data.new_files > 0) {
						summaryHtml += '<div style="font-size: 13px; color: #666; margin-top: 8px; padding-top: 8px; border-top: 1px solid #e0e0e0;">';
						summaryHtml += '├ ' + DiluxOneOffloadSync.i18n.previously_failed + ' ' + data.pending_files.toLocaleString() + ' (' + data.pending_size_formatted + ')';
						summaryHtml += '<br>';
						summaryHtml += '└ ' + DiluxOneOffloadSync.i18n.new_files_found + ' ' + data.new_files.toLocaleString() + ' (' + data.new_files_size_formatted + ')';
						summaryHtml += '</div>';
					}

					summaryHtml += '</div>';

					// Performance Level Selector
					summaryHtml += '<div style="margin: 20px 0; padding: 15px; background: #e7f3ff; border-left: 4px solid #2196f3; border-radius: 4px;">';
					summaryHtml += '<label for="retry-concurrency-select" style="display: block; margin-bottom: 10px; font-weight: 600; color: #333;">';
					summaryHtml += '⚡ ' + DiluxOneOffloadSync.i18n.performance_level;
					summaryHtml += '</label>';
					summaryHtml += '<select id="retry-concurrency-select" class="regular-text" style="width: 100%; padding: 8px;">';
					summaryHtml += '<option value="5" selected>' + DiluxOneOffloadSync.i18n.balanced_5_parallel_recommended + '</option>';
					summaryHtml += '<option value="20">' + DiluxOneOffloadSync.i18n.fast_20_parallel_more_resources + '</option>';
					summaryHtml += '<option value="40">' + DiluxOneOffloadSync.i18n.intensive_40_parallel_maximum_speed + '</option>';
					summaryHtml += '</select>';
					summaryHtml += '<p class="description" style="margin-top: 8px; font-size: 12px; color: #666;">';
					summaryHtml += DiluxOneOffloadSync.i18n.higher_values_faster_upload_but_more;
					summaryHtml += '</p>';
					summaryHtml += '</div>';

					// Action buttons
					summaryHtml += '<div style="margin-top: 20px; display: flex; gap: 10px;">';
					summaryHtml += '<button id="retry-upload-btn" class="button button-primary" style="flex: 1;">';
					summaryHtml += '🔄 ' + DiluxOneOffloadSync.i18n.retry_upload;
					summaryHtml += '</button>';
					summaryHtml += '<button id="sync-modal-cancel" class="button" style="flex: 0;">' + DiluxOneOffloadSync.i18n.cancel + '</button>';
					summaryHtml += '</div>';
					summaryHtml += '</div>';

					$('#sync-modal-summary').html(summaryHtml);

					// Retry upload button handler
					$('#retry-upload-btn').on('click', function() {
						startSyncProcess(false, true); // fromScratch=false, retryFailed=true
					});
				} else {
					$('#sync-modal').hide();
					showNotification(DiluxOneOffloadSync.i18n.error_calculating_failed_files, 'error');
				}
			},
			error: function() {
				button.prop('disabled', false);
				$('#sync-modal').hide();
				showNotification(DiluxOneOffloadSync.i18n.connection_error, 'error');
			}
		});
	});


	// ⭐ NEW: Resync all files - Shows confirmation modal first
	$(document).on('click', '.resync-all-btn', function() {
		// Hide content modal, show summary
		$('#sync-modal-content').hide();

		// Show confirmation modal with improved UX/UI
		var confirmHtml = '<div class="sync-summary" style="padding: 20px;">';

		// Icon and title
		confirmHtml += '<div style="text-align: center; margin-bottom: 20px;">';
		confirmHtml += '<div style="font-size: 64px; margin-bottom: 15px;">⚠️</div>';
		confirmHtml += '<h2 style="margin: 0 0 10px 0; font-size: 24px; color: #d63638;">' + DiluxOneOffloadSync.i18n.confirm_complete_resync + '</h2>';
		confirmHtml += '</div>';

		// Warning message
		confirmHtml += '<div style="background: #fff3cd; padding: 20px; border-radius: 6px; margin-bottom: 25px; border-left: 4px solid #f0b849;">';
		confirmHtml += '<p style="margin: 0 0 15px 0; color: #856404; line-height: 1.6; font-size: 15px;">';
		confirmHtml += '<strong>' + DiluxOneOffloadSync.i18n.are_you_sure_you_want_to + '</strong>';
		confirmHtml += '</p>';
		confirmHtml += '<ul style="margin: 15px 0; padding-left: 20px; color: #856404; line-height: 1.8;">';
		confirmHtml += '<li>' + DiluxOneOffloadSync.i18n.all_sync_history_will_be_cleared + '</li>';
		confirmHtml += '<li>' + DiluxOneOffloadSync.i18n.files_will_be_scanned_from_scratch + '</li>';
		confirmHtml += '<li>' + DiluxOneOffloadSync.i18n.already_synced_files_will_be_detected + '</li>';
		confirmHtml += '</ul>';
		confirmHtml += '<p style="margin: 15px 0 0 0; color: #721c24; font-weight: 600; background: #f8d7da; padding: 12px; border-radius: 4px; border-left: 4px solid #d63638;">';
		confirmHtml += '⚠️ ' + DiluxOneOffloadSync.i18n.this_action_cannot_be_undone;
		confirmHtml += '</p>';
		confirmHtml += '</div>';

		// Action buttons
		confirmHtml += '<div style="margin-top: 25px; display: flex; gap: 10px; justify-content: center;">';
		confirmHtml += '<button id="resync-cancel-btn" class="button button-secondary" style="padding: 10px 30px; font-size: 15px;">' + DiluxOneOffloadSync.i18n.cancel + '</button>';
		confirmHtml += '<button id="resync-confirm-btn" class="button button-primary" style="padding: 10px 30px; font-size: 15px; background: #d63638; border-color: #d63638;">' + DiluxOneOffloadSync.i18n.yes_resync_all_files + '</button>';
		confirmHtml += '</div>';
		confirmHtml += '</div>';

		$('#sync-modal-summary').html(confirmHtml);
		$('#sync-modal').show();

		// Cancel button - close modal
		$('#resync-cancel-btn').on('click', function() {
			$('#sync-modal').hide();
		});

		// Confirm button - proceed with resync
		$('#resync-confirm-btn').on('click', function() {
			const $btn = $(this);
			$btn.prop('disabled', true).text(DiluxOneOffloadSync.i18n.processing);

			// Show loading state
			$('#sync-modal-summary').html('<div style="text-align: center; padding: 40px;"><span class="spinner is-active" style="float: none; margin: 0 auto;"></span><p style="margin-top: 20px;">' + DiluxOneOffloadSync.i18n.clearing_sync_data + '</p></div>');

			// Call backend to clear table and set state to CONFIGURED
			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'diluxone_offload_prepare_resync',
					nonce: diluxOneOffloadAdmin.nonce
				},
				success: function(response) {
					if (response.success) {
						// Show success message
						$('#sync-modal-summary').html('<div style="text-align: center; padding: 40px;"><div style="font-size: 64px; margin-bottom: 20px;">✅</div><h3 style="color: #46b450; margin: 0 0 15px 0;">' + DiluxOneOffloadSync.i18n.sync_data_cleared + '</h3><p style="color: #666;">' + DiluxOneOffloadSync.i18n.reloading_page + '</p></div>');

						// Reload page after 1 second to show CONFIGURED state with "Start Sync" button
						setTimeout(function() {
							location.reload();
						}, 1000);
					} else {
						$('#sync-modal').hide();
						showNotification(DiluxOneOffloadSync.i18n.error_preparing_resync, 'error');
					}
				},
				error: function() {
					$('#sync-modal').hide();
					showNotification(DiluxOneOffloadSync.i18n.connection_error, 'error');
				}
			});
		});
	});

	// View failed files button
	$(document).on('click', '.view-failed-btn', function() {
		$('#failed-files-modal').fadeIn(200);
	});

	// Close failed files modal - ONLY via close button (no backdrop click)
	$('#close-failed-modal').on('click', function(e) {
		$('#failed-files-modal').fadeOut(200);
	});

	// Clear failed files list
	$(document).on('click', '.clear-failed-btn', function() {
		if (confirm(DiluxOneOffloadSync.i18n.are_you_sure_you_want_to_2)) {
			const button = $(this);
			button.prop('disabled', true).text(DiluxOneOffloadSync.i18n.clearing);

			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'diluxone_offload_clear_failed',
					nonce: diluxOneOffloadAdmin.nonce
				},
				success: function(response) {
					if (response.success) {
						alert(response.data);
						window.location.reload();
					} else {
						alert('Error: ' + response.data);
						button.prop('disabled', false).text(DiluxOneOffloadSync.i18n.clear_list);
					}
				},
				error: function() {
					alert(DiluxOneOffloadSync.i18n.connection_error);
					button.prop('disabled', false).text(DiluxOneOffloadSync.i18n.clear_list);
				}
			});
		}
	});

	// ⭐ "Clear Failed & Enable" button - Open modal
	$(document).on('click', '#discard-and-enable-static-btn', function(e) {
		e.preventDefault();
		e.stopPropagation();
		$('#clear-and-enable-modal').show();
	});

	// Close Clear & Enable modal - ONLY via close button, NOT backdrop
	$('.close-clear-enable-modal').on('click', function() {
		$('#clear-and-enable-modal').hide();
		// Reset modal to initial view
		$('#clear-enable-confirm-view').show();
		$('#clear-enable-processing-view').hide();
		$('#clear-enable-success-view').hide();
		$('#clear-enable-error-view').hide();
	});

	// Confirm Clear & Enable action - ALL IN MODAL
	$('#confirm-clear-and-enable').on('click', function() {

		// Switch to processing view (stay in modal)
		$('#clear-enable-confirm-view').hide();
		$('#clear-enable-processing-view').show();

		// First discard failed files
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'diluxone_offload_discard_failed_files',
				nonce: diluxOneOffloadAdmin.nonce
			},
			success: function(response) {
				if (response.success) {

					// Then enable offloading
					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'diluxone_offload_activate_offloading',
							nonce: diluxOneOffloadAdmin.offloadingNonce
						},
						success: function(offloadingResponse) {
							if (offloadingResponse.success) {
								// Show success view in modal
								$('#clear-enable-processing-view').hide();
								$('#clear-enable-success-view').show();

								// Reload page after 2.5 seconds
								setTimeout(function() {
									window.location.reload();
								}, 2500);
							} else {
								// Show error view in modal
								$('#clear-enable-processing-view').hide();
								$('#clear-enable-error-message').text(DiluxOneOffloadSync.i18n.files_discarded_but_failed_to_enable);
								$('#clear-enable-error-view').show();
							}
						},
						error: function() {
							// Show error view in modal
							$('#clear-enable-processing-view').hide();
							$('#clear-enable-error-message').text(DiluxOneOffloadSync.i18n.connection_error_while_enabling_offloading);
							$('#clear-enable-error-view').show();
						}
					});
				} else {
					// Show error view in modal
					$('#clear-enable-processing-view').hide();
					$('#clear-enable-error-message').text(DiluxOneOffloadSync.i18n.failed_to_discard_files);
					$('#clear-enable-error-view').show();
				}
			},
			error: function() {
				// Show error view in modal
				$('#clear-enable-processing-view').hide();
				$('#clear-enable-error-message').text(DiluxOneOffloadSync.i18n.connection_error);
				$('#clear-enable-error-view').show();
			}
		});
	});

	// ⭐ "Cancel Sync & Reset" button - Open modal
	$(document).on('click', '#cancel-all-sync-btn', function(e) {
		e.preventDefault();
		e.stopPropagation();

		// ⭐ Show loading state immediately for better UX
		showLoadingState('Validating Action', 'Checking sync status...');

		// ⭐ NEW: Verify this tab has control BEFORE allowing cancel
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'diluxone_offload_get_sync_state',
				nonce: diluxOneOffloadAdmin.nonce,
				session_id: tabSessionId
			},
			success: function(response) {
				// Hide loading state first
				hideLoadingState();
				if (response.success) {
					const state = response.data.state;

					if (state === 'inactive') {
						// Another tab has control - show inactive tab UI instead
						showInactiveTabUI(response.data.sync_meta);
						startStateMonitoring();
						return;
					}

					// This tab has control or no sync active - safe to show cancel modal
					$('#cancel-sync-modal').show();
				} else {
					console.error('[DiluxOne Offload] Error checking sync state:', response);
					showNotice('Error checking sync state. Please refresh the page.', 'error');
				}
			},
			error: function(xhr, status, error) {
				// Hide loading state on error
				hideLoadingState();
				console.error('[DiluxOne Offload] AJAX error checking sync state:', error);
				showNotice('Connection error. Please refresh the page.', 'error');
			}
		});
	});

	// Close Cancel Sync modal - ONLY via close button, NOT backdrop
	$('.close-cancel-sync-modal').on('click', function() {
		$('#cancel-sync-modal').hide();
	});

	// Confirm Cancel Sync action
	$('#confirm-cancel-sync').on('click', function() {

		// ⭐ Show "Resetting..." state inside the modal (better UX)
		showLoadingState('Resetting Sync', 'Clearing sync data and resetting state...');

		// Call cancel_sync AJAX to clear DB and reset state
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'diluxone_offload_cancel_sync',
				nonce: diluxOneOffloadAdmin.nonce,
				session_id: tabSessionId // ⭐ Include session_id for validation
			},
			success: function(response) {
				// Hide the confirmation modal
				$('#cancel-sync-modal').hide();

				// ⭐ Check if validation failed
				if (!response.success && response.data && response.data.validation_failed) {
					console.error('[DiluxOne Offload] Reset blocked by validation:', response.data.reason);

					// Show error in main modal
					const errorHtml = '<div style="text-align: center; padding: 60px 20px;">' +
						'<div style="font-size: 60px; color: #dc3232; margin-bottom: 20px;">⚠️</div>' +
						'<h3 style="margin: 0 0 12px 0; color: #dc3232; font-size: 20px; font-weight: 600;">Cannot Reset</h3>' +
						'<p style="color: #666; font-size: 15px; margin: 0 0 20px 0;">' + (response.data.message || 'Another tab is currently syncing') + '</p>' +
						'<button class="button button-primary" onclick="jQuery(\'#sync-modal\').hide(); location.reload();">Refresh Page</button>' +
						'</div>';

					$('#sync-container').html(errorHtml).show();
					return;
				}

				if (response.success) {

					// Show success message in the main modal
					const successHtml = '<div style="text-align: center; padding: 60px 20px;">' +
						'<div style="font-size: 60px; color: #46b450; margin-bottom: 20px;">✓</div>' +
						'<h3 style="margin: 0 0 12px 0; color: #46b450; font-size: 20px; font-weight: 600;">Success!</h3>' +
						'<p style="color: #666; font-size: 15px; margin: 0;">' + (response.data.message || DiluxOneOffloadSync.i18n.sync_cancelled_and_reset_to_configured) + '</p>' +
						'<p style="color: #999; font-size: 13px; margin-top: 15px;">Refreshing page...</p>' +
						'</div>';

					$('#sync-container').html(successHtml).show();

					// Reload page after 1.5 seconds
					setTimeout(function() {
						window.location.reload();
					}, 1500);
				} else {
					console.error('[DiluxOne Offload] Failed to cancel sync:', response);

					// Show error in main modal
					const errorHtml = '<div style="text-align: center; padding: 60px 20px;">' +
						'<div style="font-size: 60px; color: #dc3232; margin-bottom: 20px;">✗</div>' +
						'<h3 style="margin: 0 0 12px 0; color: #dc3232; font-size: 20px; font-weight: 600;">Error</h3>' +
						'<p style="color: #666; font-size: 15px; margin: 0 0 20px 0;">' + DiluxOneOffloadSync.i18n.failed_to_cancel_sync + ': ' + (response.data.message || response.data || 'Unknown error') + '</p>' +
						'<button class="button button-primary" onclick="jQuery(\'#sync-modal\').hide();">Close</button>' +
						'</div>';

					$('#sync-container').html(errorHtml).show();
				}
			},
			error: function(xhr, status, error) {
				// Hide the confirmation modal
				$('#cancel-sync-modal').hide();

				console.error('[DiluxOne Offload] AJAX error cancelling sync:', error);

				// Show error in main modal
				const errorHtml = '<div style="text-align: center; padding: 60px 20px;">' +
					'<div style="font-size: 60px; color: #dc3232; margin-bottom: 20px;">✗</div>' +
					'<h3 style="margin: 0 0 12px 0; color: #dc3232; font-size: 20px; font-weight: 600;">Connection Error</h3>' +
					'<p style="color: #666; font-size: 15px; margin: 0 0 20px 0;">' + DiluxOneOffloadSync.i18n.connection_error_while_cancelling_sync + '</p>' +
					'<button class="button button-primary" onclick="jQuery(\'#sync-modal\').hide();">Close</button>' +
					'</div>';

				$('#sync-container').html(errorHtml).show();
			}
		});
	});

	// ══════════════════════════════════════════════════════════
	// ⭐ NEW: Delete Local Files (Dedicated Modal)
	// ══════════════════════════════════════════════════════════
	var isDeleteCancelled = false;
	var totalFilesToDelete = 0;
	var deletedFilesCount = 0;
	var failedFilesCount = 0;

	$('#delete-local-files-btn').on('click', function() {
		// Show modal with loading state
		$('#delete-modal-loading').show();
		$('#delete-modal-info').hide();
		$('#delete-modal-progress').hide();
		$('#delete-modal-start').hide();
		$('#delete-modal-summary').hide();
		$('#delete-modal-cancel').show(); // Reset Cancel button visibility
		$('#delete-modal').show();

		// Fetch deletable files stats
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'diluxone_offload_get_deletable_stats',
				nonce: diluxOneOffloadAdmin.nonce
			},
			success: function(response) {
				// Hide loading, show info
				$('#delete-modal-loading').hide();
				$('#delete-modal-info').show();
				$('#delete-modal-start').show();

				if (response.success && response.data) {
					$('#delete-modal-total-files').text((response.data.files || 0).toLocaleString());
					$('#delete-modal-total-size').text(response.data.size_formatted || '0 B');
					$('#delete-modal-start').prop('disabled', false);
					totalFilesToDelete = response.data.files || 0;
				} else {
					$('#delete-modal-total-files').text('0');
					$('#delete-modal-total-size').text('0 B');
					$('#delete-modal-start').prop('disabled', false);
				}
			},
			error: function() {
				$('#delete-modal-loading').hide();
				$('#delete-modal-info').show();
				$('#delete-modal-start').show();
				$('#delete-modal-total-files').text('Error');
				$('#delete-modal-total-size').text('Error');
				$('#delete-modal-start').prop('disabled', false);
			}
		});
	});

	// Handle delete modal cancel
	$('#delete-modal-cancel').on('click', function() {
		if ($('#delete-modal-progress').is(':visible')) {
			isDeleteCancelled = true;
		}
		$('#delete-modal').hide();
	});

	// Handle delete modal start
	$('#delete-modal-start').on('click', function() {
		// Hide info, show progress
		$('#delete-modal-info').hide();
		$('#delete-modal-progress').show();
		$('#delete-modal-start').hide();

		// Reset state
		isDeleteCancelled = false;
		deletedFilesCount = 0;
		failedFilesCount = 0;

		// Reset progress
		$('#delete-modal-progress-bar').css('width', '0%');
		$('#delete-modal-progress-text').text('0 / ' + totalFilesToDelete + ' (0%)');
		$('#delete-modal-progress-percent').text('0%');
		$('#delete-modal-stats-processed').text('0');
		$('#delete-modal-stats-successful').text('0');
		$('#delete-modal-stats-failed').text('0');

		processDeleteBatch();
	});

	// Process delete batch (recursion)
	function processDeleteBatch() {
		if (isDeleteCancelled) {
			return;
		}

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'diluxone_offload_process_delete_batch',
				nonce: diluxOneOffloadAdmin.nonce
			},
			success: function(response) {
				if (!response.success) {
					alert('Error: ' + ((response.data && response.data.message) || response.data || 'Unknown error'));
					$('#delete-modal').hide();
					return;
				}

				const data = response.data;

				// Update stats
				deletedFilesCount += data.deleted_this_batch || 0;
				failedFilesCount += data.failed_this_batch || 0;

				const processedTotal = deletedFilesCount + failedFilesCount;
				const percentage = totalFilesToDelete > 0 ? Math.round((processedTotal / totalFilesToDelete) * 100) : 0;

				// Update UI
				$('#delete-modal-progress-bar').css('width', percentage + '%');
				$('#delete-modal-progress-percent').text(percentage + '%');
				$('#delete-modal-progress-text').text(processedTotal + ' / ' + totalFilesToDelete + ' (' + percentage + '%)');
				$('#delete-modal-stats-processed').text(processedTotal.toLocaleString());
				$('#delete-modal-stats-successful').text(deletedFilesCount.toLocaleString());
				$('#delete-modal-stats-failed').text(failedFilesCount.toLocaleString());


				if (data.status === 'completed') {
					// Done! Show summary
					onDeleteComplete(deletedFilesCount, failedFilesCount);
				} else {
					// ⚡ Continue immediately (backend handles timing)
					processDeleteBatch();
				}
			},
			error: function(xhr, status, error) {
				console.error('[DiluxOne Offload Delete] Error:', error);
				alert(DiluxOneOffloadSync.i18n.connection_error_deletion_interrupted);
				$('#delete-modal').hide();
			}
		});
	}

	// ⭐ Delete completion handler
	function onDeleteComplete(successful, failed) {
		// Hide progress
		$('#delete-modal-progress').hide();

		const total = successful + failed;

		// Build completion summary
		let summaryHtml = '<div style="text-align: center; padding: 20px;">';

		if (failed === 0) {
			// ✅ All successful
			summaryHtml += '<div style="font-size: 64px; margin-bottom: 20px;">✅</div>';
			summaryHtml += '<h3 style="color: #46b450; margin: 0 0 10px 0;">' + DiluxOneOffloadSync.i18n.deletion_completed_successfully + '</h3>';
			summaryHtml += '<p style="font-size: 16px; color: #666; margin: 10px 0;">';
			summaryHtml += DiluxOneOffloadSync.i18n.all_local_files_have_been_deleted;
			summaryHtml += '</p>';
		} else {
			// ⚠️ Some failures
			summaryHtml += '<div style="font-size: 64px; margin-bottom: 20px;">⚠️</div>';
			summaryHtml += '<h3 style="color: #f0b849; margin: 0 0 10px 0;">' + DiluxOneOffloadSync.i18n.deletion_completed_with_errors + '</h3>';
			summaryHtml += '<p style="font-size: 16px; color: #666; margin: 10px 0;">';
			summaryHtml += DiluxOneOffloadSync.i18n.some_files_could_not_be_deleted;
			summaryHtml += '</p>';
		}

		// Stats
		summaryHtml += '<div style="background: #f5f5f5; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: left;">';
		summaryHtml += '<div style="margin-bottom: 10px;"><strong>' + DiluxOneOffloadSync.i18n.total_files + '</strong> ' + total.toLocaleString() + '</div>';
		summaryHtml += '<div style="margin-bottom: 10px; color: #46b450;"><strong>' + DiluxOneOffloadSync.i18n.deleted + '</strong> ' + successful.toLocaleString() + '</div>';
		if (failed > 0) {
			summaryHtml += '<div style="color: #d63638;"><strong>' + DiluxOneOffloadSync.i18n.failed + '</strong> ' + failed.toLocaleString() + '</div>';
		}
		summaryHtml += '</div>';

		// Accept button
		summaryHtml += '<div style="margin-top: 20px;">';
		summaryHtml += '<button id="delete-accept-btn" class="button button-primary" style="padding: 10px 30px; font-size: 16px;">';
		summaryHtml += DiluxOneOffloadSync.i18n.accept;
		summaryHtml += '</button>';
		summaryHtml += '</div>';
		summaryHtml += '</div>';

		$('#delete-modal-summary').html(summaryHtml).show();

		// Hide Cancel button when showing completion summary
		$('#delete-modal-cancel').hide();

		// Accept button handler
		$(document).on('click', '#delete-accept-btn', function() {
			window.location.reload();
		});
	}

	// ══════════════════════════════════════════════════════════
	// ⭐ NEW: Disconnect from Cloud Provider (Modern Modal)
	// ══════════════════════════════════════════════════════════
	$('#disconnect-from-cloud-btn').on('click', function() {
		// Show modern disconnect modal
		$('#disconnect-modal').show();
	});

	// Close Disconnect modal
	$('.close-disconnect-modal').on('click', function() {
		$('#disconnect-modal').hide();
		// Reset modal to initial view
		$('#disconnect-confirm-view').show();
		$('#disconnect-scanning-view').hide();
		$('#disconnect-options-view').hide();
		$('#disconnect-progress-view').hide();
		$('#disconnect-success-view').hide();
		$('#disconnect-error-view').hide();
	});

	// Confirm Disconnect - Start scanning
	$('#confirm-disconnect').on('click', function() {
		currentSyncMode = 'download';

		// Switch to scanning view
		$('#disconnect-confirm-view').hide();
		$('#disconnect-scanning-view').show();

		// ⭐ STEP 1: Scan remote files first

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'diluxone_offload_scan_remote',
				nonce: diluxOneOffloadAdmin.nonce
			},
			success: function(scanResponse) {
				if (scanResponse.success) {

					// ⭐ STEP 2: Calculate download requirements from DB
					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'diluxone_offload_calculate_download',
							nonce: diluxOneOffloadAdmin.nonce
						},
						success: function(response) {
							if (response.success && response.data) {
								const data = response.data;

								// ⭐ Skip directly to deactivate if nothing to download
								if (data.pending === 0) {

									// Update scanning view to show deactivation message
									$('#disconnect-scanning-view h3').text(DiluxOneOffloadSync.i18n.deactivating_offloading);
									$('#disconnect-scanning-view p').text(data.total_cloud.toLocaleString() + ' ' + DiluxOneOffloadSync.i18n.files_already_exist_locally);
									// Keep scanning view visible with spinner

									// ⭐ FIX: Call deactivate offloading endpoint BEFORE reload
									$.ajax({
										url: ajaxurl,
										type: 'POST',
										data: {
											action: 'diluxone_offload_deactivate_offloading',
											nonce: diluxOneOffloadAdmin.offloadingNonce
										},
										success: function(response) {

											// Hide scanning view and show success view
											$('#disconnect-scanning-view').hide();
											$('#disconnect-success-view').show();
											$('#disconnect-success-view p').text(data.total_cloud.toLocaleString() + ' files already exist locally. Offloading has been disabled.');

											// Auto-reload after 2.5 seconds
											setTimeout(function() {
												window.location.reload();
											}, 2500);
										},
										error: function() {
											// Hide scanning view and show error
											$('#disconnect-scanning-view').hide();
											$('#disconnect-error-message').text(DiluxOneOffloadSync.i18n.files_are_already_local_but_failed);
											$('#disconnect-error-view').show();
										}
									});
									return;
								}

								// Build stats HTML for options view
								var summaryHtml = '';
								summaryHtml += '<div style="display: flex; justify-content: space-between; margin-bottom: 10px;">';
								summaryHtml += '<span style="font-weight: 600;">📁 ' + DiluxOneOffloadSync.i18n.total_files_in_cloud + '</span>';
								summaryHtml += '<span>' + data.total_cloud.toLocaleString() + ' (' + data.total_size_formatted + ')</span>';
								summaryHtml += '</div>';

								if (data.already_local > 0) {
									summaryHtml += '<div style="display: flex; justify-content: space-between; margin-bottom: 10px; color: #46b450;">';
									summaryHtml += '<span style="font-weight: 600;">✅ ' + DiluxOneOffloadSync.i18n.already_local + '</span>';
									summaryHtml += '<span>' + data.already_local.toLocaleString() + ' (' + data.local_size_formatted + ')</span>';
									summaryHtml += '</div>';
								}

								summaryHtml += '<div style="display: flex; justify-content: space-between; color: #d63638;">';
								summaryHtml += '<span style="font-weight: 600;">⬇️ ' + DiluxOneOffloadSync.i18n.pending_download + '</span>';
								summaryHtml += '<span>' + data.pending.toLocaleString() + ' (' + data.pending_size_formatted + ')</span>';
								summaryHtml += '</div>';

								// ⭐ Performance Level Selector
								summaryHtml += '<div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">';
								summaryHtml += '<label for="download-concurrency-select" style="display: block; margin-bottom: 8px; font-weight: 600;">';
								summaryHtml += '⚡ ' + DiluxOneOffloadSync.i18n.performance_level;
								summaryHtml += '</label>';
								summaryHtml += '<select id="download-concurrency-select" class="regular-text" style="width: 100%; padding: 8px;">';
								summaryHtml += '<option value="5" selected>' + DiluxOneOffloadSync.i18n.balanced_5_parallel + '</option>';
								summaryHtml += '<option value="20">' + DiluxOneOffloadSync.i18n.fast_20_parallel + '</option>';
								summaryHtml += '<option value="40">' + DiluxOneOffloadSync.i18n.intensive_40_parallel + '</option>';
								summaryHtml += '</select>';
								summaryHtml += '</div>';

								// Show options view with stats
								$('#disconnect-stats').html(summaryHtml);
								$('#disconnect-scanning-view').hide();
								$('#disconnect-options-view').show();

								// Store data for start button
								$('#start-disconnect').data('downloadData', data);
							} else {
								// Show error view
								$('#disconnect-scanning-view').hide();
								$('#disconnect-error-message').text('Failed to calculate download requirements');
								$('#disconnect-error-view').show();
							}
						},
						error: function() {
							// Show error view
							$('#disconnect-scanning-view').hide();
							$('#disconnect-error-message').text('Connection error while calculating downloads');
							$('#disconnect-error-view').show();
						}
					});
				} else {
					// Scan failed - show error
					$('#disconnect-scanning-view').hide();
					$('#disconnect-error-message').text(scanResponse.data || 'Failed to scan cloud storage');
					$('#disconnect-error-view').show();
				}
			},
			error: function() {
				// Scan error - show error view
				$('#disconnect-scanning-view').hide();
				$('#disconnect-error-message').text('Connection error while scanning cloud storage');
				$('#disconnect-error-view').show();
			}
		});
	});

	// Force Disconnect - skip scan, deactivate offloading only (keep provider config)
	$('#force-disconnect-btn').on('click', function() {
		var $btn = $(this);
		$btn.prop('disabled', true).text(DiluxOneOffloadSync.i18n.disconnecting);

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'diluxone_offload_deactivate_offloading',
				nonce: diluxOneOffloadAdmin.offloadingNonce
			},
			success: function(response) {
				if (response.success) {
					$btn.text(DiluxOneOffloadSync.i18n.disconnected_reloading);
					setTimeout(function() { window.location.reload(); }, 1500);
				} else {
					$btn.prop('disabled', false).text(DiluxOneOffloadSync.i18n.force_disconnect_without_sync);
					alert(response.data || DiluxOneOffloadSync.i18n.failed_to_disconnect);
				}
			},
			error: function() {
				$btn.prop('disabled', false).text(DiluxOneOffloadSync.i18n.force_disconnect_without_sync);
				alert(DiluxOneOffloadSync.i18n.connection_error);
			}
		});
	});

	// Start Download button handler (REVERSE SYNC)
	$('#start-disconnect').on('click', function() {
		const concurrency = parseInt($('#download-concurrency-select').val()) || 5;
		const data = $(this).data('downloadData');


		// Switch to progress view
		$('#disconnect-options-view').hide();
		$('#disconnect-progress-view').show();

		// Start reverse sync AJAX (correct endpoint)
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'diluxone_offload_start_reverse_sync',
				nonce: diluxOneOffloadAdmin.nonce,
				concurrency: concurrency,
				mode: 'continue'
			},
			success: function(response) {
				if (response.success) {
					// Start processing batches
					processReverseBatch();
				} else {
					$('#disconnect-progress-view').hide();
					$('#disconnect-error-message').text('Failed to start download: ' + response.data);
					$('#disconnect-error-view').show();
				}
			},
			error: function() {
				$('#disconnect-progress-view').hide();
				$('#disconnect-error-message').text('Connection error while starting download');
				$('#disconnect-error-view').show();
			}
		});
	});

	// Process reverse sync batches
	function processReverseBatch() {
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'diluxone_offload_process_reverse_batch',
				nonce: diluxOneOffloadAdmin.nonce
			},
			success: function(response) {
				if (response.success && response.data) {
					const data = response.data;

					// Update progress bar
					const totalFiles = data.total_files || 1;
					// ⭐ FIX: Backend returns 'processed_files' not 'downloaded'
					const downloaded = data.processed_files || data.successful_downloads || data.downloaded || 0;
					// ⭐ FIX: Read both possible names (pending_files for SYNC, remaining_files for DISCONNECT)
					const remaining = data.pending_files || data.remaining_files || 0;
					const percent = Math.round((downloaded / totalFiles) * 100);

					// ⭐ Update progress bar and percentage
					$('#disconnect-progress-bar').css('width', percent + '%');
					$('#disconnect-progress-percent').text(percent + '%');
					$('#disconnect-progress-text').text(downloaded + ' / ' + totalFiles + ' files (' + percent + '%)');

					// Update statistics (same shape as the sync modal).
					$('#disconnect-stats-downloaded').text(downloaded.toLocaleString());
					$('#disconnect-stats-successful').text(downloaded.toLocaleString());
					$('#disconnect-stats-remaining').text(remaining.toLocaleString());


					// ⭐ FIX: Improved validation - ONLY complete if status === 'completed'
					// Don't rely solely on remaining === 0 to prevent premature completion
					if (data.status === 'completed') {
						onDisconnectComplete(downloaded, data.failed || 0, data.skipped || 0);
					} else if (data.status !== 'processing' && remaining === 0) {
						// Fallback: If status is not 'processing' and no files remaining, also complete
						onDisconnectComplete(downloaded, data.failed || 0, data.skipped || 0);
					} else {
						// Continue with next batch
						setTimeout(processReverseBatch, 100);
					}
				} else {
					$('#disconnect-progress-view').hide();
					$('#disconnect-error-message').text('Download failed: ' + (response.data || 'Unknown error'));
					$('#disconnect-error-view').show();
				}
			},
			error: function() {
				$('#disconnect-progress-view').hide();
				$('#disconnect-error-message').text('Connection error during download');
				$('#disconnect-error-view').show();
			}
		});
	}

	// Cancel Download button (same behaviour as the sync modal: no alert).
	$('#cancel-disconnect').on('click', function() {

		// Change button state to "Cancelling..."
		$('#disconnect-progress-label').text(DiluxOneOffloadSync.i18n.cancelling_download);
		$('#cancel-disconnect').prop('disabled', true).css('opacity', '0.5');

		// Reload page (esto cancela el polling automáticamente)
		setTimeout(function() {
			window.location.reload();
		}, 500);
	});

	// ⭐ Disconnect completion handler
	function onDisconnectComplete(successful, failed, skipped) {
		// Hide progress
		$('#disconnect-progress-view').hide();

		const total = successful + failed + skipped;

		// Check if there were failures
		if (failed > 0 || skipped > 0) {
			// ⚠️ Show summary with stats (don't disconnect, don't reload)
			let summaryHtml = '<div style="text-align: center; padding: 20px;">';
			summaryHtml += '<div style="font-size: 64px; margin-bottom: 20px;">⚠️</div>';
			summaryHtml += '<h3 style="color: #f0b849; margin: 0 0 10px 0;">' + DiluxOneOffloadSync.i18n.download_completed_with_errors + '</h3>';
			summaryHtml += '<p style="font-size: 16px; color: #666; margin: 10px 0;">';
			summaryHtml += DiluxOneOffloadSync.i18n.some_files_could_not_be_downloaded;
			summaryHtml += '</p>';

			// Stats
			summaryHtml += '<div style="background: #f5f5f5; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: left;">';
			summaryHtml += '<div style="margin-bottom: 10px;"><strong>' + DiluxOneOffloadSync.i18n.total_files + '</strong> ' + total.toLocaleString() + '</div>';
			summaryHtml += '<div style="margin-bottom: 10px; color: #46b450;"><strong>' + DiluxOneOffloadSync.i18n.downloaded + '</strong> ' + successful.toLocaleString() + '</div>';
			if (failed > 0) {
				summaryHtml += '<div style="margin-bottom: 10px; color: #d63638;"><strong>' + DiluxOneOffloadSync.i18n.failed + '</strong> ' + failed.toLocaleString() + '</div>';
			}
			if (skipped > 0) {
				summaryHtml += '<div style="color: #f0b849;"><strong>' + DiluxOneOffloadSync.i18n.skipped + '</strong> ' + skipped.toLocaleString() + '</div>';
			}
			summaryHtml += '</div>';

			// Close button
			summaryHtml += '<div style="margin-top: 20px;">';
			summaryHtml += '<button class="button button-primary close-disconnect-modal" style="padding: 10px 30px; font-size: 16px;">';
			summaryHtml += DiluxOneOffloadSync.i18n.close;
			summaryHtml += '</button>';
			summaryHtml += '</div>';
			summaryHtml += '</div>';

			// Replace modal content with summary
			$('.diluxone-offload-modal-content', '#disconnect-modal').html(summaryHtml);
		} else {
			// ✅ All successful - disconnect offloading and show success

			// Call disable offloading endpoint
			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'diluxone_offload_deactivate_offloading',
					nonce: diluxOneOffloadAdmin.offloadingNonce
				},
				success: function(response) {

					// Show success view
					$('#disconnect-success-view').show();

					// Auto-reload after 2.5 seconds
					setTimeout(function() {
						window.location.reload();
					}, 2500);
				},
				error: function() {
					// If disable fails, show error
					$('#disconnect-error-message').text(DiluxOneOffloadSync.i18n.files_downloaded_but_failed_to_disable);
					$('#disconnect-error-view').show();
				}
			});
		}
	}

	// ══════════════════════════════════════════════════════════
	// DEV MODE: Skip Sync Buttons
	// ══════════════════════════════════════════════════════════

	$('#dev-enable-without-sync-btn').on('click', function() {
		var $btn = $(this);

		if (!confirm(DiluxOneOffloadSync.i18n.dev_mode_enable_offloading_without_syncing)) {
			return;
		}

		$btn.prop('disabled', true);
		var originalHtml = $btn.html();
		$btn.html(DiluxOneOffloadSync.i18n.enabling);

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'diluxone_offload_dev_enable_without_sync',
				nonce: diluxOneOffloadAdmin.nonce
			},
			success: function(response) {
				if (response.success) {
					showNotification(DiluxOneOffloadSync.i18n.dev_mode_offloading_enabled_without_sync, 'success');
					setTimeout(function() { window.location.reload(); }, 1000);
				} else {
					showNotification('Error: ' + (response.data || 'Unknown error'), 'error');
					$btn.prop('disabled', false).html(originalHtml);
				}
			},
			error: function() {
				showNotification(DiluxOneOffloadSync.i18n.connection_error, 'error');
				$btn.prop('disabled', false).html(originalHtml);
			}
		});
	});

	$('#dev-disconnect-without-sync-btn').on('click', function() {
		var $btn = $(this);

		if (!confirm(DiluxOneOffloadSync.i18n.dev_mode_disconnect_without_downloading_files)) {
			return;
		}

		$btn.prop('disabled', true);
		var originalHtml = $btn.html();
		$btn.html(DiluxOneOffloadSync.i18n.disconnecting);

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'diluxone_offload_dev_disconnect_without_sync',
				nonce: diluxOneOffloadAdmin.nonce
			},
			success: function(response) {
				if (response.success) {
					showNotification(DiluxOneOffloadSync.i18n.dev_mode_offloading_disabled_without_sync, 'success');
					setTimeout(function() { window.location.reload(); }, 1000);
				} else {
					showNotification('Error: ' + (response.data || 'Unknown error'), 'error');
					$btn.prop('disabled', false).html(originalHtml);
				}
			},
			error: function() {
				showNotification(DiluxOneOffloadSync.i18n.connection_error, 'error');
				$btn.prop('disabled', false).html(originalHtml);
			}
		});
	});

	// Auto-start sync if coming from cloud-provider tab CTA
	var urlParams = new URLSearchParams(window.location.search);
	if (urlParams.get('auto-start') === '1' && $('#start-sync-btn').length && !$('#start-sync-btn').prop('disabled')) {
		// Clean URL to prevent re-trigger on refresh
		var cleanUrl = DiluxOneOffloadSync.data.urls.sync;
		window.history.replaceState({}, '', cleanUrl);
		// Trigger sync start after UI is ready
		setTimeout(function() {
			$('#start-sync-btn').trigger('click');
		}, 500);
	}
});
