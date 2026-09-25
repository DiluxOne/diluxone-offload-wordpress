<?php
/**
 * Cloud Stream Wrapper
 *
 * This file IS a PHP stream wrapper implementation. It must operate at the native
 * filesystem layer (fopen/fread/fwrite/fclose) on temporary files outside of
 * /wp-content/uploads/, so the \WP_Filesystem abstraction cannot be used here.
 * Likewise, trigger_error() is required by the stream wrapper protocol so that
 * callers like fopen() can detect errors. These rules are intentionally suppressed
 * file-wide:
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
 * phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
 * phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload;

use DiluxOneOffload\Enums\PluginState;
use DiluxOneOffload\Interfaces\CloudStorageClientInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloud Stream Wrapper
 *
 * Custom stream wrapper that redirects file operations to cloud storage.
 * This is the core component that makes WordPress write directly to cloud.
 */
class CloudStreamWrapper {

	/** @var string Protocol name */
	const PROTOCOL = 'diluxoneoffload';

	/** @var resource Stream context (set by PHP automatically for stream wrappers) */
	public $context;

	/** @var resource|false|null Current file handle (null before stream_open, false on fopen failure, resource otherwise) */
	private $handle = null;

	/** @var string|null Temp file backing $handle; removed on close. */
	private $temp_file = null;

	/**
	 * Whether bytes have been written since the last successful upload.
	 *
	 * A write stream is backed by a temp file, so there is no buffer to
	 * compare against the cache to tell whether anything changed. PHP also
	 * ignores what stream_flush() and stream_close() return, so this is what
	 * keeps a file from being uploaded twice — or, worse, not at all.
	 *
	 * @var bool
	 */
	private bool $dirty = false;

	/** @var string Current file path */
	private $path = '';

	/**
	 * Whether the last fetch_to_temp_file() failed because the blob does not
	 * exist, as opposed to a transport or server error. Only the former may
	 * turn an append into a new file; the latter must fail the open, or the
	 * append would upload the new bytes alone over an object that is still
	 * there.
	 *
	 * @var bool
	 */
	private bool $fetch_missing = false;

	/** @var string File mode (r, w, a, etc.) */
	private $mode = '';

	/** @var CloudStorageClientInterface|null */
	private static $cloud_client = null;

	/**
	 * @var array<string, mixed> Statistics cache
	 */
	private static $stat_cache = array();

	/**
	 * @var array<string, mixed> Per-request cache of the last small file read or written
	 */
	private static $file_cache = array();

	/**
	 * Paths whose upload failed in this request, with the reason.
	 *
	 * PHP ignores what stream_flush() and stream_close() return, so copy()
	 * and file_put_contents() report success even when the PUT to the cloud
	 * failed. WordPress would then insert an attachment for a file that is
	 * nowhere. fail_upload_if_write_failed(), on the wp_handle_upload and
	 * wp_handle_sideload filters, consults this list and turns the upload
	 * into the explicit error it should have been.
	 *
	 * @var array<string, string>
	 */
	private static $write_failures = array();

	/**
	 * Largest file the per-request cache keeps: one upload block, 4 MiB.
	 *
	 * The cache saves the download that WordPress makes right after writing a
	 * file (getimagesize(), the image editor), and this cap is what keeps the
	 * plugin's promise that PHP never holds more than one block of a file.
	 *
	 * @var int
	 */
	const CACHE_MAX_BYTES = 4194304; // 4 * 1024 * 1024

	/** @var string|null Per-request memo of the cloud_host (host where assets are
	 *  served from). Empty string when the plugin is not configured. */
	private static ?string $cloud_host_cache = null;

	/**
	 * @var array<string, mixed>|null \Iterator for directory listing
	 */
	private $dir_iterator = null;

	/** @var string Current directory path being read */
	private $dir_path = '';

	/** @var string Prefix for directory listing */
	private $dir_prefix = '';

	/**
	 * Register the stream wrapper
	 *
	 * @return bool
	 */
	public static function register() {
		if ( in_array( self::PROTOCOL, stream_get_wrappers(), true ) ) {
			stream_wrapper_unregister( self::PROTOCOL );
		}

		$registered = stream_wrapper_register( self::PROTOCOL, __CLASS__ );

		if ( $registered ) {
			Logger::debug( '[DiluxOne Offload CloudStreamWrapper] Registered protocol: ' . self::PROTOCOL . '://' );
		} else {
			Logger::error( '[DiluxOne Offload CloudStreamWrapper] Failed to register protocol' );
		}

		return $registered;
	}

	/**
	 * Unregister the stream wrapper
	 *
	 * @return bool
	 */
	public static function unregister() {
		if ( in_array( self::PROTOCOL, stream_get_wrappers(), true ) ) {
			$unregistered = stream_wrapper_unregister( self::PROTOCOL );

			if ( $unregistered ) {
				Logger::debug( '[DiluxOne Offload CloudStreamWrapper] Unregistered protocol: ' . self::PROTOCOL . '://' );
			}

			return $unregistered;
		}

		return true;
	}

	/**
	 * Check if stream wrapper is active
	 *
	 * @return bool
	 */
	public static function is_active() {
		return in_array( self::PROTOCOL, stream_get_wrappers(), true );
	}

	/**
	 * Activate cloud offloading by replacing wp_upload_dir
	 *
	 * @return bool
	 */
	public static function activate_offloading() {
		$state = ConfigManager::get_state();

		// If already active, just ensure filters are registered
		if ( $state === PluginState::OFFLOADING_ACTIVE ) {
			// Ensure stream wrapper is registered
			self::register();

			// Re-register filters (WordPress removes them on some requests)
			remove_filter( 'upload_dir', array( __CLASS__, 'filter_upload_dir' ), 10 );

			add_filter( 'upload_dir', array( __CLASS__, 'filter_upload_dir' ), 10, 1 );
			self::register_upload_result_filters();

			// Re-arm HTTPS upgrade for cloud-host URLs (cheap; idempotent).
			self::register_force_https_filters();

			// Re-setup admin hooks (they might have been removed)
			self::setup_admin_hooks();

			return true;
		}

		if ( ! PluginState::can_activate_offloading( $state ) ) {
			Logger::error( '[DiluxOne Offload CloudStreamWrapper] Cannot activate offloading in state: ' . $state );
			return false;
		}

		// Register stream wrapper
		if ( ! self::register() ) {
			return false;
		}

		// Hook into WordPress upload directory
		add_filter( 'upload_dir', array( __CLASS__, 'filter_upload_dir' ), 10, 1 );
		self::register_upload_result_filters();

		// Re-upgrade cloud-host URLs to https:// when WP core's set_url_scheme()
		// downgrades them on plain-HTTP local installs.
		self::register_force_https_filters();

		// Setup admin hooks for plugin/theme/core installation/updates
		self::setup_admin_hooks();

		// Update state
		ConfigManager::set_state( PluginState::OFFLOADING_ACTIVE );

		// Drop the metadata of a finished forward sync.
		// It is of no use once offloading is on, and leaving it behind makes
		// validate_multi_tab() read a dead heartbeat and drop the plugin back
		// to CONFIGURED.
		delete_option( 'diluxone_offload_sync_meta' );
		Logger::debug( '[DiluxOne Offload CloudStreamWrapper] Cleared completed sync metadata' );

		Logger::info( '[DiluxOne Offload CloudStreamWrapper] Offloading activated' );

		return true;
	}

