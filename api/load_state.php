<?php
/**
 * Load State API - Streams GeoJSON directly to client
 * 
 * Uses direct file streaming with readfile() - no PHP memory overhead.
 * The file goes straight from disk to the network buffer.
 */

// Minimal error handling
ini_set('display_errors', 0);
@set_time_limit(300);

// Helper to output JSON error
function jsonError($msg, $code = 500) {
    http_response_code($code);
    header('Content-Type: application/json');
    die(json_encode(['error' => $msg]));
}

// Resolve paths
$baseDir = realpath(__DIR__ . '/..');
if (!$baseDir) jsonError('Cannot resolve base directory.');

$dataDir = $baseDir . '/data';
$stateCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_GET['state'] ?? ''));
if (!$stateCode) jsonError('Missing or invalid state parameter.', 400);

$precinctsPath = "$dataDir/precincts/$stateCode/precincts.geojson";

if (!file_exists($precinctsPath)) {
    // List available states
    $available = [];
    $dir = "$dataDir/precincts";
    if (is_dir($dir) && ($dh = opendir($dir))) {
        while (($e = readdir($dh)) !== false) {
            if ($e[0] !== '.' && is_dir("$dir/$e") && file_exists("$dir/$e/precincts.geojson")) {
                $available[] = $e;
            }
        }
        closedir($dh);
    }
    jsonError("No data for state '$stateCode'. Available: " . implode(', ', $available), 404);
}

if (!is_readable($precinctsPath)) {
    jsonError('File not readable.', 403);
}

// Get state metadata
$stateMeta = ['code' => $stateCode, 'abbr' => $stateCode, 'name' => $stateCode];
$defaultDistricts = 10;

$statesFile = "$dataDir/states.json";
if (file_exists($statesFile)) {
    $states = json_decode(file_get_contents($statesFile), true);
    if (is_array($states)) {
        foreach ($states as $s) {
            if (strtoupper($s['abbr'] ?? $s['code'] ?? '') === $stateCode) {
                $stateMeta = [
                    'code' => $stateCode,
                    'abbr' => $s['abbr'] ?? $stateCode,
                    'name' => $s['name'] ?? $stateCode,
                ];
                $defaultDistricts = $s['defaultNumDistricts'] ?? 10;
                break;
            }
        }
    }
}

// Stream response directly - no memory overhead
header('Content-Type: application/json');

// Disable output buffering for true streaming
while (ob_get_level()) ob_end_clean();

// Start JSON object, embed metadata, then stream the geojson file directly
echo '{"state":' . json_encode($stateMeta) . ',';
echo '"defaultNumDistricts":' . $defaultDistricts . ',';
echo '"precincts":';

// Stream file directly from disk to output - uses almost no PHP memory
readfile($precinctsPath);

echo '}';