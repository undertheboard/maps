<?php
/**
 * RDH Direct API Import
 * 
 * This endpoint connects directly to the Redistricting Data Hub API
 * to fetch and import precinct data for a selected state.
 * 
 * Endpoints:
 *   POST /api/rdh_import.php
 * 
 * Actions:
 *   - action=list_datasets: List available datasets for a state
 *   - action=import: Import selected datasets
 * 
 * Required parameters:
 *   - rdh_username: RDH account username/email
 *   - rdh_password: RDH account password
 *   - state: State code (e.g., "NC" or "North Carolina")
 * 
 * Optional parameters:
 *   - keywords: Filter datasets by keywords (e.g., "precinct, 2020")
 *   - dataset_ids: Comma-separated list of dataset IDs to import (for action=import)
 */

header('Content-Type: application/json');

// Increase memory and execution time for large downloads
ini_set('memory_limit', '1024M');
set_time_limit(300);

// Error handling
ini_set('display_errors', 0);
error_reporting(E_ALL);

set_exception_handler(function ($e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'ok'      => false,
        'error'   => 'Uncaught exception',
        'message' => $e->getMessage(),
    ]);
    exit;
});

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'ok'      => false,
        'error'   => 'PHP error',
        'message' => $errstr,
    ]);
    exit;
});

// RDH API Configuration
define('RDH_API_LIST_URL', 'https://redistrictingdatahub.org/wp-json/download/list');
define('RDH_API_FILE_URL', 'https://redistrictingdatahub.org/wp-json/download/file/');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method. Use POST.']);
    exit;
}

// Get action
$action = trim($_POST['action'] ?? 'list_datasets');

// Get and validate credentials
$rdhUsername = trim($_POST['rdh_username'] ?? '');
$rdhPassword = trim($_POST['rdh_password'] ?? '');

if ($rdhUsername === '' || $rdhPassword === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing RDH credentials (rdh_username and rdh_password required).']);
    exit;
}

// Get and validate state
$state = trim($_POST['state'] ?? '');
if ($state === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing state parameter.']);
    exit;
}

// Route to appropriate handler
switch ($action) {
    case 'list_datasets':
        handleListDatasets($rdhUsername, $rdhPassword, $state);
        break;
    case 'import':
        handleImport($rdhUsername, $rdhPassword, $state);
        break;
    default:
        echo json_encode(['ok' => false, 'error' => 'Invalid action. Use list_datasets or import.']);
        exit;
}

/**
 * List available datasets from RDH for a state
 */
