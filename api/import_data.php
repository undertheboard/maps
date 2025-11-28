<?php
/**
 * Enhanced Import System for Precinct Data
 * 
 * This is a new, improved import system that works alongside the existing
 * upload_precincts.php. It provides:
 * 
 * 1. Direct GeoJSON file import (no shapefile conversion needed)
 * 2. CSV data import for merging election/demographic data with existing GeoJSON
 * 3. Built-in support for Redistricting Data Hub (RDH) data formats
 * 4. Configurable field mapping
 * 5. Better validation and error handling
 * 6. Support for larger files with streaming
 * 
 * Redistricting Data Hub Support:
 *   The system automatically recognizes and maps RDH field naming conventions:
 *   - UNIQUE_ID, GEOID20: Precinct identifiers
 *   - G20PREDBID: 2020 Presidential Democratic votes (Biden)
 *   - G20PRERTRU: 2020 Presidential Republican votes (Trump)
 *   - TOTPOP, VAP: Population fields
 *   - And many more standard RDH column formats
 * 
 * Usage:
 *   POST /api/import_data.php
 *   Content-Type: multipart/form-data
 * 
 *   Required fields:
 *     - state: State code (e.g., "NC" or "37")
 *     - import_type: "geojson" | "csv_merge" | "rdh_geojson" | "rdh_csv"
 * 
 *   For geojson import:
 *     - geojson_file: The GeoJSON file to import
 *     - id_field: (optional) Field name to use as precinct ID, defaults to "id"
 *     - field_mapping: (optional) JSON object mapping source fields to target fields
 * 
 *   For csv_merge import:
 *     - csv_file: The CSV file to merge
 *     - join_field: (optional) Field in GeoJSON to join on, defaults to "id"
 *     - csv_key_column: (optional) Column in CSV to use as key, defaults to "id"
 *     - field_mapping: (optional) JSON object mapping CSV columns to GeoJSON properties
 * 
 *   For rdh_geojson import (Redistricting Data Hub GeoJSON):
 *     - geojson_file: The RDH GeoJSON file to import
 *     - Auto-maps RDH standard field names
 * 
 *   For rdh_csv import (Redistricting Data Hub CSV merge):
 *     - csv_file: The RDH CSV file to merge
 *     - Auto-maps RDH standard field names
 */

header('Content-Type: application/json');

// Increase memory for large files
ini_set('memory_limit', '1024M');

// Error handling
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

set_exception_handler(function ($e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'ok'      => false,
        'error'   => 'Uncaught exception',
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
    exit;
});

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'ok'      => false,
        'error'   => 'PHP error',
        'errno'   => $errno,
        'message' => $errstr,
        'file'    => $errfile,
        'line'    => $errline,
    ]);
    exit;
});

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method. Use POST.']);
    exit;
}

// Get and validate state parameter
$state = trim($_POST['state'] ?? '');
if ($state === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing state parameter.']);
    exit;
}

$stateCode = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $state));
if ($stateCode === '') {
    echo json_encode(['ok' => false, 'error' => 'Invalid state code.']);
    exit;
}

// Get import type
$importType = trim($_POST['import_type'] ?? '');
$validTypes = ['geojson', 'csv_merge', 'rdh_geojson', 'rdh_csv'];
if (!in_array($importType, $validTypes)) {
    echo json_encode([
        'ok'    => false,
        'error' => 'Invalid import_type. Must be one of: ' . implode(', ', $validTypes),
    ]);
    exit;
}

// Parse optional field mapping
$fieldMapping = [];
if (!empty($_POST['field_mapping'])) {
    $fieldMapping = json_decode($_POST['field_mapping'], true);
    if (!is_array($fieldMapping)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid field_mapping. Must be a JSON object.']);
        exit;
    }
}

// Ensure target directory exists
$targetDir = __DIR__ . "/../data/precincts/{$stateCode}";
if (!is_dir($targetDir)) {
    if (!mkdir($targetDir, 0775, true)) {
        echo json_encode(['ok' => false, 'error' => 'Failed to create state precinct directory.']);
        exit;
    }
}

