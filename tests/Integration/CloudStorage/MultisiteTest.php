<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use Tests\Integration\FakeCloudClient;
use DiluxOneOffload\Plugin;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\CloudStreamWrapper;
use DiluxOneOffload\DiluxOneOffloadDB;
use DiluxOneOffload\SyncManager;
use DiluxOneOffload\Enums\PluginState;

/**
 * Integration tests for multisite behaviour.
 *
 * The readme promises "per-site or network-level configuration". These tests
 * are what stands behind that sentence: every site gets its own tracking
 * table, sites added after activation get one too, and one site's provider
 * config never leaks into another.
 *
 * Object keys are namespaced per site (`uploads/sites/<id>/` off the main
 * site), so several sites of a network can share one container: that is
 * tested here end to end, through the stream wrapper, the sync scan and a
 * Disconnect catalogue, against a fake client whose blob store is shared.
 *
 * They only run when the tests environment is a network; `make env-multisite`
 * converts it. On a single site they are skipped, not silently passed.
 */
class MultisiteTest extends IntegrationTestCase {

    /** @var int[] Sites created by a test, removed in tearDown. */
    private array $created_sites = [];

    private ?FakeCloudClient $client = null;

    /** @var string[] Local files created by a test, removed in tearDown. */
    private array $fixtures = [];

    protected function setUp(): void {
        if (!is_multisite()) {
            $this->markTestSkipped('Needs a multisite tests environment (make env-multisite).');
        }
        parent::setUp();
    }

    protected function tearDown(): void {
        remove_filter('diluxone_offload_pre_cloud_client', [$this, 'injectClient']);
        $this->client = null;
        foreach ($this->fixtures as $f) {
            @unlink($f);
        }
        $this->fixtures = [];
        while (ms_is_switched()) {
            restore_current_blog();
        }
        foreach ($this->created_sites as $id) {
            wp_delete_site($id);
        }
        $this->created_sites = [];
        parent::tearDown();
    }

    public function injectClient($pre) {
        return $this->client;
    }

    /** A fake client whose blob store is one container shared by every site. */
    private function useFakeClient(): FakeCloudClient {
        $this->client = new FakeCloudClient('http://127.0.0.1:1');
        add_filter('diluxone_offload_pre_cloud_client', [$this, 'injectClient']);
        self::resetWrapperClient();
        return $this->client;
    }

    /** Configure the current site with a provider, the way a user would. */
    private function configureCurrentSite(string $state = PluginState::CONFIGURED): void {
        ConfigManager::save_config([
            'cloud_provider'  => 'azure',
            'provider_config' => ['storage_account' => 'msacct', 'container_name' => 'shared', 'access_key' => base64_encode(random_bytes(32))],
        ]);
        ConfigManager::set_state($state);
        delete_option('diluxone_offload_sync_meta');
    }

    /** The upload_dir array WordPress would hand the filter for this site, unfiltered. */
    private function nativeUploadDir(string $subdir): array {
        $base = CloudStreamWrapper::native_upload_basedir();
        return [
            'path'    => $base . $subdir,
            'url'     => 'https://example.test/wp-content/uploads' . $subdir,
            'subdir'  => $subdir,
            'basedir' => $base,
            'baseurl' => 'https://example.test/wp-content/uploads',
            'error'   => false,
        ];
    }

    // ── Helpers ─────────────────────────────────────────────

    private function tableExistsOn(int $site_id): bool {
        // get_table_name() reads $wpdb->prefix live, so switching blogs is
        // enough to make the DB layer look at that site's table.
        switch_to_blog($site_id);
        $found = DiluxOneOffloadDB::table_exists();
        restore_current_blog();
        return $found;
    }

    private function createSite(string $slug): int {
        $id = wp_insert_site([
            'domain' => (string) parse_url(network_home_url(), PHP_URL_HOST),
            'path'   => '/' . $slug . '/',
            'title'  => $slug,
        ]);
        $this->assertIsInt($id, 'site creation must succeed');
        $this->created_sites[] = $id;
        return $id;
    }

