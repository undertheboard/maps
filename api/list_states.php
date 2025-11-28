<?php
header('Content-Type: application/json');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$baseDir       = realpath(__DIR__ . '/..');
$dataDir       = $baseDir . '/data';
$precinctsRoot = $dataDir . '/precincts';

if ($precinctsRoot === false || !is_dir($precinctsRoot)) {
    echo json_encode([
        'error'  => 'Precincts directory not found.',
        'states' => [],
    ]);
    exit;
}

// Optional: load states.json for nicer names
$statesMeta = [];
$statesFile = $dataDir . '/states.json';
if (file_exists($statesFile)) {
    $json = json_decode(file_get_contents($statesFile), true);
    if (is_array($json)) {
        foreach ($json as $st) {
            $code = strtoupper($st['code'] ?? $st['abbr'] ?? '');
            if ($code !== '') {
                $statesMeta[$code] = $st;
            }
        }
    }
}

$states = [];
$dir = opendir($precinctsRoot);
if ($dir === false) {
    echo json_encode([
        'error'  => 'Unable to read precincts directory.',
        'states' => [],
    ]);
    exit;
}

while (($entry = readdir($dir)) !== false) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }
    $stateDir = $precinctsRoot . '/' . $entry;
    if (!is_dir($stateDir)) {
        continue;
    }
    $geoPath = $stateDir . '/precincts.geojson';
    if (!is_readable($geoPath)) {
        continue;
    }

    $code = strtoupper($entry);

    if (isset($statesMeta[$code])) {
        $meta = $statesMeta[$code];
        $states[] = [
            'code' => $code,
            'abbr' => $meta['abbr'] ?? $meta['code'] ?? $code,
            'name' => $meta['name'] ?? $code,
        ];
    } else {
        // Fallback if no metadata in states.json
        $states[] = [
            'code' => $code,
            'abbr' => $code,
            'name' => $code,
        ];
    }
}
closedir($dir);

// Sort by state name
usort($states, function ($a, $b) {
    return strcasecmp($a['name'], $b['name']);
});

echo json_encode([
    'error'  => null,
    'states' => $states,
]);