	/**
	 * Deactivate cloud offloading
	 *
	 * @return bool
	 */
	public static function deactivate_offloading() {
		// Remove WordPress hooks
		remove_filter( 'upload_dir', array( __CLASS__, 'filter_upload_dir' ), 10 );
		remove_filter( 'wp_handle_upload', array( __CLASS__, 'fail_upload_if_write_failed' ), 5 );
		remove_filter( 'wp_handle_sideload', array( __CLASS__, 'fail_upload_if_write_failed' ), 5 );
		self::unregister_force_https_filters();

		// Unregister stream wrapper
		self::unregister();

		// Update state
		ConfigManager::set_state( PluginState::SYNCED );

		Logger::info( '[DiluxOne Offload CloudStreamWrapper] Offloading deactivated' );

		return true;
	}

	/**
	 * Make a failed cloud write fail the WordPress upload that caused it.
	 *
	 * Runs at priority 5 so it sees the result before any other plugin does.
	 */
	private static function register_upload_result_filters(): void {
		if ( ! has_filter( 'wp_handle_upload', array( __CLASS__, 'fail_upload_if_write_failed' ) ) ) {
			add_filter( 'wp_handle_upload', array( __CLASS__, 'fail_upload_if_write_failed' ), 5, 2 );
		}
		if ( ! has_filter( 'wp_handle_sideload', array( __CLASS__, 'fail_upload_if_write_failed' ) ) ) {
			add_filter( 'wp_handle_sideload', array( __CLASS__, 'fail_upload_if_write_failed' ), 5, 2 );
		}
	}

	/**
	 * Remember that the write for $path did not reach the cloud.
	 *
	 * @param string $path   Path relative to the protocol (e.g. uploads/2026/09/a.jpg).
	 * @param string $reason Provider or transport error.
	 */
	private static function note_write_failure( string $path, string $reason ): void {
		self::$write_failures[ $path ] = $reason;
	}

	/**
	 * Forget a recorded failure for $path (a later attempt succeeded).
	 *
	 * @param string $path Path relative to the protocol.
	 */
	private static function clear_write_failure( string $path ): void {
		unset( self::$write_failures[ $path ] );
	}

	/**
	 * The reason the last write for $path failed in this request, and forget it.
	 *
	 * @param string $path Path relative to the protocol, or a full protocol URL.
	 * @return string|null Null when the write reached the cloud.
	 */
	public static function take_write_failure( string $path ): ?string {
		$path = ltrim( str_replace( self::PROTOCOL . '://', '', $path ), '/' );
		if ( ! isset( self::$write_failures[ $path ] ) ) {
			return null;
		}
		$reason = self::$write_failures[ $path ];
		unset( self::$write_failures[ $path ] );
		return $reason;
	}

	/**
	 * Filter for wp_handle_upload and wp_handle_sideload: if the file WordPress
	 * just "moved" into the cloud never got there, hand back the error array
	 * core expects, so media_handle_upload() returns a WP_Error and no
	 * attachment is inserted for a file that does not exist.
	 *
	 * @param array<string, mixed> $upload  The upload result ('file', 'url', 'type', or 'error').
	 * @param string               $context 'upload' or 'sideload'.
	 * @return array<string, mixed>
	 */
	public static function fail_upload_if_write_failed( $upload, $context = 'upload' ) {
		if ( ! is_array( $upload ) || isset( $upload['error'] ) || empty( $upload['file'] ) ) {
			return $upload;
		}

		$file = (string) $upload['file'];
		if ( strpos( $file, self::PROTOCOL . '://' ) !== 0 ) {
			return $upload;
		}

		$reason = self::take_write_failure( $file );
		if ( null === $reason ) {
			return $upload;
		}

		Logger::error( '[DiluxOne Offload CloudStreamWrapper] Upload reported to WordPress as failed (' . $context . '): ' . $file . ' - ' . $reason );

		return array(
			'error' => sprintf(
				/* translators: %s: the error returned by the cloud provider or the network. */
				__( 'The file could not be uploaded to cloud storage: %s', 'diluxone-offload' ),
				$reason
			),
		);
	}

	/**
	 * Setup admin hooks to disable cloud offloading during plugin/theme/core installation/updates
	 *
	 * WordPress needs to use wp-content/upgrade/ directory for these operations,
	 * not uploads/. We temporarily remove the upload_dir filter to allow this.
	 */
	private static function setup_admin_hooks(): void {
		// Disable cloud offloading during WordPress core, plugin, and theme updates/installations
		add_action( 'load-update.php', array( __CLASS__, 'tear_down' ) );           // General updates page
		add_action( 'load-update-core.php', array( __CLASS__, 'tear_down' ) );      // WordPress core updates
		add_action( 'load-plugin-install.php', array( __CLASS__, 'tear_down' ) );   // Plugin installation
		add_action( 'load-theme-install.php', array( __CLASS__, 'tear_down' ) );    // Theme installation
	}

	/**
	 * Temporarily disable cloud offloading for plugin/theme/core operations
	 *
	 * This removes the upload_dir filter so WordPress can use wp-content/upgrade/
	 * directory instead of uploads/ for plugin/theme/core files.
	 *
	 * Called automatically by load-* hooks during admin operations.
	 */
	public static function tear_down(): void {
		remove_filter( 'upload_dir', array( __CLASS__, 'filter_upload_dir' ) );
		Logger::debug( '[DiluxOne Offload CloudStreamWrapper] Temporarily disabled upload_dir filter for plugin/theme/core operation' );
	}

