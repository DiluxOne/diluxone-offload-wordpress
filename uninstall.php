<?php
/**
 * Uninstall DiluxOne Offload.
 *
 * Runs when the plugin is deleted from the Plugins screen. Removes what the
 * plugin itself created in the database: its options, its transients and its
 * file-tracking table — on every site of a network, since each site has its
 * own. Media files are left exactly where they are, locally and in the
 * cloud; nothing here touches the storage account.
 *
 * @package DiluxOneOffload
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove this plugin's data from the current site.
 *
 * @return void
 */
function diluxone_offload_uninstall_site() {
	global $wpdb;

	$options = array(
		'diluxone_offload_config',
		'diluxone_offload_plugin_state',
		'diluxone_offload_sync_meta',
		'diluxone_offload_sync_progress',
		'diluxone_offload_failed_files',
		'diluxone_offload_connection_health',
		'diluxone_offload_db_version',
		// Names earlier builds used; nothing writes them any more, but a site
		// upgraded from one of them still has them.
		'diluxone_offload_debug_enabled',
		'diluxone_offload_cloud_storage_config',
		'diluxone_offload_cloud_storage_network_config',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Transients, including the per-user ones (connection_test_passed_{id},
	// notice_{id}) whose names can't be listed in advance.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup of this plugin's own transients; no API lists them by prefix.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_diluxone_offload_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_diluxone_offload_' ) . '%'
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dropping this plugin's own table on uninstall; the name is built from $wpdb->prefix, not input.
	$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}diluxone_offload_files`" );

	wp_cache_flush();
}

if ( is_multisite() ) {
	$diluxone_offload_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $diluxone_offload_site_ids as $diluxone_offload_site_id ) {
		switch_to_blog( (int) $diluxone_offload_site_id );
		diluxone_offload_uninstall_site();
		restore_current_blog();
	}
} else {
	diluxone_offload_uninstall_site();
}
