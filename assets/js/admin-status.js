/**
 * DiluxOne Offload — Status › Health: "Check now".
 *
 * Runs a connection check regardless of the five-minute cache and rewrites
 * the health table from the answer; the rest of the screen reloads on the
 * next visit.
 */
jQuery(document).ready(function($) {
	var $button = $('#check-health-now');
	if (!$button.length) {
		return;
	}

	$button.on('click', function() {
		var i18n = DiluxOneOffloadStatus.i18n;
		$button.prop('disabled', true).text(i18n.checking);

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: { action: 'diluxone_offload_check_health', nonce: diluxOneOffloadAdmin.nonce },
			success: function(response) {
				$button.prop('disabled', false).text(i18n.check_now);
				if (!response.success) {
					$('#health-status').text((response.data && response.data.message) || i18n.request_failed);
					return;
				}
				var h = response.data;
				var pill = $('<span class="diluxone-offload-pill"></span>');
				if (h.status === 'healthy') {
					pill.addClass('diluxone-offload-pill--active').text(i18n.healthy);
				} else {
					pill.addClass('diluxone-offload-pill--pending').text(i18n.unhealthy.replace('%s', h.reason || h.error_code));
				}
				$('#health-status').empty().append(pill);
				$('#health-last-check').html('<strong></strong>').find('strong').text(i18n.just_now);
				if (h.status === 'healthy') {
					$('#health-last-success').html('<strong></strong>').find('strong').text(i18n.just_now);
				}
				$('#health-failures').find('strong').text(String(h.consecutive_failures));
			},
			error: function() {
				$button.prop('disabled', false).text(i18n.check_now);
				$('#health-status').text(i18n.request_failed);
			}
		});
	});
});
