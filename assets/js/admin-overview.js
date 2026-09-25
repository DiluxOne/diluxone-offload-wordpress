jQuery(document).ready(function($) {
	function formatBytes(bytes) {
		if (!bytes || bytes === 0) return '0 B';
		var k = 1024;
		var sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
		var i = Math.floor(Math.log(bytes) / Math.log(k));
		return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
	}

	// Replace the numbers with an error state that says what actually failed.
	// A 503 or a timeout is not a credentials problem; the health banner is
	// the one place that diagnoses the connection, this only reports it.
	function showStatsError(message) {
		var i18n = DiluxOneOffloadOverview.i18n;
		var $bars = $('<div class="diluxone-offload-overview-bars"><div class="diluxone-offload-bar-section"><div class="diluxone-offload-bar-header"><span class="diluxone-offload-bar-title"></span><span class="diluxone-offload-bar-value" style="color: #d63638; font-weight: 600;"></span></div></div></div>');
		$bars.find('.diluxone-offload-bar-title').text(i18n.storage);
		$bars.find('.diluxone-offload-bar-value').text(i18n.not_available);

		var $files = $('<div class="diluxone-offload-files-section"><div class="diluxone-offload-files-grid"><div class="diluxone-offload-files-count"><span class="diluxone-offload-stat-label"></span><div class="diluxone-offload-stat-value" style="color: #d63638; font-size: 16px;"></div></div></div></div>');
		$files.find('.diluxone-offload-stat-label').text(i18n.total_files);
		$files.find('.diluxone-offload-stat-value').text(i18n.not_available);

		var $why = $('<p class="description" style="color: #d63638; margin-top: 10px;"></p>').text(message);

		$('#stats-content').empty().append($bars, $files, $why);
	}

	$('#refresh-stats-btn').on('click', function() {
		var $button = $(this);
		var $wrap = $('.diluxone-offload-stats-wrap');

		$button.prop('disabled', true);
		$button.find('.dashicons').addClass('spin');
		// Blur the panel and show the overlay on top of it. Hiding the panel
		// instead would collapse the card to the height of a spinner and make
		// the whole page jump twice per refresh.
		$wrap.addClass('diluxone-offload-loading').attr('aria-busy', 'true');
		$('#stats-loading').show();

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'diluxone_offload_refresh_stats',
				nonce: diluxOneOffloadAdmin.nonce
			},
			timeout: 30000,
			success: function(response) {
				if (response.success) {
					var d = response.data;
					$('#stat-file-count').text(parseInt(d.fileCount || 0).toLocaleString());

					// Hide pie chart if no files
					if (parseInt(d.fileCount || 0) === 0) {
						$('#stat-pie-section').hide();
					}

					$('#stat-storage-detail').text(formatBytes(d.storageUsedBytes));

					// Update pie chart
					if (d.filesByType) {
						var ft = d.filesByType;
						var total = (ft.images || 0) + (ft.videos || 0) + (ft.audio || 0) + (ft.other || 0);
						if (total > 0) {
							var pImages = ((ft.images || 0) / total * 100).toFixed(1);
							var pVideos = ((ft.videos || 0) / total * 100).toFixed(1);
							var pAudio = ((ft.audio || 0) / total * 100).toFixed(1);
							var pOther = (100 - pImages - pVideos - pAudio).toFixed(1);
							var s1 = parseFloat(pImages);
							var s2 = s1 + parseFloat(pVideos);
							var s3 = s2 + parseFloat(pAudio);
							var $pie = $('#stat-pie-section');
							if ($pie.length) {
								$pie.find('.diluxone-offload-pie').css('background', 'conic-gradient(#2271b1 0% ' + s1 + '%, #d63638 ' + s1 + '% ' + s2 + '%, #dba617 ' + s2 + '% ' + s3 + '%, #8c8f94 ' + s3 + '% 100%)');
								var $legends = $pie.find('.diluxone-offload-legend-item');
								var labels = [
									DiluxOneOffloadOverview.i18n.images,
									DiluxOneOffloadOverview.i18n.videos,
									DiluxOneOffloadOverview.i18n.audio,
									DiluxOneOffloadOverview.i18n.other
								];
								var counts = [ft.images || 0, ft.videos || 0, ft.audio || 0, ft.other || 0];
								var pcts = [pImages, pVideos, pAudio, pOther];
								$legends.each(function(i) {
									var $dot = $(this).find('.diluxone-offload-legend-dot').clone();
									$(this).empty().append($dot).append(document.createTextNode(' ' + labels[i] + ' ' + parseInt(counts[i]).toLocaleString() + ' (' + pcts[i] + '%)'));
								});
							} else {
								// Pie chart section doesn't exist yet — build it
								var pieHtml = '<div class="diluxone-offload-pie-container" id="stat-pie-section">';
								pieHtml += '<div class="diluxone-offload-pie" style="background: conic-gradient(#2271b1 0% ' + s1 + '%, #d63638 ' + s1 + '% ' + s2 + '%, #dba617 ' + s2 + '% ' + s3 + '%, #8c8f94 ' + s3 + '% 100%);"></div>';
								pieHtml += '<div class="diluxone-offload-pie-legend">';
								var colors = ['#2271b1', '#d63638', '#dba617', '#8c8f94'];
								var labels2 = [
									DiluxOneOffloadOverview.i18n.images,
									DiluxOneOffloadOverview.i18n.videos,
									DiluxOneOffloadOverview.i18n.audio,
									DiluxOneOffloadOverview.i18n.other
								];
								var counts2 = [ft.images || 0, ft.videos || 0, ft.audio || 0, ft.other || 0];
								var pcts2 = [pImages, pVideos, pAudio, pOther];
								for (var i = 0; i < 4; i++) {
									pieHtml += '<div class="diluxone-offload-legend-item"><span class="diluxone-offload-legend-dot" style="background: ' + colors[i] + ';"></span> ' + labels2[i] + ' ' + parseInt(counts2[i]).toLocaleString() + ' (' + pcts2[i] + '%)</div>';
								}
								pieHtml += '</div></div>';
								$('.diluxone-offload-files-count').after(pieHtml);
							}
						}
					}

					$('#stat-last-updated').text(DiluxOneOffloadOverview.i18n.last_updated + ' ' + DiluxOneOffloadOverview.i18n.just_now);
				} else {
					showStatsError((response.data && response.data.message) || 'Unknown error');
				}
			},
			error: function(xhr, status, error) {
				showStatsError(status === 'timeout' ? DiluxOneOffloadOverview.i18n.request_timed_out_try_again_later : 'Network error: ' + error);
			},
			complete: function() {
				$button.prop('disabled', false);
				$button.find('.dashicons').removeClass('spin');
				$('#stats-loading').hide();
				$wrap.removeClass('diluxone-offload-loading').attr('aria-busy', 'false');
			}
		});
	});

	// Cold cache: the server rendered the panel blurred instead of blocking on a
	// container listing, so ask for the numbers now that the page is on screen.
	// This drives the Refresh button's own handler rather than repeating it —
	// one implementation, so the pie chart behaves identically on first paint
	// and on every refresh after it.
	if ($('.diluxone-offload-loading').length) {
		$('#refresh-stats-btn').trigger('click');
	}
});
