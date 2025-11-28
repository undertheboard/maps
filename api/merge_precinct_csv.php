<?php
/**
 * Streaming merge:
 *  - Reads CSV into memory keyed by UNIQUE_ID.
 *  - Streams GeoJSON FeatureCollection from input file.
 *  - For each feature, merges CSV columns into properties and writes
 *    to a new output FeatureCollection without loading all features at once.
 *
 * Usage (CLI):
 *   php api/merge_precinct_csv_stream.php \
 *       data/precincts/NC/precincts.geojson \
 *       data/precincts/NC/results.csv \
 *       data/precincts/NC/precincts_merged.geojson
 */

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json');
}

// ---------- Parse arguments / defaults ----------

$geojsonPath = $argv[1] ?? __DIR__ . '/../data/precincts/NC/precincts.geojson';
$csvPath     = $argv[2] ?? __DIR__ . '/../data/precincts/NC/results.csv';
$outPath     = $argv[3] ?? __DIR__ . '/../data/precincts/NC/precincts_merged.geojson';

// ---------- Basic checks ----------

if (!file_exists($geojsonPath)) {
    exitWithError("GeoJSON file not found: $geojsonPath");
}
if (!file_exists($csvPath)) {
    exitWithError("CSV file not found: $csvPath");
}

// ---------- Load CSV into associative array keyed by UNIQUE_ID ----------

$csvHandle = fopen($csvPath, 'r');
if ($csvHandle === false) {
    exitWithError("Failed to open CSV: $csvPath");
}

$header = fgetcsv($csvHandle);
if ($header === false) {
    fclose($csvHandle);
    exitWithError("CSV has no header row: $csvPath");
}
$header = array_map('trim', $header);

$uniqueIdIndex = array_search('UNIQUE_ID', $header);
if ($uniqueIdIndex === false) {
    fclose($csvHandle);
    exitWithError("CSV does not contain a UNIQUE_ID column.");
}

$csvRowsById = [];
while (($row = fgetcsv($csvHandle)) !== false) {
    if (count($row) !== count($header)) {
        // skip malformed lines
        continue;
    }
    $assoc = array_combine($header, $row);
    $id    = trim($assoc['UNIQUE_ID']);
    if ($id === '') {
        continue;
    }
    $csvRowsById[$id] = $assoc;
}
fclose($csvHandle);

// ---------- Open input and output files ----------

$in = fopen($geojsonPath, 'r');
if ($in === false) {
    exitWithError("Failed to open GeoJSON for reading: $geojsonPath");
}

$out = fopen($outPath, 'w');
if ($out === false) {
    fclose($in);
    exitWithError("Failed to open output file for writing: $outPath");
}

// ---------- Utility: find feature key from decoded feature ----------

function getFeatureKeyFromArray(array $feature): ?string
{
    $p = $feature['properties'] ?? [];
    if (isset($p['UNIQUE_ID'])) {
        return trim((string)$p['UNIQUE_ID']);
    }
    if (isset($p['id'])) {
        return trim((string)$p['id']);
    }
    return null;
}

// ---------- Stream parse: copy header, process features array, copy footer ----------

$inFeatures = false;       // are we inside the "features" array?
$firstOut   = true;        // to manage commas between output features
$buffer     = '';          // buffer to accumulate a feature's JSON text
$braceDepth = 0;           // track nested braces for feature object
$featuresStarted = false;  // have we seen the opening '[' of the features array?

// Write initial part of the file up through `"features":[`
while (($line = fgets($in)) !== false) {
    $trim = trim($line);

    // Look for the start of features array: a line containing "features":[
    if (!$inFeatures && strpos($trim, '"features"') !== false && strpos($trim, '[') !== false) {
        // Write up to and including the "features":[
        // But strip any trailing content after '[' because we'll handle features ourselves
        $before = substr($line, 0, strpos($line, '[') + 1);
        fwrite($out, $before);
        $inFeatures = true;
        $featuresStarted = true;
        break;
    } else {
        // Part of header (type, crs, bbox, etc.)
        fwrite($out, $line);
    }
}

