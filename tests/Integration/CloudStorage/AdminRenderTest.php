<?php
namespace Tests\Integration\CloudStorage;

use Tests\Integration\IntegrationTestCase;
use Tests\Integration\FakeCloudClient;
use Tests\Integration\LocalBlobServer;
use DiluxOneOffload\Admin;
use DiluxOneOffload\ConfigManager;
use DiluxOneOffload\Enums\PluginState;
use DiluxOneOffload\DiluxOneOffloadDB as DB;
use WPAjaxDieContinueException;

/**
 * Integration tests for the Admin class: hooks, screen and tab routing, the
 * redirects of the old tab URLs, asset enqueuing and — above all — rendering
 * every screen and tab in every plugin state, including each flavour of the
 * connection-health banner.
 *
 * Rendering is asserted on structure (the wrapper, the heading, the tab
 * strip, the rail, the banner) and on the absence of PHP notices in the
 * output, because that is what a user sees: a page, or a page with a warning
 * splattered across it.
 */
class AdminRenderTest extends IntegrationTestCase {

    private static ?LocalBlobServer $server = null;
    private ?FakeCloudClient $fake = null;
    private int $admin_id = 0;

    /** Every view: the tab slug (or the screen key for a screen without tabs) => [page, tab]. */
    private const VIEWS = [
        'overview'    => ['diluxone-offload', ''],
        'connection'  => ['diluxone-offload-provider', 'connection'],
        'credentials' => ['diluxone-offload-provider', 'credentials'],
        'sync'        => ['diluxone-offload-sync', 'sync'],
        'offloading'  => ['diluxone-offload-sync', 'offloading'],
        'disconnect'  => ['diluxone-offload-sync', 'disconnect'],
        'transfers'   => ['diluxone-offload-settings', 'transfers'],
        'serving'     => ['diluxone-offload-settings', 'serving'],
        'logging'     => ['diluxone-offload-settings', 'logging'],
        'health'      => ['diluxone-offload-status', 'health'],
        'system'      => ['diluxone-offload-status', 'system'],
    ];

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        self::$server = new LocalBlobServer(8768);
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
        $id = wp_insert_user([
            'user_login' => 'render_' . wp_generate_password(8, false),
            'user_pass'  => wp_generate_password(12),
            'role'       => 'administrator',
        ]);
        $this->admin_id = (int) $id;
        wp_set_current_user($this->admin_id);
        if (!class_exists('WP_Screen')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
        }
        require_once ABSPATH . 'wp-admin/includes/screen.php';
        set_current_screen('toplevel_page_diluxone-offload');
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
    }

    protected function tearDown(): void {
        if ($this->fake) {
            remove_filter('diluxone_offload_pre_cloud_client', [$this, 'injectFake']);
            $this->fake = null;
        }
        delete_transient('diluxone_offload_azure_stats');
        if ($this->admin_id) {
            wp_delete_user($this->admin_id);
        }
        wp_set_current_user(0);
        $_GET = [];
        parent::tearDown();
    }

    public function injectFake($pre) {
        return $this->fake;
    }

    private function useFakeClient(): FakeCloudClient {
        $this->fake = new FakeCloudClient(self::$server->base_url);
        add_filter('diluxone_offload_pre_cloud_client', [$this, 'injectFake']);
        return $this->fake;
    }

    private function configure(string $state = PluginState::CONFIGURED): void {
        ConfigManager::save_config([
            'cloud_provider'  => 'azure',
            'provider_config' => [
                'storage_account' => 'renderacct',
                'container_name'  => 'media',
                'access_key'      => base64_encode(random_bytes(32)),
            ],
        ]);
        ConfigManager::set_state($state);
    }

    /**
     * Renders a view (a screen, or a tab of one) and fails on any PHP
     * warning/notice/deprecation raised while doing so — an undefined array
     * key in a template is a bug even when display_errors is off.
     */
    private function render(string $view): string {
        [$page, $tab] = self::VIEWS[$view];
        $_GET['page'] = $page;
        if ($tab === '') {
            unset($_GET['tab']);
        } else {
            $_GET['tab'] = $tab;
        }
        $GLOBALS['wp_scripts'] = null;
        $GLOBALS['wp_styles'] = null;
        $problems = [];
        set_error_handler(function (int $no, string $str, string $file, int $line) use (&$problems): bool {
            if (strpos($file, '/diluxone-offload') !== false) {
                $problems[] = "$str in $file:$line";
            }
            return true;
        });
        ob_start();
        try {
            Admin::enqueue_admin_assets('toplevel_page_diluxone-offload');
            Admin::render_admin_page();
        } finally {
            $html = (string) ob_get_clean();
            restore_error_handler();
        }
        $this->assertSame([], $problems, "PHP notices while rendering $view");
        $this->assertStringContainsString('diluxone-offload-admin', $html, "wrapper missing in $view");
        $this->assertStringContainsString('DiluxOne Offload | ', $html, "$view has the screen heading");
        $this->assertStringContainsString('diluxone-offload-rail-state', $html, "$view has the rail");
        return $html;
    }

    private function views(): array {
        return array_keys(self::VIEWS);
    }

    // ── Identity and routing ────────────────────────────────

    public function test_plugin_name_and_version(): void {
        $this->assertSame('DiluxOne Offload', Admin::plugin_name());
        // Not the literal number: that would have to be edited on every
        // release, and a test that breaks on a version bump teaches people to
        // edit it without reading it. The number is already pinned to the
        // plugin header and readme.txt by the version-alignment check in CI.
        // What is worth pinning here is the wiring — the admin reports the
        // plugin's own version rather than a copy that can drift — and that
        // what it reports is a version at all.
        $this->assertSame(DILUXONE_OFFLOAD_VERSION, Admin::get_plugin_version());
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+(-(dev|alpha|beta|rc)[.0-9]*)?$/',
            Admin::get_plugin_version(),
            'the admin reports something that is not a version'
        );
    }

    public function test_init_registers_the_menu_and_the_post_handlers(): void {
        Admin::init();
        $this->assertNotFalse(has_action('admin_menu', [Admin::class, 'add_admin_menu']));
        $this->assertNotFalse(has_action('admin_post_diluxone_offload_save_config', [Admin::class, 'save_config']));
        $this->assertNotFalse(has_filter('admin_title', [Admin::class, 'admin_title']));
    }

    public function test_admin_title_swaps_the_menu_label_for_the_plugin_name(): void {
        $this->assertStringContainsString('DiluxOne Offload', Admin::admin_title('Foo ‹ Site — WordPress', 'Foo'));
        $this->assertSame('Bar ‹ Site', Admin::admin_title('Bar ‹ Site', 'Other'));
        set_current_screen('dashboard');
        $this->assertSame('Dashboard ‹ Site', Admin::admin_title('Dashboard ‹ Site', 'Dashboard'), 'other screens are left alone');
    }

    public function test_screens_their_pages_and_their_tabs(): void {
        $screens = Admin::screens();
        $this->assertSame(['overview', 'cloud-provider', 'sync-offloading', 'settings', 'status'], array_keys($screens), 'in the order a person walks through them');
        $this->assertSame([], $screens['overview']['tabs'], 'Overview has no tabs');
        $this->assertSame(['connection', 'credentials'], array_keys($screens['cloud-provider']['tabs']));
        $this->assertSame(['sync', 'offloading', 'disconnect'], array_keys($screens['sync-offloading']['tabs']));
        $this->assertSame(['transfers', 'serving', 'logging'], array_keys($screens['settings']['tabs']));
        $this->assertSame(['health', 'system'], array_keys($screens['status']['tabs']));
        $this->assertArrayNotHasKey('tools', $screens, 'the Tools tab went out with import/export');
        $this->assertArrayNotHasKey('filenames', $screens, 'Filenames is 4.0.0');
        foreach (self::VIEWS as [$page]) {
            $expected = $page === 'diluxone-offload' ? 'overview' : Admin::screen_for_page($page);
            $this->assertSame($expected, Admin::screen_for_page($page));
            $this->assertSame($page, $screens[Admin::screen_for_page($page)]['page'], "$page belongs to a screen");
        }
        $this->assertSame('overview', Admin::screen_for_page('diluxone-offload-nope'), 'an unknown page lands on Overview');
    }

    public function test_tab_for_takes_the_requested_tab_or_the_first_one(): void {
        $this->assertSame('credentials', Admin::tab_for('cloud-provider', 'credentials'));
        $this->assertSame('connection', Admin::tab_for('cloud-provider', 'nope'), 'unknown tab: the first one');
        $this->assertSame('connection', Admin::tab_for('cloud-provider', ''), 'no tab: the first one');
        $this->assertSame('', Admin::tab_for('overview', 'anything'), 'a screen without tabs has none');
    }

    public function test_the_old_tab_urls_map_to_their_screens(): void {
        $this->assertSame(['cloud-provider', 'connection'], Admin::legacy_tab('cloud-provider'));
        $this->assertSame(['sync-offloading', 'sync'], Admin::legacy_tab('sync-offloading'));
        $this->assertSame(['sync-offloading', 'sync'], Admin::legacy_tab('sync'), 'older alias');
        $this->assertSame(['settings', 'transfers'], Admin::legacy_tab('settings'));
        $this->assertSame(['status', 'health'], Admin::legacy_tab('status'));
        $this->assertSame(['status', 'health'], Admin::legacy_tab('status-tools'), 'older alias');
        $this->assertSame(['overview', ''], Admin::legacy_tab('activity'), 'the old Activity URL falls back');
        $this->assertSame(['overview', ''], Admin::legacy_tab('nope'), 'unknown falls back');
    }

    public function test_screen_urls_name_every_tab(): void {
        $urls = Admin::screen_urls();
        $this->assertSame(array_keys(self::VIEWS), array_keys($urls));
        $this->assertStringContainsString('page=diluxone-offload-provider&tab=credentials', $urls['credentials']);
        $this->assertStringNotContainsString('tab=', $urls['overview'], 'Overview has no tab argument');
        $this->assertSame($urls['sync'], Admin::screen_url('sync-offloading', 'sync'));
        $this->assertSame(Admin::screen_url('sync-offloading'), Admin::screen_url('sync-offloading', 'not-a-tab'), 'an unknown tab is dropped: the screen opens on its first tab');
        $this->assertStringContainsString('auto-start=1', Admin::screen_url('sync-offloading', 'sync', ['auto-start' => '1']));
    }

    public function captureRedirect(string $location) {
        throw new \RuntimeException('redirect:' . $location);
    }

    /** @dataProvider legacyRedirects */
    public function test_an_old_tab_url_on_the_top_level_page_redirects_to_its_screen(array $get, string $expect): void {
        $_GET = $get;
        add_filter('wp_redirect', [$this, 'captureRedirect']);
        try {
            Admin::redirect_legacy_tab();
            $this->assertSame('', $expect, 'no redirect happened');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($expect, $e->getMessage());
        } finally {
            remove_filter('wp_redirect', [$this, 'captureRedirect']);
            $_GET = [];
        }
    }

    /** @return array<string, array{array<string, string>, string}> */
    public function legacyRedirects(): array {
        return [
            'cloud-provider' => [['page' => 'diluxone-offload', 'tab' => 'cloud-provider'], 'page=diluxone-offload-provider&tab=connection'],
            'sync alias'     => [['page' => 'diluxone-offload', 'tab' => 'sync'], 'page=diluxone-offload-sync&tab=sync'],
            'auto-start'     => [['page' => 'diluxone-offload', 'tab' => 'sync-offloading', 'auto-start' => '1'], 'auto-start=1'],
            'status-tools'   => [['page' => 'diluxone-offload', 'tab' => 'status-tools'], 'page=diluxone-offload-status&tab=health'],
            'unknown'        => [['page' => 'diluxone-offload', 'tab' => 'nope'], 'page=diluxone-offload'],
            'no tab'         => [['page' => 'diluxone-offload'], ''],
        ];
    }

    public function test_add_admin_menu_registers_the_menu_and_a_submenu_per_screen(): void {
        global $menu, $submenu;
        $menu = [];
        $submenu = [];
        Admin::add_admin_menu();
        $found = false;
        foreach ((array) $menu as $item) {
            if (isset($item[2]) && $item[2] === 'diluxone-offload') {
                $found = true;
            }
        }
        $this->assertTrue($found, 'menu slug diluxone-offload registered');
        $pages = array_map(fn($i) => $i[2], (array) ($submenu['diluxone-offload'] ?? []));
        $this->assertSame(['diluxone-offload', 'diluxone-offload-provider', 'diluxone-offload-sync', 'diluxone-offload-settings', 'diluxone-offload-status'], $pages, 'one submenu per screen, Overview first on the parent slug');
        $this->assertNotFalse(has_action('load-toplevel_page_diluxone-offload', [Admin::class, 'redirect_legacy_tab']), 'the old tab URLs are redirected on load');
    }

    public function test_accent_colour_is_a_css_colour(): void {
        $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{3,8}$/', Admin::accent_color());
    }

    public function test_a_screen_with_tabs_shows_the_strip_and_marks_the_open_tab(): void {
        $html = $this->render('credentials');
        $this->assertStringContainsString('nav-tab-wrapper', $html);
        $this->assertMatchesRegularExpression('/class="nav-tab nav-tab-active"\s+aria-current="page">\s*Credentials/', $html);
        $this->assertStringContainsString('DiluxOne Offload | Cloud Provider', $html, 'the heading names the screen, not the tab');
        $this->assertStringNotContainsString('nav-tab-wrapper', $this->render('overview'), 'Overview has no tab strip');
    }

    // ── Rendering: every tab, every state ───────────────────

    public function test_every_view_renders_when_not_configured(): void {
        foreach ($this->views() as $view) {
            $html = $this->render($view);
            $this->assertNotSame('', $html);
        }
        $this->assertStringContainsString('Sync &amp; Offloading Not Available', $this->render('sync'));
        $this->assertStringContainsString('Connect a provider in the Connection tab first', $this->render('credentials'));
    }

    public function test_every_view_renders_when_configured(): void {
        $this->configure(PluginState::CONFIGURED);
        $this->useFakeClient();
        foreach ($this->views() as $view) {
            $this->render($view);
        }
        $this->assertStringContainsString('remove-provider', $this->render('credentials'), 'the provider can be deleted before offloading');
        $this->assertStringContainsString('Run the sync first', $this->render('offloading'));
        $this->assertStringContainsString('nothing to bring back', $this->render('disconnect'));
    }

    public function test_every_view_renders_when_synced(): void {
        $this->configure(PluginState::SYNCED);
        $this->useFakeClient();
        $this->addTestFiles(3);
        foreach ($this->views() as $view) {
            $this->render($view);
        }
        // Three files the sync never took are "synced with errors": Offloading points back at Sync.
        $this->assertStringContainsString('could not be uploaded', $this->render('offloading'));
        $this->assertStringNotContainsString('enable-offloading-btn', $this->render('offloading'));
        foreach ([1, 2, 3] as $i) {
            DB::mark_synced("/2024/01/test-file-$i.jpg");
        }
        $this->assertStringContainsString('enable-offloading-btn', $this->render('offloading'), 'Enable Offloading lives on the Offloading tab once every file is in the cloud');
        $this->assertStringNotContainsString('enable-offloading-btn"', $this->render('sync'), 'and not on the Sync tab');
    }

    public function test_every_view_renders_when_offloading_is_active(): void {
        $this->configure(PluginState::OFFLOADING_ACTIVE);
        $this->useFakeClient();
        $this->addTestFiles(2);
        \DiluxOneOffload\DiluxOneOffloadDB::mark_synced('/2024/01/test-file-1.jpg');
        foreach ($this->views() as $view) {
            $this->render($view);
        }
        $this->assertStringContainsString('delete-local-files-btn', $this->render('offloading'));
        $this->assertStringContainsString('disconnect-from-cloud-btn', $this->render('disconnect'));
        $this->assertStringNotContainsString('disconnect-from-cloud-btn', $this->render('sync'), 'Disconnect has its own tab');
        $this->assertStringNotContainsString('remove-provider"', $this->render('credentials'), 'the provider cannot be deleted while offloading');
        $this->assertStringContainsString('Disconnect first', $this->render('credentials'));
    }

    public function test_overview_uses_cached_stats_when_present(): void {
        $this->configure(PluginState::SYNCED);
        $this->useFakeClient();
        set_transient('diluxone_offload_azure_stats', ['fileCount' => 42, 'storageUsedBytes' => 1024, 'storageLimitBytes' => null, 'plan' => null, 'bandwidthUsedBytes' => null, 'storageCheckedAt' => gmdate('c'), 'quotaExceeded' => false, 'filesByType' => ['images' => 40, 'videos' => 0, 'audio' => 0, 'other' => 2]], 300);
        $html = $this->render('overview');
        $this->assertStringContainsString('42', $html);
        $this->assertStringNotContainsString('diluxone-offload-stats-wrap diluxone-offload-loading', $html, 'warm cache: not in loading state');
        $this->assertMatchesRegularExpression('/id="stats-loading"[^>]*display: none/', $html, 'warm cache: overlay hidden');
    }

    public function test_overview_paints_the_loading_overlay_on_a_cold_cache(): void {
        $this->configure(PluginState::SYNCED);
        $this->useFakeClient();
        delete_transient('diluxone_offload_azure_stats');
        $html = $this->render('overview');
        $this->assertStringContainsString('diluxone-offload-stats-wrap diluxone-offload-loading', $html);
        $this->assertDoesNotMatchRegularExpression('/id="stats-loading"[^>]*display: none/', $html, 'cold cache: overlay visible');
    }

    public function test_sync_tab_explains_unreadable_credentials(): void {
        $this->configure(PluginState::CONFIGURED);
        // Corrupt the stored key so decryption fails, then the health records it.
        $cfg = get_option('diluxone_offload_config');
        $cfg['provider_config']['access_key'] = 'DILUXONEOFFLOADENC1:not-base64!!';
        update_option('diluxone_offload_config', $cfg);
        update_option('diluxone_offload_connection_health', ['status' => 'unhealthy', 'error_code' => 'decrypt_failed', 'error_message' => 'x', 'consecutive_failures' => 1, 'last_check' => time(), 'last_success' => 0, 'error_source' => 'crypto']);
        $html = $this->render('sync');
        $this->assertStringContainsString('Unreadable', $html);
    }

    /** @dataProvider healthErrors */
    public function test_health_banner_for_each_error_code(string $code, string $expect): void {
        $this->configure(PluginState::SYNCED);
        $this->useFakeClient();
        update_option('diluxone_offload_connection_health', ['status' => 'unhealthy', 'error_code' => $code, 'error_message' => 'boom', 'consecutive_failures' => 3, 'last_check' => time(), 'last_success' => 0, 'error_source' => 'azure']);
        $html = $this->render('overview');
        $this->assertStringContainsString($expect, strtolower($html));
        $this->assertNotSame('', Admin::pause_reason_short($code));
    }

    /** @return array<string, array{string,string}> */
    public function healthErrors(): array {
        return [
            'decrypt' => ['decrypt_failed', 'credential'],
            '401'     => ['401', 'permission'],
            '403'     => ['403', 'permission'],
            '404'     => ['404', 'not found'],
            'network' => ['exception', 'connect'],
            'other'   => ['500', 'paused'],
        ];
    }

    /** @dataProvider lastSuccessAges */
    public function test_health_banner_says_when_the_cloud_last_answered(int $age, string $expect): void {
        $this->configure(PluginState::OFFLOADING_ACTIVE);
        $this->useFakeClient();
        update_option('diluxone_offload_connection_health', ['status' => 'unhealthy', 'error_code' => '403', 'error_message' => 'x', 'consecutive_failures' => 3, 'last_check' => time(), 'last_success' => time() - $age, 'error_source' => 'azure']);
        $html = $this->render('overview');
        if ($expect === '') {
            $this->assertStringNotContainsString('Last successful connection', $html);
        } else {
            $this->assertStringContainsString($expect, $html);
        }
        $this->assertStringContainsString('New uploads are refused until the connection recovers', $html, 'offloading is on: uploads are refused, never written elsewhere');
    }

    /** @return array<string, array{int,string}> */
    public function lastSuccessAges(): array {
        return [
            'just now' => [60, ''],
            'minutes'  => [600, '10 minutes ago'],
            'hours'    => [7200, '2 hours ago'],
            'days'     => [3 * 86400, '3 days ago'],
        ];
    }

    public function test_sync_tab_resets_a_sync_whose_tab_went_away(): void {
        $this->configure(PluginState::SYNCING);
        $this->useFakeClient();
        update_option('diluxone_offload_sync_meta', ['status' => 'started', 'sync_session_id' => 'gone', 'last_heartbeat' => time() - 600], false);
        $this->render('sync');
        $this->assertSame(PluginState::SYNCED, ConfigManager::get_state(), 'stale syncing state is healed on render');
    }

    public function test_sync_tab_keeps_a_live_sync(): void {
        $this->configure(PluginState::SYNCING);
        $this->useFakeClient();
        update_option('diluxone_offload_sync_meta', ['status' => 'started', 'sync_session_id' => 'live', 'last_heartbeat' => time()], false);
        $this->render('offloading');
        $this->assertSame(PluginState::SYNCING, ConfigManager::get_state(), 'every tab of the screen heals or keeps the state alike');
    }

    public function test_pause_reason_short_has_a_fallback(): void {
        $this->assertNotSame('', Admin::pause_reason_short('something-new'));
    }

    // ── Template branches ───────────────────────────────────

    public function test_every_view_shows_a_queued_notice_exactly_once(): void {
        foreach ($this->views() as $tab) {
            Admin::flash_notice('success', 'Saved fine <b>');
            $html = $this->render($tab);
            $this->assertStringContainsString('notice-success', $html, $tab);
            $this->assertStringContainsString('Saved fine &lt;b&gt;', $html, "$tab escapes the message");
            $this->assertStringNotContainsString('notice-success', $this->render($tab), "$tab shows it once");

            Admin::flash_notice('error', 'Went wrong');
            $html = $this->render($tab);
            $this->assertStringContainsString('notice-error', $html, $tab);
            $this->assertStringContainsString('Went wrong', $html, $tab);
        }
    }

    public function test_a_message_in_the_url_is_ignored(): void {
        $_GET['success'] = 'Not from us';
        $_GET['error']   = 'Not from us either';
        try {
            $html = $this->render('transfers');
        } finally {
            unset($_GET['success'], $_GET['error']);
        }
        $this->assertStringNotContainsString('Not from us', $html);
    }

    public function test_overview_names_the_azure_account(): void {
        $this->configure(PluginState::CONFIGURED);
        $this->useFakeClient();
        $html = $this->render('overview');
        $this->assertStringContainsString('renderacct', $html);
    }

    /**
     * The overview paints the cached stats and says how old they are.
     *
     * @dataProvider checkedAtAges
     */
    public function test_overview_renders_the_cached_stats_and_their_age(int $age, string $expect): void {
        $this->configure(PluginState::SYNCED);
        $this->useFakeClient();
        set_transient('diluxone_offload_azure_stats', [
            'fileCount' => 5, 'storageUsedBytes' => 900,
            'storageCheckedAt' => gmdate('c', time() - $age), 'filesByType' => ['images' => 5, 'videos' => 0, 'audio' => 0, 'other' => 0],
        ], 300);
        try {
            $html = $this->render('overview');
        } finally {
            delete_transient('diluxone_offload_azure_stats');
        }
        $this->assertStringContainsString('900 B', $html, 'storage used');
        $this->assertStringContainsString('stat-pie-section', $html);
        $this->assertStringContainsString($expect, $html);
    }

    /** @return array<string, array{int,string}> */
    public function checkedAtAges(): array {
        return [
            'just now' => [10, 'just now'],
            'minutes'  => [600, '10 minutes ago'],
            'hours'    => [7200, '2 hours ago'],
            'days'     => [3 * 86400, gmdate('Y', time() - 3 * 86400)],
        ];
    }

    public function test_overview_without_a_file_breakdown_shows_plain_usage(): void {
        $this->configure(PluginState::SYNCED);
        $this->useFakeClient();
        set_transient('diluxone_offload_azure_stats', ['fileCount' => 1, 'storageUsedBytes' => 2048, 'storageCheckedAt' => null, 'filesByType' => null], 300);
        try {
            $html = $this->render('overview');
        } finally {
            delete_transient('diluxone_offload_azure_stats');
        }
        $this->assertStringContainsString('2 KB', $html);
    }

    /** @dataProvider pausedStates */
    public function test_status_tab_explains_a_paused_plugin(string $state, string $expect): void {
        $this->configure($state);
        $this->useFakeClient();
        update_option('diluxone_offload_connection_health', ['status' => 'unhealthy', 'error_code' => '403', 'error_message' => 'x', 'consecutive_failures' => 3, 'last_check' => time(), 'last_success' => 0, 'error_source' => 'azure']);
        $html = $this->render('health');
        $this->assertStringContainsString('Paused (', $html);
        $this->assertStringContainsString($expect, $html);
    }

    /** @return array<string, array{string,string}> */
    public function pausedStates(): array {
        return [
            'synced'     => [PluginState::SYNCED, 'is-paused'],
            'offloading' => [PluginState::OFFLOADING_ACTIVE, 'New uploads are refused until the connection recovers'],
        ];
    }

    public function test_status_tab_flags_unreadable_credentials(): void {
        $this->configure(PluginState::SYNCED);
        update_option('diluxone_offload_connection_health', ['status' => 'unhealthy', 'error_code' => 'decrypt_failed', 'error_message' => 'x', 'consecutive_failures' => 1, 'last_check' => time(), 'last_success' => 0, 'error_source' => 'crypto']);
        $html = $this->render('health');
        $this->assertStringContainsString('Awaiting Re-entry', $html);
        $this->assertStringContainsString('Re-enter Credentials', $html);
    }

    public function test_sync_tab_offers_to_continue_an_interrupted_sync(): void {
        $this->configure(PluginState::CONFIGURED);
        $this->useFakeClient();
        DB::add_file('/2026/09/pending.jpg', 10);
        DB::add_file('/2026/09/done.jpg', 10);
        DB::mark_synced('/2026/09/done.jpg');
        $html = $this->render('sync');
        $this->assertStringContainsString('Sync Not Completed', $html);
        $this->assertStringContainsString('start-sync-btn', $html);
    }

    public function test_sync_tab_with_everything_synced_but_not_finished(): void {
        $this->configure(PluginState::CONFIGURED);
        $this->useFakeClient();
        DB::add_file('/2026/09/done.jpg', 10);
        DB::mark_synced('/2026/09/done.jpg');
        $html = $this->render('sync');
        $this->assertStringContainsString('Sync Not Completed', $html);
    }

    public function test_status_health_shows_the_connection_table_and_the_tracking_rows(): void {
        $this->configure(PluginState::SYNCED);
        $this->useFakeClient();
        $this->addTestFiles(3);
        update_option('diluxone_offload_connection_health', ['status' => 'healthy', 'error_code' => '', 'error_message' => '', 'consecutive_failures' => 0, 'last_check' => time() - 120, 'last_success' => time() - 120, 'error_source' => '']);
        $html = $this->render('health');
        $this->assertStringContainsString('Connection Health', $html);
        $this->assertStringContainsString('2 minutes ago', $html);
        $this->assertStringContainsString('3 files tracked', $html);
        $this->assertStringContainsString('Free disk', $this->render('system'));
    }

    public function test_status_system_shows_the_free_disk_while_offloading_is_active(): void {
        // While offloading is on, wp_upload_dir() answers with the cloud path,
        // which has no disk: the row must read the server's own directory.
        $this->configure(PluginState::OFFLOADING_ACTIVE);
        $this->useFakeClient();
        \DiluxOneOffload\CloudStreamWrapper::register();
        \DiluxOneOffload\CloudStreamWrapper::activate_offloading();
        try {
            $this->assertStringStartsWith('diluxoneoffload://', (string) wp_upload_dir()['basedir'], 'the filter is on');
            $html = $this->render('system');
        } finally {
            \DiluxOneOffload\CloudStreamWrapper::deactivate_offloading();
            \DiluxOneOffload\CloudStreamWrapper::unregister();
        }
        $this->assertStringNotContainsString('not available', $html);
        $this->assertMatchesRegularExpression('/Free disk.*?<strong>[\d.,]+\s?[KMGT]?B<\/strong>/s', $html, 'a size, read from the server\'s uploads directory');
        $this->assertStringNotContainsString('diluxoneoffload://', substr($html, strpos($html, 'Upload Directory')), 'the rows show the server path, not the cloud one');
    }


    public function test_assets_are_enqueued_only_on_our_page(): void {
        $GLOBALS['wp_scripts'] = null;
        Admin::enqueue_admin_assets('edit.php');
        $this->assertFalse(wp_script_is('diluxone-offload-admin', 'enqueued'));
        Admin::enqueue_admin_assets('toplevel_page_diluxone-offload');
        $this->assertTrue(wp_script_is('diluxone-offload-admin', 'enqueued'));
        $this->assertTrue(wp_style_is('diluxone-offload-admin', 'enqueued'));
    }

    public function test_each_tab_gets_its_own_script_and_localized_strings(): void {
        $this->configure(PluginState::SYNCED);
        $this->useFakeClient();
        $with_js = ['overview' => 'DiluxOneOffloadOverview', 'connection' => 'DiluxOneOffloadProvider', 'credentials' => 'DiluxOneOffloadProvider', 'sync' => 'DiluxOneOffloadSync', 'offloading' => 'DiluxOneOffloadSync', 'disconnect' => 'DiluxOneOffloadSync'];
        foreach ($with_js as $view => $object) {
            $this->render($view);
            $handles = array_filter(wp_scripts()->queue, fn($h) => strpos($h, 'diluxone-offload-admin-') === 0);
            $this->assertCount(1, $handles, "$view enqueues exactly one screen script");
            $data = wp_scripts()->get_data(reset($handles), 'data');
            $this->assertStringContainsString($object, (string) $data, "$view localizes $object");
            $this->assertStringContainsString('"urls"', (string) $data, "$view hands the screen URLs to the script");
        }
        foreach (['transfers', 'serving', 'logging', 'health', 'system'] as $css_only) {
            $this->render($css_only);
            $this->assertCount(0, array_filter(wp_scripts()->queue, fn($h) => strpos($h, 'diluxone-offload-admin-') === 0), "$css_only has css only");
        }
    }
}
