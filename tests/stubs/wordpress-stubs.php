<?php
/**
 * Minimal WordPress function stubs for unit testing.
 *
 * These provide basic implementations of the WordPress functions that
 * helpers and DTOs call directly. For hook functions (add_action,
 * add_filter, do_action, apply_filters with side effects, etc.) use
 * Brain Monkey inside the test, NOT a stub here.
 *
 * Patchwork (loaded by Brain Monkey) cannot redefine functions that
 * already exist when it boots — so any stub here takes precedence
 * over a Brain Monkey expectation for the same name.
 */

// ── Sanitization functions ──────────────────────────────────────

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string {
        return trim(strip_tags($str));
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string {
        return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $key));
    }
}

if (!function_exists('absint')) {
    function absint($maybeint): int {
        return abs((int) $maybeint);
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw(string $url): string {
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }
}

// ── Escaping functions ──────────────────────────────────────────

if (!function_exists('esc_html')) {
    function esc_html(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string {
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }
}

// ── Site / URL functions ────────────────────────────────────────
// Backed by $GLOBALS['_test_wp_url_base'] so tests can override the host.

if (!function_exists('_test_wp_url_base')) {
    function _test_wp_url_base(): string {
        return $GLOBALS['_test_wp_url_base'] ?? 'http://localhost';
    }
}

if (!function_exists('site_url')) {
    function site_url(string $path = '', ?string $scheme = null): string {
        $base = _test_wp_url_base();
        return $path ? $base . '/' . ltrim($path, '/') : $base;
    }
}

if (!function_exists('home_url')) {
    function home_url(string $path = '', ?string $scheme = null): string {
        return site_url($path, $scheme);
    }
}

if (!function_exists('untrailingslashit')) {
    function untrailingslashit(string $s): string {
        return rtrim($s, '/');
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit(string $s): string {
        return rtrim($s, '/') . '/';
    }
}

if (!function_exists('current_time')) {
    function current_time(string $type, $gmt = 0) {
        return $type === 'timestamp' || $type === 'U' ? time() : gmdate('Y-m-d H:i:s');
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1) {
        return parse_url($url, $component);
    }
}

// ── i18n functions ──────────────────────────────────────────────

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string {
        return $text;
    }
}

if (!function_exists('_e')) {
    function _e(string $text, string $domain = 'default'): void {
        echo $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string {
        return esc_html($text);
    }
}

if (!function_exists('esc_html_e')) {
    function esc_html_e(string $text, string $domain = 'default'): void {
        echo esc_html($text);
    }
}

// ── Utility functions ───────────────────────────────────────────

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, array $defaults = []): array {
        if (is_object($args)) {
            $parsed = get_object_vars($args);
        } elseif (is_array($args)) {
            $parsed = $args;
        } else {
            parse_str((string) $args, $parsed);
        }
        return array_merge($defaults, $parsed);
    }
}

if (!function_exists('did_action')) {
    function did_action(string $hook_name): int {
        return 0;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook_name, $value, ...$args) {
        return $value;
    }
}

if (!function_exists('do_action')) {
    function do_action(string $hook_name, ...$args): void {
        // No-op in unit tests. Use Brain Monkey if you need to assert.
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, int $options = 0, int $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}

// ── Options API (backed by $GLOBALS so tests can set/reset state) ──

if (!function_exists('get_option')) {
    function get_option(string $option, $default = false) {
        $store = $GLOBALS['_test_wp_options'] ?? [];
        return array_key_exists($option, $store) ? $store[$option] : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $option, $value, $autoload = null): bool {
        if (!isset($GLOBALS['_test_wp_options'])) {
            $GLOBALS['_test_wp_options'] = [];
        }
        $GLOBALS['_test_wp_options'][$option] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $option): bool {
        if (isset($GLOBALS['_test_wp_options'][$option])) {
            unset($GLOBALS['_test_wp_options'][$option]);
        }
        return true;
    }
}

// ── Salts (used by Crypto class via wp_salt) ────────────────────

if (!function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string {
        // Deterministic per-scheme salt for tests. Real WP returns long
        // random strings derived from wp-config.php constants.
        return 'test-salt-for-' . $scheme;
    }
}

// ── HTTP API, transients and filesystem helpers ─────────────────
//
// wp_remote_* dispatch to $GLOBALS['_test_wp_http'] when a test sets it:
//     $GLOBALS['_test_wp_http'] = function (string $method, string $url, array $args) {
//         return ['response' => ['code' => 200, 'message' => 'OK'], 'body' => '...', 'headers' => []];
//     };
// Return a WP_Error to simulate a transport failure. Without a handler every
// request answers 200 with an empty body, so a test that forgets to set one
// fails on its assertions rather than on a missing function.

if (!class_exists('WP_Error')) {
    class WP_Error {
        /** @var array<string, string[]> */
        private array $errors = [];
        public function __construct(string $code = '', string $message = '', $data = '') {
            if ($code !== '') { $this->errors[$code][] = $message; }
        }
        public function get_error_message(string $code = ''): string {
            if ($code === '') { $code = (string) array_key_first($this->errors); }
            return $this->errors[$code][0] ?? '';
        }
        public function get_error_code(): string { return (string) array_key_first($this->errors); }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool { return $thing instanceof WP_Error; }
}

if (!function_exists('_test_wp_http_dispatch')) {
    function _test_wp_http_dispatch(string $method, string $url, array $args) {
        $GLOBALS['_test_wp_http_log'][] = ['method' => $method, 'url' => $url, 'args' => $args];
        $handler  = $GLOBALS['_test_wp_http'] ?? null;
        $response = is_callable($handler)
            ? $handler($method, $url, $args)
            : ['response' => ['code' => 200, 'message' => 'OK'], 'body' => '', 'headers' => []];
        // Honour WordPress's streamed download: with 'stream' => true the body
        // lands in 'filename' and the response body is empty, exactly as the
        // real transport behaves. The stream wrapper's read path depends on it.
        if (is_array($response) && !empty($args['stream']) && !empty($args['filename'])) {
            @mkdir(dirname($args['filename']), 0777, true);
            file_put_contents($args['filename'], (string) ($response['body'] ?? ''));
            $response['body'] = '';
        }
        return $response;
    }
}

if (!function_exists('wp_remote_request')) {
    function wp_remote_request(string $url, array $args = []) {
        return _test_wp_http_dispatch(strtoupper((string) ($args['method'] ?? 'GET')), $url, $args);
    }
}
if (!function_exists('wp_remote_get')) {
    function wp_remote_get(string $url, array $args = []) { return _test_wp_http_dispatch('GET', $url, $args); }
}
if (!function_exists('wp_remote_post')) {
    function wp_remote_post(string $url, array $args = []) { return _test_wp_http_dispatch('POST', $url, $args); }
}
if (!function_exists('wp_remote_head')) {
    function wp_remote_head(string $url, array $args = []) { return _test_wp_http_dispatch('HEAD', $url, $args); }
}
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) {
        return is_array($response) ? (int) ($response['response']['code'] ?? 0) : '';
    }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response): string {
        return is_array($response) ? (string) ($response['body'] ?? '') : '';
    }
}
if (!function_exists('wp_remote_retrieve_headers')) {
    function wp_remote_retrieve_headers($response) {
        return is_array($response) ? ($response['headers'] ?? []) : [];
    }
}
if (!function_exists('wp_remote_retrieve_header')) {
    function wp_remote_retrieve_header($response, string $header) {
        if (!is_array($response)) { return ''; }
        $headers = array_change_key_case($response['headers'] ?? [], CASE_LOWER);
        return $headers[strtolower($header)] ?? '';
    }
}