function handleListDatasets(string $username, string $password, string $state): void
{
    $keywords = trim($_POST['keywords'] ?? '');
    
    // Build request URL
    $params = [
        'username' => $username,
        'password' => $password,
        'format'   => 'json',
        'states'   => $state,
    ];
    
    if ($keywords !== '') {
        $params['keywords'] = $keywords;
    }
    
    $url = RDH_API_LIST_URL . '?' . http_build_query($params);
    
    // Make request to RDH API
    $context = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 60,
            'header'  => "Accept: application/json\r\n",
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        $error = error_get_last();
        echo json_encode([
            'ok'    => false,
            'error' => 'Failed to connect to RDH API. Please check your credentials and try again.',
            'detail' => $error['message'] ?? 'Unknown error',
        ]);
        exit;
    }
    
    // Parse response
    $data = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        // RDH might return CSV or error message
        if (strpos($response, 'Filter by state found 0 states') !== false) {
            echo json_encode([
                'ok'    => false,
                'error' => 'Invalid state specified or no datasets found for this state.',
            ]);
            exit;
        }
        
        if (strpos($response, 'incorrect') !== false || strpos($response, 'password') !== false) {
            echo json_encode([
                'ok'    => false,
                'error' => 'Invalid RDH credentials. Please check your username and password.',
            ]);
            exit;
        }
        
        echo json_encode([
            'ok'    => false,
            'error' => 'Unexpected response from RDH API.',
            'raw'   => substr($response, 0, 500),
        ]);
        exit;
    }
    
    // Filter and categorize datasets
    $datasets = [];
    $categories = [
        'precinct_boundaries' => [],
        'election_results'    => [],
        'demographics'        => [],
        'other'               => [],
    ];
    
    if (is_array($data)) {
        foreach ($data as $item) {
            $title = $item['Title'] ?? $item['title'] ?? '';
            $format = $item['Format'] ?? $item['format'] ?? '';
            $url = $item['URL'] ?? $item['url'] ?? '';
            $datasetId = '';
            
            // Extract dataset ID from URL
            if (preg_match('/datasetid=(\d+)/', $url, $matches)) {
                $datasetId = $matches[1];
            }
            
            $dataset = [
                'id'     => $datasetId,
                'title'  => $title,
                'format' => strtoupper($format),
                'url'    => $url,
            ];
            
            $datasets[] = $dataset;
            
            // Categorize
            $titleLower = strtolower($title);
            if (strpos($titleLower, 'precinct') !== false || strpos($titleLower, 'vtd') !== false) {
                if (strpos($titleLower, 'election') !== false || strpos($titleLower, 'results') !== false) {
                    $categories['election_results'][] = $dataset;
                } else {
                    $categories['precinct_boundaries'][] = $dataset;
                }
            } elseif (strpos($titleLower, 'election') !== false || strpos($titleLower, 'results') !== false) {
                $categories['election_results'][] = $dataset;
            } elseif (strpos($titleLower, 'acs') !== false || strpos($titleLower, 'cvap') !== false || 
                      strpos($titleLower, 'population') !== false || strpos($titleLower, 'demographic') !== false) {
                $categories['demographics'][] = $dataset;
            } else {
                $categories['other'][] = $dataset;
            }
        }
    }
    
    // Find recommended datasets for redistricting
    $recommended = findRecommendedDatasets($datasets, $state);
    
    echo json_encode([
        'ok'          => true,
        'state'       => $state,
        'totalCount'  => count($datasets),
        'datasets'    => $datasets,
        'categories'  => $categories,
        'recommended' => $recommended,
    ]);
}

/**
 * Find recommended datasets for redistricting
 */
function findRecommendedDatasets(array $datasets, string $state): array
{
    $recommended = [];
    
    // Look for official redistricting dataset or 2020 precinct boundaries with election data
    foreach ($datasets as $dataset) {
        $titleLower = strtolower($dataset['title']);
        $format = strtoupper($dataset['format']);
        
        // Prefer shapefiles for geometry
        $isShapefile = $format === 'SHP' || $format === 'SHAPEFILE';
        $isGeoJSON = strpos($titleLower, 'geojson') !== false;
        
        // Look for precinct-level data with election results
        if ((strpos($titleLower, 'precinct') !== false || strpos($titleLower, 'vtd') !== false) &&
            (strpos($titleLower, '2020') !== false || strpos($titleLower, '2022') !== false || strpos($titleLower, '2024') !== false)) {
            
            if (strpos($titleLower, 'election') !== false || strpos($titleLower, 'results') !== false ||
                strpos($titleLower, 'official') !== false) {
                $recommended[] = [
                    'dataset'  => $dataset,
                    'priority' => 1,
                    'reason'   => 'Precinct-level election results',
                ];
            } elseif ($isShapefile || $isGeoJSON) {
                $recommended[] = [
                    'dataset'  => $dataset,
                    'priority' => 2,
                    'reason'   => 'Precinct boundaries',
                ];
            }
        }
        
        // Look for disaggregated data (has election + demographics)
        if (strpos($titleLower, 'disag') !== false && ($isShapefile || $isGeoJSON)) {
            $recommended[] = [
                'dataset'  => $dataset,
                'priority' => 0,
                'reason'   => 'Disaggregated precinct data (recommended)',
            ];
        }
    }
    
    // Sort by priority
    usort($recommended, function($a, $b) {
        return $a['priority'] - $b['priority'];
    });
    
    // Return top 5 recommendations
    return array_slice($recommended, 0, 5);
}

