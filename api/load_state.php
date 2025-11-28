<?php
header('Content-Type: application/json');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Diagnostic info collection
$diagnostics = [
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => PHP_VERSION,
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
];

// Convert any PHP error into a JSON response instead of HTML
set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$diagnostics) {
    http_response_code(500);
    echo json_encode([
        'error'       => 'PHP error',
        'errno'       => $errno,
        'message'     => $errstr,
        'file'        => $errfile,
        'line'        => $errline,
        'diagnostics' => $diagnostics,
    ], JSON_PRETTY_PRINT);
    exit;
});

// Convert uncaught exceptions into JSON
set_exception_handler(function ($e) use (&$diagnostics) {
    http_response_code(500);
    echo json_encode([
        'error'       => 'Uncaught exception',
        'message'     => $e->getMessage(),
        'file'        => $e->getFile(),
        'line'        => $e->getLine(),
        'trace'       => $e->getTraceAsString(),
        'diagnostics' => $diagnostics,
    ], JSON_PRETTY_PRINT);
    exit;
});

// Step 1: Resolve base directory
$diagnostics['step'] = 'Resolving base directory';
$baseDir = realpath(__DIR__ . '/..');
if ($baseDir === false) {
    echo json_encode([
        'error'       => 'Cannot resolve base directory.',
        'attempted'   => __DIR__ . '/..',
        '__DIR__'     => __DIR__,
        'diagnostics' => $diagnostics,
    ], JSON_PRETTY_PRINT);
    exit;
}
$diagnostics['baseDir'] = $baseDir;

// Step 2: Check data directory
$dataDir = $baseDir . '/data';
$diagnostics['dataDir'] = $dataDir;
$diagnostics['dataDir_exists'] = is_dir($dataDir);
$diagnostics['dataDir_readable'] = is_readable($dataDir);

if (!is_dir($dataDir)) {
    echo json_encode([
        'error'       => 'Data directory does not exist.',
        'path'        => $dataDir,
        'suggestion'  => 'Run setup.php first to create required directories.',
        'diagnostics' => $diagnostics,
    ], JSON_PRETTY_PRINT);
    exit;
}

// Step 3: Validate state parameter
$diagnostics['step'] = 'Validating state parameter';
$stateParam = $_GET['state'] ?? '';
$diagnostics['stateParam_raw'] = $stateParam;

$stateParam = trim($stateParam);
$diagnostics['stateParam_trimmed'] = $stateParam;

if ($stateParam === '') {
    echo json_encode([
        'error'       => 'Missing state parameter.',
        'hint'        => 'Pass state code as ?state=NC or ?state=CA',
        'GET_params'  => $_GET,
        'diagnostics' => $diagnostics,
    ], JSON_PRETTY_PRINT);
    exit;
}

// Folder name under data/precincts – e.g. NC
$stateCode = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $stateParam));
$diagnostics['stateCode'] = $stateCode;

if ($stateCode === '') {
    echo json_encode([
        'error'       => 'Invalid state code after sanitization.',
        'original'    => $stateParam,
        'sanitized'   => $stateCode,
        'diagnostics' => $diagnostics,
    ], JSON_PRETTY_PRINT);
    exit;
}

// Step 4: Check precincts directory structure
$diagnostics['step'] = 'Checking precincts directory';
$precinctsBaseDir = $dataDir . '/precincts';
$diagnostics['precinctsBaseDir'] = $precinctsBaseDir;
$diagnostics['precinctsBaseDir_exists'] = is_dir($precinctsBaseDir);

if (!is_dir($precinctsBaseDir)) {
    echo json_encode([
        'error'       => 'Precincts base directory does not exist.',
        'path'        => $precinctsBaseDir,
        'suggestion'  => 'Run setup.php first or create data/precincts/ directory.',
        'diagnostics' => $diagnostics,
    ], JSON_PRETTY_PRINT);
    exit;
}

// List available state directories
$availableStates = [];
$dirHandle = opendir($precinctsBaseDir);
if ($dirHandle) {
    while (($entry = readdir($dirHandle)) !== false) {
        if ($entry !== '.' && $entry !== '..' && is_dir($precinctsBaseDir . '/' . $entry)) {
            $hasGeoJson = file_exists($precinctsBaseDir . '/' . $entry . '/precincts.geojson');
            $availableStates[] = [
                'code' => $entry,
                'has_geojson' => $hasGeoJson,
            ];
        }
    }
    closedir($dirHandle);
}
$diagnostics['availableStates'] = $availableStates;

// Step 5: Check state-specific directory
$stateDir = $precinctsBaseDir . '/' . $stateCode;
$diagnostics['stateDir'] = $stateDir;
$diagnostics['stateDir_exists'] = is_dir($stateDir);

