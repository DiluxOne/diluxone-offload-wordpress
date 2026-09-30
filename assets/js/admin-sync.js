jQuery(document).ready(function($) {

	// The markup of the sync's panels is built from WordPress' classes and the
	// plugin's (admin-sync.css): no inline styles; every word from T.
	const T = DiluxOneOffloadSync.i18n;

	function esc(value) {
		return $('<div>').text(value === undefined || value === null ? '' : String(value)).html();
	}

	// A translated string with its %s / %1$s placeholders filled, as sprintf() does.
	function fmt(template) {
		const args = Array.prototype.slice.call(arguments, 1);
		let next = 0;
		return String(template).replace(/%(?:(\d+)\$)?s/g, function(match, position) {
			const value = position ? args[position - 1] : args[next++];
			return value === undefined ? '' : String(value);
		});
	}

	function waitingHtml(title, message) {
		return '<div class="diluxone-offload-waiting"><div class="spinner is-active"></div>' +
			'<h3>' + esc(title) + '</h3><p>' + esc(message) + '</p></div>';
	}

	// Label and value rows; a row's third item marks it is-ok, is-warn or is-failed.
	function kvHtml(rows) {
		return '<dl class="diluxone-offload-kv">' + rows.map(function(row) {
			return '<div' + (row[2] ? ' class="' + row[2] + '"' : '') + '><dt>' + esc(row[0]) + '</dt><dd>' + esc(row[1]) + '</dd></div>';
		}).join('') + '</dl>';
	}

	// How a run ended: ok, warn or failed, with its icon, title and line.
	function outcomeHtml(kind, title, text) {
		const icon = { ok: 'dashicons-yes-alt', warn: 'dashicons-warning', failed: 'dashicons-dismiss' }[kind];
		return '<div class="diluxone-offload-outcome diluxone-offload-outcome--' + kind + '">' +
			'<span class="dashicons ' + icon + '"></span><h3>' + esc(title) + '</h3>' +
			(text ? '<p>' + esc(text) + '</p>' : '') + '</div>';
	}

	function concurrencyChoiceHtml(id, label, options, description) {
		return '<div class="diluxone-offload-choice"><label for="' + id + '">' + esc(label) + '</label>' +
			'<select id="' + id + '">' + options.map(function(o, i) {
				return '<option value="' + o[0] + '"' + (i === 0 ? ' selected' : '') + '>' + esc(o[1]) + '</option>';
			}).join('') + '</select>' +
			(description ? '<p class="description">' + esc(description) + '</p>' : '') + '</div>';
	}

	// Buttons in built panels that close the modal or reload the page.
	$(document).on('click', '.diluxone-offload-modal-close', function() {
		$(this).closest('.diluxone-offload-modal').hide();
	});
	$(document).on('click', '.diluxone-offload-reload', function() {
		location.reload();
	});

	// One place for a message above the cards: a WordPress notice of its type.
	function notify(message, type) {
		const $notification = $('#diluxone-offload-notification');
		$notification
			.removeClass('notice-success notice-error notice-warning notice-info')
			.addClass('notice-' + (type || 'info'))
			.html('<p>' + message + '</p>')
			.show();
		if (type === 'success' || type === 'info' || !type) {
			setTimeout(function() { $notification.fadeOut(); }, 5000);
		}
	}

	// ⭐ FIX: Use event delegation for Cancel button to work with dynamically created content
	$(document).on('click', '#sync-modal-cancel', function() {

		if ($('#sync-modal-progress').is(':visible')) {
			// Cancel ongoing sync
			isSyncCancelled = true;

			// Show cancelling message
			$('#sync-modal-progress-label').text(DiluxOneOffloadSync.i18n.cancelling_sync);
			$('#sync-modal-cancel').prop('disabled', true);

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

	function showNotification(message, type = 'info') {
		notify(message, type);
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

	// A waiting state in the modal (the sync's calculation, a check of its state).
	function showLoadingState(title = T.analyzing_sync_status, message = T.checking_for_active_synchronization) {
		$('#sync-modal').show();
		$('#sync-modal-content').hide();
		$('#sync-modal-summary').hide();
		$('#sync-modal-progress').hide();
		$('#sync-container').html(waitingHtml(title, message)).show();
	}

	// Hide loading state modal
	function hideLoadingState() {
		$('#sync-modal').hide();
		$('#sync-container').empty();
	}

	function showNotice(message, type = 'info') {
		notify(message, type);
	}

	// Run initial check on page load (always, for multi-tab coordination)
	// The spinner inside checkInitialSyncState() will only show if pluginState === 'syncing'
	checkInitialSyncState();

	// ⭐ REFACTORED: Start sync process with DOUBLE VALIDATION
	// Called when user clicks Start/Continue/Retry sync buttons
	function startSyncProcess(fromScratch, retryFailed) {
		retryFailed = retryFailed || false;


		// Show loading state with unified look & feel
		showLoadingState(T.validating_action, T.calculating_files_to_sync);

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
					showNotice(esc(fmt(T.error_with_reason, response.data || T.unknown_error)), 'error');
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
				showNotice(esc(T.unexpected_response), 'error');
			},
			error: function(xhr, status, error) {
				console.error('[DiluxOne Offload Sync] AJAX error on pre-check:', error);
				$('#sync-modal').hide();
				showNotice(esc(T.connection_error_try_again), 'error');
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

		const rows = [
			[T.total_files, data.total_files.toLocaleString() + ' (' + data.total_size_formatted + ')'],
			[T.already_uploaded, data.synced_files.toLocaleString() + ' (' + data.synced_size_formatted + ')', 'is-ok']
		];
		if (data.new_files > 0) {
			rows.push([T.new_files, data.new_files.toLocaleString() + ' (' + data.new_files_size_formatted + ')', 'is-warn']);
		}
		if (data.pending_files > 0) {
			rows.push([T.pending, data.pending_files.toLocaleString() + ' (' + data.pending_size_formatted + ')', 'is-warn']);
		}

		let summaryHtml = '<h2><span class="dashicons dashicons-cloud-upload"></span>' + esc(T.upload_summary) + '</h2>';
		summaryHtml += kvHtml(rows);
		summaryHtml += concurrencyChoiceHtml('upload-concurrency-select', T.upload_performance, [
			[5, T.balanced_5_parallel], [20, T.fast_20_parallel], [40, T.intensive_40_parallel]
		]);

		// The way on: continue, complete, or start from scratch.
		const continueDisabled = (data.synced_files === 0 || data.pending_files === 0);
		const scratch = '<button id="scratch-upload-btn" class="button button-large"><span class="dashicons dashicons-update"></span>' + esc(T.upload_from_scratch) + '</button>';
		summaryHtml += '<div class="diluxone-offload-buttons diluxone-offload-buttons--fill">';
		if (!continueDisabled) {
			summaryHtml += '<button id="continue-upload-btn" class="button button-primary button-large"><span class="dashicons dashicons-controls-play"></span>' + esc(T.continue_upload) + '</button>' + scratch;
		} else if (data.pending_files === 0 && data.synced_files > 0) {
			summaryHtml += '<button id="continue-upload-btn" class="button button-primary button-large"><span class="dashicons dashicons-yes-alt"></span>' + esc(T.scan_and_complete_sync) + '</button>' + scratch;
		} else {
			summaryHtml += scratch.replace('class="button button-large"', 'class="button button-primary button-large"');
		}
		summaryHtml += '</div>';
		summaryHtml += '<div class="diluxone-offload-modal__footer"><button id="close-sync-options-btn" class="button">' + esc(T.cancel) + '</button></div>';

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
		$('#sync-modal-progress-bar').css('width', '0%').parent().attr('aria-valuenow', 0);
		$('#sync-modal-progress-text').text('0 / 0 (0%)');
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
					showNotice(esc(fmt(T.error_with_reason, response.data || T.unknown_error)), 'error');
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
					showNotice(esc(T.unexpected_response), 'error');
				}
			},
			error: function(xhr, status, error) {
				console.error('[DiluxOne Offload Sync] AJAX error on execution:', error);
				$('#sync-modal').hide();
				showNotice(esc(T.connection_error_try_again), 'error');
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
				showNotice(esc(T.sync_already_active), 'warning');
				setTimeout(() => location.reload(), 2000);
				break;

			case 'state_conflict':
				console.warn('[DiluxOne Offload Validation] Plugin state conflict');
				$('#sync-modal').hide();
				showNotice(esc(T.state_conflict_refreshing), 'warning');
				setTimeout(() => location.reload(), 1000);
				break;

			case 'files_not_synced':
				const stats = details;
				const failedCount = stats.failed_count || 0;
				const pendingCount = stats.pending_count || 0;
				$('#sync-modal').hide();
				showNotice(esc(fmt(T.cannot_proceed_failed_pending, failedCount.toLocaleString(), pendingCount.toLocaleString())), 'error');
				break;

			default:
				console.error('[DiluxOne Offload Validation] Unknown error:', reason);
				$('#sync-modal').hide();
				showNotice(esc(fmt(T.operation_not_allowed, reason)), 'error');
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
					const errorMsg = response.data?.message || response.data || T.unexpected_response;
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
				label.text(fmt(T.connection_error_retrying, backoff / 1000, retryCount, maxRetries));

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

						showNotice(esc(T.session_expired_reloading), 'warning');

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

		let inactiveHtml = outcomeHtml('warn', T.sync_active_in_another_tab, T.another_tab_is_processing);
		inactiveHtml += kvHtml([[T.progress, fmt(T.n_of_total_files, processed.toLocaleString(), total.toLocaleString()) + ' (' + percentage.toFixed(1) + '%)']]);
		inactiveHtml += '<div class="diluxone-offload-buttons"><button id="continue-here-btn" class="button button-primary button-large"><span class="dashicons dashicons-controls-play"></span>' + esc(T.continue_here) + '</button></div>';
		inactiveHtml += '<p class="description diluxone-offload-progress-line">' + esc(T.moves_sync_to_this_tab) + '</p>';

		$('#sync-container').html(inactiveHtml).show();

		// Attach event handler
		$('#continue-here-btn').on('click', function() {
			takeControl();
		});
	}

	function takeControl() {

		// ⭐ FIX: Show "Transferring control..." loading state
		const transferringHtml = waitingHtml(T.transferring_control, T.taking_over_sync);

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
					notify(esc(T.failed_to_take_control + ' ' + (response.data || T.unknown_error)), 'error');
					// Restore inactive UI
					$('#sync-container').empty();
					showInactiveTabUI(response.data.sync_meta || {});
				}
			},
			error: function(xhr, status, error) {
				notify(esc(T.connection_error_taking_control), 'error');
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
		$('#sync-modal-progress-bar').css('width', percentage + '%').parent().attr('aria-valuenow', Math.round(percentage));
		$('#sync-modal-progress-text').text(fmt(T.n_of_total_files, processed.toLocaleString(), total.toLocaleString()) + ' (' + Math.round(percentage) + '%)');
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

		// ⭐ FIXED LOGIC: Consider successful if we have successful uploads OR if total equals successful
		const isSuccess = (data.status === 'completed' && failed === 0) || (successful > 0 && failed === 0) || (total > 0 && successful === total);

		let summaryHtml;
		if (isSuccess) {
			summaryHtml = outcomeHtml('ok', T.sync_completed_successfully, T.all_files_have_been_synced_to);
		} else if (failed > 0) {
			summaryHtml = outcomeHtml('warn', T.sync_completed_with_errors, T.some_files_could_not_be_synced);
		} else {
			summaryHtml = outcomeHtml('failed', T.sync_failed, data.message || T.unknown_error);
		}

		const rows = [[T.total_files, total.toLocaleString()], [T.successful, successful.toLocaleString(), 'is-ok']];
		if (failed > 0) {
			rows.push([T.failed, failed.toLocaleString(), 'is-failed']);
		}
		summaryHtml += kvHtml(rows);

		summaryHtml += '<div class="diluxone-offload-buttons">';
		if (isSuccess) {
			summaryHtml += '<button id="enable-offloading-btn" class="button button-primary button-large">' + esc(T.enable_offloading) + '</button>';
			summaryHtml += '<button id="later-btn" class="button button-large">' + esc(T.later) + '</button>';
		} else if (failed > 0) {
			summaryHtml += '<button id="accept-errors-btn" class="button button-primary button-large">' + esc(T.accept) + '</button>';
		} else {
			summaryHtml += '<button id="sync-complete-close-btn" class="button button-primary button-large">' + esc(T.close) + '</button>';
		}
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
					showNotification(esc(T.offloading_enabled_successfully), 'success');
					setTimeout(() => window.location.reload(), 1000);
				} else {
					showNotification(esc(fmt(T.error_with_reason, response.data || T.unknown_error)), 'error');
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
		$('#sync-modal-summary').html(waitingHtml(T.retry_failed_files, T.calculating_failed_files)).show();
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

					// What a retry takes: the failed files, and new ones the scan found.
					function formatSize(bytes) {
						if (bytes === 0) return '0 B';
						var k = 1024;
						var sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
						var i = Math.floor(Math.log(bytes) / Math.log(k));
						return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i];
					}
					const totalToRetry = data.pending_files + data.new_files;
					const rows = [[T.failed_files_to_retry, totalToRetry.toLocaleString() + ' (' + formatSize(data.pending_size + data.new_files_size) + ')', 'is-warn']];
					if (data.new_files > 0) {
						rows.push([T.previously_failed, data.pending_files.toLocaleString() + ' (' + data.pending_size_formatted + ')']);
						rows.push([T.new_files_found, data.new_files.toLocaleString() + ' (' + data.new_files_size_formatted + ')']);
					}

					let summaryHtml = '<h2><span class="dashicons dashicons-update"></span>' + esc(T.retry_failed_files) + '</h2>';
					summaryHtml += kvHtml(rows);
					summaryHtml += concurrencyChoiceHtml('retry-concurrency-select', T.performance_level, [
						[5, T.balanced_5_parallel_recommended], [20, T.fast_20_parallel_more_resources], [40, T.intensive_40_parallel_maximum_speed]
					], T.higher_values_faster_upload_but_more);
					summaryHtml += '<div class="diluxone-offload-modal__footer">';
					summaryHtml += '<button id="sync-modal-cancel" class="button">' + esc(T.cancel) + '</button>';
					summaryHtml += '<button id="retry-upload-btn" class="button button-primary"><span class="dashicons dashicons-update"></span>' + esc(T.retry_upload) + '</button>';
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

		// The confirmation of a resync from scratch.
		let confirmHtml = '<h2><span class="dashicons dashicons-warning"></span>' + esc(T.confirm_complete_resync) + '</h2>';
		confirmHtml += '<div class="notice notice-warning inline"><p><strong>' + esc(T.are_you_sure_you_want_to) + '</strong></p>';
		confirmHtml += '<ul class="ul-disc"><li>' + esc(T.all_sync_history_will_be_cleared) + '</li><li>' + esc(T.files_will_be_scanned_from_scratch) + '</li><li>' + esc(T.already_synced_files_will_be_detected) + '</li></ul>';
		confirmHtml += '<p><strong>' + esc(T.this_action_cannot_be_undone) + '</strong></p></div>';
		confirmHtml += '<div class="diluxone-offload-modal__footer">';
		confirmHtml += '<button id="resync-cancel-btn" class="button">' + esc(T.cancel) + '</button>';
		confirmHtml += '<button id="resync-confirm-btn" class="button button-primary diluxone-offload-button-danger">' + esc(T.yes_resync_all_files) + '</button>';
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
			$('#sync-modal-summary').html(waitingHtml(T.processing, T.clearing_sync_data));

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
						$('#sync-modal-summary').html(outcomeHtml('ok', T.sync_data_cleared, T.reloading_page));

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
						notify(esc(response.data), 'success');
						setTimeout(function() { window.location.reload(); }, 1000);
					} else {
						notify(esc(fmt(T.error_with_reason, response.data)), 'error');
						button.prop('disabled', false).text(DiluxOneOffloadSync.i18n.clear_list);
					}
				},
				error: function() {
					notify(esc(T.connection_error), 'error');
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
		showLoadingState(T.validating_action, T.checking_sync_status);

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
					showNotice(esc(T.error_checking_sync_state), 'error');
				}
			},
			error: function(xhr, status, error) {
				// Hide loading state on error
				hideLoadingState();
				console.error('[DiluxOne Offload] AJAX error checking sync state:', error);
				showNotice(esc(T.connection_error_refresh), 'error');
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
		showLoadingState(T.resetting_sync, T.clearing_and_resetting);

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
					const errorHtml = outcomeHtml('warn', T.cannot_reset, response.data.message || T.another_tab_syncing) +
						'<div class="diluxone-offload-buttons"><button class="button button-primary diluxone-offload-reload">' + esc(T.refresh_page) + '</button></div>';

					$('#sync-container').html(errorHtml).show();
					return;
				}

				if (response.success) {

					// Show success message in the main modal
					const successHtml = outcomeHtml('ok', response.data.message || T.sync_cancelled_and_reset_to_configured, T.reloading_page);

					$('#sync-container').html(successHtml).show();

					// Reload page after 1.5 seconds
					setTimeout(function() {
						window.location.reload();
					}, 1500);
				} else {
					console.error('[DiluxOne Offload] Failed to cancel sync:', response);

					// Show error in main modal
					const errorHtml = outcomeHtml('failed', T.failed_to_cancel_sync, response.data.message || response.data || T.unknown_error) +
						'<div class="diluxone-offload-buttons"><button class="button button-primary diluxone-offload-modal-close">' + esc(T.close) + '</button></div>';

					$('#sync-container').html(errorHtml).show();
				}
			},
			error: function(xhr, status, error) {
				// Hide the confirmation modal
				$('#cancel-sync-modal').hide();

				console.error('[DiluxOne Offload] AJAX error cancelling sync:', error);

				// Show error in main modal
				const errorHtml = outcomeHtml('failed', T.connection_error, T.connection_error_while_cancelling_sync) +
					'<div class="diluxone-offload-buttons"><button class="button button-primary diluxone-offload-modal-close">' + esc(T.close) + '</button></div>';

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
				$('#delete-modal-total-files').text(T.error);
				$('#delete-modal-total-size').text(T.error);
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
		$('#delete-modal-progress-bar').css('width', '0%').parent().attr('aria-valuenow', 0);
		$('#delete-modal-progress-text').text('0 / ' + totalFilesToDelete + ' (0%)');
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
					showNotification(esc(fmt(T.error_with_reason, (response.data && response.data.message) || response.data || T.unknown_error)), 'error');
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
				$('#delete-modal-progress-bar').css('width', percentage + '%').parent().attr('aria-valuenow', percentage);
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
				$('#delete-modal').hide();
				notify(esc(T.connection_error_deletion_interrupted), 'error');
			}
		});
	}

	// ⭐ Delete completion handler
	function onDeleteComplete(successful, failed) {
		// Hide progress
		$('#delete-modal-progress').hide();

		const total = successful + failed;

		let summaryHtml = failed === 0
			? outcomeHtml('ok', T.deletion_completed_successfully, T.all_local_files_have_been_deleted)
			: outcomeHtml('warn', T.deletion_completed_with_errors, T.some_files_could_not_be_deleted);
		const rows = [[T.total_files, total.toLocaleString()], [T.deleted, successful.toLocaleString(), 'is-ok']];
		if (failed > 0) {
			rows.push([T.failed, failed.toLocaleString(), 'is-failed']);
		}
		summaryHtml += kvHtml(rows);
		summaryHtml += '<div class="diluxone-offload-buttons"><button id="delete-accept-btn" class="button button-primary button-large">' + esc(T.accept) + '</button></div>';

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

								// What the download will do, and how many at once.
								const rows = [[T.total_files_in_cloud, data.total_cloud.toLocaleString() + ' (' + data.total_size_formatted + ')']];
								if (data.already_local > 0) {
									rows.push([T.already_local, data.already_local.toLocaleString() + ' (' + data.local_size_formatted + ')', 'is-ok']);
								}
								rows.push([T.pending_download, data.pending.toLocaleString() + ' (' + data.pending_size_formatted + ')', 'is-warn']);
								const summaryHtml = kvHtml(rows) + concurrencyChoiceHtml('download-concurrency-select', T.performance_level, [
									[5, T.balanced_5_parallel], [20, T.fast_20_parallel], [40, T.intensive_40_parallel]
								]);

								// Show options view with stats
								$('#disconnect-stats').html(summaryHtml);
								$('#disconnect-scanning-view').hide();
								$('#disconnect-options-view').show();

								// Store data for start button
								$('#start-disconnect').data('downloadData', data);
							} else {
								// Show error view
								$('#disconnect-scanning-view').hide();
								$('#disconnect-error-message').text(T.failed_to_calculate_downloads);
								$('#disconnect-error-view').show();
							}
						},
						error: function() {
							// Show error view
							$('#disconnect-scanning-view').hide();
							$('#disconnect-error-message').text(T.connection_error_calculating_downloads);
							$('#disconnect-error-view').show();
						}
					});
				} else {
					// Scan failed - show error
					$('#disconnect-scanning-view').hide();
					$('#disconnect-error-message').text(scanResponse.data || T.failed_to_scan_cloud);
					$('#disconnect-error-view').show();
				}
			},
			error: function() {
				// Scan error - show error view
				$('#disconnect-scanning-view').hide();
				$('#disconnect-error-message').text(T.connection_error_scanning_cloud);
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
					$('#disconnect-error-message').text(response.data || T.failed_to_disconnect);
				}
			},
			error: function() {
				$btn.prop('disabled', false).text(DiluxOneOffloadSync.i18n.force_disconnect_without_sync);
				$('#disconnect-error-message').text(T.connection_error);
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
					$('#disconnect-error-message').text(fmt(T.failed_to_start_download, response.data));
					$('#disconnect-error-view').show();
				}
			},
			error: function() {
				$('#disconnect-progress-view').hide();
				$('#disconnect-error-message').text(T.connection_error_starting_download);
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
					$('#disconnect-progress-bar').css('width', percent + '%').parent().attr('aria-valuenow', percent);
					$('#disconnect-progress-text').text(fmt(T.n_of_total_files, downloaded.toLocaleString(), totalFiles.toLocaleString()) + ' (' + percent + '%)');

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
					$('#disconnect-error-message').text(fmt(T.download_failed, response.data || T.unknown_error));
					$('#disconnect-error-view').show();
				}
			},
			error: function() {
				$('#disconnect-progress-view').hide();
				$('#disconnect-error-message').text(T.connection_error_during_download);
				$('#disconnect-error-view').show();
			}
		});
	}

	// Cancel Download button (same behaviour as the sync modal: no alert).
	$('#cancel-disconnect').on('click', function() {

		// Change button state to "Cancelling..."
		$('#disconnect-progress-label').text(DiluxOneOffloadSync.i18n.cancelling_download);
		$('#cancel-disconnect').prop('disabled', true);

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
			// Some files did not come back: say so, and keep offloading on.
			let summaryHtml = outcomeHtml('warn', T.download_completed_with_errors, T.some_files_could_not_be_downloaded);
			const rows = [[T.total_files, total.toLocaleString()], [T.downloaded, successful.toLocaleString(), 'is-ok']];
			if (failed > 0) {
				rows.push([T.failed, failed.toLocaleString(), 'is-failed']);
			}
			if (skipped > 0) {
				rows.push([T.skipped, skipped.toLocaleString(), 'is-warn']);
			}
			summaryHtml += kvHtml(rows);
			summaryHtml += '<div class="diluxone-offload-buttons"><button class="button button-primary close-disconnect-modal">' + esc(T.close) + '</button></div>';

			// Replace modal content with summary
			$('.diluxone-offload-modal__dialog', '#disconnect-modal').html(summaryHtml);
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
					showNotification(esc(fmt(T.error_with_reason, response.data || T.unknown_error)), 'error');
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
					showNotification(esc(fmt(T.error_with_reason, response.data || T.unknown_error)), 'error');
					$btn.prop('disabled', false).html(originalHtml);
				}
			},
			error: function() {
				showNotification(DiluxOneOffloadSync.i18n.connection_error, 'error');
				$btn.prop('disabled', false).html(originalHtml);
			}
		});
	});

	// ========================================================================
	// Before the first sync: what the container or bucket already holds
	// ========================================================================
	// The callout exists only on the first Start Sync. The script lists this
	// site's prefix (diluxone_offload_inspect_target); when something is
	// there, Start Sync waits until the owner continues with it, empties it
	// (typing the name) or goes to use another one. Resolves true when the
	// sync may start right away.
	var targetChecked = $.Deferred();
	var $target = $('#diluxone-offload-target');

	function targetText(pattern, values) {
		var i = 0;
		return pattern.replace(/%(\d)\$s|%s/g, function(m, n) {
			return String(values[n ? parseInt(n, 10) - 1 : i++]);
		});
	}

	function targetStatus(text) {
		$('#diluxone-offload-target-status').text(text);
	}

	function releaseStart() {
		$('#start-sync-btn').prop('disabled', false);
	}

	// The listing failed: only the reason shows, and the sync may start.
	function listFailed(reason) {
		$target.prop('hidden', false).find('.diluxone-offload-target-actions, .description').not('#diluxone-offload-target-status').prop('hidden', true);
		targetStatus(targetText(DiluxOneOffloadSync.i18n.target_list_failed, [reason]));
		releaseStart();
		targetChecked.resolve(false);
	}

	if ($target.length) {
		var i18n = DiluxOneOffloadSync.i18n;
		var found = 0;
		$('#start-sync-btn').prop('disabled', true);
		$.post(ajaxurl, { action: 'diluxone_offload_inspect_target', nonce: diluxOneOffloadAdmin.nonce })
			.done(function(response) {
				if (!response.success) {
					listFailed(response.data || '');
					return;
				}
				var d = response.data;
				if (!d.files) {
					releaseStart();
					targetChecked.resolve(true);
					return;
				}
				found = d.files;
				$('#diluxone-offload-target-found').text(targetText(d.files === 1 ? i18n.target_found_one : i18n.target_found_many, [d.files, d.size, d.prefix, d.target]));
				$target.prop('hidden', false);
				targetChecked.resolve(false);
			})
			.fail(function() {
				listFailed('HTTP');
			});

		$('#diluxone-offload-target-continue').on('click', function() {
			$target.prop('hidden', true);
			releaseStart();
		});

		$('#diluxone-offload-target-empty-open').on('click', function() {
			$('#diluxone-offload-target-empty').prop('hidden', false);
			$('#diluxone-offload-target-confirm').trigger('focus');
		});

		$('#diluxone-offload-target-confirm').on('input', function() {
			$('#diluxone-offload-target-empty-go').prop('disabled', $(this).val() === '');
		});

		$('#diluxone-offload-target-empty-go').on('click', function() {
			var $go = $(this);
			var typed = $('#diluxone-offload-target-confirm').val();
			var deleted = 0;
			$go.prop('disabled', true);
			$('#diluxone-offload-target-continue, #diluxone-offload-target-empty-open').prop('disabled', true);

			// Each round resumes the listing where the one before stopped.
			(function round(marker) {
				$.post(ajaxurl, { action: 'diluxone_offload_empty_target', nonce: diluxOneOffloadAdmin.nonce, confirm: typed, marker: marker })
					.done(function(response) {
						if (!response.success) {
							targetStatus(response.data || i18n.target_request_failed);
							$go.prop('disabled', false);
							$('#diluxone-offload-target-continue, #diluxone-offload-target-empty-open').prop('disabled', false);
							return;
						}
						var r = response.data;
						deleted += r.deleted;
						if (r.failed > 0) {
							targetStatus(targetText(i18n.target_empty_failed, [deleted, r.failed, (r.errors && r.errors[0]) || '']));
							$go.prop('disabled', false);
							$('#diluxone-offload-target-continue, #diluxone-offload-target-empty-open').prop('disabled', false);
							return;
						}
						if (!r.done) {
							targetStatus(targetText(i18n.target_emptying, [Math.max(0, found - deleted)]));
							round(r.next);
							return;
						}
						$('#diluxone-offload-target-found').text('');
						$target.find('.diluxone-offload-target-actions, .description, #diluxone-offload-target-empty').not('#diluxone-offload-target-status').prop('hidden', true);
						targetStatus(targetText(i18n.target_emptied, [deleted]));
						releaseStart();
					})
					.fail(function() {
						targetStatus(i18n.target_request_failed);
						$go.prop('disabled', false);
						$('#diluxone-offload-target-continue, #diluxone-offload-target-empty-open').prop('disabled', false);
					});
			})('');
		});
	} else {
		targetChecked.resolve(true);
	}

	// Auto-start sync if coming from cloud-provider tab CTA, once the target
	// check said nothing is in the way; otherwise the owner decides first.
	var urlParams = new URLSearchParams(window.location.search);
	if (urlParams.get('auto-start') === '1' && $('#start-sync-btn').length) {
		// Clean URL to prevent re-trigger on refresh
		var cleanUrl = DiluxOneOffloadSync.data.urls.sync;
		window.history.replaceState({}, '', cleanUrl);
		targetChecked.done(function(clear) {
			if (!clear || $('#start-sync-btn').prop('disabled')) {
				return;
			}
			// Trigger sync start after UI is ready
			setTimeout(function() {
				$('#start-sync-btn').trigger('click');
			}, 500);
		});
	}
});
