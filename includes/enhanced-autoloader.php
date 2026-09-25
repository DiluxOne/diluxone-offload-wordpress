<?php
/**
 * Autoloader for DiluxOne Offload.
 *
 * Loads plugin classes from includes/ via a custom mapping that supports
 * both legacy WP-style filenames (`class-diluxone-offload-foo.php`) and modern
 * PSR-12 PascalCase filenames (`Foo.php`). Both styles coexist in the
 * codebase by design.
 *
 * @package DiluxOneOffload
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Autoloader function
 *
 * @param string $class_name
 */
function diluxone_offload_autoloader( string $class_name ): void {
	// Only autoload our classes
	if ( strpos( $class_name, 'DiluxOneOffload\\' ) !== 0 ) {
		return;
	}

	// Remove namespace prefix
	$class_name = str_replace( 'DiluxOneOffload\\', '', $class_name );

	// Class to file mappings
	$class_mappings = array(
		// Core classes
		'ConfigManager'                           => 'includes/class-diluxone-offload-config-manager.php',
		'SyncManager'                             => 'includes/class-diluxone-offload-sync-manager.php',
		'CloudStreamWrapper'                      => 'includes/class-diluxone-offload-cloud-stream-wrapper.php',
		'Logger'                                  => 'includes/class-diluxone-offload-logger.php',
		'Crypto'                                  => 'includes/class-diluxone-offload-crypto.php',
		'Admin'                                   => 'includes/class-diluxone-offload-admin.php',
		'Plugin'                                  => 'includes/class-diluxone-offload-plugin-enhanced.php',
		'ValidationHelper'                        => 'includes/class-diluxone-offload-validation-helper.php',
		'MimeHelper'                              => 'includes/class-diluxone-offload-mime-helper.php',

		// Image Editors
		'DiluxOneOffload_Image_Editor_Imagick'    => 'includes/class-diluxone-offload-image-editor-imagick.php',
		'DiluxOneOffload_Image_Editor_GD'         => 'includes/class-diluxone-offload-image-editor-gd.php',

		// Enums
		'Enums\\PluginState'                      => 'includes/Enums/class-plugin-state.php',

		// Interfaces
		'Interfaces\\CloudStorageClientInterface' => 'includes/interfaces/interface-cloud-storage-client.php',

		// Providers
		'Providers\\AzureProvider'                => 'includes/providers/class-azure-provider.php',

		// Factories
		'Factories\\CloudStorageFactory'          => 'includes/factories/class-cloud-storage-factory.php',

		// DTOs (Data Transfer Objects)
		'DTOs\\OperationResult'                   => 'includes/DTOs/OperationResult.php',
		'DTOs\\UploadResult'                      => 'includes/DTOs/UploadResult.php',
		'DTOs\\ConnectionResult'                  => 'includes/DTOs/ConnectionResult.php',
		'DTOs\\FileInfo'                          => 'includes/DTOs/FileInfo.php',
		'DTOs\\AzureConfig'                       => 'includes/DTOs/AzureConfig.php',
		'DTOs\\PluginConfig'                      => 'includes/DTOs/PluginConfig.php',
		'DTOs\\ProviderConfig'                    => 'includes/DTOs/ProviderConfig.php',
		'DTOs\\PluginSettings'                    => 'includes/DTOs/PluginSettings.php',
		'DTOs\\SyncFilter'                        => 'includes/DTOs/SyncFilter.php',

		// Enums
		'Enums\\SyncStatus'                       => 'includes/Enums/SyncStatus.php',
	);

	// Check if we have a mapping for this class
	if ( isset( $class_mappings[ $class_name ] ) ) {
		$file_path = DILUXONE_OFFLOAD_DIR . $class_mappings[ $class_name ];

		if ( file_exists( $file_path ) ) {
			require_once $file_path;
			// Verbose autoloader trace removed — re-enable temporarily during
			// classloader debugging only (will spam debug.log on every request).
			return;
		}
	}

	// Fallback: try to auto-generate file path
	$potential_paths = array(
		'includes/class-diluxone-offload-' . strtolower( str_replace( '\\', '-', $class_name ) ) . '.php',
		'includes/class-diluxone-offload-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php',
	);

	foreach ( $potential_paths as $path ) {
		$full_path = DILUXONE_OFFLOAD_DIR . $path;
		if ( file_exists( $full_path ) ) {
			require_once $full_path;
			return;
		}
	}
}

// Register the autoloader
spl_autoload_register( 'diluxone_offload_autoloader' );
