<?php
/**
 * Tools › Site Health: a test for the connection to the storage and a
 * section in the Info tab.
 *
 * @package DiluxOneOffload
 * @since 2.1.0
 */

namespace DiluxOneOffload;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DiluxOneOffload\Enums\PluginState;
use DiluxOneOffload\Factories\CloudStorageFactory;

/**
 * Reports what the plugin already knows; it never contacts the storage
 * itself (the connection health is kept by every request and by the checks
 * on the plugin's screens).
 */
class SiteHealth {

	/**
	 * Hook the test and the Info section.
	 */
	public static function init(): void {
		add_filter( 'site_status_tests', array( self::class, 'register_test' ) );
		add_filter( 'debug_information', array( self::class, 'debug_information' ) );
	}

	/**
	 * Add the connection test to the direct (synchronous) tests.
	 *
	 * @param mixed $tests Site Health's tests (an array, unless another filter broke it).
	 * @return array<string, mixed>
	 */
	public static function register_test( $tests ) {
		$tests = is_array( $tests ) ? $tests : array();
		$tests['direct']['diluxone_offload_connection'] = array(
			'label' => __( 'DiluxOne Offload can reach its storage', 'diluxone-offload' ),
			'test'  => array( self::class, 'connection_test' ),
		);
		return $tests;
	}

	/**
	 * The connection test's result: good when the storage answered last
	 * time (or nothing is configured), critical with the reason when it did
	 * not.
	 *
	 * @return array<string, mixed>
	 */
	public static function connection_test(): array {
		$badge  = array(
			'label' => __( 'Media storage', 'diluxone-offload' ),
			'color' => 'blue',
		);
		$health = ConfigManager::get_connection_health();
		$link   = sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=diluxone-offload-status&tab=health' ) ),
			esc_html__( 'Open Status › Health', 'diluxone-offload' )
		);

		if ( ! ConfigManager::is_configured() ) {
			return array(
				'label'       => __( 'DiluxOne Offload has no storage configured yet', 'diluxone-offload' ),
				'status'      => 'good',
				'badge'       => $badge,
				'description' => '<p>' . esc_html__( 'The media library stays on this server until a provider is connected.', 'diluxone-offload' ) . '</p>',
				'test'        => 'diluxone_offload_connection',
			);
		}

		if ( 'unhealthy' === ( $health['status'] ?? '' ) ) {
			$paused = ( $health['consecutive_failures'] ?? 0 ) >= ConfigManager::PAUSE_AFTER_FAILURES && PluginState::is_offloading_active( ConfigManager::get_state() );
			return array(
				'label'       => $paused
					? __( 'Media uploads are paused: DiluxOne Offload cannot reach its storage', 'diluxone-offload' )
					: __( 'DiluxOne Offload could not reach its storage', 'diluxone-offload' ),
				'status'      => 'critical',
				'badge'       => array_merge( $badge, array( 'color' => 'red' ) ),
				'description' => '<p>' . esc_html(
					sprintf(
						/* translators: 1: an error code such as 403, 2: what the storage service answered */
						__( 'The last attempt failed: %1$s %2$s', 'diluxone-offload' ),
						(string) ( $health['error_code'] ?? '' ),
						(string) ( $health['error_message'] ?? '' )
					)
				) . '</p>',
				'actions'     => $link,
				'test'        => 'diluxone_offload_connection',
			);
		}

		return array(
			'label'       => __( 'DiluxOne Offload reaches its storage', 'diluxone-offload' ),
			'status'      => 'good',
			'badge'       => $badge,
			'description' => '<p>' . esc_html(
				sprintf(
					/* translators: %s: the storage service, e.g. "Cloudflare R2" */
					__( 'The last request to %s went through.', 'diluxone-offload' ),
					CloudStorageFactory::get_service_label( ConfigManager::get_config() )
				)
			) . '</p>',
			'actions'     => $link,
			'test'        => 'diluxone_offload_connection',
		);
	}

	/**
	 * The plugin's section in Site Health › Info: provider, service, state
	 * and version. Never a key, a secret or the account's credentials.
	 *
	 * @param mixed $info Site Health's sections (an array, unless another filter broke it).
	 * @return array<string, mixed>
	 */
	public static function debug_information( $info ) {
		$info   = is_array( $info ) ? $info : array();
		$config = ConfigManager::get_config();
		$health = ConfigManager::get_connection_health();

		$info['diluxone-offload'] = array(
			'label'  => __( 'DiluxOne Offload', 'diluxone-offload' ),
			'fields' => array(
				'version'  => array(
					'label' => __( 'Version', 'diluxone-offload' ),
					'value' => DILUXONE_OFFLOAD_VERSION,
				),
				'provider' => array(
					'label' => __( 'Provider', 'diluxone-offload' ),
					'value' => ConfigManager::is_configured() ? CloudStorageFactory::get_provider_label( (string) ( $config['cloud_provider'] ?? '' ) ) : __( 'None', 'diluxone-offload' ),
				),
				'service'  => array(
					'label' => __( 'Service', 'diluxone-offload' ),
					'value' => ConfigManager::is_configured() ? CloudStorageFactory::get_service_label( $config ) : __( 'None', 'diluxone-offload' ),
				),
				'state'    => array(
					'label' => __( 'State', 'diluxone-offload' ),
					'value' => (string) ConfigManager::get_state(),
				),
				'health'   => array(
					'label' => __( 'Connection', 'diluxone-offload' ),
					'value' => (string) ( $health['status'] ?? 'unknown' ),
				),
			),
		);
		return $info;
	}
}