if (!function_exists('get_transient')) {
    function get_transient(string $key) {
        return $GLOBALS['_test_wp_transients'][$key] ?? false;
    }
}
if (!function_exists('set_transient')) {
    function set_transient(string $key, $value, int $expiration = 0): bool {
        $GLOBALS['_test_wp_transients'][$key] = $value;
        return true;
    }
}
if (!function_exists('delete_transient')) {
    function delete_transient(string $key): bool {
        $had = isset($GLOBALS['_test_wp_transients'][$key]);
        unset($GLOBALS['_test_wp_transients'][$key]);
        return $had;
    }
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p(string $target): bool { return is_dir($target) || @mkdir($target, 0777, true); }
}
if (!function_exists('wp_tempnam')) {
    function wp_tempnam(string $filename = '', string $dir = ''): string {
        return (string) tempnam($dir !== '' ? $dir : sys_get_temp_dir(), $filename !== '' ? $filename : 'wp');
    }
}
if (!function_exists('wp_delete_file')) {
    function wp_delete_file(string $file): void { @unlink($file); }
}
if (!function_exists('maybe_unserialize')) {
    function maybe_unserialize($original) {
        if (!is_string($original) || $original === '' || !preg_match('/^(?:[aOs]:|[bidN];)/', $original)) {
            return $original;
        }
        $unserialized = @unserialize($original);
        return $unserialized === false && $original !== 'b:0;' ? $original : $unserialized;
    }
}