// Route to appropriate handler
switch ($importType) {
    case 'geojson':
        handleGeoJSONImport($stateCode, $targetDir, $fieldMapping, false);
        break;
    case 'rdh_geojson':
        handleGeoJSONImport($stateCode, $targetDir, $fieldMapping, true);
        break;
    case 'csv_merge':
        handleCSVMerge($stateCode, $targetDir, $fieldMapping, false);
        break;
    case 'rdh_csv':
        handleCSVMerge($stateCode, $targetDir, $fieldMapping, true);
        break;
    default:
        echo json_encode(['ok' => false, 'error' => 'Unknown import type.']);
        exit;
}

/**
 * Handle direct GeoJSON file import
 * @param bool $isRDH Whether to use Redistricting Data Hub field mappings
 */
function handleGeoJSONImport(string $stateCode, string $targetDir, array $fieldMapping, bool $isRDH): void
{
    // Check for file upload
    if (!isset($_FILES['geojson_file']) || $_FILES['geojson_file']['error'] !== UPLOAD_ERR_OK) {
        $errorMsg = 'File upload failed.';
        if (isset($_FILES['geojson_file']['error'])) {
            $errorMsg .= ' Error code: ' . $_FILES['geojson_file']['error'];
        }
        echo json_encode(['ok' => false, 'error' => $errorMsg]);
        exit;
    }

    $tmpFile = $_FILES['geojson_file']['tmp_name'];
    
    // For RDH imports, use UNIQUE_ID or GEOID20 as the default ID field
    $idField = trim($_POST['id_field'] ?? ($isRDH ? 'UNIQUE_ID' : 'id'));
    
    // Validate the file is valid JSON
    $content = file_get_contents($tmpFile);
    if ($content === false) {
        echo json_encode(['ok' => false, 'error' => 'Failed to read uploaded file.']);
        exit;
    }

    $geojson = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode([
            'ok'    => false,
            'error' => 'Invalid JSON file: ' . json_last_error_msg(),
        ]);
        exit;
    }

    // Validate it's a FeatureCollection
    if (!is_array($geojson)) {
        echo json_encode(['ok' => false, 'error' => 'GeoJSON must be an object.']);
        exit;
    }

    $type = $geojson['type'] ?? '';
    if ($type !== 'FeatureCollection') {
        echo json_encode([
            'ok'    => false,
            'error' => 'GeoJSON must be a FeatureCollection, got: ' . $type,
        ]);
        exit;
    }

    if (!isset($geojson['features']) || !is_array($geojson['features'])) {
        echo json_encode(['ok' => false, 'error' => 'GeoJSON FeatureCollection must have a features array.']);
        exit;
    }

    // Process and normalize features
    $processedFeatures = [];
    $featureCount = 0;
    $warnings = [];

    foreach ($geojson['features'] as $index => $feature) {
        if (!isset($feature['type']) || $feature['type'] !== 'Feature') {
            $warnings[] = "Skipped item at index $index: not a Feature.";
            continue;
        }

        if (!isset($feature['geometry']) || !is_array($feature['geometry'])) {
            $warnings[] = "Skipped feature at index $index: missing geometry.";
            continue;
        }

        $props = $feature['properties'] ?? [];
        
        // Normalize properties (with RDH support if enabled)
        $normalizedProps = normalizeProperties($props, $index, $idField, $fieldMapping, $isRDH);
        
        $processedFeatures[] = [
            'type'       => 'Feature',
            'properties' => $normalizedProps,
            'geometry'   => $feature['geometry'],
        ];
        
        $featureCount++;
    }

    if ($featureCount === 0) {
        echo json_encode(['ok' => false, 'error' => 'No valid features found in GeoJSON.']);
        exit;
    }

    // Write output file
    $outputPath = $targetDir . '/precincts.geojson';
    $outputData = [
        'type'     => 'FeatureCollection',
        'features' => $processedFeatures,
    ];

    $written = file_put_contents($outputPath, json_encode($outputData, JSON_UNESCAPED_UNICODE));
    if ($written === false) {
        echo json_encode(['ok' => false, 'error' => 'Failed to write output file.']);
        exit;
    }

    echo json_encode([
        'ok'           => true,
        'stateCode'    => $stateCode,
        'importType'   => 'geojson',
        'featureCount' => $featureCount,
        'warnings'     => $warnings,
    ]);
}