if (!is_dir($stateDir)) {
    echo json_encode([
        'error'          => "State directory does not exist for '$stateCode'.",
        'path'           => $stateDir,
        'availableStates'=> $availableStates,
        'suggestion'     => 'Upload a shapefile or GeoJSON for this state first.',
        'diagnostics'    => $diagnostics,
    ], JSON_PRETTY_PRINT);
    exit;
}

// Step 6: Check precincts.geojson file
$diagnostics['step'] = 'Checking precincts.geojson';
$precinctsPath = $stateDir . '/precincts.geojson';
$diagnostics['precinctsPath'] = $precinctsPath;
$diagnostics['precinctsPath_exists'] = file_exists($precinctsPath);

if (!file_exists($precinctsPath)) {
    // List what files ARE in the state directory
    $filesInStateDir = [];
    $dh = opendir($stateDir);
    if ($dh) {
        while (($f = readdir($dh)) !== false) {
            if ($f !== '.' && $f !== '..') {
                $filesInStateDir[] = $f;
            }
        }
        closedir($dh);
    }
    
    echo json_encode([
        'error'           => 'No precinct data for this state yet.',
        'path'            => $precinctsPath,
        'stateDir'        => $stateDir,
        'filesInStateDir' => $filesInStateDir,
        'suggestion'      => 'Upload a shapefile ZIP or import GeoJSON first.',
        'diagnostics'     => $diagnostics,
    ], JSON_PRETTY_PRINT);
    exit;
}

// Step 7: Check file permissions
$diagnostics['precinctsPath_readable'] = is_readable($precinctsPath);
$diagnostics['precinctsPath_size'] = filesize($precinctsPath);
$diagnostics['precinctsPath_mtime'] = date('Y-m-d H:i:s', filemtime($precinctsPath));

if (!is_readable($precinctsPath)) {
    echo json_encode([
        'error'       => 'precincts.geojson exists but is not readable.',
        'path'        => $precinctsPath,
        'permissions' => substr(sprintf('%o', fileperms($precinctsPath)), -4),
        'owner'       => function_exists('posix_getpwuid') ? posix_getpwuid(fileowner($precinctsPath))['name'] : fileowner($precinctsPath),
        'suggestion'  => 'Check file permissions. The web server needs read access.',
        'diagnostics' => $diagnostics,
    ], JSON_PRETTY_PRINT);
    exit;
}

// Check file size
$fileSize = filesize($precinctsPath);
$diagnostics['fileSize_bytes'] = $fileSize;
$diagnostics['fileSize_human'] = $fileSize > 1048576 
    ? round($fileSize / 1048576, 2) . ' MB' 
    : round($fileSize / 1024, 2) . ' KB';

if ($fileSize === 0) {
    echo json_encode([
        'error'       => 'precincts.geojson is empty (0 bytes).',
        'path'        => $precinctsPath,
        'suggestion'  => 'The file upload may have failed. Try uploading again.',
        'diagnostics' => $diagnostics,
    ], JSON_PRETTY_PRINT);
    exit;
}

// Step 8: Read file contents
$diagnostics['step'] = 'Reading file contents';
$memoryBefore = memory_get_usage(true);
$diagnostics['memory_before_read'] = round($memoryBefore / 1048576, 2) . ' MB';

$raw = file_get_contents($precinctsPath);
if ($raw === false) {
    echo json_encode([
        'error'       => 'Failed to read precincts.geojson.',
        'path'        => $precinctsPath,
        'file_size'   => $fileSize,
        'suggestion'  => 'File may be corrupted or there may be a permissions issue.',
        'diagnostics' => $diagnostics,
    ], JSON_PRETTY_PRINT);
    exit;
}

$diagnostics['raw_length'] = strlen($raw);
$diagnostics['memory_after_read'] = round(memory_get_usage(true) / 1048576, 2) . ' MB';

// Step 9: Parse JSON
$diagnostics['step'] = 'Parsing JSON';

// Check for BOM or other issues at start of file
$firstBytes = substr($raw, 0, 10);
$diagnostics['first_bytes_hex'] = bin2hex($firstBytes);
$diagnostics['first_chars'] = substr($raw, 0, 100);

// Remove BOM if present
if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
    $raw = substr($raw, 3);
    $diagnostics['bom_removed'] = true;
}

$geo = json_decode($raw, true);
$jsonError = json_last_error();
$jsonErrorMsg = json_last_error_msg();
$diagnostics['json_error_code'] = $jsonError;
$diagnostics['json_error_msg'] = $jsonErrorMsg;

if ($jsonError !== JSON_ERROR_NONE) {
    // Try to find where the error is
    $errorContext = '';
    if ($jsonError === JSON_ERROR_SYNTAX) {
        // Find approximate location of syntax error
        for ($i = 0; $i < strlen($raw); $i += 1000) {
            $chunk = substr($raw, 0, $i + 1000);
            $test = json_decode($chunk);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errorContext = 'Error appears near position ' . $i . ': "' . substr($raw, max(0, $i - 50), 100) . '"';
                break;
            }
        }
    }
    
    echo json_encode([
        'error'        => 'precincts.geojson is not valid JSON.',
        'json_error'   => $jsonErrorMsg,
        'error_code'   => $jsonError,
        'error_context'=> $errorContext,
        'file_start'   => substr($raw, 0, 500),
        'file_end'     => substr($raw, -500),
        'suggestion'   => 'The JSON file may be corrupted or incomplete. Try re-uploading.',
        'diagnostics'  => $diagnostics,
    ], JSON_PRETTY_PRINT);
    exit;
}

