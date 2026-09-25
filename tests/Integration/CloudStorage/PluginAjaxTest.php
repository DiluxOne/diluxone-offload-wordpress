<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use Tests\Integration\FakeCloudClient;
use Tests\Integration\LocalBlobServer;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\Enums\PluginState;
use WPAjaxDieContinueException;

/**
 * Integration tests for every AJAX endpoint the admin JavaScript calls.
 *
 * Two layers. First, a sweep over ALL registered wp_ajax_diluxone_offload_*
 * actions: each must refuse a request without a nonce and a request from a
 * user without manage_options. That is the property the review team asked
 * for, and a sweep means a new handler cannot be added without it. Second,
 * the flows the Sync tab actually drives — start, batch, poll, activate —
 * against the provider stand-in, asserting the JSON the browser would get.
 */
class PluginAjaxTest extends IntegrationTestCase {

    private static ?LocalBlobServer $server = null;
    private FakeCloudClient $client;
    private int $admin_id = 0;
    private int $subscriber_id = 0;
    /** @var string[] */
    private array $fixtures = [];

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        self::$server = new LocalBlobServer(8766);
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
        $this->client = new FakeCloudClient(self::$server->base_url);
        add_filter('diluxone_offload_pre_cloud_client', [$this, 'injectClient']);
        $this->rebuildSyncManager();

        $this->admin_id = $this->makeUser('administrator');
        $this->subscriber_id = $this->makeUser('subscriber');