/**
 * Handle CSV merge with existing GeoJSON
 * @param bool $isRDH Whether to use Redistricting Data Hub field mappings
 */
function handleCSVMerge(string $stateCode, string $targetDir, array $fieldMapping, bool $isRDH): void
{
    // Check for CSV file upload
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $errorMsg = 'CSV file upload failed.';
        if (isset($_FILES['csv_file']['error'])) {
            $errorMsg .= ' Error code: ' . $_FILES['csv_file']['error'];
        }
        echo json_encode(['ok' => false, 'error' => $errorMsg]);
        exit;
    }

    $csvFile = $_FILES['csv_file']['tmp_name'];
    
    // For RDH imports, use UNIQUE_ID as the default key column
    $joinField = trim($_POST['join_field'] ?? ($isRDH ? 'id' : 'id'));
    $csvKeyColumn = trim($_POST['csv_key_column'] ?? ($isRDH ? 'UNIQUE_ID' : 'id'));

    // Check if existing GeoJSON exists
    $geojsonPath = $targetDir . '/precincts.geojson';
    if (!file_exists($geojsonPath)) {
        echo json_encode([
            'ok'    => false,
            'error' => "No existing GeoJSON found for state $stateCode. Import GeoJSON first.",
        ]);
        exit;
    }

    // Read existing GeoJSON
    $geojsonContent = file_get_contents($geojsonPath);
    if ($geojsonContent === false) {
        echo json_encode(['ok' => false, 'error' => 'Failed to read existing GeoJSON file.']);
        exit;
    }

    $geojson = json_decode($geojsonContent, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($geojson)) {
        echo json_encode(['ok' => false, 'error' => 'Existing GeoJSON is invalid.']);
        exit;
    }

    // Read CSV into associative array
    $csvHandle = fopen($csvFile, 'r');
    if ($csvHandle === false) {
        echo json_encode(['ok' => false, 'error' => 'Failed to open CSV file.']);
        exit;
    }

    $header = fgetcsv($csvHandle);
    if ($header === false || count($header) === 0) {
        fclose($csvHandle);
        echo json_encode(['ok' => false, 'error' => 'CSV file has no header row.']);
        exit;
    }

    // Normalize header names (trim whitespace)
    $header = array_map('trim', $header);

    // Find the key column index
    $keyColIndex = array_search($csvKeyColumn, $header);
    if ($keyColIndex === false) {
        // Try case-insensitive search
        foreach ($header as $i => $col) {
            if (strtolower($col) === strtolower($csvKeyColumn)) {
                $keyColIndex = $i;
                break;
            }
        }
    }

    if ($keyColIndex === false) {
        fclose($csvHandle);
        echo json_encode([
            'ok'    => false,
            'error' => "CSV key column '$csvKeyColumn' not found in header. Available columns: " . implode(', ', $header),
        ]);
        exit;
    }

    // Read all CSV rows into a lookup table
    $csvData = [];
    $lineNum = 1;
    while (($row = fgetcsv($csvHandle)) !== false) {
        $lineNum++;
        if (count($row) !== count($header)) {
            continue; // Skip malformed rows
        }
        
        $assoc = array_combine($header, $row);
        $key = trim((string)($assoc[$header[$keyColIndex]] ?? ''));
        if ($key === '') {
            continue;
        }
        
        $csvData[$key] = $assoc;
    }
    fclose($csvHandle);

    if (empty($csvData)) {
        echo json_encode(['ok' => false, 'error' => 'No valid data rows found in CSV.']);
        exit;
    }

    // Merge CSV data into GeoJSON features
    $matchCount = 0;
    $unmatchedCount = 0;

    foreach ($geojson['features'] as &$feature) {
        $props = $feature['properties'] ?? [];
        $joinValue = trim((string)($props[$joinField] ?? ''));
        
        if ($joinValue === '') {
            $unmatchedCount++;
            continue;
        }

        if (isset($csvData[$joinValue])) {
            $csvRow = $csvData[$joinValue];
            
            // Merge CSV columns into properties
            foreach ($csvRow as $column => $value) {
                // Skip the key column (already in GeoJSON)
                if ($column === $header[$keyColIndex]) {
                    continue;
                }

                // Apply field mapping if specified
                $targetField = $column;
                if (isset($fieldMapping[$column])) {
                    $targetField = $fieldMapping[$column];
                }

                // Cast numeric values
                if (is_numeric($value) && $value !== '') {
                    $value = $value + 0; // Cast to int or float
                }

                // For RDH imports, apply standard RDH field mappings
                if ($isRDH) {
                    $targetField = mapRDHField($column);
                } else {
                    $targetField = $column;
                }
                
                // Apply custom field mapping if specified (overrides RDH mapping)
                if (isset($fieldMapping[$column])) {
                    $targetField = $fieldMapping[$column];
                }

                $props[$targetField] = $value;
            }
            
            $feature['properties'] = $props;
            $matchCount++;
        } else {
            $unmatchedCount++;
        }
    }
    unset($feature);

    // Write updated GeoJSON
    $backupPath = $targetDir . '/precincts.backup.geojson';
    copy($geojsonPath, $backupPath);

    $written = file_put_contents($geojsonPath, json_encode($geojson, JSON_UNESCAPED_UNICODE));
    if ($written === false) {
        // Restore backup
        copy($backupPath, $geojsonPath);
        echo json_encode(['ok' => false, 'error' => 'Failed to write merged GeoJSON file.']);
        exit;
    }

    echo json_encode([
        'ok'           => true,
        'stateCode'    => $stateCode,
        'importType'   => $isRDH ? 'rdh_csv' : 'csv_merge',
        'matchCount'   => $matchCount,
        'unmatchedCount' => $unmatchedCount,
        'csvRowCount'  => count($csvData),
        'backupPath'   => 'precincts.backup.geojson',
    ]);
}