	/**
	 * Filter WordPress upload directory to use cloud protocol
	 *
	 * Double protection: this method should not be called during plugin/theme/core
	 * operations due to tear_down() hooks, but we add a path check as extra safety.
	 *
	 * @param array<string, mixed> $upload_dir
	 * @return array<string, mixed>
	 */
	public static function filter_upload_dir( $upload_dir ) {
		// Extra safety: Skip filtering if path contains 'upgrade' directory
		// This handles edge cases where tear_down() hooks might not fire
		if ( isset( $upload_dir['path'] ) && strpos( $upload_dir['path'], 'wp-content/upgrade' ) !== false ) {
			Logger::debug( '[DiluxOne Offload CloudStreamWrapper] Skipping upload_dir filter - detected upgrade directory' );
			return $upload_dir;
		}

		if ( isset( $upload_dir['basedir'] ) && strpos( $upload_dir['basedir'], 'wp-content/upgrade' ) !== false ) {
			Logger::debug( '[DiluxOne Offload CloudStreamWrapper] Skipping upload_dir filter - detected upgrade directory in basedir' );
			return $upload_dir;
		}

		$original_path    = $upload_dir['path'];
		$original_basedir = $upload_dir['basedir'];

		// What arrives here is the native uploads directory, whatever this site
		// decided that is. It is the only trustworthy answer: UPLOADS, an
		// upload_path option, a multisite layout and any other plugin's
		// upload_dir filter all end up in this value, and none of them has to
		// live under wp-content.
		$prefix = self::key_prefix();

		$upload_dir['path']    = self::PROTOCOL . '://' . $prefix . self::below( $original_basedir, $original_path );
		$upload_dir['basedir'] = self::PROTOCOL . '://' . $prefix;

		// URLs should point to cloud storage
		$cloud_client = self::get_cloud_client();
		if ( $cloud_client ) {
			try {
				// The object key always reads <prefix>/YYYY/MM, however the
				// site spells that directory on disk.
				$upload_dir['url']     = $cloud_client->get_file_url( $prefix . self::below( $original_basedir, $original_path ) );
				$upload_dir['baseurl'] = $cloud_client->get_file_url( $prefix );
			} catch ( \Exception $e ) {
				Logger::error( '[DiluxOne Offload CloudStreamWrapper] filter_upload_dir exception: ' . $e->getMessage() );
				// Fall back to original URLs on error
			}
		}

		return $upload_dir;
	}

	/**
	 * The object-key prefix of this site's uploads.
	 *
	 * `uploads` on a single site and on the main site of a network;
	 * `uploads/sites/<id>` on every other site of a network — the layout
	 * WordPress itself uses on disk. Two sites of a network that share one
	 * container therefore never share a key, and each site's listings, sync
	 * and Disconnect see only its own objects. Derived from the blog id, not
	 * from the directory on disk, so a network still on the old `blogs.dir`
	 * layout gets the same keys as one on `uploads/sites`.
	 *
	 * @return string
	 */
	public static function key_prefix(): string {
		if ( is_multisite() && ! is_main_site() ) {
			return 'uploads/sites/' . get_current_blog_id();
		}

		return 'uploads';
	}