/**
 * Import selected datasets from RDH
 */
function handleImport(string $username, string $password, string $state): void
{
    $datasetIds = trim($_POST['dataset_ids'] ?? '');
    
    if ($datasetIds === '') {
        echo json_encode(['ok' => false, 'error' => 'Missing dataset_ids parameter.']);
        exit;
    }
    
    $ids = array_filter(array_map('trim', explode(',', $datasetIds)));
    
    if (empty($ids)) {
        echo json_encode(['ok' => false, 'error' => 'No valid dataset IDs provided.']);
        exit;
    }
    
    // Get the list of datasets to find URLs
    $params = [
        'username' => $username,
        'password' => $password,
        'format'   => 'json',
        'states'   => $state,
    ];
    
    $url = RDH_API_LIST_URL . '?' . http_build_query($params);
    
    $context = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 60,
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        echo json_encode(['ok' => false, 'error' => 'Failed to connect to RDH API.']);
        exit;
    }
    
    $data = json_decode($response, true);
    
    if (!is_array($data)) {
        echo json_encode(['ok' => false, 'error' => 'Failed to parse dataset list from RDH.']);
        exit;
    }
    
    // Find datasets matching the requested IDs
    $datasetsToDownload = [];
    foreach ($data as $item) {
        $itemUrl = $item['URL'] ?? $item['url'] ?? '';
        
        if (preg_match('/datasetid=(\d+)/', $itemUrl, $matches)) {
            $datasetId = $matches[1];
            
            if (in_array($datasetId, $ids)) {
                $datasetsToDownload[] = [
                    'id'     => $datasetId,
                    'title'  => $item['Title'] ?? $item['title'] ?? '',
                    'format' => strtoupper($item['Format'] ?? $item['format'] ?? ''),
                    'url'    => $itemUrl,
                ];
            }
        }
    }
    
    if (empty($datasetsToDownload)) {
        echo json_encode(['ok' => false, 'error' => 'No matching datasets found for the provided IDs.']);
        exit;
    }
    
    // Get state code for directory
    $stateCode = strtoupper(getStateCode($state));
    
    // Create target directory
    $targetDir = __DIR__ . "/../data/precincts/{$stateCode}";
    if (!is_dir($targetDir)) {
        if (!mkdir($targetDir, 0775, true)) {
            echo json_encode(['ok' => false, 'error' => 'Failed to create state precinct directory.']);
            exit;
        }
    }
    
    // Create temp directory for downloads
    $tempDir = __DIR__ . "/../data/tmp_shapefiles/rdh_import_" . uniqid();
    if (!mkdir($tempDir, 0775, true)) {
        echo json_encode(['ok' => false, 'error' => 'Failed to create temp directory.']);
        exit;
    }
    
    $results = [];
    $geojsonFiles = [];
    
    // Download and process each dataset
    foreach ($datasetsToDownload as $dataset) {
        $result = downloadAndProcessDataset($dataset, $username, $password, $tempDir, $targetDir);
        $results[] = $result;
        
        if ($result['ok'] && isset($result['geojson_path'])) {
            $geojsonFiles[] = $result['geojson_path'];
        }
    }
    
    // Merge all GeoJSON files if we have multiple
    $finalResult = null;
    if (count($geojsonFiles) === 1) {
        // Single file, just copy it
        $finalResult = $geojsonFiles[0];
    } elseif (count($geojsonFiles) > 1) {
        // Merge multiple GeoJSON files
        $finalResult = mergeGeoJSONFiles($geojsonFiles, $targetDir);
    }
    
    $successCount = count(array_filter($results, fn($r) => $r['ok']));
    $featureCount = 0;
    
    if ($finalResult && file_exists($finalResult)) {
        $content = file_get_contents($finalResult);
        $geojson = json_decode($content, true);
        if (isset($geojson['features'])) {
            $featureCount = count($geojson['features']);
        }
    }
    
    echo json_encode([
        'ok'           => $successCount > 0,
        'stateCode'    => $stateCode,
        'imported'     => $successCount,
        'total'        => count($datasetsToDownload),
        'featureCount' => $featureCount,
        'results'      => $results,
    ]);
}

