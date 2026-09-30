<?php
namespace Tests\Integration;

/**
 * A throwaway HTTP server that keeps connections open, for the tests of
 * connection reuse: PHP's built-in server (LocalBlobServer) closes every
 * connection after one answer, so nothing could be reused against it.
 *
 * A plain socket loop in its own process. Every request gets the status in
 * ?status= (201 by default), an empty body, the MD5 of what it received as
 * its ETag, and `Connection: keep-alive`; `Expect: 100-continue` is
 * answered. connections() says how many TCP connections clients opened.
 */
class KeepAliveServer {

    /** @var resource|null */
    private $proc = null;
    private string $dir;
    private int $baseline = 0;
    public string $base_url;

    public function __construct(int $port) {
        $this->dir      = sys_get_temp_dir() . '/dlx-keepalive-' . $port;
        $this->base_url = 'http://127.0.0.1:' . $port;
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
        }
        file_put_contents($this->dir . '/connections', '0');
        file_put_contents($this->dir . '/server.php', <<<'PHP'
<?php
[$port, $count_file] = [(int) $argv[1], $argv[2]];
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
$clients = [];
$buffers = [];
$continued = [];
$connections = 0;
while (true) {
    $read = array_merge([$server], array_values($clients));
    $write = null;
    $except = null;
    if (false === stream_select($read, $write, $except, 1)) {
        break;
    }
    foreach ($read as $socket) {
        if ($socket === $server) {
            $client = stream_socket_accept($server, 0);
            if ($client) {
                stream_set_blocking($client, false);
                $id = (int) $client;
                $clients[$id] = $client;
                $buffers[$id] = '';
                file_put_contents($count_file, (string) ++$connections);
            }
            continue;
        }
        $id = (int) $socket;
        $data = fread($socket, 65536);
        if ('' === $data || false === $data) {
            if (feof($socket)) {
                fclose($socket);
                unset($clients[$id], $buffers[$id], $continued[$id]);
            }
            continue;
        }
        $buffers[$id] .= $data;
        while (false !== ($end = strpos($buffers[$id], "\r\n\r\n"))) {
            $head = substr($buffers[$id], 0, $end);
            $length = preg_match('/^content-length:\s*(\d+)/im', $head, $m) ? (int) $m[1] : 0;
            if (strlen($buffers[$id]) < $end + 4 + $length) {
                if (false !== stripos($head, 'expect: 100-continue') && empty($continued[$id])) {
                    fwrite($socket, "HTTP/1.1 100 Continue\r\n\r\n");
                    $continued[$id] = true;
                }
                break;
            }
            $body = substr($buffers[$id], $end + 4, $length);
            $buffers[$id] = (string) substr($buffers[$id], $end + 4 + $length);
            $continued[$id] = false;
            $status = preg_match('/[?&]status=(\d{3})/', strtok($head, "\r\n"), $m) ? (int) $m[1] : 201;
            fwrite($socket, "HTTP/1.1 $status Answer\r\nContent-Length: 0\r\nETag: \"" . md5($body) . "\"\r\nConnection: keep-alive\r\n\r\n");
        }
    }
}
PHP
        );
        $cmd        = sprintf('exec php %s %d %s > /dev/null 2>&1', escapeshellarg($this->dir . '/server.php'), $port, escapeshellarg($this->dir . '/connections'));
        $this->proc = proc_open($cmd, [], $pipes);

        for ($i = 0; $i < 50; $i++) {
            $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($s) {
                fclose($s);
                // The probe itself was a connection: it is not counted.
                usleep(100000);
                $this->baseline = $this->total();
                return;
            }
            usleep(50000);
        }
        throw new \RuntimeException('keep-alive server did not start');
    }

    /** How many TCP connections clients opened since the server started, the readiness probe aside. */
    public function connections(): int {
        return $this->total() - $this->baseline;
    }

    private function total(): int {
        clearstatcache();
        return (int) file_get_contents($this->dir . '/connections');
    }

    public function stop(): void {
        if (is_resource($this->proc)) {
            proc_terminate($this->proc);
            proc_close($this->proc);
        }
        $this->proc = null;
    }
}
