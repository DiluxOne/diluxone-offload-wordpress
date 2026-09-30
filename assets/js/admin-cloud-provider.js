jQuery(document).ready(function($) {
	// ========================================================================
	// Helper: Get current provider
	// ========================================================================
	function getCurrentProvider() {
		var $select = $('#cloud_provider');
		return $select.length ? $select.val() : DiluxOneOffloadProvider.data.config_cloud_provider;
	}

	// ========================================================================
	// Test Connection (for NOT_CONFIGURED state only)
	// ========================================================================
	$(document).on('click', '.test-connection-btn', function() {
		var $button = $(this);
		var $section = $button.closest('.provider-config');
		var $result = $section.find('.connection-result');
		var provider = getCurrentProvider();

		var data = {
			action: 'diluxone_offload_test_connection',
			nonce: diluxOneOffloadAdmin.nonce,
			provider: provider
		};

		// Every named field of the chosen provider's section; the server
		// reads only the ones that provider posts.
		var missing = false;
		$section.find(':input[name]').each(function() {
			data[this.name] = $(this).is(':checkbox') ? ($(this).is(':checked') ? $(this).val() : '') : $(this).val();
			if ($(this).is('[data-required]') && !data[this.name]) {
				missing = true;
			}
		});
		if (missing) {
			$result.html('<div class="notice notice-error inline"><p><strong>' + DiluxOneOffloadProvider.i18n.connection_failed + '</strong><br>' + DiluxOneOffloadProvider.i18n.please_fill_in_all_required_fields + '</p></div>').show();
			return;
		}

		$button.prop('disabled', true);
		$button.html('<span class="spinner is-active diluxone-offload-button-spinner"></span>' + DiluxOneOffloadProvider.i18n.testing);
		$result.empty();

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: data,
			success: function(response) {
				$button.prop('disabled', false);
				$button.html('<span class="dashicons dashicons-admin-links"></span>' + DiluxOneOffloadProvider.i18n.test_connection);
				if (response.success) {
					$result.html('<div class="notice notice-success inline"><p><strong>' + DiluxOneOffloadProvider.i18n.connection_successful + '</strong><br>' + (response.data.message || '') + '</p></div>').show();
					$('#submit').prop('disabled', false);
					$section.find('.test-status-message').hide();
				} else {
					$result.html('<div class="notice notice-error inline"><p><strong>' + DiluxOneOffloadProvider.i18n.connection_failed + '</strong><br>' + (response.data.message || '') + '</p></div>').show();
					$('#submit').prop('disabled', true);
					$section.find('.test-status-message').show();
				}
			},
			error: function(xhr, status, error) {
				$button.prop('disabled', false);
				$button.html('<span class="dashicons dashicons-admin-links"></span>' + DiluxOneOffloadProvider.i18n.test_connection);
				$result.html('<div class="notice notice-error inline"><p><strong>' + DiluxOneOffloadProvider.i18n.connection_failed + '</strong><br>' + $('<div>').text(error).html() + '</p></div>').show();
				$('#submit').prop('disabled', true);
				$section.find('.test-status-message').show();
			}
		});
	});

	// ========================================================================
	// Form validation (NOT_CONFIGURED state only)
	// ========================================================================
	$('form').on('submit', function(e) {
		var provider = getCurrentProvider();
		if (provider === 'azure') {
			var accountName = $('#account_name').val();
			var containerName = $('#container_name').val();
			if (accountName && !/^[a-z0-9]{3,24}$/.test(accountName)) {
				alert(DiluxOneOffloadProvider.i18n.storage_account_name_must_be_3);
				e.preventDefault();
				return false;
			}
			if (containerName && !/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/.test(containerName)) {
				alert(DiluxOneOffloadProvider.i18n.container_name_must_contain_only_lowercase);
				e.preventDefault();
				return false;
			}
		}
	});

	// ========================================================================
	// S3-compatible: the service fills in Endpoint and Public URL
	// ========================================================================
	// A field the user typed in keeps its value when region or bucket change
	// (data-edited); a new service re-derives both, because another
	// service's address is never right.
	var s3Presets = (DiluxOneOffloadProvider.data && DiluxOneOffloadProvider.data.s3_presets) || {};

	function s3Fill(pattern) {
		return (pattern || '').replace('{region}', $('#s3_region').val() || '').replace('{bucket}', $('#s3_bucket').val() || '');
	}

	function s3Derive() {
		var preset = s3Presets[$('#s3_preset').val()];
		if (!preset) {
			return;
		}
		$('#s3_endpoint, #s3_public_url').each(function() {
			if ($(this).attr('data-edited') !== '1') {
				$(this).val(s3Fill(this.id === 's3_endpoint' ? preset.endpoint : preset.public_url));
			}
		});
		s3HttpWarning();
	}

	function s3HttpWarning() {
		var preset = s3Presets[$('#s3_preset').val()];
		var http = /^http:\/\//i.test($('#s3_endpoint').val() || '');
		$('.diluxone-offload-s3-http-warning').prop('hidden', !(http && preset && preset.http));
	}

	$('#s3_preset').on('change', function() {
		var preset = s3Presets[$(this).val()];
		if (!preset) {
			return;
		}
		$('.diluxone-offload-s3-hints [data-preset]').each(function() {
			$(this).prop('hidden', $(this).data('preset') !== $('#s3_preset').val());
		});
		$('#s3_region').val(preset.region).prop('readonly', !!preset.region_fixed);
		// Advanced: the ACL only where the service honours one, the
		// addressing style editable only under Custom.
		$('.diluxone-offload-s3-acl-row').prop('hidden', !preset.acl);
		$('#s3_object_acl').prop('checked', false).prop('disabled', !preset.acl);
		$('#s3_path_style').val(preset.path_style ? 'path' : 'virtual').prop('disabled', $(this).val() !== 'custom');
		$('#s3_endpoint, #s3_public_url').removeAttr('data-edited');
		s3Derive();
	});

	$('#s3_region, #s3_bucket').on('input change', s3Derive);

	$('#s3_endpoint, #s3_public_url').on('input', function() {
		$(this).attr('data-edited', '1');
		s3HttpWarning();
	});

	if ($('#s3_preset').length) {
		$('#s3_preset').trigger('change');
	}

	// ========================================================================
	// Remove Provider Modal
	// ========================================================================
	$('#remove-provider').on('click', function() {
		$('#remove-provider-modal').show();
	});

	$('.cancel-remove, #remove-provider-modal .diluxone-offload-modal-overlay').on('click', function() {
		$('#remove-provider-modal').hide();
	});

	$('#confirm-delete-provider').on('click', function() {
		var $button = $(this);
		var $buttonText = $button.find('.button-text');
		var $spinner = $button.find('.spinner');

		$button.prop('disabled', true);
		$buttonText.text(DiluxOneOffloadProvider.i18n.deleting_configuration);
		$spinner.addClass('is-active').prop('hidden', false);

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'diluxone_offload_ajax_remove_provider',
				nonce: diluxOneOffloadAdmin.nonce
			},
			success: function(response) {
				if (response.success) {
					// The provider is gone: the Connection tab shows the form again.
					window.location.href = DiluxOneOffloadProvider.data.urls.connection;
				} else {
					alert(DiluxOneOffloadProvider.i18n.error_deleting_configuration + ' ' + ((response.data && response.data.message) || ''));
					$button.prop('disabled', false);
					$buttonText.text(DiluxOneOffloadProvider.i18n.yes_delete_configuration);
					$spinner.removeClass('is-active').prop('hidden', true);
				}
			},
			error: function(xhr, status, error) {
				alert(DiluxOneOffloadProvider.i18n.error_deleting_configuration + ' ' + error);
				$button.prop('disabled', false);
				$buttonText.text(DiluxOneOffloadProvider.i18n.yes_delete_configuration);
				$spinner.removeClass('is-active').prop('hidden', true);
			}
		});
	});

	// ========================================================================
	// Credentials tab: test the new key, then save it
	// ========================================================================
	$('#show_new_account_key').on('change', function() {
		$('#provider-credentials input[data-secret]').attr('type', $(this).is(':checked') ? 'text' : 'password');
	});

	$('#provider-credentials :input[data-field]').on('input change', function() {
		// A key that changed after a test has not been tested.
		$('#save-new-credentials').prop('disabled', true);
	});

	// The saved fields shown on the tab plus the new secret, each under the
	// name the provider posts (data-field).
	function newCredentials() {
		var data = {
			nonce: diluxOneOffloadAdmin.nonce,
			provider: getCurrentProvider()
		};
		$('#provider-credentials [data-field]').each(function() {
			var value = $(this).attr('data-value');
			data[$(this).data('field')] = $(this).is(':input') ? $(this).val() : (value !== undefined ? value : $(this).text().trim());
		});
		return data;
	}

	$('#test-new-credentials').on('click', function() {
		var $button = $(this);
		var $result = $('#new-credentials-result');
		var data = $.extend({ action: 'diluxone_offload_test_connection' }, newCredentials());

		var missing = $('#provider-credentials :input[data-field][data-required]').filter(function() {
			return !$(this).val();
		}).length > 0;
		if (missing) {
			$result.html('<div class="notice notice-error inline"><p>' + DiluxOneOffloadProvider.i18n.please_enter_the_new_access_key + '</p></div>');
			return;
		}

		$button.prop('disabled', true);
		$button.html('<span class="spinner is-active diluxone-offload-button-spinner"></span>' + DiluxOneOffloadProvider.i18n.testing);
		$result.empty();

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: data,
			success: function(response) {
				$button.prop('disabled', false);
				$button.html('<span class="dashicons dashicons-admin-links"></span>' + DiluxOneOffloadProvider.i18n.test_connection);
				if (response.success) {
					$result.html('<div class="notice notice-success inline"><p><strong>' + DiluxOneOffloadProvider.i18n.connection_successful + '</strong><br>' + (response.data.message || '') + '</div>');
					$('#save-new-credentials').prop('disabled', false);
				} else {
					$result.html('<div class="notice notice-error inline"><p><strong>' + DiluxOneOffloadProvider.i18n.connection_failed + '</strong><br>' + (response.data.message || '') + '</div>');
					$('#save-new-credentials').prop('disabled', true);
				}
			},
			error: function(xhr, status, error) {
				$button.prop('disabled', false);
				$button.html('<span class="dashicons dashicons-admin-links"></span>' + DiluxOneOffloadProvider.i18n.test_connection);
				$result.html('<div class="notice notice-error inline"><p>' + $('<div>').text(error).html() + '</p></div>');
				$('#save-new-credentials').prop('disabled', true);
			}
		});
	});

	$('#save-new-credentials').on('click', function() {
		var $button = $(this);
		var data = $.extend({ action: 'diluxone_offload_save_updated_credentials' }, newCredentials());

		$button.prop('disabled', true);
		$button.html('<span class="spinner is-active diluxone-offload-button-spinner"></span>' + DiluxOneOffloadProvider.i18n.saving);

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: data,
			success: function(response) {
				if (response.success) {
					// The server queued the confirmation notice; the reload shows it.
					window.location.href = DiluxOneOffloadProvider.data.urls.credentials;
				} else {
					alert(DiluxOneOffloadProvider.i18n.error_saving_credentials + ' ' + ((response.data && response.data.message) || ''));
					$button.prop('disabled', false);
					$button.html(DiluxOneOffloadProvider.i18n.save);
				}
			},
			error: function(xhr, status, error) {
				alert(DiluxOneOffloadProvider.i18n.error_saving_credentials + ' ' + error);
				$button.prop('disabled', false);
				$button.html(DiluxOneOffloadProvider.i18n.save);
			}
		});
	});

});

// Provider config toggle (NOT_CONFIGURED state only)
function showProviderConfig(provider) {
	var configs = document.querySelectorAll('.provider-config');
	configs.forEach(function(el) {
		el.hidden = true;
		// Remove required from hidden fields to prevent browser validation errors
		el.querySelectorAll('[required]').forEach(function(input) {
			input.removeAttribute('required');
		});
	});
	if (provider) {
		var selected = document.getElementById(provider + '-config');
		if (selected) {
			selected.hidden = false;
			// Restore required on the visible fields that need it
			selected.querySelectorAll('[data-required]').forEach(function(input) {
				input.setAttribute('required', '');
			});
		}
	}
}

document.addEventListener('DOMContentLoaded', function() {
	var select = document.getElementById('cloud_provider');
	if (!select) return;
	showProviderConfig(select.value);
	select.addEventListener('change', function() {
		showProviderConfig(this.value);
	});
});