/**
 * Download and process a single dataset
 */
function downloadAndProcessDataset(array $dataset, string $username, string $password, string $tempDir, string $targetDir): array
{
    $url = $dataset['url'];
    $format = $dataset['format'];
    $title = $dataset['title'];
    
    // Extract file URL from the full URL
    $fileUrl = '';
    if (preg_match('/download\/file\/([^?]+)/', $url, $matches)) {
        $filename = $matches[1];
        $fileUrl = RDH_API_FILE_URL . urlencode($filename) . '?' . http_build_query([
            'username'  => $username,
            'password'  => $password,
            'datasetid' => $dataset['id'],
        ]);
    } else {
        // Try to use the URL directly with credentials
        $separator = strpos($url, '?') !== false ? '&' : '?';
        $fileUrl = $url . $separator . http_build_query([
            'username' => $username,
            'password' => $password,
        ]);
    }
    
    // Download the file
    $context = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'timeout'         => 120,
            'follow_location' => true,
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);
    
    $fileContent = @file_get_contents($fileUrl, false, $context);
    
    if ($fileContent === false) {
        return [
            'ok'      => false,
            'dataset' => $title,
            'error'   => 'Failed to download file',
        ];
    }
    
    // Save to temp file
    $tempFile = $tempDir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $title) . '.zip';
    file_put_contents($tempFile, $fileContent);
    
    // Process based on format
    if ($format === 'SHP' || $format === 'SHAPEFILE') {
        return processShapefile($tempFile, $tempDir, $targetDir, $title);
    } elseif ($format === 'CSV') {
        return processCSV($tempFile, $tempDir, $targetDir, $title);
    } else {
        // Try to detect format from content
        $zip = new ZipArchive();
        if ($zip->open($tempFile) === true) {
            $hasShp = false;
            $hasGeoJSON = false;
            
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (preg_match('/\.shp$/i', $name)) {
                    $hasShp = true;
                }
                if (preg_match('/\.geojson$/i', $name) || preg_match('/\.json$/i', $name)) {
                    $hasGeoJSON = true;
                }
            }
            $zip->close();
            
            if ($hasShp) {
                return processShapefile($tempFile, $tempDir, $targetDir, $title);
            } elseif ($hasGeoJSON) {
                return processGeoJSONZip($tempFile, $tempDir, $targetDir, $title);
            }
        }
        
        return [
            'ok'      => false,
            'dataset' => $title,
            'error'   => 'Unsupported file format: ' . $format,
        ];
    }
}

/**
 * Process a shapefile ZIP
 */
function processShapefile(string $zipFile, string $tempDir, string $targetDir, string $title): array
{
    // Load php-shapefile
    require_once __DIR__ . '/../lib/php-shapefile/src/Shapefile/ShapefileAutoloader.php';
    \Shapefile\ShapefileAutoloader::register();
    
    // Extract ZIP
    $extractDir = $tempDir . '/extract_' . uniqid();
    mkdir($extractDir, 0775, true);
    
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        return ['ok' => false, 'dataset' => $title, 'error' => 'Could not open ZIP file'];
    }
    $zip->extractTo($extractDir);
    $zip->close();
    
    // Find .shp file
    $shpFile = null;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extractDir));
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'shp') {
            $shpFile = $file->getPathname();
            break;
        }
    }
    
    if (!$shpFile) {
        return ['ok' => false, 'dataset' => $title, 'error' => 'No .shp file found in ZIP'];
    }
    
    try {
        $reader = new \Shapefile\ShapefileReader($shpFile);
    } catch (\Shapefile\ShapefileException $e) {
        return ['ok' => false, 'dataset' => $title, 'error' => 'Error reading shapefile: ' . $e->getMessage()];
    }
    
    // Convert to GeoJSON
    $features = [];
    $index = 0;
    
    while ($geometry = $reader->fetchRecord()) {
        if ($geometry->isDeleted()) {
            $index++;
            continue;
        }
        
        $geojsonStr = $geometry->getGeoJSON(false, false);
        $geom = json_decode($geojsonStr, true);
        
        if (!is_array($geom) || !isset($geom['type'], $geom['coordinates'])) {
            $index++;
            continue;
        }
        
        $props = $geometry->getDataArray();
        $props = normalizeRDHProperties($props, $index);
        
        $features[] = [
            'type'       => 'Feature',
            'properties' => $props,
            'geometry'   => $geom,
        ];
        
        $index++;
    }
    
    if (empty($features)) {
        return ['ok' => false, 'dataset' => $title, 'error' => 'No features found in shapefile'];
    }
    
    // Save GeoJSON
    $outputPath = $targetDir . '/precincts.geojson';
    $geojson = [
        'type'     => 'FeatureCollection',
        'features' => $features,
    ];
    
    file_put_contents($outputPath, json_encode($geojson, JSON_UNESCAPED_UNICODE));
    
    return [
        'ok'           => true,
        'dataset'      => $title,
        'featureCount' => count($features),
        'geojson_path' => $outputPath,
    ];
}

