<?php
// Show errors during development (you can turn this off later)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$baseDir    = __DIR__;
$dataDir    = $baseDir . '/data';
$statesFile = $dataDir . '/states.json';

// Redirect to setup if core pieces missing
if (!file_exists($statesFile) || !is_dir($dataDir . '/precincts') || !is_dir($dataDir . '/plans')) {
    header('Location: setup.php');
    exit;
}

// Parse districts.csv file
$districtsData = [];
$districtsFile = $dataDir . '/districts.csv';
if (file_exists($districtsFile)) {
    $handle = fopen($districtsFile, 'r');
    if ($handle) {
        // Read header row
        $header = fgetcsv($handle);
        
        // Read data rows
        while (($row = fgetcsv($handle)) !== false) {
            // Check if this is a valid data row (has columns)
            if (count($row) < count($header)) {
                continue;
            }
            
            // Create associative array from row
            $rowData = array_combine($header, $row);
            
            // Get the District ID (first column)
            $districtId = trim($rowData['ID']);
            
            // Skip the state summary row (empty ID)
            if ($districtId === '') {
                continue;
            }
            
            // Skip "Un" (unassigned) row
            if ($districtId === 'Un') {
                continue;
            }
            
            // Store district data keyed by District ID
            $districtsData[$districtId] = [
                'id' => $districtId,
                'total_pop' => floatval($rowData['Total Pop']),
                'deviation' => floatval($rowData['Deviation']),
                'dem' => floatval($rowData['Dem']),
                'rep' => floatval($rowData['Rep']),
                'oth' => floatval($rowData['Oth']),
                'total_vap' => floatval($rowData['Total VAP']),
                'white' => floatval($rowData['White']),
                'minority' => floatval($rowData['Minority']),
                'hispanic' => floatval($rowData['Hispanic']),
                'black' => floatval($rowData['Black']),
                'asian' => floatval($rowData['Asian']),
                'native' => floatval($rowData['Native']),
                'pacific' => floatval($rowData['Pacific'])
            ];
        }
        fclose($handle);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>US Redistricting Tool</title>
  <link rel="stylesheet" href="public/css/style.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
</head>
<body>
  <header class="app-header">
    <div class="header-brand">
      <h1>🗺️ US Redistricting Tool</h1>
    </div>
    <div class="header-controls">
      <div class="state-selector">
        <label for="stateSelect">State:</label>
        <select id="stateSelect"></select>
        <button id="loadStateBtn" class="btn-primary">Load State</button>
      </div>
      <div class="plan-controls">
        <input type="text" id="planName" placeholder="Plan name...">
        <button id="newPlanBtn" class="btn-secondary">New</button>
        <button id="savePlanBtn" class="btn-success">Save</button>
        <select id="existingPlans"></select>
        <button id="loadPlanBtn" class="btn-secondary">Load</button>
      </div>
    </div>
  </header>

  <main class="layout">
    <section class="sidebar">
      <div class="sidebar-tabs">
        <button class="tab-btn active" data-tab="controls">🎛️ Controls</button>
        <button class="tab-btn" data-tab="data">📁 Data</button>
        <button class="tab-btn" data-tab="metrics">📊 Metrics</button>
      </div>

      <div class="tab-content active" id="tab-controls">
        <div class="panel">
          <h3>📍 District Settings</h3>
          <div class="form-group">
            <label for="numDistricts">Number of districts:</label>
            <input type="number" id="numDistricts" min="1" max="100" value="10" class="input-number">
          </div>
        </div>

        <div class="panel">
          <h3>🤖 Auto-Generate Map</h3>
          <p class="help-text">Automatically create districts based on fairness goals.</p>
          <div class="automap-controls">
            <div class="form-group">
              <label for="fairnessPreset">Fairness Level:</label>
              <select id="fairnessPreset" class="input-select">
                <option value="very_r">Very Republican (60% R)</option>
                <option value="lean_r">Lean Republican (54% R)</option>
                <option value="fair" selected>Fair / Competitive (50-50)</option>
                <option value="lean_d">Lean Democratic (54% D)</option>
                <option value="very_d">Very Democratic (60% D)</option>
              </select>
            </div>
            <div class="form-group inline">
              <label><input type="checkbox" id="useCustomTarget"> Custom target:</label>
              <input type="number" id="customTarget" min="0" max="100" value="50" step="1" class="input-number small" disabled>
              <span class="unit">% Dem</span>
            </div>
            <button id="automapBtn" class="btn-automap">🗺️ Generate Districts</button>
          </div>
          <div id="automapStatus" class="status-message"></div>
        </div>

        <div class="panel">
          <h3>✏️ Drawing Mode</h3>
          <div class="radio-group">
            <label class="radio-label"><input type="radio" name="drawMode" value="assign" checked><span class="radio-text">Assign to district</span></label>
            <label class="radio-label"><input type="radio" name="drawMode" value="erase"><span class="radio-text">Remove from district</span></label>
          </div>
          <div class="district-selector-container">
            <label for="districtSelector">Paint with District:</label>
            <select id="districtSelector" class="input-select">
              <option value="1">District 1</option>
            </select>
          </div>
          <div class="shortcuts-panel">
            <h4>⌨️ Keyboard Shortcuts</h4>
            <div class="shortcut-item"><span class="shortcut-key">1-9</span><span class="shortcut-desc">Select district</span></div>
            <div class="shortcut-item"><span class="shortcut-key">E</span><span class="shortcut-desc">Toggle erase mode</span></div>
            <div class="shortcut-item"><span class="shortcut-key">A</span><span class="shortcut-desc">Toggle assign mode</span></div>
          </div>
        </div>

        <div class="panel">
          <h3>🎨 Display Options</h3>
          <div class="checkbox-group">
            <label class="checkbox-label"><input type="checkbox" id="showCountyBorders"><span>Show county borders</span></label>
            <label class="checkbox-label"><input type="checkbox" id="showPrecinctLines" checked><span>Show precinct lines</span></label>
          </div>
          <div class="form-group">
            <label for="colorMode">Color scheme:</label>
            <select id="colorMode" class="input-select">
              <option value="district_set">By District Number</option>
              <option value="district_lean">By District Partisan Lean</option>
              <option value="precinct_lean">By Precinct Partisan Lean</option>
            </select>
          </div>
          <div id="colorLegend" class="color-legend"></div>
        </div>

        <div class="panel collapsible">
          <h3 class="panel-toggle">🏷️ District Colors <span class="toggle-icon">▼</span></h3>
          <div class="panel-content" id="districtColorLegend"></div>
        </div>
      </div>

      <div class="tab-content" id="tab-data">
        <div class="panel">
          <h3>📤 Upload Shapefile</h3>
          <p class="help-text">Upload a ZIP containing precinct shapefile (.shp, .shx, .dbf, .prj, .cpg)</p>
          <form id="uploadForm" enctype="multipart/form-data">
            <div class="form-group">
              <label for="uploadState">State code:</label>
              <input type="text" id="uploadState" name="state" placeholder="e.g., NC or 37" class="input-text" required>
            </div>
            <div class="form-group">
              <label for="precinctZip">Shapefile ZIP:</label>
              <input type="file" id="precinctZip" name="precinctZip" accept=".zip" class="input-file" required>
            </div>
            <button type="submit" class="btn-primary">Upload Shapefile</button>
          </form>
          <div id="uploadProgressContainer" class="upload-progress-container">
            <div class="upload-progress-bar">
              <div class="progress-fill" id="uploadProgressFill"></div>
              <span class="progress-text" id="uploadProgressText">0%</span>
            </div>
          </div>
          <div id="uploadStatus" class="status-message"></div>
        </div>

        <div class="panel">
          <h3>📥 Import GeoJSON / CSV</h3>
          <p class="help-text">Import precinct data directly. Supports Redistricting Data Hub formats!</p>
          <form id="enhancedImportForm" enctype="multipart/form-data">
            <div class="form-group">
              <label for="importState">State code:</label>
              <input type="text" id="importState" name="state" placeholder="e.g., NC or 37" class="input-text" required>
            </div>
            <div class="form-group">
              <label for="importType">Import type:</label>
              <select id="importType" name="import_type" class="input-select" required>
                <option value="">-- Select import type --</option>
                <optgroup label="Standard Import">
                  <option value="geojson">GeoJSON File</option>
                  <option value="csv_merge">CSV Merge (with existing GeoJSON)</option>
                </optgroup>
                <optgroup label="Redistricting Data Hub (RDH)">
                  <option value="rdh_geojson">RDH GeoJSON (auto-maps fields)</option>
                  <option value="rdh_csv">RDH CSV Merge (auto-maps fields)</option>
                </optgroup>
              </select>
            </div>
            <div id="geojsonImportOptions" class="conditional-options" style="display:none;">
              <div class="form-group">
                <label for="geojsonFile">GeoJSON file:</label>
                <input type="file" id="geojsonFile" name="geojson_file" accept=".geojson,.json" class="input-file">
              </div>
              <div class="form-group">
                <label for="idField">ID field name (optional):</label>
                <input type="text" id="idField" name="id_field" placeholder="id" class="input-text">
              </div>
            </div>
            <div id="csvImportOptions" class="conditional-options" style="display:none;">
              <div class="form-group">
                <label for="csvFile">CSV file:</label>
                <input type="file" id="csvFile" name="csv_file" accept=".csv" class="input-file">
              </div>
              <div class="form-group">
                <label for="joinField">GeoJSON join field:</label>
                <input type="text" id="joinField" name="join_field" placeholder="id" class="input-text">
              </div>
              <div class="form-group">
                <label for="csvKeyColumn">CSV key column:</label>
                <input type="text" id="csvKeyColumn" name="csv_key_column" placeholder="id" class="input-text">
              </div>
            </div>
            <div id="rdhInfo" class="info-box" style="display:none;">
              <strong>💡 RDH Auto-Mapping:</strong> Fields like UNIQUE_ID, GEOID20, TOTPOP, VAP, G20PREDBID, G20PRERTRU are automatically recognized.
            </div>
            <details class="advanced-options">
              <summary>Advanced: Field Mapping</summary>
              <p class="help-text">JSON mapping from source to target field names.</p>
              <textarea id="fieldMapping" name="field_mapping" rows="3" placeholder='{"PRECINCT_NAME": "name"}' class="input-textarea"></textarea>
            </details>
            <button type="submit" class="btn-primary">Import Data</button>
          </form>
          <div id="importProgressContainer" class="upload-progress-container">
            <div class="upload-progress-bar">
              <div class="progress-fill" id="importProgressFill"></div>
              <span class="progress-text" id="importProgressText">0%</span>
            </div>
          </div>
          <div id="importStatus" class="status-message"></div>
        </div>

        <div class="panel">
          <h3>🌐 RDH Direct Import</h3>
          <p class="help-text">Connect directly to Redistricting Data Hub API to download precinct data.</p>
          <form id="rdhDirectForm">
            <div class="form-group">
              <label for="rdhUsername">RDH Username/Email:</label>
              <input type="text" id="rdhUsername" name="rdh_username" placeholder="your@email.com" class="input-text">
            </div>
            <div class="form-group">
              <label for="rdhPassword">RDH Password:</label>
              <input type="password" id="rdhPassword" name="rdh_password" placeholder="Password" class="input-text">
            </div>
            <div class="form-group">
              <label for="rdhState">State:</label>
              <input type="text" id="rdhState" name="state" placeholder="e.g., North Carolina or NC" class="input-text">
            </div>
            <button type="button" id="rdhListBtn" class="btn-secondary">List Available Datasets</button>
            <button type="button" id="rdhImportBtn" class="btn-primary" style="display:none;">Import Selected</button>
          </form>
          <div id="rdhDatasetList" class="dataset-list" style="display:none;"></div>
          <div id="rdhProgressContainer" class="upload-progress-container">
            <div class="upload-progress-bar">
              <div class="progress-fill" id="rdhProgressFill"></div>
              <span class="progress-text" id="rdhProgressText">0%</span>
            </div>
          </div>
          <div id="rdhStatus" class="status-message"></div>
        </div>

        <div class="panel">
          <h3>🔧 System</h3>
          <p class="help-text"><a href="setup.php" target="_blank" class="link">Run Setup &amp; Diagnostics</a></p>
        </div>
      </div>

      <div class="tab-content" id="tab-metrics">
        <div class="panel">
          <h3>📊 District Metrics</h3>
          <div id="metricsPanel" class="metrics-table">
            <p class="placeholder-text">Load a state and assign precincts to see metrics.</p>
          </div>
        </div>
      </div>
    </section>

    <section class="map-panel">
      <div class="map-toolbar">
        <div class="toolbar-left">
          <span class="toolbar-hint">💡 Click precincts to assign districts. Scroll to zoom, drag to pan.</span>
        </div>
        <div class="toolbar-right">
          <span id="mapStatus" class="map-status"></span>
        </div>
      </div>
      <div id="leafletMap"></div>
      <canvas id="mapCanvas"></canvas>
      <div id="hoverInfo" class="hover-info"></div>
    </section>
  </main>

  <script>
    // Districts data from CSV
    window.districtsData = <?php echo json_encode($districtsData); ?>;
  </script>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="public/js/map.js"></script>
  <script src="public/js/metrics.js"></script>
  <script src="public/js/automap.js"></script>
  <script src="public/js/storage.js"></script>
  <script src="public/js/app.js"></script>
</body>
</html>
