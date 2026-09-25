/**
 * DiluxOne Offload — admin JavaScript shared by every tab.
 */
jQuery(document).ready(function($) {

    // Re-disable Save if the credentials change after a successful test
    // (only when the button started out disabled, i.e. the provider form).
    if ($('#submit').prop('disabled')) {
        $('#account_name, #account_key, #container_name').on('input change', function() {
            $('#submit').prop('disabled', true);
        });
    }

    // Auto-refresh the Status tab every 5 minutes
    if ($('.diluxone-offload-status').length && diluxOneOffloadAdmin.autoRefresh) {
        setInterval(function() {
            location.reload();
        }, 300000);
    }
});