	/**
	 * Whether an object key belongs to this site.
	 *
	 * A listing by prefix is not enough on the main site of a network: its
	 * `uploads/` is the parent of every other site's `uploads/sites/<id>/`,
	 * so those keys come back too and must be left out, or the main site
	 * would count, catalogue and restore the other sites' files as its own.
	 *
	 * @param string $key Object key.
	 * @return bool
	 */
	public static function owns_key( string $key ): bool {
		if ( 0 !== strpos( $key, self::key_prefix() . '/' ) ) {
			return false;
		}

		if ( is_multisite() && is_main_site() && 0 === strpos( $key, 'uploads/sites/' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Only this site's entries of a listing (see owns_key()).
	 *
	 * @param array<mixed> $files Listing entries, each an array with a `path` key.
	 * @return array<int, array<mixed>>
	 */
	public static function site_files( array $files ): array {
		$own = array();
		foreach ( $files as $file ) {
			if ( is_array( $file ) && self::owns_key( (string) ( $file['path'] ?? '' ) ) ) {
				$own[] = $file;
			}
		}

		return $own;
	}

	/**
	 * The part of a path hanging below a directory, leading slash included.
	 *
	 * Empty when the path is the directory itself, so a caller can concatenate
	 * it onto a prefix that carries no trailing slash.
	 *
	 * @param string $base The directory.
	 * @param string $path A path inside it.
	 * @return string
	 */
	private static function below( string $base, string $path ): string {
		$base = rtrim( $base, '/' );

		if ( $path === $base ) {
			return '';
		}

		if ( 0 === strpos( $path, $base . '/' ) ) {
			return substr( $path, strlen( $base ) );
		}

		// Not below the directory at all. Nothing sensible to strip.
		return '';
	}

	/**
	 * Where uploads really live on disk.
	 *
	 * Asking wp_upload_dir() outright does not work while offloading is on,
	 * because this class is the filter rewriting its answer to the cloud
	 * protocol. So the filter steps aside for the length of the call and steps
	 * back in at the priority it held.
	 *
	 * @return string Absolute path without a trailing slash, empty if WordPress reports none.
	 */
	public static function native_upload_basedir(): string {
		$priority = has_filter( 'upload_dir', array( __CLASS__, 'filter_upload_dir' ) );

		if ( false !== $priority ) {
			remove_filter( 'upload_dir', array( __CLASS__, 'filter_upload_dir' ), (int) $priority );
		}

		$upload_dir = wp_upload_dir( null, false );

		if ( false !== $priority ) {
			add_filter( 'upload_dir', array( __CLASS__, 'filter_upload_dir' ), (int) $priority );
		}

		return rtrim( (string) $upload_dir['basedir'], '/' );
	}

	// ------------------------------------------------------------------
	// Force-HTTPS for cloud-host URLs
	//
	// WHY: WP core's set_url_scheme() downgrades https:// to http:// when the
	// request comes in over plain HTTP (is_ssl() === false). That's harmless
	// for local URLs, but Azure Blob Storage rejects HTTP with a 400, breaking
	// the front-end whenever offloading is on and the WP site URL is plain
	// http (typical for local dev: http://localhost:8090).
	//
	// The fix is small and local: a filter on the URL-emitting hooks that
	// re-applies https:// only when the URL points at the cloud_host. We do
	// not touch URLs at any other host (including the WP site itself), so
	// the rest of WordPress keeps working as configured.
	// ------------------------------------------------------------------

	/**
	 * Register the URL filters that re-upgrade cloud-host URLs to https://.
	 * Idempotent — duplicate registration is removed first.
	 */
	public static function register_force_https_filters(): void {
		self::unregister_force_https_filters();
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'force_https_on_url' ), 99, 1 );
		add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'force_https_on_srcset' ), 99, 1 );
		add_filter( 'style_loader_src', array( __CLASS__, 'force_https_on_url' ), 99, 1 );
		add_filter( 'script_loader_src', array( __CLASS__, 'force_https_on_url' ), 99, 1 );
	}

	/**
	 * Remove the URL filters registered by register_force_https_filters().
	 */
	public static function unregister_force_https_filters(): void {
		remove_filter( 'wp_get_attachment_url', array( __CLASS__, 'force_https_on_url' ), 99 );
		remove_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'force_https_on_srcset' ), 99 );
		remove_filter( 'style_loader_src', array( __CLASS__, 'force_https_on_url' ), 99 );
		remove_filter( 'script_loader_src', array( __CLASS__, 'force_https_on_url' ), 99 );
	}

	/**
	 * Filter callback. Returns $url unchanged unless it starts with
	 * "http://<cloud_host>", in which case it is reissued as
	 * "https://<cloud_host>...".
	 *
	 * @param mixed $url
	 * @return mixed
	 */
	public static function force_https_on_url( $url ) {
		if ( ! is_string( $url ) || $url === '' ) {
			return $url;
		}
		$cloud_host = self::get_cloud_host();
		if ( $cloud_host === '' || ! self::is_force_https_enabled() ) {
			return $url;
		}
		$needle = 'http://' . $cloud_host;
		if ( stripos( $url, $needle ) === 0 ) {
			return 'https://' . substr( $url, strlen( 'http://' ) );
		}
		return $url;
	}

	/**
	 * Adapter for wp_calculate_image_srcset, which returns an array keyed by
	 * image width with each entry containing a 'url' field.
	 *
	 * @param mixed $sources
	 * @return mixed
	 */
	public static function force_https_on_srcset( $sources ) {
		if ( ! is_array( $sources ) ) {
			return $sources;
		}
		foreach ( $sources as $key => $source ) {
			if ( is_array( $source ) && isset( $source['url'] ) && is_string( $source['url'] ) ) {
				$sources[ $key ]['url'] = self::force_https_on_url( $source['url'] );
			}
		}
		return $sources;
	}

	/**
	 * Read the force_https_on_cloud setting from the plugin config.
	 * Defaults to true so the fix is on out-of-the-box.
	 */
	private static function is_force_https_enabled(): bool {
		$config = ConfigManager::get_config();
		return ! empty( $config['force_https_on_cloud'] );
	}

	/**
	 * Lower-cased host where the cloud-storage plugin serves assets from.
	 * Returns '' when the plugin is not configured. Memoised per-request.
	 */
	private static function get_cloud_host(): string {
		if ( self::$cloud_host_cache !== null ) {
			return self::$cloud_host_cache;
		}
		$config = ConfigManager::get_config();
		if ( empty( $config['cloud_provider'] ) ) {
			self::$cloud_host_cache = '';
			return self::$cloud_host_cache;
		}
		$provider = $config['cloud_provider'];
		$pc       = $config['provider_config'] ?? array();

		$host = '';
		if ( $provider === 'azure' && ! empty( $pc['storage_account'] ) ) {
			$host = $pc['storage_account'] . '.blob.core.windows.net';
		}

		self::$cloud_host_cache = strtolower( $host );
		return self::$cloud_host_cache;
	}


	/**
	 * Stream wrapper: Open file
	 *
	 * Every mode is backed by a temp file on disk: a read or an edit starts
	 * from the blob downloaded to it, a write from an empty one, and the
	 * bytes a caller writes go to that file as they arrive. No part of the
	 * file lives in a PHP string, so a video costs the same memory as a
	 * thumbnail whatever the mode.
	 *
	 * @param string $path
	 * @param string $mode
	 * @param int    $options
	 * @param string &$opened_path
	 * @return bool
	 */
	public function stream_open( $path, $mode, $options, &$opened_path ) {
		$this->path  = $this->parse_path( $path );
		$this->mode  = $mode;
		$this->dirty = false;

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			Logger::debug( '[DiluxOne Offload CloudStreamWrapper] Opening: ' . $this->path . ' (mode: ' . $mode . ')' );
		}

		$cloud_client = self::get_cloud_client();
		if ( ! $cloud_client ) {
			Logger::error( '[DiluxOne Offload CloudStreamWrapper] Cloud client not available' );
			return false;
		}

		$reads_existing = strpos( $mode, 'r' ) !== false;
		$appends        = strpos( $mode, 'a' ) !== false;
		$writes         = $appends || strpos( $mode, 'w' ) !== false || strpos( $mode, '+' ) !== false;

		if ( ! $reads_existing && ! $writes ) {
			// 'x' and 'c' are not modes WordPress uses for media.
			return false;
		}

		if ( $writes && ! $this->writes_allowed() ) {
			return false;
		}

		if ( $reads_existing || $appends ) {
			// Start from the blob's current contents. A read or an edit needs
			// the blob to exist; for an append a blob that is confirmed missing
			// is a new file, while any other failure refuses the open.
			$temp_file = $this->fetch_to_temp_file( $cloud_client );
			if ( null === $temp_file ) {
				if ( ! $appends || ! $this->fetch_missing ) {
					return false;
				}
				$temp_file = self::tempnam( $this->path );
			}
		} else {
			$temp_file = self::tempnam( $this->path );
		}

		return $this->open_temp_file( $temp_file, self::local_mode( $mode ) );
	}

	/**
	 * Whether a write may start: after three consecutive failures the
	 * connection is treated as down and a write is refused up front, so the
	 * caller (wp_handle_upload(), copy(), a plugin) gets a failed open and
	 * reports it, instead of a file that appears to save and is nowhere.
	 * Nothing is written anywhere else. check_connection_health() re-probes
	 * at most every five minutes, so writes reopen on their own once the
	 * cloud is back; a single blip still just retries.
	 */
	private function writes_allowed(): bool {
		$health = \DiluxOneOffload\ConfigManager::get_connection_health();
		if ( ( $health['consecutive_failures'] ?? 0 ) >= 3 ) {
			$health = \DiluxOneOffload\ConfigManager::check_connection_health();
		}
		if ( ( $health['consecutive_failures'] ?? 0 ) >= 3 ) {
			Logger::error( '[DiluxOne Offload CloudStreamWrapper] Write refused, cloud connection unavailable (' . ( $health['error_code'] ?? '' ) . '): ' . $this->path );
			return false;
		}

		return true;
	}

	/**
	 * Bring the blob's current contents to a temp file: from the per-request
	 * cache when it holds this path, otherwise by downloading it — straight
	 * to disk, so the file never passes through PHP memory.
	 *
	 * @param CloudStorageClientInterface $cloud_client
	 * @return string|null The temp file, or null when the blob could not be fetched.
	 */
	private function fetch_to_temp_file( CloudStorageClientInterface $cloud_client ): ?string {
		$this->fetch_missing = false;
		$temp_file           = self::tempnam( $this->path );

		$cached_content = $this->cache_get( $this->path );
		if ( null !== $cached_content ) {
			if ( false === file_put_contents( $temp_file, $cached_content ) ) {
				wp_delete_file( $temp_file );
				return null;
			}
			return $temp_file;
		}

		try {
			$result = $cloud_client->download_file( $this->path, $temp_file );
		} catch ( \Exception $e ) {
			Logger::error( '[DiluxOne Offload CloudStreamWrapper] stream_open download exception: ' . $this->path . ' - ' . $e->getMessage() );
			wp_delete_file( $temp_file );
			return null;
		}

		if ( empty( $result['success'] ) ) {
			// The provider reports the status in its message; 404 is the one
			// answer that means "no such object" rather than "could not tell".
			$this->fetch_missing = (bool) preg_match( '/\b404\b|not found/i', (string) ( $result['error'] ?? '' ) );
			wp_delete_file( $temp_file );
			return null;
		}

		$this->cache_temp_file( $temp_file );

		return $temp_file;
	}

	/**
	 * Keep a small file for the read that usually follows a write, asking the
	 * size first: reading a 300 MB video into memory only to find out it is
	 * too big to cache would throw away the point of having streamed it.
	 *
	 * @param string $temp_file The temp file holding the blob.
	 */
	private function cache_temp_file( string $temp_file ): void {
		clearstatcache( true, $temp_file );
		$size = filesize( $temp_file );
		if ( false === $size || $size > self::CACHE_MAX_BYTES ) {
			return;
		}

		$content = file_get_contents( $temp_file );
		if ( false !== $content ) {
			$this->cache_set( $this->path, $content );
		}
	}

	/**
	 * Attach the stream to its temp file.
	 *
	 * @param string $temp_file The temp file backing the stream.
	 * @param string $mode      fopen() mode for the temp file, from local_mode().
	 */
	private function open_temp_file( string $temp_file, string $mode ): bool {
		$handle = fopen( $temp_file, $mode );
		if ( false === $handle ) {
			Logger::error( '[DiluxOne Offload CloudStreamWrapper] Could not open the temp file backing ' . $this->path );
			wp_delete_file( $temp_file );
			return false;
		}

		$this->temp_file = $temp_file;
		$this->handle    = $handle;

		return true;
	}

	/**
	 * The mode the temp file is opened with for a given wrapper mode: always
	 * binary, and a plain write opened read-write so the upload that follows
	 * can be sized and read from the same handle.
	 *
	 * @param string $mode The mode fopen() was called with.
	 */
	private static function local_mode( string $mode ): string {
		$mode = str_replace( array( 'b', 't' ), '', $mode );
		if ( 'w' === $mode ) {
			$mode = 'w+';
		}

		return $mode . 'b';
	}

	/**
	 * Whether this stream may upload its temp file: any mode that can write.
	 *
	 * A plain read also has a handle and a temp file, and must never upload.
	 */
	private function is_temp_backed_write(): bool {
		return is_resource( $this->handle )
			&& null !== $this->temp_file
			&& ( strpos( $this->mode, 'w' ) !== false || strpos( $this->mode, 'a' ) !== false || strpos( $this->mode, '+' ) !== false );
	}

	/**
	 * Create a temp file, loading wp_tempnam() if this request has not.
	 *
	 * It lives in wp-admin/includes/file.php, which is not loaded on every
	 * request that can end up writing media.
	 *
	 * @param string $path Name hint for the temp file.
	 * @return string
	 */
	private static function tempnam( string $path ): string {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		return wp_tempnam( $path );
	}

	/**
	 * Stream wrapper: Read from file
	 *
	 * @param int $count
	 * @return string|false
	 */
	public function stream_read( $count ) {
		if ( ! is_resource( $this->handle ) || $count <= 0 ) {
			return '';
		}

		return fread( $this->handle, $count );
	}

	/**
	 * Stream wrapper: Write to file
	 *
	 * @param string $data
	 * @return int
	 */
	public function stream_write( $data ) {
		if ( ! is_resource( $this->handle ) ) {
			return 0;
		}

		$written = fwrite( $this->handle, $data );
		if ( false === $written ) {
			return 0;
		}

		$this->dirty = true;

		return $written;
	}

	/**
	 * Stream wrapper: Flush the stream to the cloud
	 *
	 * PHP's file_put_contents() calls fopen() -> fwrite() -> fflush() ->
	 * fclose(), and page builders that generate CSS/JS files under uploads
	 * rely on it, so the upload has to happen here and not only on close.
	 *
	 * @return bool
	 */
	public function stream_flush() {
		// A read-only stream has nothing to flush.
		if ( strpos( $this->mode, 'r' ) === 0 && strpos( $this->mode, '+' ) === false ) {
			return false;
		}

		// A write backed by a temp file: the bytes are already on disk, so the
		// file goes to the cloud from there. Returning fflush() alone would
		// tell file_put_contents() the write succeeded while nothing ever left
		// the server, and WordPress would record an attachment for a file that
		// does not exist.
		$write_handle = $this->handle;
		if ( $this->is_temp_backed_write() && is_resource( $write_handle ) ) {
			fflush( $write_handle );

			if ( ! $this->dirty ) {
				return true;
			}

			return $this->upload_temp_file();
		}

		// A read handle: nothing goes to the cloud, the local file is flushed.
		if ( is_resource( $this->handle ) ) {
			return fflush( $this->handle );
		}

		return false;
	}

	/**
	 * Send the temp file backing this write stream to the cloud.
	 *
	 * The provider reads it from disk, so the file never passes through PHP
	 * memory here either, whatever its size.
	 *
	 * @return bool
	 */
	private function upload_temp_file(): bool {
		// One attempt per set of bytes. Clearing this up front is what keeps a
		// failed flush from being retried by stream_close(), which would send
		// the file twice and count the failure twice against the health
		// threshold. A later fwrite() sets it again, so new bytes still get
		// their own attempt.
		$this->dirty = false;

		$cloud_client = self::get_cloud_client();
		if ( ! $cloud_client ) {
			Logger::error( '[DiluxOne Offload CloudStreamWrapper] upload: cloud client not available: ' . $this->path );
			self::note_write_failure( $this->path, 'Cloud client not available' );
			return false;
		}

		clearstatcache( true, (string) $this->temp_file );
		$size = filesize( (string) $this->temp_file );
		if ( false === $size ) {
			Logger::error( '[DiluxOne Offload CloudStreamWrapper] upload: could not size the temp file for ' . $this->path );
			self::note_write_failure( $this->path, 'Could not read the file to upload' );
			return false;
		}

		try {
			$result = $cloud_client->upload_file(
				(string) $this->temp_file,
				$this->path,
				array( 'mime_type_from_path' => $this->path )
			);
		} catch ( \Exception $e ) {
			Logger::error( '[DiluxOne Offload CloudStreamWrapper] upload exception: ' . $this->path . ' - ' . $e->getMessage() );
			\DiluxOneOffload\ConfigManager::record_connection_failure( 'exception', $e->getMessage(), 'upload' );
			self::note_write_failure( $this->path, $e->getMessage() );
			return false;
		}

		if ( empty( $result['success'] ) ) {
			$error_msg = $result['error'] ?? 'Unknown upload error';
			Logger::info( '[DiluxOne Offload CloudStreamWrapper] upload failed: ' . $this->path . ' - ' . $error_msg );
			$error_code = '';
			if ( preg_match( '/(\d{3})/', $error_msg, $matches ) ) {
				$error_code = $matches[1];
			}
			\DiluxOneOffload\ConfigManager::record_connection_failure( $error_code, $error_msg, 'upload' );
			self::note_write_failure( $this->path, $error_msg );
			return false;
		}

		self::clear_write_failure( $this->path );

		// Auto-recovery: if was unhealthy and upload succeeded, mark healthy
		$health = \DiluxOneOffload\ConfigManager::get_connection_health();
		if ( $health['status'] === 'unhealthy' ) {
			\DiluxOneOffload\ConfigManager::record_connection_success();
		}

		// Cache the stat so the read that usually follows a write does not pay
		// for a HEAD request. The mode has to say "regular file": with a zero
		// mode is_file() answers false and callers decide the upload vanished.
		self::$stat_cache[ $this->path ] = array(
			0         => 0,
			'dev'     => 0,
			1         => 0,
			'ino'     => 0,
			2         => 33188,
			'mode'    => 33188,  // Regular file with 0644 permissions
			3         => 1,
			'nlink'   => 1,
			4         => 0,
			'uid'     => 0,
			5         => 0,
			'gid'     => 0,
			6         => 0,
			'rdev'    => 0,
			7         => $size,
			'size'    => $size,
			8         => time(),
			'atime'   => time(),
			9         => time(),
			'mtime'   => time(),
			10        => time(),
			'ctime'   => time(),
			11        => -1,
			'blksize' => -1,
			12        => -1,
			'blocks'  => -1,
		);

		// And keep it for the read that usually follows, when it is small.
		$this->cache_temp_file( (string) $this->temp_file );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			Logger::debug( '[DiluxOne Offload CloudStreamWrapper] Uploaded ' . $this->path . ' (' . $size . ' bytes)' );
		}

		return true;
	}

	/**
	 * Stream wrapper: Close file
	 *
	 * @return bool
	 */
	public function stream_close() {
		// fopen()/fwrite()/fclose() without fflush() never reaches
		// stream_flush(), so this is the last chance to get those bytes to the
		// cloud — and it has to happen before the handle and its temp file go.
		$write_handle = $this->handle;
		if ( $this->dirty && $this->is_temp_backed_write() && is_resource( $write_handle ) ) {
			fflush( $write_handle );
			$this->upload_temp_file();
		}

		if ( $this->handle ) {
			fclose( $this->handle );
			$this->handle = null;
		}

		// Once the handle is closed nothing refers to the temp file again, and
		// leaving it behind on every getimagesize()/copy() fills the temp dir.
		if ( null !== $this->temp_file ) {
			wp_delete_file( $this->temp_file );
			$this->temp_file = null;
		}

		return true;
	}

	/**
	 * Stream wrapper: Get file statistics
	 *
	 * @return array<int|string, mixed>|false
	 */
	public function stream_stat() {
		return is_resource( $this->handle ) ? fstat( $this->handle ) : false;
	}

	/**
	 * Stream wrapper: Check if end of file
	 *
	 * @return bool
	 */
	public function stream_eof() {
		return ! is_resource( $this->handle ) || feof( $this->handle );
	}

	/**
	 * Stream wrapper: Seek to position
	 *
	 * @param int $offset
	 * @param int $whence
	 * @return bool
	 */
	public function stream_seek( $offset, $whence = SEEK_SET ) {
		return is_resource( $this->handle ) && 0 === fseek( $this->handle, $offset, $whence );
	}

	/**
	 * Stream wrapper: Get current position
	 *
	 * @return int
	 */
	public function stream_tell() {
		if ( ! is_resource( $this->handle ) ) {
			return 0;
		}

		$pos = ftell( $this->handle );

		return false === $pos ? 0 : $pos;
	}

	/**
	 * Stream wrapper: Change file metadata
	 *
	 * Called when chmod(), touch(), chown(), chgrp() are used on cloud files.
	 * Azure Blob Storage doesn't support Unix-style permissions, so we simulate
	 * success to prevent warnings.
	 *
	 * @param string $path The file path
	 * @param int    $option STREAM_META_TOUCH, STREAM_META_OWNER_NAME, etc.
	 * @param mixed  $value The metadata value
	 * @return bool
	 */
	public function stream_metadata( $path, $option, $value ) {
		// Blob storage has no Unix permissions; succeeding silently keeps
		// WordPress's own chmod() calls from raising warnings.
		return true;
	}

	/**
	 * Stream wrapper: Get URL statistics
	 *
	 * @param string $path
	 * @param int    $flags
	 * @return array<int|string, mixed>|false
	 */
	public function url_stat( $path, $flags ) {
		$parsed_path = $this->parse_path( $path );

		$extension = pathinfo( $parsed_path, PATHINFO_EXTENSION );

		/**
		 * If the file is actually just a path to a directory
		 * then return it as always existing. This is to work
		 * around wp_upload_dir doing file_exists checks on
		 * the uploads directory on every page load.
		 */
		if ( ! $extension ) {
			return array(
				0         => 0,
				'dev'     => 0,
				1         => 0,
				'ino'     => 0,
				2         => 16895,
				'mode'    => 16895,
				3         => 0,
				'nlink'   => 0,
				4         => 0,
				'uid'     => 0,
				5         => 0,
				'gid'     => 0,
				6         => -1,
				'rdev'    => -1,
				7         => 0,
				'size'    => 0,
				8         => 0,
				'atime'   => 0,
				9         => 0,
				'mtime'   => 0,
				10        => 0,
				'ctime'   => 0,
				11        => -1,
				'blksize' => -1,
				12        => -1,
				'blocks'  => -1,
			);
		}

		// Check if this path is in the url_stat cache
		if ( isset( self::$stat_cache[ $parsed_path ] ) ) {
			return self::$stat_cache[ $parsed_path ];
		}

		// Create stat (with HEAD request)
		$stat = $this->create_stat( $parsed_path, $flags );

		// Cache result (even if false)
		self::$stat_cache[ $parsed_path ] = $stat;

		return $stat;
	}

	/**
	 * Create stat by checking file existence in Azure
	 *
	 * @param string $path
	 * @param int    $flags
	 * @return array<int|string, mixed>|false
	 */
	private function create_stat( $path, $flags ) {
		$cloud_client = self::get_cloud_client();
		if ( ! $cloud_client ) {
			return $this->trigger_error_internal( 'Cloud client not available', $flags );
		}

		// Try to check if file exists in Azure (HEAD request)
		// Note: file_exists() returns boolean (true/false)
		try {
			$exists = $cloud_client->file_exists( $path );
		} catch ( \Exception $e ) {
			Logger::error( '[DiluxOne Offload CloudStreamWrapper] create_stat exception: ' . $path . ' - ' . $e->getMessage() );
			return $this->trigger_error_internal( 'Cloud error: ' . $e->getMessage(), $flags );
		}

		if ( ! $exists ) {
			// File doesn't exist - trigger error (returns false)
			return $this->trigger_error_internal( 'File or directory not found: ' . $path, $flags );
		}

		// File exists - return stat array
		return $this->format_url_stat( array() );
	}

	/**
	 * Trigger error based on flags
	 *
	 * @param string   $error
	 * @param int|null $flags
	 * @return false|array<int|string, mixed>
	 */
	private function trigger_error_internal( $error, $flags = null ) {
		// This is triggered with things like file_exists()
		if ( $flags & STREAM_URL_STAT_QUIET ) {
			return $flags & STREAM_URL_STAT_LINK
				? $this->format_url_stat( false )
				: false;
		}

		// This is triggered when doing things like lstat() or stat().
		// PHP stream wrapper protocol expects errors to be raised via trigger_error()
		// so callers like fopen() / file_exists() can detect them. trigger_error()
		// respects display_errors, and some callers here do pass dynamic text (a
		// caught exception's message, a request path) — reachable by anyone
		// requesting a missing attachment, not just wp-admin. Only WP_DEBUG sites
		// get the detail; everyone else gets a fixed, uninformative message.
		$message = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? (string) $error : 'Cloud storage stat failed';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- Required by PHP stream wrapper protocol; message is internal and WP_DEBUG-gated above.
		trigger_error( $message, E_USER_WARNING );

		return false;
	}

	/**
	 * Prepare a url_stat result array
	 *
	 * @param mixed $result
	 * @return array<int|string, int>
	 */
	private function format_url_stat( $result = null ) {
		$stat = array(
			0         => 0,
			'dev'     => 0,
			1         => 0,
			'ino'     => 0,
			2         => 0,
			'mode'    => 0,
			3         => 0,
			'nlink'   => 0,
			4         => 0,
			'uid'     => 0,
			5         => 0,
			'gid'     => 0,
			6         => -1,
			'rdev'    => -1,
			7         => 0,
			'size'    => 0,
			8         => 0,
			'atime'   => 0,
			9         => 0,
			'mtime'   => 0,
			10        => 0,
			'ctime'   => 0,
			11        => -1,
			'blksize' => -1,
			12        => -1,
			'blocks'  => -1,
		);

		switch ( gettype( $result ) ) {
			case 'NULL':
			case 'string':
				// Directory with 0777 access
				$stat[2]      = 0040777;
				$stat['mode'] = 0040777;
				break;
			case 'array':
				// Regular file with 0777 access
				$stat[2]      = 0100777;
				$stat['mode'] = 0100777;
				// Apply caller-provided size when present (e.g. in-memory buffer
				// mode passes the buffered length here so fstat()/filesize()
				// don't always report 0 bytes for cloud-backed streams).
				if ( isset( $result['size'] ) && is_int( $result['size'] ) ) {
					$stat[7]      = $result['size'];
					$stat['size'] = $result['size'];
				}
				break;
		}

		return $stat;
	}

	/**
	 * Stream wrapper: Check if file exists
	 *
	 * Assumes the file is there instead of paying for a HEAD request: the
	 * caller only asks about paths the plugin itself wrote.
	 *
	 * @param string $path
	 * @return bool
	 */
	public function stream_exists( $path ) {
		// Always true: if something asked for this path, treat it as present.
		// Evita HEAD request innecesario
		return true;
	}

	/**
	 * Stream wrapper: Delete file
	 *
	 * Always succeeds, even when the blob is already gone: plugins delete
	 * temp files during regeneration and cloud latency can answer 404, and
	 * either way the goal — the file does not exist — is met.
	 *
	 * @param string $path
	 * @return bool
	 */
	public function unlink( $path ) {
		$parsed_path = $this->parse_path( $path );

		$cloud_client = self::get_cloud_client();
		if ( ! $cloud_client ) {
			return false;
		}

		// Delete from cloud (ignore result - file might not exist, that's OK)
		try {
			$result = $cloud_client->delete_file( $parsed_path );
		} catch ( \Exception $e ) {
			Logger::error( '[DiluxOne Offload CloudStreamWrapper] unlink exception (non-critical): ' . $parsed_path . ' - ' . $e->getMessage() );
			$result = array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}

		// Whether the delete succeeded or the file was already gone, the goal
		// is met: clear the caches and report success.
		unset( self::$stat_cache[ $parsed_path ] );
		unset( self::$file_cache[ $parsed_path ] );

		// Cache as "deleted" so later stat checks do not ask the cloud again.
		self::$stat_cache[ $parsed_path ] = false;

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			if ( $result['success'] ) {
				Logger::info( '[DiluxOne Offload CloudStreamWrapper] Deleted: ' . $parsed_path );
			} else {
				// Log but don't fail (file might already be deleted, that's fine)
				Logger::error( '[DiluxOne Offload CloudStreamWrapper] Delete result (non-critical): ' . $parsed_path . ' - ' . $result['error'] );
			}
		}

		// ⭐ ALWAYS return true (goal achieved: file doesn't exist)
		return true;
	}

	/**
	 * Stream wrapper: Rename/move file
	 *
	 * This is CRITICAL for plugins like Astra that use atomic writes:
	 * 1. Write to temp file: file_put_contents('temp.css', $content)
	 * 2. Atomic rename: rename('temp.css', 'final.css')
	 *
	 * Implementation uses Azure Copy Blob API for efficient server-side copy:
	 * - Copy source blob to destination (server-side, no download/upload)
	 * - Delete source blob
	 * - Update cache accordingly
	 *
	 * @param string $path_from Source path
	 * @param string $path_to Destination path
	 * @return bool True on success, false on failure
	 */
	public function rename( $path_from, $path_to ) {
		$parsed_from = $this->parse_path( $path_from );
		$parsed_to   = $this->parse_path( $path_to );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			Logger::info( '[DiluxOne Offload CloudStreamWrapper] rename: ' . $parsed_from . ' -> ' . $parsed_to );
		}

		$cloud_client = self::get_cloud_client();
		if ( ! $cloud_client ) {
			Logger::error( '[DiluxOne Offload CloudStreamWrapper] rename failed: Cloud client not available' );
			return false;
		}

		// Step 1: Copy blob from source to destination (server-side)
		try {
			$copy_result = $cloud_client->copy_blob( $parsed_from, $parsed_to );
		} catch ( \Exception $e ) {
			Logger::error( '[DiluxOne Offload CloudStreamWrapper] rename copy exception: ' . $parsed_from . ' -> ' . $parsed_to . ' - ' . $e->getMessage() );
			return false;
		}

		if ( ! $copy_result['success'] ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				Logger::warning( '[DiluxOne Offload CloudStreamWrapper] rename failed at copy: ' . ( $copy_result['error'] ?? 'Unknown error' ) );
			}
			return false;
		}

		// Step 2: Delete source blob
		try {
			$delete_result = $cloud_client->delete_file( $parsed_from );
		} catch ( \Exception $e ) {
			Logger::error( '[DiluxOne Offload CloudStreamWrapper] rename delete exception (non-critical): ' . $parsed_from . ' - ' . $e->getMessage() );
			$delete_result = array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}

		// Note: We don't check delete result strictly because:
		// - Copy succeeded, so destination file exists ✓
		// - If delete fails, source file still exists (not ideal but not critical)
		// - Goal of rename is achieved: file exists at destination
		if ( ! $delete_result['success'] ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				Logger::warning( '[DiluxOne Offload CloudStreamWrapper] rename: copy succeeded but delete failed (non-critical): ' . ( $delete_result['error'] ?? 'Unknown error' ) );
			}
		}

		// Step 3: Update cache
		// Transfer cache from source to destination
		$cached_content = $this->cache_get( $parsed_from );
		if ( $cached_content !== null ) {
			$this->cache_set( $parsed_to, $cached_content );
		}

		// Transfer stat cache from source to destination
		if ( isset( self::$stat_cache[ $parsed_from ] ) ) {
			self::$stat_cache[ $parsed_to ] = self::$stat_cache[ $parsed_from ];
		}

		// Clear source from cache (mark as deleted)
		unset( self::$file_cache[ $parsed_from ] );
		unset( self::$stat_cache[ $parsed_from ] );
		self::$stat_cache[ $parsed_from ] = false; // Mark as deleted

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			Logger::info( '[DiluxOne Offload CloudStreamWrapper] rename successful: ' . $parsed_from . ' -> ' . $parsed_to );
		}

		return true;
	}

	/**
	 * Stream wrapper: Create directory
	 *
	 * @param string $path
	 * @param int    $mode
	 * @param int    $options
	 * @return bool
	 */
	public function mkdir( $path, $mode, $options ) {
		// Cloud storage doesn't need directory creation
		// Just return true to let WordPress think it succeeded
		return true;
	}

	/**
	 * Stream wrapper: Remove directory
	 *
	 * @param string $path
	 * @param int    $options
	 * @return bool
	 */
	public function rmdir( $path, $options ) {
		// Cloud storage doesn't have explicit directories
		return true;
	}

	/**
	 * Parse stream wrapper path to get relative cloud path
	 *
	 * @param string $path
	 * @return string
	 */
	private function parse_path( $path ) {
		// Remove protocol and normalize path
		$path = str_replace( self::PROTOCOL . '://', '', $path );
		return ltrim( $path, '/' );
	}

	/**
	 * Get cloud client instance
	 *
	 * @return CloudStorageClientInterface|null
	 */
	private static function get_cloud_client() {
		if ( self::$cloud_client === null ) {
			self::$cloud_client = ConfigManager::get_cloud_client();
		}

		return self::$cloud_client;
	}

	/**
	 * Clear stat cache
	 */
	public static function clear_stat_cache(): void {
		self::$stat_cache = array();
	}

	/**
	 * File cache methods
	 *
	 * A per-request cache of the last small file read or written, so the
	 * reads WordPress makes right after an upload (thumbnail generation)
	 * do not download it again.
	 */

	/**
	 * Get cached file content
	 *
	 * @param string $path File path
	 * @return string|null File content or null if not cached
	 */
	private function cache_get( $path ) {
		if ( isset( self::$file_cache[ $path ] ) ) {
			return self::$file_cache[ $path ];
		}
		return null;
	}

	/**
	 * Cache file content
	 *
	 * Only caches files up to CACHE_MAX_BYTES.
	 *
	 * @param string $path File path
	 * @param string $content File content
	 */
	private function cache_set( $path, $content ): void {
		// Don't cache files that are too big
		if ( strlen( $content ) > self::CACHE_MAX_BYTES ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				Logger::info( '[DiluxOne Offload CloudStreamWrapper] File too large to cache: ' . $path . ' (' . strlen( $content ) . ' bytes)' );
			}
			return;
		}

		// Only keep the most recent file
		self::$file_cache          = array();
		self::$file_cache[ $path ] = $content;

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			Logger::info( '[DiluxOne Offload CloudStreamWrapper] Cached file: ' . $path . ' (' . strlen( $content ) . ' bytes)' );
		}
	}

	/**
	 * Clear file cache
	 */
	public static function clear_file_cache(): void {
		self::$file_cache = array();
	}

	/**
	 * Directory methods
	 *
	 * These methods back opendir(), readdir(), rewinddir() and closedir().
	 */

	/**
	 * Open directory handle
	 *
	 * Called by opendir() - lists blobs with given prefix
	 *
	 * @param string   $path Directory path
	 * @param int|null $options Options (null when called via dir_rewinddir)
	 * @return bool
	 */
	public function dir_opendir( $path, $options ) {
		$this->dir_path   = $this->parse_path( $path );
		$this->dir_prefix = rtrim( $this->dir_path, '/' ) . '/';

		$cloud_client = self::get_cloud_client();
		if ( ! $cloud_client ) {
			Logger::error( '[DiluxOne Offload CloudStreamWrapper] dir_opendir: Cloud client not available' );
			return false;
		}

		// List blobs with prefix (simulate directory listing)
		// Note: Azure doesn't have native "listBlobs with prefix" in our client
		// For now, we'll create an empty iterator to prevent errors
		// This is a simplified version - full implementation would require Azure Blob list API
		$this->dir_iterator = array();

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			Logger::info( '[DiluxOne Offload CloudStreamWrapper] dir_opendir: ' . $this->dir_path );
		}

		return true;
	}

	/**
	 * Read directory entry
	 *
	 * Called by readdir() - returns next file/directory name
	 *
	 * @return string|false
	 */
	public function dir_readdir() {
		// Check if iterator is valid
		if ( ! is_array( $this->dir_iterator ) || empty( $this->dir_iterator ) ) {
			return false;
		}

		// Get current item and advance
		$current = array_shift( $this->dir_iterator );

		if ( $current === null ) {
			return false;
		}

		// Remove prefix to return relative path
		if ( $this->dir_prefix && strpos( $current, $this->dir_prefix ) === 0 ) {
			return substr( $current, strlen( $this->dir_prefix ) );
		}

		return $current;
	}

	/**
	 * Close directory handle
	 *
	 * Called by closedir()
	 *
	 * @return bool
	 */
	public function dir_closedir() {
		$this->dir_iterator = null;
		$this->dir_path     = '';
		$this->dir_prefix   = '';

		return true;
	}

	/**
	 * Rewind directory handle
	 *
	 * Called by rewinddir() - resets directory pointer
	 *
	 * @return bool
	 */
	public function dir_rewinddir() {
		// Reset by re-opening the directory
		$this->dir_iterator = null;
		return $this->dir_opendir( $this->dir_path, null );
	}
}