        ConfigManager::save_config([
            'cloud_provider'  => 'azure',
            'provider_config' => [
                'storage_account' => 'ajaxacct',
                'container_name'  => 'media',
                'access_key'      => base64_encode(random_bytes(32)),
            ],
        ]);
        ConfigManager::set_state(PluginState::CONFIGURED);
        delete_option('diluxone_offload_sync_meta');
        $_POST = [];
        $_REQUEST = [];
    }

    protected function tearDown(): void {
        remove_filter('diluxone_offload_pre_cloud_client', [$this, 'injectClient']);
        foreach ($this->fixtures as $f) {
            @unlink($f);
        }
        $this->fixtures = [];
        foreach ([$this->admin_id, $this->subscriber_id] as $id) {
            if ($id) {
                wp_delete_user($id);
            }
        }
        wp_set_current_user(0);
        $_POST = [];
        $_REQUEST = [];
        delete_option('diluxone_offload_sync_meta');
        parent::tearDown();
    }

    public function injectClient($pre) {
        return $this->client;
    }

    /**
     * The Plugin singleton built its SyncManager at plugins_loaded, before this
     * test existed, so that instance holds no client. Rebuild it now that the
     * stand-in is injected — the same object the AJAX handlers will use.
     */
    private function rebuildSyncManager(): void {
        $plugin = \DiluxOneOffload\Plugin::get_instance();
        $prop   = new \ReflectionProperty($plugin, 'sync_manager');
        if ( PHP_VERSION_ID < 80100 ) { // Required before 8.1, deprecated from 8.5.
        	$prop->setAccessible( true );
        }
        $prop->setValue($plugin, new \DiluxOneOffload\SyncManager());
    }

    // ── Helpers ─────────────────────────────────────────────

    private function makeUser(string $role): int {
        $id = wp_insert_user([
            'user_login' => 'ajax_' . $role . '_' . wp_generate_password(6, false),
            'user_pass'  => wp_generate_password(12),
            'role'       => $role,
        ]);
        $this->assertNotInstanceOf(\WP_Error::class, $id);
        return (int) $id;
    }

    private function fixture(string $relative, string $content = 'x'): string {
        $path = wp_upload_dir()['basedir'] . '/' . ltrim($relative, '/');
        wp_mkdir_p(dirname($path));
        file_put_contents($path, $content);
        $this->fixtures[] = $path;
        return $path;
    }

    /**
     * Fire an AJAX action as the current user and return the decoded JSON
     * (or null when the handler died without a JSON body).
     *
     * @param array<string, mixed> $post
     * @return array{json: array|null, died: string, raw: string}
     */
    private function call(string $action, array $post = [], string $nonce_action = 'diluxone_offload_admin', bool $with_nonce = true): array {
        $_POST = $post;
        if ($with_nonce) {
            $_POST['nonce'] = wp_create_nonce($nonce_action);
        }
        $_REQUEST = $_POST;

        ob_start();
        $died = '';
        try {
            do_action('wp_ajax_' . $action);
        } catch (WPAjaxDieContinueException $e) {
            $died = (string) $e->getMessage();
        }
        $out = (string) ob_get_clean();
        $json = json_decode($out, true);
        return ['json' => is_array($json) ? $json : null, 'died' => $died, 'raw' => $out];
    }

    /** @return string[] every registered wp_ajax_diluxone_offload_* action */
    private static function registeredActions(): array {
        global $wp_filter;
        $actions = [];
        foreach (array_keys($wp_filter) as $hook) {
            if (strpos($hook, 'wp_ajax_diluxone_offload_') === 0) {
                $actions[] = substr($hook, strlen('wp_ajax_'));
            }
        }
        sort($actions);
        return $actions;
    }

    // ── Security sweep ──────────────────────────────────────

    public function test_there_are_ajax_actions_to_sweep(): void {
        // Every action the JS calls, plus the two DEV MODE endpoints.
        $this->assertGreaterThanOrEqual(26, count(self::registeredActions()));
    }

    public function test_every_action_refuses_a_request_without_a_nonce(): void {
        wp_set_current_user($this->admin_id);
        foreach (self::registeredActions() as $action) {
            $r = $this->call($action, [], 'diluxone_offload_admin', false);
            $accepted = is_array($r['json']) && !empty($r['json']['success']);
            $this->assertFalse($accepted, "$action accepted a request with no nonce");
        }
    }

    public function test_every_action_refuses_a_subscriber(): void {
        wp_set_current_user($this->subscriber_id);
        foreach (self::registeredActions() as $action) {
            // A subscriber can mint the nonce; the capability check is what must stop them.
            foreach (['diluxone_offload_admin', 'diluxone_offload_admin_nonce'] as $nonce_action) {
                $r = $this->call($action, [], $nonce_action);
                $accepted = is_array($r['json']) && !empty($r['json']['success']);
                $this->assertFalse($accepted, "$action accepted a subscriber (nonce $nonce_action)");
            }
        }
    }

    // ── Sync flow as the browser drives it ──────────────────

    public function test_start_sync_then_batch_then_state(): void {
        wp_set_current_user($this->admin_id);
        $this->fixture('2026/09/ajax-1.jpg', 'one');
        $this->fixture('2026/09/ajax-2.jpg', 'two');

        $start = $this->call('diluxone_offload_start_sync', ['session_id' => 'tab-A', 'confirmed' => '1']);
        $this->assertNotNull($start['json'], 'start_sync must answer JSON; died=' . $start['died']);
        $this->assertTrue($start['json']['success'], print_r($start['json'], true));
        $this->assertSame(PluginState::SYNCING, ConfigManager::get_state());

        $batch = $this->call('diluxone_offload_process_batch', ['session_id' => 'tab-A']);
        $this->assertTrue($batch['json']['success'], print_r($batch['json'], true));
        $this->assertSame('completed', $batch['json']['data']['status'] ?? null);
        $this->assertArrayHasKey('uploads/2026/09/ajax-1.jpg', $this->client->blobs);

        $state = $this->call('diluxone_offload_get_sync_state', ['session_id' => 'tab-A']);
        $this->assertTrue($state['json']['success']);
        $this->assertIsArray($state['json']['data']);
    }

    public function test_process_batch_from_another_tab_is_told_session_lost(): void {
        wp_set_current_user($this->admin_id);
        $this->fixture('2026/09/tab.jpg');
        $this->call('diluxone_offload_start_sync', ['session_id' => 'tab-A', 'confirmed' => '1']);

        $r = $this->call('diluxone_offload_process_batch', ['session_id' => 'tab-B']);
        $this->assertNotNull($r['json']);
        $status = $r['json']['data']['status'] ?? ($r['json']['data']['message'] ?? '');
        $this->assertStringContainsString('session', strtolower(json_encode($r['json'])));
    }

    public function test_take_control_hands_the_session_to_the_new_tab(): void {
        wp_set_current_user($this->admin_id);
        $this->fixture('2026/09/take.jpg');
        $this->call('diluxone_offload_start_sync', ['session_id' => 'tab-A', 'confirmed' => '1']);

        $r = $this->call('diluxone_offload_take_control', ['session_id' => 'tab-B']);
        $this->assertNotNull($r['json'], 'no JSON; died=' . $r['died'] . ' raw=' . $r['raw']);
        $this->assertTrue($r['json']['success'], print_r($r['json'], true));
        $this->assertSame('tab-B', get_option('diluxone_offload_sync_meta')['sync_session_id']);
    }

    public function test_failed_files_count_and_calculate_sync(): void {
        wp_set_current_user($this->admin_id);
        $this->fixture('2026/09/calc.jpg');

        $calc = $this->call('diluxone_offload_calculate_sync');
        $this->assertTrue($calc['json']['success'], print_r($calc['json'], true));

        $count = $this->call('diluxone_offload_get_failed_files_count');
        $this->assertTrue($count['json']['success']);
        $this->assertSame(0, (int) ($count['json']['data']['count'] ?? $count['json']['data']['failed_count'] ?? 0));
    }

    // ── Offloading toggle ───────────────────────────────────

    public function test_activate_and_deactivate_offloading(): void {
        wp_set_current_user($this->admin_id);
        ConfigManager::set_state(PluginState::SYNCED);
        $this->assertSame(PluginState::SYNCED, ConfigManager::get_state(), 'precondition');

        $on = $this->call('diluxone_offload_activate_offloading', [], 'diluxone_offload_admin_nonce');
        $this->assertNotNull($on['json'], 'raw=' . $on['raw']);
        $this->assertTrue($on['json']['success'], print_r($on['json'], true));
        $this->assertSame(PluginState::OFFLOADING_ACTIVE, ConfigManager::get_state());

        $off = $this->call('diluxone_offload_deactivate_offloading', [], 'diluxone_offload_admin_nonce');
        $this->assertTrue($off['json']['success'], print_r($off['json'], true));
        // Deliberate product rule: turning offloading off drops back to
        // CONFIGURED, not SYNCED — the user must sync again before enabling
        // it, because files may have changed locally in the meantime.
        $this->assertSame(PluginState::CONFIGURED, ConfigManager::get_state());
    }

    public function test_activate_offloading_refuses_before_a_sync(): void {
        wp_set_current_user($this->admin_id);
        ConfigManager::set_state(PluginState::CONFIGURED);
        $r = $this->call('diluxone_offload_activate_offloading', [], 'diluxone_offload_admin_nonce');
        $this->assertFalse($r['json']['success'] ?? true);
        $this->assertSame(PluginState::CONFIGURED, ConfigManager::get_state());
    }

    // ── State resets ────────────────────────────────────────

    public function test_reset_state_to_configured(): void {
        wp_set_current_user($this->admin_id);
        ConfigManager::set_state(PluginState::SYNCED);
        $r = $this->call('diluxone_offload_reset_state_to_configured');
        $this->assertNotNull($r['json'], 'no JSON; died=' . $r['died'] . ' raw=' . $r['raw']);
        $this->assertTrue($r['json']['success'], print_r($r['json'], true));
        $this->assertSame(PluginState::CONFIGURED, ConfigManager::get_state());
    }

    public function test_prepare_resync_clears_the_table_and_returns_to_configured(): void {
        wp_set_current_user($this->admin_id);
        $this->addTestFile('/2026/09/done.jpg', 10);
        \DiluxOneOffload\DiluxOneOffloadDB::mark_synced('/2026/09/done.jpg');
        ConfigManager::set_state(PluginState::SYNCED);

        $r = $this->call('diluxone_offload_prepare_resync');
        $this->assertNotNull($r['json'], 'no JSON; died=' . $r['died'] . ' raw=' . $r['raw']);
        $this->assertTrue($r['json']['success'], print_r($r['json'], true));
        // "Resync all" forgets everything and goes back to CONFIGURED so the
        // next start_sync rescans from scratch.
        $this->assertSame(0, $this->getTableRowCount());
        $this->assertSame(PluginState::CONFIGURED, ConfigManager::get_state());
    }

    public function test_discard_failed_files_removes_them(): void {
        wp_set_current_user($this->admin_id);
        $this->addTestFile('/2026/09/bad.jpg', 10);
        foreach (['a', 'b', 'c'] as $e) {
            \DiluxOneOffload\DiluxOneOffloadDB::increment_error('/2026/09/bad.jpg', $e);
        }

        $r = $this->call('diluxone_offload_discard_failed_files');
        $this->assertNotNull($r['json'], 'no JSON; died=' . $r['died'] . ' raw=' . $r['raw']);
        $this->assertTrue($r['json']['success'], print_r($r['json'], true));
        $this->assertSame(0, $this->getTableRowCount());
    }

    public function test_discarding_the_last_failures_leaves_the_plugin_ready_to_offload(): void {
        wp_set_current_user($this->admin_id);
        $this->addTestFile('/2026/09/bad.jpg', 10);
        foreach (['a', 'b', 'c'] as $e) {
            \DiluxOneOffload\DiluxOneOffloadDB::increment_error('/2026/09/bad.jpg', $e);
        }
        // Those failures are what kept the plugin in SYNCING.
        ConfigManager::set_state(PluginState::SYNCING);

        $r = $this->call('diluxone_offload_discard_failed_files');
        $this->assertTrue($r['json']['success'], print_r($r['json'], true));

        // The "Clear Failed & Enable" flow calls activate right after the
        // discard, and that only works from SYNCED.
        $this->assertSame(PluginState::SYNCED, ConfigManager::get_state());
        $this->assertTrue(PluginState::can_activate_offloading(ConfigManager::get_state()));
    }

    public function test_discarding_failures_while_idle_leaves_the_state_alone(): void {
        wp_set_current_user($this->admin_id);
        $this->addTestFile('/2026/09/bad.jpg', 10);
        foreach (['a', 'b', 'c'] as $e) {
            \DiluxOneOffload\DiluxOneOffloadDB::increment_error('/2026/09/bad.jpg', $e);
        }
        ConfigManager::set_state(PluginState::CONFIGURED);

        $this->call('diluxone_offload_discard_failed_files');

        // Only SYNCING is promoted; every other state is none of the discard's business.
        $this->assertSame(PluginState::CONFIGURED, ConfigManager::get_state());
    }

    // ── Stats endpoints answer JSON ─────────────────────────

    /** @dataProvider statsActions */
    public function test_stats_endpoints_answer_well_formed_json(string $action): void {
        wp_set_current_user($this->admin_id);
        $r = $this->call($action);
        $this->assertNotNull($r['json'], "$action died without JSON: died=" . $r['died'] . ' raw=' . $r['raw']);
        $this->assertArrayHasKey('success', $r['json']);
    }

    /** @return array<string, array{string}> */
    public function statsActions(): array {
        return [
            'deletable stats' => ['diluxone_offload_get_deletable_stats'],
            'calculate dl'    => ['diluxone_offload_calculate_download'],
            'scan remote'     => ['diluxone_offload_scan_remote'],
        ];
    }
}