// ── Hooks (no-op registry), misc helpers ────────────────────────
//
// Hooks are recorded, never fired: unit tests assert on what a class does,
// not on WordPress dispatching. A test that needs a hook to fire calls the
// callback itself. $GLOBALS['_test_wp_hooks'] lets a test check what was
// registered.

if (!function_exists('add_action')) {
    function add_action(string $hook, $callback, int $priority = 10, int $args = 1): bool {
        $GLOBALS['_test_wp_hooks']['action'][$hook][] = ['callback' => $callback, 'priority' => $priority];
        return true;
    }
}
if (!function_exists('add_filter')) {
    function add_filter(string $hook, $callback, int $priority = 10, int $args = 1): bool {
        $GLOBALS['_test_wp_hooks']['filter'][$hook][] = ['callback' => $callback, 'priority' => $priority];
        return true;
    }
}
if (!function_exists('remove_filter')) {
    function remove_filter(string $hook, $callback, int $priority = 10): bool {
        $list = $GLOBALS['_test_wp_hooks']['filter'][$hook] ?? [];
        $before = count($list);
        $GLOBALS['_test_wp_hooks']['filter'][$hook] = array_values(array_filter(
            $list, fn($h) => !($h['callback'] == $callback && $h['priority'] === $priority)
        ));
        return count($GLOBALS['_test_wp_hooks']['filter'][$hook]) < $before;
    }
}
if (!function_exists('remove_action')) {
    function remove_action(string $hook, $callback, int $priority = 10): bool {
        return remove_filter($hook, $callback, $priority);
    }
}
if (!function_exists('has_filter')) {
    function has_filter(string $hook, $callback = false) {
        $list = $GLOBALS['_test_wp_hooks']['filter'][$hook] ?? [];
        if ($callback === false) { return count($list) > 0; }
        foreach ($list as $h) { if ($h['callback'] == $callback) { return $h['priority']; } }
        return false;
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value) {
        return is_array($value) ? array_map('wp_unslash', $value) : (is_string($value) ? stripslashes($value) : $value);
    }
}
if (!function_exists('size_format')) {
    function size_format($bytes, int $decimals = 0) {
        $bytes = (float) $bytes;
        foreach ([['TB', 1099511627776], ['GB', 1073741824], ['MB', 1048576], ['KB', 1024]] as [$unit, $size]) {
            if ($bytes >= $size) { return number_format($bytes / $size, $decimals) . ' ' . $unit; }
        }
        return number_format($bytes, $decimals) . ' B';
    }
}
if (!function_exists('wp_upload_dir')) {
    /**
     * Tests point this at a temp directory through $GLOBALS['_test_wp_upload_dir'];
     * the shape mirrors WordPress's. The stream wrapper's filter_upload_dir
     * is a filter callback, so a test that wants the cloud basedir applies it
     * explicitly.
     */
    function wp_upload_dir(?string $time = null, bool $create_dir = true, bool $refresh_cache = false): array {
        $base = $GLOBALS['_test_wp_upload_dir'] ?? (sys_get_temp_dir() . '/dlx-uploads');
        if ($create_dir && !is_dir($base)) { @mkdir($base, 0777, true); }
        return [
            'path'    => $base,
            'url'     => 'http://example.test/wp-content/uploads',
            'subdir'  => '',
            'basedir' => $base,
            'baseurl' => 'http://example.test/wp-content/uploads',
            'error'   => false,
        ];
    }
}
if (!function_exists('wp_get_upload_dir')) {
    function wp_get_upload_dir(): array { return wp_upload_dir(null, false); }
}

// ── Multisite ────────────────────────────────────────────────
// $GLOBALS['_test_multisite'] = ['enabled' => bool, 'main' => bool, 'blog_id' => int]

if (!function_exists('is_multisite')) {
    function is_multisite(): bool {
        return (bool) ($GLOBALS['_test_multisite']['enabled'] ?? false);
    }
}

if (!function_exists('is_main_site')) {
    function is_main_site($site_id = null): bool {
        return (bool) ($GLOBALS['_test_multisite']['main'] ?? true);
    }
}

if (!function_exists('get_current_blog_id')) {
    function get_current_blog_id(): int {
        return (int) ($GLOBALS['_test_multisite']['blog_id'] ?? 1);
    }
}
