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

		data.account_name = $('#account_name').val();
		data.account_key = $('#account_key').val();
		data.container_name = $('#container_name').val();
		if (!data.account_name || !data.account_key || !data.container_name) {
			$result.html('<div style="padding: 10px; background: #f8d7da; border-left: 3px solid #dc3545; color: #721c24; border-radius: 3px;"><strong>' + DiluxOneOffloadProvider.i18n.connection_failed + '</strong><br>' + DiluxOneOffloadProvider.i18n.please_fill_in_all_required_fields + '</div>').show();
			return;
		}

		$button.prop('disabled', true);
		$button.html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>' + DiluxOneOffloadProvider.i18n.testing);
		$result.empty();

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: data,
			success: function(response) {
				$button.prop('disabled', false);
				$button.html('<span class="dashicons dashicons-admin-links"></span>' + DiluxOneOffloadProvider.i18n.test_connection);
				if (response.success) {
					$result.html('<div style="padding: 10px; background: #d4edda; border-left: 3px solid #28a745; color: #155724; border-radius: 3px;"><strong>' + DiluxOneOffloadProvider.i18n.connection_successful + '</strong><br>' + (response.data.message || '') + '</div>').show();
					$('#submit').prop('disabled', false);
					$section.find('.test-status-message').hide();
				} else {
					$result.html('<div style="padding: 10px; background: #f8d7da; border-left: 3px solid #dc3545; color: #721c24; border-radius: 3px;"><strong>' + DiluxOneOffloadProvider.i18n.connection_failed + '</strong><br>' + (response.data.message || '') + '</div>').show();
					$('#submit').prop('disabled', true);
					$section.find('.test-status-message').show();
				}
			},
			error: function(xhr, status, error) {
				$button.prop('disabled', false);
				$button.html('<span class="dashicons dashicons-admin-links"></span>' + DiluxOneOffloadProvider.i18n.test_connection);
				$result.html('<div style="padding: 10px; background: #f8d7da; border-left: 3px solid #dc3545; color: #721c24; border-radius: 3px;"><strong>' + DiluxOneOffloadProvider.i18n.connection_failed + '</strong><br>Error: ' + error + '</div>').show();
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

		$button.prop('disabled', true).css('opacity', '0.6');
		$buttonText.text(DiluxOneOffloadProvider.i18n.deleting_configuration);
		$spinner.css('visibility', 'visible').show();

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
					alert('Error: ' + (response.data.message || 'Unknown error'));
					$button.prop('disabled', false).css('opacity', '1');
					$buttonText.text(DiluxOneOffloadProvider.i18n.yes_delete_configuration);
					$spinner.hide();
				}
			},
			error: function(xhr, status, error) {
				alert(DiluxOneOffloadProvider.i18n.error_deleting_configuration + ' ' + error);
				$button.prop('disabled', false).css('opacity', '1');
				$buttonText.text(DiluxOneOffloadProvider.i18n.yes_delete_configuration);
				$spinner.hide();
			}
		});
	});

	// ========================================================================
	// Credentials tab: test the new key, then save it
	// ========================================================================
	$('#show_new_account_key').on('change', function() {
		$('#new_account_key').attr('type', $(this).is(':checked') ? 'text' : 'password');
	});

	$('#new_account_key').on('input change', function() {
		// A key that changed after a test has not been tested.
		$('#save-new-credentials').prop('disabled', true);
	});

	function newCredentials() {
		return {
			nonce: diluxOneOffloadAdmin.nonce,
			provider: getCurrentProvider(),
			account_name: $('#credentials_account_name').text().trim(),
			account_key: $('#new_account_key').val(),
			container_name: $('#credentials_container_name').text().trim()
		};
	}

	$('#test-new-credentials').on('click', function() {
		var $button = $(this);
		var $result = $('#new-credentials-result');
		var data = $.extend({ action: 'diluxone_offload_test_connection' }, newCredentials());

		if (!data.account_key) {
			$result.html('<div style="padding: 10px; background: #f8d7da; border-left: 3px solid #dc3545; color: #721c24; border-radius: 3px;">' + DiluxOneOffloadProvider.i18n.please_enter_the_new_access_key + '</div>');
			return;
		}

		$button.prop('disabled', true);
		$button.html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>' + DiluxOneOffloadProvider.i18n.testing);
		$result.empty();

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: data,
			success: function(response) {
				$button.prop('disabled', false);
				$button.html('<span class="dashicons dashicons-admin-links"></span>' + DiluxOneOffloadProvider.i18n.test_connection);
				if (response.success) {
					$result.html('<div style="padding: 10px; background: #d4edda; border-left: 3px solid #28a745; color: #155724; border-radius: 3px;"><strong>' + DiluxOneOffloadProvider.i18n.connection_successful + '</strong><br>' + (response.data.message || '') + '</div>');
					$('#save-new-credentials').prop('disabled', false);
				} else {
					$result.html('<div style="padding: 10px; background: #f8d7da; border-left: 3px solid #dc3545; color: #721c24; border-radius: 3px;"><strong>' + DiluxOneOffloadProvider.i18n.connection_failed + '</strong><br>' + (response.data.message || '') + '</div>');
					$('#save-new-credentials').prop('disabled', true);
				}
			},
			error: function(xhr, status, error) {
				$button.prop('disabled', false);
				$button.html('<span class="dashicons dashicons-admin-links"></span>' + DiluxOneOffloadProvider.i18n.test_connection);
				$result.html('<div style="padding: 10px; background: #f8d7da; border-left: 3px solid #dc3545; color: #721c24; border-radius: 3px;">Error: ' + error + '</div>');
				$('#save-new-credentials').prop('disabled', true);
			}
		});
	});

	$('#save-new-credentials').on('click', function() {
		var $button = $(this);
		var data = $.extend({ action: 'diluxone_offload_save_updated_credentials' }, newCredentials());

		$button.prop('disabled', true);
		$button.html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>' + DiluxOneOffloadProvider.i18n.saving);

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: data,
			success: function(response) {
				if (response.success) {
					// The server queued the confirmation notice; the reload shows it.
					window.location.href = DiluxOneOffloadProvider.data.urls.credentials;
				} else {
					alert('Error: ' + (response.data.message || 'Unknown error'));
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
		el.style.display = 'none';
		// Remove required from hidden fields to prevent browser validation errors
		el.querySelectorAll('[required]').forEach(function(input) {
			input.removeAttribute('required');
		});
	});
	if (provider) {
		var selected = document.getElementById(provider + '-config');
		if (selected) {
			selected.style.display = 'block';
			// Restore required on visible fields
			selected.querySelectorAll('input[name]').forEach(function(input) {
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