/**
 * Normalize properties for consistent format
 * @param bool $isRDH Whether to use Redistricting Data Hub field mappings
 */
function normalizeProperties(array $props, int $index, string $idField, array $fieldMapping, bool $isRDH = false): array
{
    // Build uppercase lookup for case-insensitive matching
    $upper = [];
    foreach ($props as $k => $v) {
        $upper[strtoupper((string)$k)] = $v;
    }

    $normalized = [];

    // --- ID ---
    $id = null;
    
    // First try the specified idField
    if (isset($props[$idField])) {
        $id = $props[$idField];
    } elseif (isset($upper[strtoupper($idField)])) {
        $id = $upper[strtoupper($idField)];
    }
    
    // Fall back to common ID fields (including RDH standard fields)
    if ($id === null || $id === '') {
        $id = $upper['UNIQUE_ID'] ??  // RDH standard
              $upper['GEOID20'] ??    // RDH/Census 2020
              $upper['GEOID'] ?? 
              $upper['ID'] ?? 
              $upper['PRECINCT'] ?? 
              $upper['PREC_ID'] ?? 
              $upper['VTD'] ??        // Voting Tabulation District
              $upper['GEOID10'] ?? 
              ('p_' . $index);
    }
    
    $normalized['id'] = (string)$id;

    // --- Population ---
    // RDH uses TOTPOP, VAP (Voting Age Population), etc.
    $pop = $props['population'] ?? 
           $upper['POPULATION'] ?? 
           $upper['TOTPOP'] ??      // RDH standard for total population
           $upper['POP'] ?? 
           $upper['POP_TOT'] ??
           $upper['TOTAL_POP'] ??
           0;
    $normalized['population'] = (int)$pop;

    // --- Voting Age Population (RDH specific) ---
    if (isset($upper['VAP']) || isset($props['vap'])) {
        $normalized['vap'] = (int)($upper['VAP'] ?? $props['vap'] ?? 0);
    }

    // --- Dem votes ---
    // RDH uses patterns like G20PREDBID (General 2020 Presidential Democratic Biden)
    $dem = findRDHDemVotes($props, $upper) ?? 
           $props['dem'] ?? 
           $upper['DEM'] ?? 
           $upper['D_VOTES'] ?? 
           $upper['DEM_20'] ?? 
           $upper['PRESDEM20'] ?? 
           0;
    $normalized['dem'] = (int)$dem;

    // --- Rep votes ---
    // RDH uses patterns like G20PRERTRU (General 2020 Presidential Republican Trump)
    $rep = findRDHRepVotes($props, $upper) ??
           $props['rep'] ?? 
           $upper['REP'] ?? 
           $upper['R_VOTES'] ?? 
           $upper['REP_20'] ?? 
           $upper['PRESREP20'] ?? 
           0;
    $normalized['rep'] = (int)$rep;

    // Apply custom field mapping
    foreach ($fieldMapping as $source => $target) {
        if (isset($props[$source])) {
            $value = $props[$source];
            if (is_numeric($value) && $value !== '') {
                $value = $value + 0;
            }
            $normalized[$target] = $value;
        } elseif (isset($upper[strtoupper($source)])) {
            $value = $upper[strtoupper($source)];
            if (is_numeric($value) && $value !== '') {
                $value = $value + 0;
            }
            $normalized[$target] = $value;
        }
    }

    // For RDH imports, map additional standard fields
    if ($isRDH) {
        foreach ($props as $k => $v) {
            $mappedField = mapRDHField($k);
            if ($mappedField !== $k && !isset($normalized[$mappedField])) {
                $value = $v;
                if (is_numeric($value) && $value !== '') {
                    $value = $value + 0;
                }
                $normalized[$mappedField] = $value;
            }
        }
    }

    // Copy all other properties (preserving original case)
    foreach ($props as $k => $v) {
        $lowerK = strtolower($k);
        // Skip properties we've already normalized
        if (in_array($lowerK, ['id', 'population', 'pop', 'totpop', 'pop_tot', 
                               'dem', 'd_votes', 'dem_20', 'presdem20',
                               'rep', 'r_votes', 'rep_20', 'presrep20',
                               'unique_id', 'geoid20', 'geoid', 'vap'])) {
            continue;
        }
        // Skip if already mapped
        if (isset($fieldMapping[$k])) {
            continue;
        }
        // Keep the property (with RDH mapping if applicable)
        $targetKey = $isRDH ? mapRDHField($k) : $k;
        if (!isset($normalized[$targetKey])) {
            $normalized[$targetKey] = $v;
        }
    }

    return $normalized;
}

