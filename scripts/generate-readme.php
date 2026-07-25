<?php

/**
 * Regenerates the "API Reference" section of README.md (between the marker
 * comments) from openapi.json, so the README always matches the SDK surface.
 *
 * Run via `php scripts/generate-readme.php`.
 */

declare(strict_types=1);

require __DIR__ . '/model.php';

use function Smartlyq\Generator\buildModel;

const BEGIN_MARKER = '<!-- BEGIN GENERATED REFERENCE -->';
const END_MARKER = '<!-- END GENERATED REFERENCE -->';

/**
 * @param array{
 *     name: string, pathParams: list<array{arg: string, raw: string}>,
 *     hasBody: bool, bodyRequired: bool, hasQuery: bool
 * } $m
 */
function signature(string $resourceKey, array $m): string
{
    $args = [];
    foreach ($m['pathParams'] as $p) {
        $args[] = '$' . $p['arg'];
    }
    if ($m['hasBody']) {
        $args[] = $m['bodyRequired'] ? '$body' : '$body?';
    }
    if ($m['hasQuery']) {
        $args[] = '$query?';
    }

    return '$sq->' . $resourceKey . '->' . $m['name'] . '(' . implode(', ', $args) . ')';
}

$root = dirname(__DIR__);
$resources = buildModel($root . '/openapi.json');

$lines = [];
foreach ($resources as $r) {
    $lines[] = '### ' . $r['tag'];
    $lines[] = '';
    $lines[] = '| Method | Endpoint | Description |';
    $lines[] = '| --- | --- | --- |';
    foreach ($r['methods'] as $m) {
        $lines[] = '| `' . signature($r['key'], $m) . '` | `' . $m['httpMethod'] . ' ' . $m['path'] . '` | ' . $m['summary'] . ' |';
    }
    $lines[] = '';
}

$readmePath = $root . '/README.md';
$readme = (string) file_get_contents($readmePath);
$beginIdx = strpos($readme, BEGIN_MARKER);
$endIdx = strpos($readme, END_MARKER);
if ($beginIdx === false || $endIdx === false) {
    fwrite(STDERR, "README.md is missing the generated-reference markers.\n");
    exit(1);
}

$updated = substr($readme, 0, $beginIdx + strlen(BEGIN_MARKER))
    . "\n\n" . implode("\n", $lines)
    . substr($readme, $endIdx);
file_put_contents($readmePath, $updated);

$opCount = array_sum(array_map(static fn (array $r): int => count($r['methods']), $resources));
echo 'README reference updated: ' . count($resources) . ' resources, ' . $opCount . " methods.\n";
