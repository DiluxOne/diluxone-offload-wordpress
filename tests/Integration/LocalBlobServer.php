<?php
namespace Tests\Integration;

/**
 * A throwaway HTTP server for the cURL-based upload path.
 *
 * PHP's built-in server, started with proc_open and stopped in tearDown.
 * It answers every request with the status given in ?status=, defaulting
 * to 201, which is what Azure returns for a created block blob. With
 * ?delay=N (or /delay-N/ in the path) it sleeps N seconds first, which is
 * how the timeout tests get a server slower than the setting allows. The
 * ETag of every answer is the MD5 of the body it received.
 *
 * The port asked for is used when it is free; otherwise the system picks
 * one, so a server left over from an earlier run (or another test class)
 * never answers for this one. It is ready when it answers with its own
 * token, and it serves requests with several workers, as the parallel
 * uploads of the sync need.
 */
class LocalBlobServer {

    /** @var resource|null */
    private $proc = null;
    private string $docroot;
    public string $base_url;

    public function __construct(int $port = 8765) {
        $this->docroot  = sys_get_temp_dir() . '/dlx-blob-server';
        $port           = self::free_port($port);
        $token          = bin2hex(random_bytes(8));
        $this->base_url = 'http://127.0.0.1:' . $port;
        if (!is_dir($this->docroot)) {
            mkdir($this->docroot, 0777, true);
        }
        file_put_contents($this->docroot . '/router.php', <<<'PHP'
<?php
$status = (int) ($_GET['status'] ?? 201);
if (preg_match('#/status-(\d{3})/#', (string) ($_SERVER['REQUEST_URI'] ?? ''), $m)) {
    $status = (int) $m[1];
}
$delay = (int) ($_GET['delay'] ?? 0);
if (preg_match('#/delay-(\d{1,3})/#', (string) ($_SERVER['REQUEST_URI'] ?? ''), $m)) {
    $delay = (int) $m[1];
}
if ($delay > 0) {
    sleep($delay);
}
http_response_code($status);
header('X-Dlx-Server: ' . getenv('DLX_SERVER_TOKEN'));
// Drain the body so cURL sees a clean upload, and answer with its MD5 as the
// ETag, so a test can tell which bytes arrived.
header('ETag: "' . md5((string) file_get_contents('php://input')) . '"');
echo $status >= 400 ? '<Error><Message>rejected</Message></Error>' : '';
PHP
        );
        $cmd = sprintf(
            'DLX_SERVER_TOKEN=%s PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:%d %s > /dev/null 2>&1',
            $token,
            $port,
            escapeshellarg($this->docroot . '/router.php')
        );
        $this->proc = proc_open($cmd, [], $pipes);

        // Wait until this server, not another one on the port, answers.
        $context = stream_context_create(['http' => ['timeout' => 0.5, 'ignore_errors' => true]]);
        for ($i = 0; $i < 100; $i++) {
            $headers = @get_headers($this->base_url . '/ready', false, $context);
            if (is_array($headers) && in_array('X-Dlx-Server: ' . $token, $headers, true)) {
                return;
            }
            usleep(50000);
        }
        throw new \RuntimeException('local blob server did not start');
    }

    /** The port asked for when nothing listens on it, or one the system picks. */
    private static function free_port(int $wanted): int {
        $probe = @stream_socket_server('tcp://127.0.0.1:' . $wanted);
        if (false === $probe) {
            $probe = stream_socket_server('tcp://127.0.0.1:0');
        }
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        return (int) substr($name, strrpos($name, ':') + 1);
    }

    public function stop(): void {
        if (is_resource($this->proc)) {
            $status = proc_get_status($this->proc);
            if (!empty($status['pid'])) {
                // proc_open wraps the command in a shell; kill the whole group.
                @exec('pkill -P ' . (int) $status['pid'] . ' 2>/dev/null');
            }
            proc_terminate($this->proc);
            proc_close($this->proc);
            $this->proc = null;
        }
    }

    public function __destruct() {
        $this->stop();
    }
}