/**
 * Process a GeoJSON ZIP
 */
function processGeoJSONZip(string $zipFile, string $tempDir, string $targetDir, string $title): array
{
    $extractDir = $tempDir . '/extract_' . uniqid();
    mkdir($extractDir, 0775, true);
    
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        return ['ok' => false, 'dataset' => $title, 'error' => 'Could not open ZIP file'];
    }
    $zip->extractTo($extractDir);
    $zip->close();
    
    // Find GeoJSON file
    $geojsonFile = null;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extractDir));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $ext = strtolower($file->getExtension());
            if ($ext === 'geojson' || $ext === 'json') {
                $geojsonFile = $file->getPathname();
                break;
            }
        }
    }
    
    if (!$geojsonFile) {
        return ['ok' => false, 'dataset' => $title, 'error' => 'No GeoJSON file found in ZIP'];
    }
    
    $content = file_get_contents($geojsonFile);
    $geojson = json_decode($content, true);
    
    if (!is_array($geojson) || !isset($geojson['features'])) {
        return ['ok' => false, 'dataset' => $title, 'error' => 'Invalid GeoJSON file'];
    }
    
    // Normalize properties
    foreach ($geojson['features'] as $i => &$feature) {
        if (isset($feature['properties'])) {
            $feature['properties'] = normalizeRDHProperties($feature['properties'], $i);
        }
    }
    unset($feature);
    
    // Save to target
    $outputPath = $targetDir . '/precincts.geojson';
    file_put_contents($outputPath, json_encode($geojson, JSON_UNESCAPED_UNICODE));
    
    return [
        'ok'           => true,
        'dataset'      => $title,
        'featureCount' => count($geojson['features']),
        'geojson_path' => $outputPath,
    ];
}

/**
 * Process a CSV file (merge with existing GeoJSON)
 */