if (!$featuresStarted) {
    fclose($in);
    fclose($out);
    exitWithError('Could not locate "features" array in GeoJSON.');
}

// Now process the features one by one until we hit the closing ']' of the array.
$mergedMatches = 0;
$missingCount  = 0;

while (($line = fgets($in)) !== false) {
    $trim = trim($line);

    // Detect end of features array: a line that starts with ']' or '],' (possibly with whitespace)
    if ($braceDepth === 0 && ($trim === ']' || $trim === '],' || str_starts_with($trim, '],') || str_starts_with($trim, ']'))) {
        // Close the features array in output
        fwrite($out, ']');
        // Move on to copying the rest (footer)
        break;
    }

    // If we are not currently collecting a feature and line looks like a starting '{'
    if ($braceDepth === 0 && strpos($trim, '{') !== false) {
        $buffer     = '';
        $braceDepth = 0;
    }

    // If we are collecting a feature (braceDepth > 0 or we just saw '{'), add this line
    if ($braceDepth > 0 || strpos($trim, '{') !== false) {
        $buffer .= $line;
        // Update braceDepth by counting '{' and '}' in this line
        $braceDepth += substr_count($line, '{');
        $braceDepth -= substr_count($line, '}');

        // When braceDepth becomes 0, we have a complete JSON object for a feature
        if ($braceDepth === 0 && $buffer !== '') {
            // Remove trailing comma if present (feature separators)
            $featureText = rtrim($buffer, ", \r\n");

            // Decode feature
            $feature = json_decode($featureText, true);
            if (is_array($feature) && ($feature['type'] ?? '') === 'Feature') {
                $key = getFeatureKeyFromArray($feature);
                if ($key !== null && $key !== '' && isset($csvRowsById[$key])) {
                    $row = $csvRowsById[$key];

                    if (!isset($feature['properties']) || !is_array($feature['properties'])) {
                        $feature['properties'] = [];
                    }

                    foreach ($row as $col => $val) {
                        if ($col === 'UNIQUE_ID') {
                            $feature['properties']['UNIQUE_ID'] = $val;
                            continue;
                        }
                        // cast numeric
                        if (is_numeric($val) && $val !== '') {
                            $feature['properties'][$col] = $val + 0;
                        } else {
                            $feature['properties'][$col] = $val;
                        }
                    }
                    $mergedMatches++;
                } else {
                    $missingCount++;
                }

                // Re-encode feature
                $outFeature = json_encode($feature, JSON_UNESCAPED_UNICODE);
                if ($outFeature === false) {
                    // If encoding fails, write original text as fallback
                    $outFeature = $featureText;
                }

                // Write comma if not first
                if (!$firstOut) {
                    fwrite($out, ',');
                }
                $firstOut = false;

                fwrite($out, $outFeature);
            } else {
                // Not a valid feature; just pass through raw
                if (!$firstOut) {
                    fwrite($out, ',');
                }
                $firstOut = false;
                fwrite($out, $featureText);
            }

            // Reset buffer for next feature
            $buffer = '';
        }

        // Continue to next line
        continue;
    }

    // If here, we are in features array but encountered a line we don't treat as a feature;
    // just skip it. (Typically whitespace or commas between features.)
}

// Copy the remaining footer (after closing ] of features array)
while (($line = fgets($in)) !== false) {
    fwrite($out, $line);
}

fclose($in);
fclose($out);

// ---------- Done ----------

$result = [
    'ok'            => true,
    'geojson'       => $geojsonPath,
    'csv'           => $csvPath,
    'output'        => $outPath,
    'mergedMatches' => $mergedMatches,
    'missing'       => $missingCount,
];

if (PHP_SAPI === 'cli') {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    header('Content-Type: application/json');
    echo json_encode($result);
}

exit(0);

// ---------- helper ----------

function exitWithError(string $msg): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "[ERROR] $msg\n");
    } else {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['error' => $msg]);
    }
    exit(1);
}

if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return $needle !== '' && strpos($haystack, $needle) === 0;
    }
}