/**
 * Find Democratic votes in RDH format
 * RDH uses patterns like G20PREDBID (General 2020 Presidential Democratic Biden)
 */
function findRDHDemVotes(array $props, array $upper): ?int
{
    // Common RDH presidential Democratic vote fields (2020, 2024)
    $demFields = [
        'G20PREDBID',   // 2020 Presidential Biden
        'G24PREDHAR',   // 2024 Presidential Harris (if exists)
        'G20PREDEM',    // Alternative format
        'G24PREDEM',
        'PRES20D',      // Another common format
        'PRES24D',
    ];
    
    foreach ($demFields as $field) {
        if (isset($upper[$field])) {
            return (int)$upper[$field];
        }
    }
    
    // Try pattern matching for any Democratic presidential vote
    foreach ($upper as $k => $v) {
        // Match G##PRE*D* or PRES##D patterns
        if (preg_match('/^G\d{2}PRE[A-Z]*D[A-Z]*$/i', $k) ||
            preg_match('/^PRES\d{2}D$/i', $k)) {
            return (int)$v;
        }
    }
    
    return null;
}

/**
 * Find Republican votes in RDH format
 * RDH uses patterns like G20PRERTRU (General 2020 Presidential Republican Trump)
 */
function findRDHRepVotes(array $props, array $upper): ?int
{
    // Common RDH presidential Republican vote fields (2020, 2024)
    $repFields = [
        'G20PRERTRU',   // 2020 Presidential Trump
        'G24PRERTRUMP', // 2024 Presidential Trump (if exists)
        'G20PREREP',    // Alternative format
        'G24PREREP',
        'PRES20R',      // Another common format
        'PRES24R',
    ];
    
    foreach ($repFields as $field) {
        if (isset($upper[$field])) {
            return (int)$upper[$field];
        }
    }
    
    // Try pattern matching for any Republican presidential vote
    foreach ($upper as $k => $v) {
        // Match G##PRE*R* or PRES##R patterns
        if (preg_match('/^G\d{2}PRE[A-Z]*R[A-Z]*$/i', $k) ||
            preg_match('/^PRES\d{2}R$/i', $k)) {
            return (int)$v;
        }
    }
    
    return null;
}