function processCSV(string $zipFile, string $tempDir, string $targetDir, string $title): array
{
    // For CSV, we need existing GeoJSON to merge with
    $existingPath = $targetDir . '/precincts.geojson';
    
    if (!file_exists($existingPath)) {
        return [
            'ok'      => false,
            'dataset' => $title,
            'error'   => 'CSV requires existing GeoJSON. Please import a shapefile first.',
        ];
    }
    
    // Extract ZIP
    $extractDir = $tempDir . '/extract_' . uniqid();
    mkdir($extractDir, 0775, true);
    
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        return ['ok' => false, 'dataset' => $title, 'error' => 'Could not open ZIP file'];
    }
    $zip->extractTo($extractDir);
    $zip->close();
    
    // Find CSV file
    $csvFile = null;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extractDir));
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'csv') {
            $csvFile = $file->getPathname();
            break;
        }
    }
    
    if (!$csvFile) {
        return ['ok' => false, 'dataset' => $title, 'error' => 'No CSV file found in ZIP'];
    }
    
    // Load existing GeoJSON
    $geojson = json_decode(file_get_contents($existingPath), true);
    
    // Load CSV
    $handle = fopen($csvFile, 'r');
    $header = fgetcsv($handle);
    $header = array_map('trim', $header);
    
    // Build lookup from CSV
    $csvData = [];
    $keyColumn = findKeyColumn($header);
    $keyIndex = array_search($keyColumn, $header);
    
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) === count($header)) {
            $assoc = array_combine($header, $row);
            $key = trim($assoc[$keyColumn] ?? '');
            if ($key !== '') {
                $csvData[$key] = $assoc;
            }
        }
    }
    fclose($handle);
    
    // Merge into GeoJSON
    $matchCount = 0;
    foreach ($geojson['features'] as &$feature) {
        $props = $feature['properties'] ?? [];
        $id = $props['id'] ?? $props['UNIQUE_ID'] ?? $props['GEOID20'] ?? '';
        
        if (isset($csvData[$id])) {
            foreach ($csvData[$id] as $col => $val) {
                if ($col === $keyColumn) continue;
                $mappedKey = mapRDHField($col);
                if (is_numeric($val) && $val !== '') {
                    $val = $val + 0;
                }
                $props[$mappedKey] = $val;
            }
            $feature['properties'] = $props;
            $matchCount++;
        }
    }
    unset($feature);
    
    // Save updated GeoJSON
    file_put_contents($existingPath, json_encode($geojson, JSON_UNESCAPED_UNICODE));
    
    return [
        'ok'           => true,
        'dataset'      => $title,
        'matchCount'   => $matchCount,
        'geojson_path' => $existingPath,
    ];
}

/**
 * Find the key column in CSV header
 */
function findKeyColumn(array $header): string
{
    $candidates = ['UNIQUE_ID', 'GEOID20', 'GEOID', 'ID', 'PRECINCT', 'VTD'];
    
    foreach ($candidates as $candidate) {
        foreach ($header as $col) {
            if (strtoupper(trim($col)) === $candidate) {
                return $col;
            }
        }
    }
    
    return $header[0] ?? 'id';
}

/**
 * Merge multiple GeoJSON files
 */
function mergeGeoJSONFiles(array $files, string $targetDir): string
{
    $allFeatures = [];
    
    foreach ($files as $file) {
        if (file_exists($file)) {
            $content = file_get_contents($file);
            $geojson = json_decode($content, true);
            
            if (isset($geojson['features'])) {
                $allFeatures = array_merge($allFeatures, $geojson['features']);
            }
        }
    }
    
    $merged = [
        'type'     => 'FeatureCollection',
        'features' => $allFeatures,
    ];
    
    $outputPath = $targetDir . '/precincts.geojson';
    file_put_contents($outputPath, json_encode($merged, JSON_UNESCAPED_UNICODE));
    
    return $outputPath;
}

/**
 * Normalize RDH properties to standard format
 */
function normalizeRDHProperties(array $props, int $index): array
{
    $upper = [];
    foreach ($props as $k => $v) {
        $upper[strtoupper((string)$k)] = $v;
    }
    
    $normalized = [];
    
    // ID
    $id = $upper['UNIQUE_ID'] ?? $upper['GEOID20'] ?? $upper['GEOID'] ?? $upper['ID'] ?? 
          $upper['PRECINCT'] ?? $upper['VTD'] ?? ('p_' . $index);
    $normalized['id'] = (string)$id;
    
    // Population
    $pop = $upper['TOTPOP'] ?? $upper['POPULATION'] ?? $upper['POP'] ?? $upper['POP_TOT'] ?? 0;
    $normalized['population'] = (int)$pop;
    
    // VAP
    if (isset($upper['VAP'])) {
        $normalized['vap'] = (int)$upper['VAP'];
    }
    
    // Democratic votes
    $dem = findRDHDemVotes($upper);
    $normalized['dem'] = (int)$dem;
    
    // Republican votes
    $rep = findRDHRepVotes($upper);
    $normalized['rep'] = (int)$rep;
    
    // Copy other relevant fields
    $copyFields = ['COUNTY', 'NAME', 'PRECINCT_NAME', 'CVAP'];
    foreach ($copyFields as $field) {
        if (isset($upper[$field])) {
            $normalized[strtolower($field)] = $upper[$field];
        }
    }
    
    return $normalized;
}

