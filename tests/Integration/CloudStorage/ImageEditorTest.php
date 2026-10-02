<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use Tests\Integration\FaultyCloudClient;
use Tests\Integration\LocalBlobServer;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\CloudStreamWrapper;
use DiluxOneOffload\Enums\PluginState;
use DiluxOneOffload\DiluxOneOffload_Image_Editor_GD;
use DiluxOneOffload\DiluxOneOffload_Image_Editor_Imagick;

/**
 * Integration tests for the image editors that work on diluxoneoffload://
 * paths. WordPress generates every thumbnail through one of these, so if
 * they break, every upload after offloading has no sizes.
 *
 * The editors copy the blob to a temp file, let the parent editor work on
 * disk, and copy the result back through the stream wrapper — so this
 * exercises the wrapper's read and write paths with a real image library.
 */
class ImageEditorTest extends IntegrationTestCase {

    private static ?LocalBlobServer $server = null;
    private FaultyCloudClient $client;

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
        require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';
        require_once ABSPATH . WPINC . '/class-wp-image-editor-imagick.php';
        self::$server = new LocalBlobServer(8770);
    }

    public static function tearDownAfterClass(): void {
        if (self::$server) {
            self::$server->stop();
            self::$server = null;
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void {
        parent::setUp();
        $this->client = new FaultyCloudClient(self::$server->base_url);
        add_filter('diluxone_offload_pre_cloud_client', [$this, 'injectClient']);
        ConfigManager::save_config([
            'cloud_provider'  => 'azure',
            'provider_config' => ['storage_account' => 'imgacct', 'container_name' => 'media', 'access_key' => base64_encode(random_bytes(32))],
        ]);
        ConfigManager::set_state(PluginState::SYNCED);
        CloudStreamWrapper::clear_stat_cache();
        CloudStreamWrapper::clear_file_cache();
        $this->assertTrue(CloudStreamWrapper::activate_offloading(), 'offloading on: wp_upload_dir points at the protocol');

        // A real 64x48 PNG "in the cloud".
        $im = imagecreatetruecolor(64, 48);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
        ob_start();
        imagepng($im);
        $this->client->blobs['uploads/2026/09/photo.png'] = (string) ob_get_clean();
        imagedestroy($im);
    }

    protected function tearDown(): void {
        CloudStreamWrapper::deactivate_offloading();
        CloudStreamWrapper::clear_stat_cache();
        CloudStreamWrapper::clear_file_cache();
        remove_filter('diluxone_offload_pre_cloud_client', [$this, 'injectClient']);
        parent::tearDown();
    }

    public function injectClient($pre) {
        return $this->client;
    }

    private function cloudPath(): string {
        $dir = wp_upload_dir();
        $this->assertStringStartsWith('diluxoneoffload://', $dir['basedir'], 'precondition: uploads live on the wrapper');
        return $dir['basedir'] . '/2026/09/photo.png';
    }

    public function test_gd_editor_loads_from_the_cloud_and_saves_a_resized_copy_back(): void {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD not available');
        }
        $editor = new DiluxOneOffload_Image_Editor_GD($this->cloudPath());
        $loaded = $editor->load();
        $this->assertTrue($loaded === true, is_wp_error($loaded) ? $loaded->get_error_message() : 'load failed');
        $this->assertSame(['width' => 64, 'height' => 48], $editor->get_size());

        $editor->resize(32, 24, true);
        $saved = $editor->save();

        $this->assertIsArray($saved, is_wp_error($saved) ? $saved->get_error_message() : 'save failed');
        $this->assertStringStartsWith('diluxoneoffload://', $saved['path']);
        $key = ltrim(str_replace('diluxoneoffload://', '', $saved['path']), '/');
        $this->assertArrayHasKey($key, $this->client->blobs, 'the resized image was uploaded');
        $info = getimagesizefromstring($this->client->blobs[$key]);
        $this->assertSame([32, 24], [$info[0], $info[1]]);
        unset($editor); // __destruct removes its temp files
    }

    public function test_imagick_editor_copies_the_blob_to_a_temp_file_before_decoding(): void {
        if (!class_exists('Imagick')) {
            $this->markTestSkipped('Imagick not available');
        }
        $editor = new DiluxOneOffload_Image_Editor_Imagick($this->cloudPath());
        $loaded = $editor->load();
        $tempsProp = new \ReflectionProperty($editor, 'temp_files_to_cleanup');
        if ( PHP_VERSION_ID < 80100 ) { // Required before 8.1, deprecated from 8.5.
        	$tempsProp->setAccessible( true );
        }
        $temps  = $tempsProp->getValue($editor);
        $this->assertCount(1, $temps, 'one temp copy was made');
        $this->assertSame($this->client->blobs['uploads/2026/09/photo.png'], file_get_contents($temps[0]), 'temp copy holds the blob');
        if (!\Imagick::queryFormats('PNG')) {
            $this->assertTrue(is_wp_error($loaded), 'without a PNG delegate Imagick reports the failure instead of hiding it');
        } else {
            $this->assertTrue($loaded);
        }
        unset($editor);
        $this->assertFileDoesNotExist($temps[0], '__destruct removed the temp copy');
    }

    public function test_imagick_editor_reports_a_missing_cloud_file(): void {
        if (!class_exists('Imagick')) {
            $this->markTestSkipped('Imagick not available');
        }
        $editor = new DiluxOneOffload_Image_Editor_Imagick(wp_upload_dir()['basedir'] . '/2026/09/missing.png');
        $this->assertTrue(is_wp_error($editor->load()));
    }

    public function test_imagick_editor_loads_from_the_cloud_and_saves_back(): void {
        if (!class_exists('Imagick') || !\Imagick::queryFormats('PNG')) {
            $this->markTestSkipped('Imagick without a PNG delegate in this runtime');
        }
        $editor = new DiluxOneOffload_Image_Editor_Imagick($this->cloudPath());
        $loaded = $editor->load();
        $this->assertTrue($loaded === true, is_wp_error($loaded) ? $loaded->get_error_message() : 'load failed');

        $editor->resize(16, 12, true);
        $saved = $editor->save();

        $this->assertIsArray($saved, is_wp_error($saved) ? $saved->get_error_message() : 'save failed');
        $key = ltrim(str_replace('diluxoneoffload://', '', $saved['path']), '/');
        $this->assertArrayHasKey($key, $this->client->blobs, 'blobs: ' . implode(',', array_keys($this->client->blobs)) . ' local: ' . var_export(file_exists(WP_CONTENT_DIR . '/uploads/2026/09/photo-16x12.png'), true) . ' saved: ' . print_r($saved, true));
        $info = getimagesizefromstring($this->client->blobs[$key]);
        $this->assertSame([16, 12], [$info[0], $info[1]]);
        unset($editor);
    }

    public function test_editors_save_local_targets_through_the_parent(): void {
        $out = sys_get_temp_dir() . '/dlx-local-' . uniqid() . '.png';
        $gd  = new DiluxOneOffload_Image_Editor_GD($this->cloudPath());
        $this->assertTrue($gd->load());
        $saved = $gd->save($out);
        $this->assertSame($out, $saved['path']);
        $this->assertFileExists($out);
        $this->assertArrayNotHasKey('uploads/' . basename($out), $this->client->blobs, 'nothing went to the cloud');
        unlink($out);
        if (class_exists('Imagick') && \Imagick::queryFormats('PNG')) {
            $im = new DiluxOneOffload_Image_Editor_Imagick($this->cloudPath());
            $this->assertTrue($im->load());
            $this->assertTrue($im->load(), 'loading twice is a no-op');
            $saved = $im->save($out);
            $this->assertSame($out, $saved['path']);
            unlink($out);
        }
    }

    public function test_gd_editor_reports_a_missing_cloud_file(): void {
        $editor = new DiluxOneOffload_Image_Editor_GD(wp_upload_dir()['basedir'] . '/2026/09/missing.png');
        $this->assertTrue(is_wp_error($editor->load()));
    }

    public function test_gd_editor_falls_through_to_the_parent_for_local_paths(): void {
        $tmp = tempnam(sys_get_temp_dir(), 'img') . '.png';
        $im = imagecreatetruecolor(8, 8);
        imagepng($im, $tmp);
        imagedestroy($im);
        $editor = new DiluxOneOffload_Image_Editor_GD($tmp);
        $this->assertTrue($editor->load());
        $this->assertSame(['width' => 8, 'height' => 8], $editor->get_size());
        @unlink($tmp);
    }

    // ── When the cloud is not there ─────────────────────────

    private function pauseWrites(): void {
        update_option('diluxone_offload_connection_health', ['status' => 'unhealthy', 'last_check' => time(), 'last_success' => 0, 'error_code' => '403', 'error_message' => 'HTTP 403', 'error_source' => 'upload', 'consecutive_failures' => ConfigManager::PAUSE_AFTER_FAILURES]);
    }

    public function test_gd_editor_reports_a_thumbnail_the_cloud_refuses_and_writes_nothing_locally(): void {
        $editor = new DiluxOneOffload_Image_Editor_GD($this->cloudPath());
        $this->assertTrue($editor->load());
        $this->assertTrue($editor->load(), 'loading twice is a no-op');
        $editor->resize(32, 24, true);
        $this->pauseWrites();

        $saved = @$editor->save();

        $this->assertInstanceOf(\WP_Error::class, $saved);
        $this->assertSame('unable-to-copy-to-cloud', $saved->get_error_code());
        $this->assertSame(['uploads/2026/09/photo.png'], array_keys($this->client->blobs), 'no thumbnail reached the cloud');
        $this->assertFileDoesNotExist(CloudStreamWrapper::native_upload_basedir() . '/2026/09/photo-32x24.png', 'no local fallback');
    }

    public function test_imagick_editor_reports_a_thumbnail_the_cloud_refuses(): void {
        if (!class_exists('Imagick') || !\Imagick::queryFormats('PNG')) {
            $this->markTestSkipped('Imagick without a PNG delegate in this runtime');
        }
        $editor = new DiluxOneOffload_Image_Editor_Imagick($this->cloudPath());
        $this->assertTrue($editor->load());
        $editor->resize(16, 12, true);
        $this->pauseWrites();

        $saved = @$editor->save();

        $this->assertInstanceOf(\WP_Error::class, $saved);
        $this->assertSame('unable-to-copy-to-cloud', $saved->get_error_code());
        $this->assertCount(1, $this->client->blobs);
    }

    public function test_imagick_editor_reports_a_blob_that_cannot_be_downloaded(): void {
        if (!class_exists('Imagick')) {
            $this->markTestSkipped('Imagick not available');
        }
        $this->client->download_error = 'HTTP 500 InternalError';
        $editor = new DiluxOneOffload_Image_Editor_Imagick($this->cloudPath());
        $loaded = @$editor->load();
        $this->assertInstanceOf(\WP_Error::class, $loaded);
        $this->assertSame('unable-to-copy-from-cloud', $loaded->get_error_code());
    }

    public function test_imagick_editor_loads_a_local_file_through_the_parent(): void {
        if (!class_exists('Imagick') || !\Imagick::queryFormats('PNG')) {
            $this->markTestSkipped('Imagick without a PNG delegate in this runtime');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'img') . '.png';
        $im  = imagecreatetruecolor(9, 7);
        imagepng($im, $tmp);
        imagedestroy($im);
        try {
            $editor = new DiluxOneOffload_Image_Editor_Imagick($tmp);
            $this->assertTrue($editor->load());
            $this->assertSame(['width' => 9, 'height' => 7], $editor->get_size());
            $this->assertSame(0, $this->client->downloads);
        } finally {
            @unlink($tmp);
        }
    }

    // ── When the image library cannot write ─────────────────

    /** Temp files the editors create, by their prefix. */
    private static function editorTemps(): array {
        return glob(get_temp_dir() . 'diluxone-offload*') ?: [];
    }

    public function test_gd_editor_save_the_library_fails_removes_its_temp_file_and_uploads_nothing(): void {
        $editor = new class($this->cloudPath()) extends DiluxOneOffload_Image_Editor_GD {
            /** GD refusing to write, as when imagepng() fails. */
            protected function make_image($filename, $callback, $arguments) {
                return false;
            }
        };
        $this->assertTrue($editor->load());
        $before = self::editorTemps();

        $saved = $editor->save();

        $this->assertInstanceOf(\WP_Error::class, $saved);
        $this->assertSame('image_save_error', $saved->get_error_code(), 'the library\'s own error is passed on');
        $this->assertSame($before, self::editorTemps(), 'the temp file of the failed save is gone');
        $this->assertSame(['uploads/2026/09/photo.png'], array_keys($this->client->blobs), 'nothing was copied to the cloud');
    }

    public function test_imagick_editor_save_the_library_fails_removes_its_temp_file_and_uploads_nothing(): void {
        if (!class_exists('Imagick') || !\Imagick::queryFormats('PNG')) {
            $this->markTestSkipped('Imagick without a PNG delegate in this runtime');
        }
        $editor = new class($this->cloudPath()) extends DiluxOneOffload_Image_Editor_Imagick {
            /** An image Imagick cannot encode: it throws, WordPress answers image_save_error. */
            public function lose_the_image(): void {
                $this->image = new \Imagick();
            }
        };
        $this->assertTrue($editor->load());
        $editor->lose_the_image();
        $before = self::editorTemps();

        $saved = $editor->save();

        $this->assertInstanceOf(\WP_Error::class, $saved);
        $this->assertSame('image_save_error', $saved->get_error_code());
        $this->assertSame($before, self::editorTemps(), 'the temp file of the failed save is gone');
        $this->assertCount(1, $this->client->blobs, 'nothing was copied to the cloud');
    }

    public function test_gd_editor_loading_twice_makes_one_temp_copy(): void {
        $editor = new DiluxOneOffload_Image_Editor_GD($this->cloudPath());
        $temps  = new \ReflectionProperty($editor, 'temp_files_to_cleanup');
        if ( PHP_VERSION_ID < 80100 ) { // Required before 8.1, deprecated from 8.5.
            $temps->setAccessible( true );
        }
        $this->assertTrue($editor->load());
        $this->assertCount(1, $temps->getValue($editor));

        $this->assertTrue($editor->load());

        $this->assertCount(1, $temps->getValue($editor));
    }
}
