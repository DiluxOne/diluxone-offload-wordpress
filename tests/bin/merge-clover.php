<?php
/**
 * Merges Clover reports (unit + integration) into one, taking for every line
 * the sum of hits across reports, and prints a per-file coverage table.
 *
 * Usage: php tests/bin/merge-clover.php out.xml in1.xml in2.xml ...
 */
declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "usage: merge-clover.php <out.xml> <in.xml>...\n");
    exit(2);
}
$out = $argv[1];
/** @var array<string, array<int, int>> $files path => [line => hits] */
$files = [];
foreach (array_slice($argv, 2) as $in) {
    $doc = new DOMDocument();
    if (!$doc->load($in)) {
        fwrite(STDERR, "cannot read $in\n");
        exit(2);
    }
    foreach ($doc->getElementsByTagName('file') as $file) {
        $name = $file->getAttribute('name');
        // Reports come from different containers; key by the path inside the plugin.
        $key = preg_replace('#^.*?/((?:includes|templates)/.*)$#', '$1', $name);
        foreach ($file->getElementsByTagName('line') as $line) {
            if ($line->getAttribute('type') !== 'stmt') {
                continue;
            }
            $n = (int) $line->getAttribute('num');
            $files[$key][$n] = ($files[$key][$n] ?? 0) + (int) $line->getAttribute('count');
        }
    }
}
ksort($files);

$doc = new DOMDocument('1.0', 'UTF-8');
$doc->formatOutput = true;
$coverage = $doc->appendChild($doc->createElement('coverage'));
$coverage->setAttribute('generated', (string) time());
$project = $coverage->appendChild($doc->createElement('project'));
$project->setAttribute('timestamp', (string) time());

$totalStmts = 0;
$totalCovered = 0;
$rows = [];
foreach ($files as $path => $lines) {
    ksort($lines);
    $el = $project->appendChild($doc->createElement('file'));
    $el->setAttribute('name', $path);
    $covered = 0;
    foreach ($lines as $n => $hits) {
        $l = $el->appendChild($doc->createElement('line'));
        $l->setAttribute('num', (string) $n);
        $l->setAttribute('type', 'stmt');
        $l->setAttribute('count', (string) $hits);
        $covered += $hits > 0 ? 1 : 0;
    }
    $m = $el->appendChild($doc->createElement('metrics'));
    $m->setAttribute('statements', (string) count($lines));
    $m->setAttribute('coveredstatements', (string) $covered);
    $totalStmts += count($lines);
    $totalCovered += $covered;
    $rows[] = [$path, $covered, count($lines)];
}
$m = $project->appendChild($doc->createElement('metrics'));
$m->setAttribute('statements', (string) $totalStmts);
$m->setAttribute('coveredstatements', (string) $totalCovered);
$doc->save($out);

usort($rows, fn($a, $b) => ($a[1] / max(1, $a[2])) <=> ($b[1] / max(1, $b[2])));
foreach ($rows as [$path, $c, $t]) {
    printf("%6.2f%%  %5d/%-5d  %s\n", $t ? 100 * $c / $t : 0, $c, $t, $path);
}
printf("\nTOTAL %.2f%% (%d/%d lines)\n", $totalStmts ? 100 * $totalCovered / $totalStmts : 0, $totalCovered, $totalStmts);