/**
 * Find Democratic votes from RDH fields
 */
function findRDHDemVotes(array $upper): int
{
    $demFields = ['G20PREDBID', 'G24PREDHAR', 'G20PREDEM', 'PRES20D', 'DEM', 'D_VOTES'];
    
    foreach ($demFields as $field) {
        if (isset($upper[$field]) && is_numeric($upper[$field])) {
            return (int)$upper[$field];
        }
    }
    
    // Pattern match
    foreach ($upper as $k => $v) {
        if (preg_match('/^G\d{2}PRE[A-Z]*D/i', $k) && is_numeric($v)) {
            return (int)$v;
        }
    }
    
    return 0;
}

/**
 * Find Republican votes from RDH fields
 */
function findRDHRepVotes(array $upper): int
{
    $repFields = ['G20PRERTRU', 'G24PRERTRUMP', 'G20PREREP', 'PRES20R', 'REP', 'R_VOTES'];
    
    foreach ($repFields as $field) {
        if (isset($upper[$field]) && is_numeric($upper[$field])) {
            return (int)$upper[$field];
        }
    }
    
    // Pattern match
    foreach ($upper as $k => $v) {
        if (preg_match('/^G\d{2}PRE[A-Z]*R/i', $k) && is_numeric($v)) {
            return (int)$v;
        }
    }
    
    return 0;
}

/**
 * Map RDH field names to simplified names
 */
function mapRDHField(string $field): string
{
    $mappings = [
        'UNIQUE_ID'  => 'id',
        'GEOID20'    => 'geoid',
        'TOTPOP'     => 'population',
        'VAP'        => 'vap',
        'G20PREDBID' => 'dem',
        'G20PRERTRU' => 'rep',
    ];
    
    $upperField = strtoupper($field);
    return $mappings[$upperField] ?? strtolower($field);
}

/**
 * Get state code from state name
 */
function getStateCode(string $state): string
{
    $state = strtolower(trim($state));
    
    $codes = [
        'alabama' => 'AL', 'alaska' => 'AK', 'arizona' => 'AZ', 'arkansas' => 'AR',
        'california' => 'CA', 'colorado' => 'CO', 'connecticut' => 'CT', 'delaware' => 'DE',
        'florida' => 'FL', 'georgia' => 'GA', 'hawaii' => 'HI', 'idaho' => 'ID',
        'illinois' => 'IL', 'indiana' => 'IN', 'iowa' => 'IA', 'kansas' => 'KS',
        'kentucky' => 'KY', 'louisiana' => 'LA', 'maine' => 'ME', 'maryland' => 'MD',
        'massachusetts' => 'MA', 'michigan' => 'MI', 'minnesota' => 'MN', 'mississippi' => 'MS',
        'missouri' => 'MO', 'montana' => 'MT', 'nebraska' => 'NE', 'nevada' => 'NV',
        'new hampshire' => 'NH', 'new jersey' => 'NJ', 'new mexico' => 'NM', 'new york' => 'NY',
        'north carolina' => 'NC', 'north dakota' => 'ND', 'ohio' => 'OH', 'oklahoma' => 'OK',
        'oregon' => 'OR', 'pennsylvania' => 'PA', 'rhode island' => 'RI', 'south carolina' => 'SC',
        'south dakota' => 'SD', 'tennessee' => 'TN', 'texas' => 'TX', 'utah' => 'UT',
        'vermont' => 'VT', 'virginia' => 'VA', 'washington' => 'WA', 'west virginia' => 'WV',
        'wisconsin' => 'WI', 'wyoming' => 'WY', 'district of columbia' => 'DC',
    ];
    
    // Check if already a code
    if (strlen($state) === 2) {
        return strtoupper($state);
    }
    
    return $codes[$state] ?? strtoupper(substr($state, 0, 2));
}
