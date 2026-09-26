<?php
namespace Tests\Integration;

/**
 * A throwaway HTTP server for the cURL-based upload path.
 *
 * PHP's built-in server, started with proc_open and stopped in tearDown.
 * It answers every request with the status given in ?status=, defaulting
 * to 201, which is what Azure returns for a created block blob. With
 * ?delay=N (or /delay-N/ in the path) it sleeps N seconds first, which is
 * how the timeout tests get a server slower than the setting allows.
 */
class LocalBlobServer {

    /** @var resource|null */
    private $proc = null;
    private string $docroot;
    public string $base_url;

    public function __construct(int $port = 8765) {
        $this->docroot  = sys_get_temp_dir() . '/dlx-blob-server';
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
// Drain the body so cURL sees a clean upload.
file_get_contents('php://input');
echo $status >= 400 ? '<Error><Message>rejected</Message></Error>' : '';
PHP
        );
        $cmd = sprintf('php -S 127.0.0.1:%d %s > /dev/null 2>&1', $port, escapeshellarg($this->docroot . '/router.php'));
        $this->proc = proc_open($cmd, [], $pipes);

        // Wait until it accepts connections (a few hundred ms at most).
        for ($i = 0; $i < 50; $i++) {
            $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($s) {
                fclose($s);
                return;
            }
            usleep(50000);
        }
        throw new \RuntimeException('local blob server did not start');
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
