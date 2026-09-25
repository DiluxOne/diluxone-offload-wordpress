<?php
namespace Tests\Integration;

/**
 * Script WordPress's HTTP layer inside integration tests.
 *
 * Hooks pre_http_request — the supported way to short-circuit wp_remote_* —
 * so a test can say what Azure or the DiluxOne API answers, with a real
 * WordPress and a real database underneath. Streamed downloads are written
 * to their target file, as the real transport would.
 *
 *     $this->scriptHttp(fn($method, $url, $args) => $this->httpReply(201));
 */
trait ScriptedHttp {

    /** @var callable|null */
    private $http_script = null;

    /** @var array<int, array{method:string,url:string,args:array}> */
    private array $http_log = [];

    private bool $http_hooked = false;

    protected function scriptHttp(callable $script): void {
        $this->http_script = $script;
        $this->http_log    = [];
        if (!$this->http_hooked) {
            add_filter('pre_http_request', [$this, 'handleScriptedRequest'], 1, 3);
            $this->http_hooked = true;
        }
    }

    protected function unhookHttp(): void {
        if ($this->http_hooked) {
            remove_filter('pre_http_request', [$this, 'handleScriptedRequest'], 1);
            $this->http_hooked = false;
        }
        $this->http_script = null;
    }

    /** @return array<int, array{method:string,url:string,args:array}> */
    protected function httpRequests(string $method = ''): array {
        if ($method === '') {
            return $this->http_log;
        }
        return array_values(array_filter($this->http_log, fn($r) => $r['method'] === $method));
    }

    /** @param array<string,string> $headers */
    protected static function httpReply(int $code, string $body = '', array $headers = []): array {
        return [
            'response' => ['code' => $code, 'message' => ''],
            'body'     => $body,
            'headers'  => $headers,
            'cookies'  => [],
            'filename' => null,
        ];
    }

    /**
     * pre_http_request callback. Public because WordPress calls it.
     *
     * @param false|array|\WP_Error $pre
     * @param array                 $args
     * @param string                $url
     * @return false|array|\WP_Error
     */
    public function handleScriptedRequest($pre, $args, $url) {
        if (!is_callable($this->http_script)) {
            return $pre;
        }
        $method = strtoupper((string) ($args['method'] ?? 'GET'));
        $this->http_log[] = ['method' => $method, 'url' => (string) $url, 'args' => $args];

        $response = ($this->http_script)($method, (string) $url, $args);

        if (is_array($response) && !empty($args['stream']) && !empty($args['filename'])) {
            wp_mkdir_p(dirname($args['filename']));
            file_put_contents($args['filename'], (string) ($response['body'] ?? ''));
            $response['body']     = '';
            $response['filename'] = $args['filename'];
        }
        return $response;
    }
}