// Free raw string memory
unset($raw);
$diagnostics['memory_after_parse'] = round(memory_get_usage(true) / 1048576, 2) . ' MB';

// Step 10: Validate GeoJSON structure
$diagnostics['step'] = 'Validating GeoJSON structure';
$diagnostics['geo_type'] = gettype($geo);
$diagnostics['geo_keys'] = is_array($geo) ? array_keys($geo) : 'N/A';

if (!is_array($geo)) {
    echo json_encode([
        'error'       => 'Parsed JSON is not an array/object.',
        'type'        => gettype($geo),
        'diagnostics' => $diagnostics,
    ], JSON_PRETTY_PRINT);
    exit;
}

$geoType = $geo['type'] ?? null;
$diagnostics['geojson_type'] = $geoType;

if ($geoType !== 'FeatureCollection') {
    echo json_encode([
        'error'       => 'GeoJSON must be a FeatureCollection.',
        'found_type'  => $geoType,
        'top_keys'    => array_keys($geo),
        'suggestion'  => 'Make sure the uploaded file is a valid GeoJSON FeatureCollection.',
        'diagnostics' => $diagnostics,
    ], JSON_PRETTY_PRINT);
    exit;
}

// Step 11: Validate features array
$diagnostics['step'] = 'Validating features';
$features = $geo['features'] ?? null;
$diagnostics['has_features'] = isset($geo['features']);
$diagnostics['features_type'] = gettype($features);

if (!is_array($features)) {
    echo json_encode([
        'error'       => 'FeatureCollection has no valid features array.',
        'features_type' => gettype($features),
        'diagnostics' => $diagnostics,
    ], JSON_PRETTY_PRINT);
    exit;
}

$featureCount = count($features);
$diagnostics['feature_count'] = $featureCount;

if ($featureCount === 0) {
    echo json_encode([
        'error'       => 'FeatureCollection has zero features.',
        'suggestion'  => 'The GeoJSON file is valid but contains no precincts.',
        'diagnostics' => $diagnostics,
    ], JSON_PRETTY_PRINT);
    exit;
}

// Sample first few features for debugging
$sampleFeatures = [];
for ($i = 0; $i < min(3, $featureCount); $i++) {
    $f = $features[$i];
    $sampleFeatures[] = [
        'index'      => $i,
        'type'       => $f['type'] ?? 'missing',
        'has_geometry' => isset($f['geometry']),
        'geometry_type' => $f['geometry']['type'] ?? 'missing',
        'properties' => $f['properties'] ?? [],
    ];
}
$diagnostics['sample_features'] = $sampleFeatures;

// Step 12: Load state metadata
$diagnostics['step'] = 'Loading state metadata';
$stateMeta = [
    'code' => $stateCode,
    'abbr' => $stateCode,
    'name' => $stateCode,
];

$statesFile = $dataDir . '/states.json';
$diagnostics['statesFile'] = $statesFile;
$diagnostics['statesFile_exists'] = file_exists($statesFile);

if (file_exists($statesFile)) {
    $statesRaw = file_get_contents($statesFile);
    $metaJson = json_decode($statesRaw, true);
    $diagnostics['states_json_valid'] = is_array($metaJson);
    
    if (is_array($metaJson)) {
        $diagnostics['states_count'] = count($metaJson);
        foreach ($metaJson as $st) {
            $abbr = strtoupper($st['abbr'] ?? $st['code'] ?? '');
            if ($abbr === $stateCode) {
                $stateMeta = [
                    'code' => $abbr,
                    'abbr' => $st['abbr'] ?? $st['code'] ?? $abbr,
                    'name' => $st['name'] ?? $abbr,
                ];
                $diagnostics['state_meta_found'] = true;
                break;
            }
        }
    }
}

// Default number of districts (could be made state-specific)
$defaultNumDistricts = $stateMeta['defaultNumDistricts'] ?? 10;

// Final success response
$diagnostics['step'] = 'Complete';
$diagnostics['success'] = true;
$diagnostics['memory_final'] = round(memory_get_usage(true) / 1048576, 2) . ' MB';

echo json_encode([
    'state'               => $stateMeta,
    'precincts'           => $geo,
    'defaultNumDistricts' => $defaultNumDistricts,
    'debug' => [
        'featureCount'    => $featureCount,
        'fileSize'        => $diagnostics['fileSize_human'],
        'loadTime'        => $diagnostics['timestamp'],
    ],
]);