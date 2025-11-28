<?php
header('Content-Type: application/json');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Convert any PHP error into a JSON response instead of HTML
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode([
        'error'   => 'PHP error',
        'errno'   => $errno,
        'message' => $errstr,
        'file'    => $errfile,
        'line'    => $errline,
    ]);
    exit;
});

// Convert uncaught exceptions into JSON
set_exception_handler(function ($e) {
    http_response_code(500);
    echo json_encode([
        'error'   => 'Uncaught exception',
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
    exit;
});

$baseDir = realpath(__DIR__ . '/..');          // e.g. /home/www/zudesa.com/maps
if ($baseDir === false) {
    echo json_encode(['error' => 'Cannot resolve base directory.']);
    exit;
}
$dataDir = $baseDir . '/data';

$stateParam = $_GET['state'] ?? '';
$stateParam = trim($stateParam);
if ($stateParam === '') {
    echo json_encode(['error' => 'Missing state parameter.']);
    exit;
}

// Folder name under data/precincts – e.g. NC
$stateCode = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $stateParam));
if ($stateCode === '') {
    echo json_encode(['error' => 'Invalid state code.']);
    exit;
}

// This is where upload_precincts.php wrote the file
$precinctsPath = $dataDir . '/precincts/' . $stateCode . '/precincts.geojson';

if (!file_exists($precinctsPath)) {
    echo json_encode([
        'error' => 'No precinct data for this state yet. Upload shapefile ZIP first.',
        'path'  => $precinctsPath,
    ]);
    exit;
}
if (!is_readable($precinctsPath)) {
    echo json_encode([
        'error' => 'precincts.geojson is not readable.',
        'path'  => $precinctsPath,
    ]);
    exit;
}

$raw = file_get_contents($precinctsPath);
if ($raw === false) {
    echo json_encode(['error' => 'Failed to read precincts.geojson.']);
    exit;
}

$geo = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode([
        'error'   => 'precincts.geojson is not valid JSON.',
        'details' => json_last_error_msg(),
    ]);
    exit;
}
if (!is_array($geo) || ($geo['type'] ?? '') !== 'FeatureCollection') {
    echo json_encode(['error' => 'precincts.geojson is not a GeoJSON FeatureCollection.']);
    exit;
}

// Optional: pretty state metadata from states.json
$stateMeta = [
    'code' => $stateCode,
    'abbr' => $stateCode,
    'name' => $stateCode,
];
$statesFile = $dataDir . '/states.json';
if (file_exists($statesFile)) {
    $metaJson = json_decode(file_get_contents($statesFile), true);
    if (is_array($metaJson)) {
        foreach ($metaJson as $st) {
            // Use abbr (state abbreviation like "CA", "NC") first for matching,
            // fall back to code (FIPS code like "06", "37") if abbr not present
            $abbr = strtoupper($st['abbr'] ?? $st['code'] ?? '');
            if ($abbr === $stateCode) {
                $stateMeta = [
                    'code' => $abbr,
                    'abbr' => $st['abbr'] ?? $st['code'] ?? $abbr,
                    'name' => $st['name'] ?? $abbr,
                ];
                break;
            }
        }
    }
}

// You can tune this per state if you wish
$defaultNumDistricts = 10;

echo json_encode([
    'state'               => $stateMeta,
    'precincts'           => $geo,
    'defaultNumDistricts' => $defaultNumDistricts,
]);