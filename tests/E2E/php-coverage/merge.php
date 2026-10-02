<?php
/**
 * Merges the per-request Xdebug files of `make coverage-e2e-php` into one
 * Clover report, with sebastianbergmann/php-code-coverage (the library
 * PHPUnit uses for `make coverage`, so lines are counted the same way).
 *
 * Runs in the dlx-cov image with the checkout mounted at the plugin's path
 * inside wp-env, so the file names Xdebug recorded resolve as they are.
 *
 * Usage: php tests/E2E/php-coverage/merge.php <raw dir> <out clover.xml>
 */
declare(strict_types=1);

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Driver\PcovDriver;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Report\Clover;
use SebastianBergmann\CodeCoverage\StaticAnalysis\ParsingFileAnalyser;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';

[, $rawDir, $out] = $argv + [null, null, null];
if ($rawDir === null || $out === null) {
    fwrite(STDERR, "usage: merge.php <raw dir> <out clover.xml>\n");
    exit(2);
}

$filter = new Filter();
$filter->includeDirectory($root . '/includes');
$filter->includeDirectory($root . '/templates');
$filter->includeFiles([$root . '/diluxone-offload.php', $root . '/uninstall.php']);

// Every executable line of every plugin file starts as not executed, so a
// file no request loaded, and a line Xdebug did not list, both count.
$analyser = new ParsingFileAnalyser(true, false);
$merged   = [];
foreach ($filter->files() as $file) {
    $merged[$file] = array_fill_keys(array_keys($analyser->executableLinesIn($file)), -1);
}

// Per line the best state any request reached: 1 executed > -1 not > -2 dead.
$requests = 0;
foreach (glob($rawDir . '/*.json') ?: [] as $json) {
    $data = json_decode((string) file_get_contents($json), true);
    if (!is_array($data)) {
        continue;
    }
    $requests++;
    foreach ($data as $file => $lines) {
        if (!isset($merged[$file])) {
            continue;
        }
        foreach ($lines as $line => $state) {
            $merged[$file][$line] = max($merged[$file][$line] ?? -2, (int) $state);
        }
    }
}
if ($requests === 0) {
    fwrite(STDERR, "no coverage recorded in $rawDir\n");
    exit(1);
}

$coverage = new CodeCoverage(new PcovDriver($filter), $filter);
$coverage->append(RawCodeCoverageData::fromXdebugWithoutPathCoverage($merged), 'e2e');
(new Clover())->process($coverage, $out, 'diluxone-offload e2e');
fwrite(STDOUT, "Merged the coverage of $requests requests.\n");
