<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use Tests\Integration\FaultyCloudClient;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\CloudStreamWrapper;
use DiluxOneOffload\DiluxOneOffloadDB;
use DiluxOneOffload\Enums\PluginState;

/**
 * The stream wrapper against a real WordPress: what an upload, a read, an
 * edit, a delete and a rename through diluxoneoffload:// leave in the cloud,
 * in the tracking table and in the connection health, and how each failure
 * of the provider reaches the caller. No local fallback: whatever fails,
 * nothing is written to the native uploads directory instead.
 */
class StreamWrapperTest extends IntegrationTestCase {

    private FaultyCloudClient $client;
    private string $native_basedir = '';

    protected function setUp(): void {
        parent::setUp();
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $this->native_basedir = wp_upload_dir()['basedir'];
        // The wrapper never opens a socket for these paths; the URL is unused.
        $this->client = new FaultyCloudClient('http://127.0.0.1:9');
        add_filter('diluxone_offload_pre_cloud_client', [$this, 'injectClient']);
        ConfigManager::save_config([
            'cloud_provider'  => 'azure',
            'provider_config' => ['storage_account' => 'wrapacct', 'container_name' => 'media', 'access_key' => base64_encode(random_bytes(32))],
        ]);
        ConfigManager::set_state(PluginState::SYNCED);
        CloudStreamWrapper::clear_stat_cache();
        CloudStreamWrapper::clear_file_cache();
        $this->assertTrue(CloudStreamWrapper::activate_offloading());
    }

    protected function tearDown(): void {
        CloudStreamWrapper::deactivate_offloading();
        CloudStreamWrapper::clear_stat_cache();
        CloudStreamWrapper::clear_file_cache();
        remove_filter('diluxone_offload_pre_cloud_client', [$this, 'injectClient']);
        delete_option(ConfigManager::LAST_UPLOAD_OPTION);
        delete_option('uploads_use_yearmonth_folders');
        parent::tearDown();
    }

    public function injectClient($pre) {
        return $this->client;
    }

    /**
     * Deleting an attachment frees its local copy once the object is gone,
     * and only this site's: on the network's main site a key under another
     * site's uploads/sites/<id>/ never touches that site's disk.
     */
    public function test_unlink_frees_this_sites_local_copy_and_never_another_sites(): void {
        $this->assertTrue(is_main_site(), 'precondition: the network\'s main site');
        $own   = $this->native_basedir . '/2026/10/own-copy.jpg';
        $other = $this->native_basedir . '/sites/2/2026/10/other-copy.jpg';
        wp_mkdir_p(dirname($own));
        wp_mkdir_p(dirname($other));
        file_put_contents($own, 'own');
        file_put_contents($other, 'other');
        try {
            $this->assertTrue(unlink($this->cloud('2026/10/own-copy.jpg')));
            $this->assertFileDoesNotExist($own, 'the local copy goes with the object');

            unlink('diluxoneoffload://uploads/sites/2/2026/10/other-copy.jpg');
            $this->assertFileExists($other, 'another site\'s local file stays');
        } finally {
            @unlink($own);
            @unlink($other);
        }
    }

    private function cloud(string $relative): string {
        $base = wp_upload_dir()['basedir'];
        $this->assertStringStartsWith('diluxoneoffload://', $base, 'precondition: uploads live on the wrapper');
        return $base . '/' . ltrim($relative, '/');
    }