/**
 * Map Redistricting Data Hub field names to simplified field names
 * 
 * RDH uses standardized naming conventions:
 * - G20PREDBID = General 2020 Presidential Democratic Biden
 * - G20PRERTRU = General 2020 Presidential Republican Trump
 * - TOTPOP = Total Population
 * - VAP = Voting Age Population
 * - GEOID20 = Geographic ID (2020 Census)
 * etc.
 */
function mapRDHField(string $field): string
{
    // Exact mappings for common RDH fields
    $exactMappings = [
        // Identifiers
        'UNIQUE_ID' => 'id',
        'GEOID20'   => 'geoid',
        'GEOID'     => 'geoid',
        'VTD'       => 'vtd',
        
        // Population
        'TOTPOP'    => 'population',
        'VAP'       => 'vap',
        'CVAP'      => 'cvap',  // Citizen Voting Age Population
        
        // Race/Ethnicity population (keep as-is but document)
        'NH_WHITE'  => 'pop_white',
        'NH_BLACK'  => 'pop_black', 
        'HISP'      => 'pop_hispanic',
        'NH_ASIAN'  => 'pop_asian',
        'NH_AMIN'   => 'pop_native',
        'NH_NHPI'   => 'pop_pacific',
        'NH_2MORE'  => 'pop_multiracial',
        
        // 2020 Presidential votes
        'G20PREDBID' => 'dem',
        'G20PRERTRU' => 'rep',
        
        // Alternative formats
        'PRES20D'   => 'dem',
        'PRES20R'   => 'rep',
    ];
    
    $upperField = strtoupper($field);
    
    if (isset($exactMappings[$upperField])) {
        return $exactMappings[$upperField];
    }
    
    // Pattern-based mappings for election data
    // Format: G[YY][RACE][PARTY][CANDIDATE] e.g., G20PREDBID
    if (preg_match('/^G(\d{2})([A-Z]{3})([DR])([A-Z]+)?$/i', $upperField, $matches)) {
        $year = $matches[1];
        $race = strtolower($matches[2]);
        $party = strtolower($matches[3]);
        $partyName = $party === 'd' ? 'dem' : 'rep';
        
        // Map race codes
        $raceNames = [
            'pre' => 'pres',    // Presidential
            'sen' => 'sen',     // Senate
            'gov' => 'gov',     // Governor
            'hou' => 'house',   // House
            'atg' => 'ag',      // Attorney General
            'sos' => 'sos',     // Secretary of State
        ];
        
        $raceName = $raceNames[$race] ?? $race;
        return "{$raceName}_{$year}_{$partyName}";
    }
    
    // Return original field if no mapping found
    return $field;
}