    // ── Tables per site ─────────────────────────────────────

    public function test_network_activation_creates_a_table_on_every_site(): void {
        $site = $this->createSite('ms-activation-' . uniqid());

        // Drop any table the site was born with, so this asserts activation alone.
        global $wpdb;
        switch_to_blog($site);
        $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}diluxone_offload_files`");
        restore_current_blog();
        $this->assertFalse($this->tableExistsOn($site), 'precondition: table gone');

        Plugin::activate(true);

        $this->assertTrue($this->tableExistsOn(get_main_site_id()), 'main site');
        $this->assertTrue($this->tableExistsOn($site), 'secondary site');
    }

    public function test_single_site_activation_touches_only_the_current_site(): void {
        $site = $this->createSite('ms-single-' . uniqid());
        global $wpdb;
        switch_to_blog($site);
        $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}diluxone_offload_files`");
        restore_current_blog();

        Plugin::activate(false);

        $this->assertFalse($this->tableExistsOn($site), 'a non-network activation must not reach other sites');
    }

    public function test_a_site_created_after_activation_gets_its_table(): void {
        // wp_insert_site fires wp_initialize_site, which the plugin listens to
        // — but only for a plugin that is active across the whole network. A
        // site where it is not active has no use for the table, and would get
        // one on first init anyway. The suite activates per site, so the
        // precondition is set here rather than inherited from whatever ran
        // before: without this the test passes or fails by test order.
        $basename = plugin_basename(DILUXONE_OFFLOAD_FILE);
        $previous = get_site_option('active_sitewide_plugins', []);
        update_site_option('active_sitewide_plugins', array_merge($previous, [$basename => time()]));

        try {
            $site = $this->createSite('ms-new-' . uniqid());
            $this->assertTrue($this->tableExistsOn($site));
        } finally {
            update_site_option('active_sitewide_plugins', $previous);
        }
    }

    public function test_a_new_site_gets_no_table_when_the_plugin_is_not_network_active(): void {
        $previous = get_site_option('active_sitewide_plugins', []);
        update_site_option('active_sitewide_plugins', []);

        try {
            $site = $this->createSite('ms-inactive-' . uniqid());
            $this->assertFalse(
                $this->tableExistsOn($site),
                'a site that does not run this plugin gets no table from it'
            );
        } finally {
            update_site_option('active_sitewide_plugins', $previous);
        }
    }

    // ── Config isolation ────────────────────────────────────

    public function test_provider_config_does_not_leak_between_sites(): void {
        $site = $this->createSite('ms-config-' . uniqid());

        ConfigManager::save_config([
            'cloud_provider'  => 'azure',
            'provider_config' => [
                'storage_account' => 'acct-main',
                'container_name'  => 'main',
                'access_key'      => base64_encode(random_bytes(32)),
            ],
        ]);
        $this->assertSame('azure', ConfigManager::get_config()['cloud_provider'], 'main site sees its config');

        switch_to_blog($site);
        $other = ConfigManager::get_config();
        $other_state = ConfigManager::get_state();
        restore_current_blog();

        $this->assertSame('', $other['cloud_provider'], 'the other site must start unconfigured');
        $this->assertSame('not_configured', $other_state);
    }

    public function test_tracking_rows_do_not_leak_between_sites(): void {
        $site = $this->createSite('ms-rows-' . uniqid());

        DiluxOneOffloadDB::add_file('2026/01/main-only.jpg', 100);
        $this->assertSame(1, (int) DiluxOneOffloadDB::get_stats()['total_files'], 'main site has its row');

        switch_to_blog($site);
        $count = (int) DiluxOneOffloadDB::get_stats()['total_files'];
        restore_current_blog();

        $this->assertSame(0, $count, 'the other site must not see rows from the main site');
    }

    // ── Object keys per site ────────────────────────────────

    public function test_the_main_site_uses_the_plain_prefix(): void {
        $this->assertSame('uploads', CloudStreamWrapper::key_prefix());
        $this->assertSame('uploads/2026/09/a.jpg', DiluxOneOffloadDB::key_from_path('/2026/09/a.jpg'));
        $this->assertSame('/2026/09/a.jpg', DiluxOneOffloadDB::path_from_key('uploads/2026/09/a.jpg'));
    }

    public function test_another_site_is_namespaced_by_its_id(): void {
        $site = $this->createSite('ms-keys-' . uniqid());
        switch_to_blog($site);
        try {
            $this->assertSame('uploads/sites/' . $site, CloudStreamWrapper::key_prefix());
            $this->assertSame('uploads/sites/' . $site . '/', DiluxOneOffloadDB::listing_prefix());
            $this->assertSame('uploads/sites/' . $site . '/2026/09/a.jpg', DiluxOneOffloadDB::key_from_path('/2026/09/a.jpg'));
            $this->assertSame('/2026/09/a.jpg', DiluxOneOffloadDB::path_from_key('uploads/sites/' . $site . '/2026/09/a.jpg'));
            $this->assertSame('/uploads/2026/09/a.jpg', DiluxOneOffloadDB::path_from_key('uploads/2026/09/a.jpg'), 'the main site\'s key is not one of this site\'s rows');
        } finally {
            restore_current_blog();
        }
    }

    public function test_upload_dir_is_mapped_under_the_site_prefix(): void {
        $this->useFakeClient();

        $main = CloudStreamWrapper::filter_upload_dir($this->nativeUploadDir('/2026/09'));
        $this->assertSame('diluxoneoffload://uploads/2026/09', $main['path']);
        $this->assertSame('diluxoneoffload://uploads', $main['basedir']);
        $this->assertSame('https://fake.cloud/uploads/2026/09', $main['url']);
        $this->assertSame('https://fake.cloud/uploads', $main['baseurl']);

        $site = $this->createSite('ms-dir-' . uniqid());
        switch_to_blog($site);
        try {
            $other = CloudStreamWrapper::filter_upload_dir($this->nativeUploadDir('/2026/09'));
        } finally {
            restore_current_blog();
        }
        $this->assertSame('diluxoneoffload://uploads/sites/' . $site . '/2026/09', $other['path']);
        $this->assertSame('diluxoneoffload://uploads/sites/' . $site, $other['basedir']);
        $this->assertSame('https://fake.cloud/uploads/sites/' . $site . '/2026/09', $other['url']);
        $this->assertSame('https://fake.cloud/uploads/sites/' . $site, $other['baseurl']);
    }

    // ── One container, several sites ────────────────────────

    public function test_two_sites_sharing_a_container_never_share_a_key(): void {
        $client = $this->useFakeClient();
        CloudStreamWrapper::register();

        // The same relative path on both sites, written through the wrapper
        // exactly the way WordPress does after filter_upload_dir().
        $this->assertNotFalse(file_put_contents('diluxoneoffload://' . CloudStreamWrapper::key_prefix() . '/2026/09/logo.png', 'main site'));

        $site = $this->createSite('ms-shared-' . uniqid());
        switch_to_blog($site);
        try {
            $this->assertNotFalse(file_put_contents('diluxoneoffload://' . CloudStreamWrapper::key_prefix() . '/2026/09/logo.png', 'other site'));
        } finally {
            restore_current_blog();
        }

        $this->assertSame('main site', $client->blobs['uploads/2026/09/logo.png'] ?? null);
        $this->assertSame('other site', $client->blobs['uploads/sites/' . $site . '/2026/09/logo.png'] ?? null);
        $this->assertCount(2, $client->blobs, 'two objects, neither overwrote the other');
    }

    public function test_the_sync_scan_of_a_site_keys_its_files_under_its_prefix(): void {
        $this->useFakeClient();
        $site = $this->createSite('ms-scan-' . uniqid());
        switch_to_blog($site);
        try {
            $this->configureCurrentSite();
            $base = wp_upload_dir()['basedir'];
            wp_mkdir_p($base . '/2026/09');
            $local = $base . '/2026/09/mine.jpg';
            file_put_contents($local, 'jpeg');
            $this->fixtures[] = $local;

            $files = (new SyncManager())->scan_files_to_sync(true);
        } finally {
            restore_current_blog();
        }

        $remote = array_column($files, 'remote_path');
        $this->assertContains('uploads/sites/' . $site . '/2026/09/mine.jpg', $remote);
        $this->assertNotContains('uploads/2026/09/mine.jpg', $remote, 'the main site\'s namespace is never used from another site');
    }

    /** The other sites' uploads live inside the main site's directory; its scan must leave them alone. */
    public function test_the_main_sites_scan_skips_the_other_sites_directories(): void {
        $this->useFakeClient();
        $site = $this->createSite('ms-scan-main-' . uniqid());
        $this->configureCurrentSite();

        $main = wp_upload_dir()['basedir'];
        wp_mkdir_p($main . '/2026/09');
        file_put_contents($main . '/2026/09/main.jpg', 'm');
        $this->fixtures[] = $main . '/2026/09/main.jpg';

        switch_to_blog($site);
        $other = wp_upload_dir()['basedir'];
        restore_current_blog();
        $this->assertStringStartsWith($main . '/sites/', $other, 'precondition: the other site lives under the main uploads directory');
        wp_mkdir_p($other . '/2026/09');
        file_put_contents($other . '/2026/09/theirs.jpg', 't');
        $this->fixtures[] = $other . '/2026/09/theirs.jpg';

        $remote = array_column((new SyncManager())->scan_files_to_sync(true), 'remote_path');
        $this->assertContains('uploads/2026/09/main.jpg', $remote);
        $this->assertSame([], array_values(array_filter($remote, fn ($k) => strpos($k, '/sites/') !== false)), 'nothing under sites/ is the main site\'s to sync');
    }

    /** The main site's prefix is the parent of every other site's: its listing must not adopt them. */
    public function test_the_main_sites_catalogue_ignores_the_other_sites_objects(): void {
        $client = $this->useFakeClient();
        $site   = $this->createSite('ms-main-' . uniqid());
        $client->blobs = [
            'uploads/2026/09/main.jpg'                     => 'main',
            'uploads/sites/' . $site . '/2026/09/mine.jpg' => 'mine',
        ];
        $this->configureCurrentSite(PluginState::OFFLOADING_ACTIVE);

        $result = (new SyncManager())->start_reverse_sync('scratch');
        delete_option('diluxone_offload_sync_meta');

        $this->assertTrue($result['success'] ?? false, print_r($result, true));
        $this->assertSame(1, (int) DiluxOneOffloadDB::get_stats()['total_files'], 'only the main site\'s own object');
        $this->assertSame(1, (int) ((array) DiluxOneOffloadDB::get_deleted_stats())['files']);
    }

    public function test_a_disconnect_catalogue_sees_only_the_sites_own_objects(): void {
        $client = $this->useFakeClient();
        $site   = $this->createSite('ms-reverse-' . uniqid());
        $client->blobs = [
            'uploads/2026/09/main.jpg'                       => 'main',
            'uploads/sites/' . $site . '/2026/09/mine.jpg'   => 'mine',
            'uploads/sites/' . ($site + 1) . '/2026/09/x.jpg' => 'someone else',
        ];

        switch_to_blog($site);
        try {
            $this->configureCurrentSite(PluginState::OFFLOADING_ACTIVE);
            $result  = (new SyncManager())->start_reverse_sync('scratch');
            $pending = (array) DiluxOneOffloadDB::get_deleted_stats();
            $rows    = (int) DiluxOneOffloadDB::get_stats()['total_files'];
            delete_option('diluxone_offload_sync_meta');
        } finally {
            restore_current_blog();
        }

        $this->assertTrue($result['success'] ?? false, print_r($result, true));
        $this->assertSame(1, $rows, 'only this site\'s object was catalogued');
        $this->assertSame(1, (int) ($pending['files'] ?? 0));
        $this->assertSame(0, (int) DiluxOneOffloadDB::get_stats()['total_files'], 'and nothing landed in the main site\'s table');
    }
}
