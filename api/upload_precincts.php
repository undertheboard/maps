<?php
header('Content-Type: application/json');

// Increase memory as much as host allows; we still stream to avoid big peaks
ini_set('memory_limit', '1024M');

// Show all PHP errors as JSON
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

set_exception_handler(function ($e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error'   => 'Uncaught exception',
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
        'trace'   => $e->getTraceAsString(),
    ]);
    exit;
});

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error'   => 'PHP error',
        'errno'   => $errno,
        'message' => $errstr,
        'file'    => $errfile,
        'line'    => $errline,
    ]);
    exit;
});

// Load php-shapefile autoloader
require_once __DIR__ . '/../lib/php-shapefile/src/Shapefile/ShapefileAutoloader.php';
\Shapefile\ShapefileAutoloader::register();

use Shapefile\ShapefileReader;
use Shapefile\ShapefileException;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Invalid method. Use POST.']);
    exit;
}

$state = $_POST['state'] ?? '';
$state = trim($state);
if ($state === '') {
    echo json_encode(['error' => 'Missing state code.']);
    exit;
}

$stateCode = preg_replace('/[^0-9A-Za-z]/', '', $state);
if ($stateCode === '') {
    echo json_encode(['error' => 'Invalid state code.']);
    exit;
}

if (!isset($_FILES['precinctZip']) || $_FILES['precinctZip']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'File upload failed. Ensure a ZIP file is selected.']);
    exit;
}

$zipTmp = $_FILES['precinctZip']['tmp_name'];

// Use app-local temp dir (must exist and be writable)
$baseTmpDir = realpath(__DIR__ . '/../data/tmp_shapefiles');
if ($baseTmpDir === false) {
    echo json_encode(['error' => 'Base temp directory data/tmp_shapefiles does not exist. Create it and make it writable.']);
    exit;
}
$tmpDir = $baseTmpDir . '/shp_upload_' . uniqid();
if (!mkdir($tmpDir, 0775, true)) {
    echo json_encode(['error' => 'Failed to create temp directory: ' . $tmpDir]);
    exit;
}

// Extract ZIP
$zip = new ZipArchive();
if ($zip->open($zipTmp) !== true) {
    echo json_encode(['error' => 'Could not open ZIP file.']);
    exit;
}
$zip->extractTo($tmpDir);
$zip->close();

// Find .shp
$shpFile = null;
$dirIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir));
foreach ($dirIterator as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'shp') {
        $shpFile = $file->getPathname();
        break;
    }
}

if (!$shpFile) {
    echo json_encode([
        'error' => 'No .shp file found in ZIP. ZIP must contain .shp, .shx, .dbf, .prj, .cpg.',
    ]);
    exit;
}

// Read shapefile via php-shapefile
try {
    // NOTE: DBF_MAX_FIELD_COUNT already increased/removed in Shapefile.php
    $reader = new ShapefileReader($shpFile);
} catch (ShapefileException $e) {
    echo json_encode(['error' => 'Error opening shapefile: ' . $e->getMessage()]);
    exit;
}

// Prepare output file and stream JSON
$targetDir = __DIR__ . "/../data/precincts/{$stateCode}";
if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true)) {
    echo json_encode(['error' => 'Failed to create state precinct directory.']);
    exit;
}

$outPath = $targetDir . '/precincts.geojson';
$outFp   = fopen($outPath, 'wb');
if ($outFp === false) {
    echo json_encode(['error' => 'Failed to open precincts.geojson for writing.']);
    exit;
}

// Write start of FeatureCollection
fwrite($outFp, '{"type":"FeatureCollection","features":[');

// Stream features
$first = true;
$featureCount = 0;

try {
    $index = 0;
    while ($Geometry = $reader->fetchRecord()) {
        if ($Geometry->isDeleted()) {
            $index++;
            continue;
        }

        // Get GeoJSON string and decode to array
        $geojsonStr = $Geometry->getGeoJSON(false, false);
        $geometry   = json_decode($geojsonStr, true);

        if (!is_array($geometry) || !isset($geometry['type'], $geometry['coordinates'])) {
            $index++;
            continue;
        }

        // DBF attributes
        $props = $Geometry->getDataArray();
        $props = normalize_precinct_properties($props, $index);

        $feature = [
            'type'       => 'Feature',
            'properties' => $props,
            'geometry'   => $geometry,
        ];

        $featureJson = json_encode($feature, JSON_UNESCAPED_UNICODE);
        if ($featureJson === false) {
            $index++;
            continue;
        }

        if (!$first) {
            fwrite($outFp, ',');
        }
        $first = false;

        fwrite($outFp, $featureJson);
        $featureCount++;
        $index++;
    }
} catch (ShapefileException $e) {
    fclose($outFp);
    echo json_encode(['error' => 'Error reading shapefile records: ' . $e->getMessage()]);
    exit;
}

// Close FeatureCollection
fwrite($outFp, ']}');
fclose($outFp);

// IMPORTANT: We DO NOT delete $tmpDir here to avoid any rmdir-related errors

echo json_encode([
    'ok'           => true,
    'stateCode'    => $stateCode,
    'featureCount' => $featureCount,
]);

/**
 * Normalize DBF fields to app fields: id, population, dem, rep.
 * Your DBF already has population, dem, rep fields; we preserve them
 * and only fall back to alternates if missing.
 */
function normalize_precinct_properties(array $props, int $index): array
{
    // Build uppercase map for easier matching of alternate names
    $upper = [];
    foreach ($props as $k => $v) {
        $upper[strtoupper((string)$k)] = $v;
    }

    // --- ID ---

    if (!isset($props['id'])) {
        // Try common ID fields; if none, fall back to an index-based id
        $precId =
            $upper['ID'] ??
            $upper['PRECINCT'] ??
            $upper['PREC_ID'] ??
            $upper['GEOID'] ??
            $upper['GEOID10'] ??
            $upper['GEOID20'] ??
            null;

        if ($precId === null || $precId === '') {
            $precId = 'p_' . $index;
        }

        $props['id'] = $precId;
    }

    // --- Population ---

    // If DBF already has a "population" field, keep it
    if (!isset($props['population'])) {
        $pop =
            $upper['POPULATION'] ??  // alternate spelling
            $upper['POP']        ??
            $upper['TOTPOP']     ??
            $upper['POP_TOT']    ??
            0;

        $props['population'] = (int)$pop;
    } else {
        // Ensure it's an integer
        $props['population'] = (int)$props['population'];
    }

    // --- Dem votes ---

    // If DBF already has "dem", keep it
    if (!isset($props['dem'])) {
        $dem =
            $upper['DEM']       ??
            $upper['D_VOTES']   ??
            $upper['DEM_20']    ??
            $upper['PRESDEM20'] ??
            0;

        $props['dem'] = (int)$dem;
    } else {
        $props['dem'] = (int)$props['dem'];
    }

    // --- Rep votes ---

    // If DBF already has "rep", keep it
    if (!isset($props['rep'])) {
        $rep =
            $upper['REP']       ??
            $upper['R_VOTES']   ??
            $upper['REP_20']    ??
            $upper['PRESREP20'] ??
            0;

        $props['rep'] = (int)$rep;
    } else {
        $props['rep'] = (int)$props['rep'];
    }

    return $props;
}