    private function trackingRow(string $key): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . self::$table_name . '` WHERE file = %s', DiluxOneOffloadDB::path_from_key($key)), ARRAY_A);
        return $row ?: null;
    }

    private function assertNothingWrittenLocally(string $relative): void {
        $this->assertFileDoesNotExist($this->native_basedir . '/' . ltrim($relative, '/'), 'no local fallback');
    }

    /** A real PNG in a temp file, as a browser upload leaves it. */
    private function pngUpload(string $name): array {
        $tmp = wp_tempnam($name);
        $im  = imagecreatetruecolor(8, 8);
        imagepng($im, $tmp);
        imagedestroy($im);
        return ['name' => $name, 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize($tmp)];
    }

    private function sideload(string $name): array {
        $file = $this->pngUpload($name);
        return wp_handle_sideload($file, ['test_form' => false]);
    }

    private function pause(int $last_check): void {
        update_option('diluxone_offload_connection_health', [
            'status'               => 'unhealthy',
            'last_check'           => $last_check,
            'last_success'         => 0,
            'error_code'           => '403',
            'error_message'        => 'HTTP 403',
            'error_source'         => 'upload',
            'consecutive_failures' => ConfigManager::PAUSE_AFTER_FAILURES,
        ]);
    }

    // ── Uploads through WordPress ──────────────────────────

    public function test_a_sideload_lands_in_the_cloud_and_in_the_tracking_table(): void {
        $result = $this->sideload('sideload-ok.png');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertStringStartsWith('diluxoneoffload://uploads/', $result['file']);
        $this->assertStringStartsWith('https://fake.cloud/uploads/', $result['url']);
        $key = substr($result['file'], strlen('diluxoneoffload://'));
        $this->assertArrayHasKey($key, $this->client->blobs);
        $this->assertStringStartsWith("\x89PNG", $this->client->blobs[$key]);
        $row = $this->trackingRow($key);
        $this->assertNotNull($row, 'the live upload is tracked');
        $this->assertSame('1', (string) $row['synced']);
        $this->assertSame(DiluxOneOffloadDB::path_from_key($key), get_option(ConfigManager::LAST_UPLOAD_OPTION)['path']);
        $this->assertNothingWrittenLocally(DiluxOneOffloadDB::path_from_key($key));
    }

    public function test_a_sideload_the_cloud_refuses_is_reported_to_wordpress_as_failed(): void {
        $this->client->upload_error = 'HTTP 403 AuthorizationFailure';

        $result = $this->sideload('sideload-refused.png');

        $this->assertArrayHasKey('error', $result, 'media_handle_sideload() must get a WP_Error, not an attachment for a missing file');
        $this->assertStringContainsString('could not be uploaded to cloud storage', $result['error']);
        $this->assertStringContainsString('HTTP 403 AuthorizationFailure', $result['error']);
        $this->assertSame([], $this->client->blobs);
        $health = ConfigManager::get_connection_health();
        $this->assertSame('unhealthy', $health['status']);
        $this->assertSame('403', $health['error_code']);
        $this->assertSame('upload', $health['error_source']);
        $this->assertNothingWrittenLocally(gmdate('Y/m') . '/sideload-refused.png');
    }

    public function test_an_upload_that_throws_is_recorded_as_an_exception_and_fails_the_upload(): void {
        $this->client->throws['upload_file'] = 'socket closed';

        $result = $this->sideload('sideload-throws.png');

        $this->assertStringContainsString('socket closed', $result['error'] ?? '');
        $health = ConfigManager::get_connection_health();
        $this->assertSame('exception', $health['error_code']);
        $this->assertSame('upload', $health['error_source']);
        $this->assertSame(1, $health['consecutive_failures']);
    }

    public function test_the_upload_result_filter_leaves_other_results_alone(): void {
        $error = ['error' => 'core said no'];
        $this->assertSame($error, CloudStreamWrapper::fail_upload_if_write_failed($error));
        $local = ['file' => '/tmp/elsewhere.png', 'url' => 'http://x/elsewhere.png', 'type' => 'image/png'];
        $this->assertSame($local, CloudStreamWrapper::fail_upload_if_write_failed($local));
        $fine = ['file' => 'diluxoneoffload://uploads/2026/09/fine.png', 'url' => 'u', 'type' => 'image/png'];
        $this->assertSame($fine, CloudStreamWrapper::fail_upload_if_write_failed($fine), 'no failure recorded for it');
        $this->assertSame('not-an-array', CloudStreamWrapper::fail_upload_if_write_failed('not-an-array'));
    }

    public function test_a_failure_is_reported_once_and_a_later_success_clears_it(): void {
        $path = $this->cloud('2026/09/retry.txt');
        $this->client->upload_error = 'HTTP 500';
        @file_put_contents($path, 'first');
        $this->client->upload_error = null;
        $this->assertSame(6, file_put_contents($path, 'second'));
        $this->assertNull(CloudStreamWrapper::take_write_failure($path), 'the success forgot the failure');
        $this->assertSame('second', $this->client->blobs['uploads/2026/09/retry.txt']);
    }

    public function test_an_upload_that_goes_through_ends_a_recorded_failure(): void {
        ConfigManager::record_connection_failure('500', 'HTTP 500', 'upload');
        $this->assertSame(4, file_put_contents($this->cloud('2026/09/heal.txt'), 'heal'));
        $health = ConfigManager::get_connection_health();
        $this->assertSame('healthy', $health['status']);
        $this->assertSame(0, $health['consecutive_failures']);
    }

    // ── The pause after three failures ─────────────────────

    public function test_writes_are_refused_while_paused_and_nothing_is_written_anywhere(): void {
        $this->pause(time());

        $this->assertFalse(@fopen($this->cloud('2026/09/paused.txt'), 'w'));
        $result = $this->sideload('paused.png');

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(0, $this->client->uploads, 'the provider was not even asked');
        $this->assertSame([], $this->client->blobs);
        $this->assertNothingWrittenLocally('2026/09/paused.txt');
        $this->assertNothingWrittenLocally(gmdate('Y/m') . '/paused.png');
    }

    public function test_a_stale_pause_is_probed_again_and_a_healthy_answer_reopens_writes(): void {
        $this->pause(time() - 600);

        $this->assertSame(5, file_put_contents($this->cloud('2026/09/reopened.txt'), 'again'));
        $this->assertSame('again', $this->client->blobs['uploads/2026/09/reopened.txt']);
        $this->assertSame('healthy', ConfigManager::get_connection_health()['status']);
    }

    public function test_a_stale_pause_whose_probe_fails_keeps_writes_refused(): void {
        $this->pause(time() - 600);
        $this->client->connection_ok = false;

        $this->assertFalse(@fopen($this->cloud('2026/09/still-paused.txt'), 'w'));
        $health = ConfigManager::get_connection_health();
        $this->assertSame(ConfigManager::PAUSE_AFTER_FAILURES + 1, $health['consecutive_failures']);
        $this->assertSame('health_check', $health['error_source']);
        $this->assertSame(0, $this->client->uploads);
    }

    public function test_a_read_is_still_served_while_writes_are_paused(): void {
        $this->client->blobs['uploads/2026/09/readable.txt'] = 'still here';
        $this->pause(time());
        $this->assertSame('still here', file_get_contents($this->cloud('2026/09/readable.txt')));
    }

    // ── Modes ──────────────────────────────────────────────

    public function test_modes_wordpress_never_uses_for_media_are_refused(): void {
        $this->assertFalse(@fopen($this->cloud('2026/09/x.txt'), 'x'));
        $this->assertFalse(@fopen($this->cloud('2026/09/c.txt'), 'c'));
        $this->assertSame([], $this->client->blobs);
    }

    public function test_append_to_a_missing_blob_creates_it(): void {
        $h = fopen($this->cloud('2026/09/new-log.txt'), 'a');
        $this->assertIsResource($h);
        fwrite($h, 'line1');
        fclose($h);
        $this->assertSame('line1', $this->client->blobs['uploads/2026/09/new-log.txt']);
    }

    public function test_append_to_an_existing_blob_keeps_its_bytes(): void {
        $this->client->blobs['uploads/2026/09/log.txt'] = 'old,';
        $h = fopen($this->cloud('2026/09/log.txt'), 'a');
        fwrite($h, 'new');
        fclose($h);
        $this->assertSame('old,new', $this->client->blobs['uploads/2026/09/log.txt']);
    }

    public function test_append_refuses_when_the_blob_cannot_be_read_rather_than_overwrite_it(): void {
        $this->client->blobs['uploads/2026/09/precious.txt'] = 'keep me';
        $this->client->download_error = 'HTTP 500 Internal';
        $this->assertFalse(@fopen($this->cloud('2026/09/precious.txt'), 'a'));
        $this->assertSame('keep me', $this->client->blobs['uploads/2026/09/precious.txt'], 'nothing was overwritten');
        $this->assertSame(0, $this->client->uploads);
    }

    public function test_a_read_of_a_missing_blob_fails(): void {
        $this->assertFalse(@fopen($this->cloud('2026/09/missing.txt'), 'r'));
    }

    public function test_a_read_whose_download_throws_fails_and_leaves_no_temp_file_open(): void {
        $this->client->blobs['uploads/2026/09/flaky.txt'] = 'x';
        $this->client->throws['download_file'] = 'connection reset';
        $this->assertFalse(@fopen($this->cloud('2026/09/flaky.txt'), 'r'));
    }

    public function test_seek_tell_and_fstat_work_on_a_cloud_stream(): void {
        $this->client->blobs['uploads/2026/09/seek.txt'] = 'abcdefgh';
        $h = fopen($this->cloud('2026/09/seek.txt'), 'r');
        $this->assertSame('abc', fread($h, 3));
        $this->assertSame(3, ftell($h));
        $this->assertSame(0, fseek($h, 6));
        $this->assertSame('gh', fread($h, 10));
        $this->assertSame(8, fstat($h)['size']);
        $this->assertFalse(fflush($h), 'a read-only stream has nothing to flush');
        fclose($h);
    }

    public function test_an_unchanged_edit_is_not_uploaded_again(): void {
        $this->client->blobs['uploads/2026/09/edit.txt'] = 'same';
        $h = fopen($this->cloud('2026/09/edit.txt'), 'r+');
        $this->assertTrue(fflush($h));
        fclose($h);
        $this->assertSame(0, $this->client->uploads);
    }

    public function test_a_flushed_write_is_uploaded_once(): void {
        $h = fopen($this->cloud('2026/09/once.css'), 'w');
        fwrite($h, 'body{}');
        $this->assertTrue(fflush($h));
        fclose($h);
        $this->assertSame(1, $this->client->uploads, 'close does not send the same bytes again');
        $this->assertSame('body{}', $this->client->blobs['uploads/2026/09/once.css']);
    }

    // ── Stat, metadata, directories ────────────────────────

    public function test_stat_of_a_blob_is_a_regular_file_and_a_missing_one_says_so_quietly(): void {
        $this->client->blobs['uploads/2026/09/here.jpg'] = 'jpg';
        $this->assertTrue(is_file($this->cloud('2026/09/here.jpg')));
        $this->assertFalse(file_exists($this->cloud('2026/09/gone.jpg')));
        $this->assertFalse(is_link($this->cloud('2026/09/gone2.jpg')));
    }

    public function test_a_loud_stat_of_a_missing_blob_warns_without_the_path_unless_debugging(): void {
        $warnings = [];
        set_error_handler(function (int $no, string $msg) use (&$warnings) {
            if (E_USER_WARNING === $no) { // The wrapper's own; PHP adds its "stat failed for <path>" after it.
                $warnings[] = $msg;
            }
            return true;
        });
        try {
            $this->assertFalse(stat($this->cloud('2026/09/secret-name.jpg')));
        } finally {
            restore_error_handler();
        }
        $this->assertNotEmpty($warnings);
        if (!WP_DEBUG) {
            $this->assertStringContainsString('Cloud storage stat failed', implode("\n", $warnings));
            $this->assertStringNotContainsString('secret-name', implode("\n", $warnings));
        }
    }

    public function test_a_stat_whose_provider_throws_is_a_missing_file_not_a_crash(): void {
        $this->client->throws['file_exists'] = 'dns failure';
        $this->assertFalse(file_exists($this->cloud('2026/09/unknown.jpg')));
    }

    public function test_directories_always_exist_and_metadata_calls_succeed(): void {
        $dir = $this->cloud('2026/09');
        $this->assertTrue(is_dir($dir));
        $this->assertTrue(mkdir($dir . '/sub'));
        $this->assertTrue(rmdir($dir . '/sub'));
        $this->client->blobs['uploads/2026/09/perm.jpg'] = 'p';
        $this->assertTrue(chmod($this->cloud('2026/09/perm.jpg'), 0644));
        $this->assertTrue(touch($this->cloud('2026/09/perm.jpg')));
        $this->assertSame('p', $this->client->blobs['uploads/2026/09/perm.jpg'], 'metadata calls change nothing');
    }

    public function test_a_directory_listing_is_empty_and_rewindable(): void {
        $this->client->blobs['uploads/2026/09/a.jpg'] = 'a';
        $h = opendir($this->cloud('2026/09'));
        $this->assertIsResource($h);
        $this->assertFalse(readdir($h));
        rewinddir($h);
        $this->assertFalse(readdir($h));
        closedir($h);
        $this->assertSame([], scandir($this->cloud('2026/09')));
    }

    // ── Delete and rename ──────────────────────────────────

    public function test_unlink_deletes_the_blob_and_forgets_the_row(): void {
        $this->assertSame(1, file_put_contents($this->cloud('2026/09/del.txt'), 'd'));
        $this->assertNotNull($this->trackingRow('uploads/2026/09/del.txt'));

        $this->assertTrue(unlink($this->cloud('2026/09/del.txt')));

        $this->assertArrayNotHasKey('uploads/2026/09/del.txt', $this->client->blobs);
        $this->assertNull($this->trackingRow('uploads/2026/09/del.txt'));
        $this->assertFalse(file_exists($this->cloud('2026/09/del.txt')), 'known deleted, from the cache');
    }

    public function test_unlink_the_provider_refuses_still_succeeds_but_keeps_the_row(): void {
        file_put_contents($this->cloud('2026/09/keep-row.txt'), 'k');
        $this->client->undeletable = ['uploads/2026/09/keep-row.txt'];

        $this->assertTrue(unlink($this->cloud('2026/09/keep-row.txt')));
        $this->assertNotNull($this->trackingRow('uploads/2026/09/keep-row.txt'), 'the blob may still be there');
    }

    public function test_unlink_whose_provider_throws_still_succeeds_but_keeps_the_row(): void {
        file_put_contents($this->cloud('2026/09/throw-row.txt'), 't');
        $this->client->throws['delete_file'] = 'timeout';

        $this->assertTrue(unlink($this->cloud('2026/09/throw-row.txt')));
        $this->assertNotNull($this->trackingRow('uploads/2026/09/throw-row.txt'));
    }

    public function test_rename_moves_the_blob_and_the_row(): void {
        file_put_contents($this->cloud('2026/09/tmp-astra.css'), 'css');

        $this->assertTrue(rename($this->cloud('2026/09/tmp-astra.css'), $this->cloud('2026/09/astra.css')));

        $this->assertSame('css', $this->client->blobs['uploads/2026/09/astra.css']);
        $this->assertArrayNotHasKey('uploads/2026/09/tmp-astra.css', $this->client->blobs);
        $this->assertNull($this->trackingRow('uploads/2026/09/tmp-astra.css'));
        $this->assertNotNull($this->trackingRow('uploads/2026/09/astra.css'));
        $this->assertSame('css', file_get_contents($this->cloud('2026/09/astra.css')));
        $this->assertFalse(file_exists($this->cloud('2026/09/tmp-astra.css')));
    }

    public function test_rename_of_a_missing_source_fails_and_changes_nothing(): void {
        $this->client->blobs['uploads/2026/09/target.css'] = 'old';
        $this->assertFalse(@rename($this->cloud('2026/09/nope.css'), $this->cloud('2026/09/target.css')));
        $this->assertSame('old', $this->client->blobs['uploads/2026/09/target.css']);
    }

    public function test_rename_whose_copy_throws_fails(): void {
        $this->client->blobs['uploads/2026/09/src.css'] = 's';
        $this->client->throws['copy_blob'] = 'boom';
        $this->assertFalse(@rename($this->cloud('2026/09/src.css'), $this->cloud('2026/09/dst.css')));
        $this->assertArrayNotHasKey('uploads/2026/09/dst.css', $this->client->blobs);
        $this->assertSame('s', $this->client->blobs['uploads/2026/09/src.css']);
    }

    public function test_rename_whose_delete_fails_still_succeeds_with_the_copy_in_place(): void {
        $this->client->blobs['uploads/2026/09/stuck.css'] = 's';
        $this->client->undeletable = ['uploads/2026/09/stuck.css'];
        $this->assertTrue(rename($this->cloud('2026/09/stuck.css'), $this->cloud('2026/09/moved.css')));
        $this->assertSame('s', $this->client->blobs['uploads/2026/09/moved.css']);
        $this->assertSame('s', $this->client->blobs['uploads/2026/09/stuck.css'], 'the source stays, as documented');
    }

    public function test_rename_whose_delete_throws_still_succeeds(): void {
        $this->client->blobs['uploads/2026/09/t.css'] = 't';
        $this->client->throws['delete_file'] = 'reset';
        $this->assertTrue(rename($this->cloud('2026/09/t.css'), $this->cloud('2026/09/t2.css')));
        $this->assertSame('t', $this->client->blobs['uploads/2026/09/t2.css']);
    }

    // ── upload_dir and URLs ────────────────────────────────

    public function test_without_year_month_folders_the_path_is_the_prefix_itself(): void {
        update_option('uploads_use_yearmonth_folders', 0);
        $dir = wp_upload_dir(null, false, true);
        $this->assertSame('diluxoneoffload://uploads', $dir['path']);
        $this->assertSame('https://fake.cloud/uploads', $dir['url']);
    }

    public function test_an_upgrade_directory_is_never_mapped_to_the_cloud(): void {
        $upgrade = ['path' => ABSPATH . 'wp-content/upgrade/x', 'basedir' => ABSPATH . 'wp-content/uploads', 'url' => 'u', 'baseurl' => 'b'];
        $this->assertSame($upgrade, CloudStreamWrapper::filter_upload_dir($upgrade));
        $upgrade2 = ['path' => '/srv/a', 'basedir' => ABSPATH . 'wp-content/upgrade', 'url' => 'u', 'baseurl' => 'b'];
        $this->assertSame($upgrade2, CloudStreamWrapper::filter_upload_dir($upgrade2));
    }

    public function test_a_provider_that_cannot_build_a_url_keeps_the_local_urls(): void {
        $native = ['path' => '/srv/up/2026/09', 'url' => 'http://site/up/2026/09', 'subdir' => '/2026/09', 'basedir' => '/srv/up', 'baseurl' => 'http://site/up', 'error' => false];
        $this->client->throws['get_file_url'] = 'no endpoint';
        $dir = CloudStreamWrapper::filter_upload_dir($native);
        $this->assertSame('diluxoneoffload://uploads/2026/09', $dir['path']);
        $this->assertSame('http://site/up/2026/09', $dir['url']);
        $this->assertSame('http://site/up', $dir['baseurl']);
    }

    public function test_a_path_outside_the_base_maps_to_the_prefix(): void {
        $native = ['path' => '/elsewhere/2026', 'url' => 'u', 'subdir' => '', 'basedir' => '/srv/up', 'baseurl' => 'b', 'error' => false];
        $this->assertSame('diluxoneoffload://uploads', CloudStreamWrapper::filter_upload_dir($native)['path']);
    }

    public function test_keys_outside_the_site_prefix_are_not_the_sites(): void {
        $this->assertFalse(CloudStreamWrapper::owns_key('other/2026/09/a.jpg'));
        $this->assertFalse(CloudStreamWrapper::owns_key('uploadsX/a.jpg'));
        $this->assertTrue(CloudStreamWrapper::owns_key('uploads/2026/09/a.jpg'));
    }

    public function test_force_https_passes_non_urls_through(): void {
        $this->assertNull(CloudStreamWrapper::force_https_on_url(null));
        $this->assertSame('', CloudStreamWrapper::force_https_on_url(''));
        $this->assertSame('nope', CloudStreamWrapper::force_https_on_srcset('nope'));
    }

    public function test_core_update_screens_turn_the_mapping_off(): void {
        $this->assertStringStartsWith('diluxoneoffload://', wp_upload_dir()['basedir']);
        foreach (['load-update.php', 'load-update-core.php', 'load-plugin-install.php', 'load-theme-install.php'] as $hook) {
            CloudStreamWrapper::activate_offloading();
            $this->assertNotFalse(has_action($hook, [CloudStreamWrapper::class, 'tear_down']), $hook);
            do_action($hook);
            $this->assertStringStartsNotWith('diluxoneoffload://', wp_upload_dir(null, false, true)['basedir'], $hook . ': packages unpack on disk');
        }
    }

    public function test_is_active_follows_the_registration(): void {
        $this->assertTrue(CloudStreamWrapper::is_active());
        CloudStreamWrapper::unregister();
        $this->assertFalse(CloudStreamWrapper::is_active());
        $this->assertTrue(CloudStreamWrapper::unregister(), 'unregistering twice is fine');
        CloudStreamWrapper::register();
        $this->assertTrue(CloudStreamWrapper::is_active());
    }

    // ── No provider behind a registered wrapper ────────────

    public function test_with_no_provider_every_operation_fails_and_nothing_is_written_locally(): void {
        // The configuration is gone (removed, or its key unreadable) while the
        // protocol is still registered for this request.
        remove_filter('diluxone_offload_pre_cloud_client', [$this, 'injectClient']);
        delete_option('diluxone_offload_config');
        self::resetWrapperClient();
        CloudStreamWrapper::clear_stat_cache();
        $path = 'diluxoneoffload://uploads/2026/09/orphan.txt';

        $this->assertFalse(@fopen($path, 'w'));
        $this->assertFalse(@file_get_contents($path));
        $this->assertFalse(file_exists($path));
        $this->assertFalse(@unlink($path));
        $this->assertFalse(@rename($path, 'diluxoneoffload://uploads/2026/09/orphan2.txt'));
        $this->assertFalse(@opendir('diluxoneoffload://uploads/2026/09'));
        $this->assertNothingWrittenLocally('2026/09/orphan.txt');
        add_filter('diluxone_offload_pre_cloud_client', [$this, 'injectClient']);
    }
}
