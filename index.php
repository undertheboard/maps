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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>US Redistricting Tool</title>
  <link rel="stylesheet" href="public/css/style.css">

  <!-- Leaflet CSS (no integrity attribute so it is not blocked) -->
  <link
    rel="stylesheet"
    href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
  />
</head>
<body>
  <header class="app-header">
    <h1>US Redistricting Tool</h1>
    <div class="state-selector">
      <label for="stateSelect">State:</label>
      <select id="stateSelect"></select>
      <button id="loadStateBtn">Load</button>
    </div>
    <div class="plan-controls">
      <label for="planName">Plan name:</label>
      <input type="text" id="planName" placeholder="My Plan">
      <button id="newPlanBtn">New Plan</button>
      <button id="savePlanBtn">Save Plan</button>
      <select id="existingPlans"></select>
      <button id="loadPlanBtn">Load Plan</button>
    </div>
  </header>

  <main class="layout">
    <section class="sidebar">
      <h2>Districting Controls</h2>
      <label for="numDistricts">Number of districts:</label>
      <input type="number" id="numDistricts" min="1" max="100" value="10">

      <h3>Automap Generator</h3>
      <p style="font-size:0.8rem;margin-bottom:0.5rem;">
        Automatically generate districts based on fairness goals.
      </p>
      <div class="automap-controls">
        <label for="fairnessPreset">Fairness Level:</label>
        <select id="fairnessPreset">
          <option value="very_r">Very R (60% Rep)</option>
          <option value="lean_r">Lean R (54% Rep)</option>
          <option value="fair" selected>Fair (50-50)</option>
          <option value="lean_d">Lean D (54% Dem)</option>
          <option value="very_d">Very D (60% Dem)</option>
        </select>
        
        <label for="customTarget" style="margin-top:0.3rem;">
          <input type="checkbox" id="useCustomTarget"> Custom Dem% Target:
        </label>
        <input type="number" id="customTarget" min="0" max="100" value="50" step="1" 
               style="width:60px;" disabled>
        
        <button id="automapBtn" class="automap-btn">🗺️ Generate Automap</button>
      </div>
      <div id="automapStatus" style="font-size:0.8rem;margin-top:0.3rem;"></div>

      <h3>Drawing Mode</h3>
      <label>
        <input type="radio" name="drawMode" value="assign" checked>
        Assign precincts
      </label>
      <label>
        <input type="radio" name="drawMode" value="erase">
        Unassign precincts
      </label>
      <label>
        <input type="checkbox" id="multiSelect"> Multi-select (future use)
      </label>

      <h3>District Colors</h3>
      <div id="districtColorLegend"></div>

      <h3>Display Options</h3>
      <div class="display-options">
        <label>
          <input type="checkbox" id="showCountyBorders"> Show County Borders
        </label>
        <label>
          <input type="checkbox" id="showPrecinctLines" checked> Show Precinct Lines
        </label>
        
        <label for="colorMode" style="margin-top:0.3rem;">Color Mode:</label>
        <select id="colorMode">
          <option value="district_set">District Set Color</option>
          <option value="district_lean">District Partisan Lean</option>
          <option value="precinct_lean">Precinct Partisan Lean</option>
        </select>
        
        <div id="colorLegend" class="color-legend">
          <!-- Legend will be populated by JavaScript -->
        </div>
      </div>

      <h3>Metrics</h3>
      <div id="metricsPanel">
        <p>Select a state and start assigning precincts to see metrics.</p>
      </div>

      <h3>Data Upload</h3>
      <p style="font-size:0.85rem;">
        Upload a ZIP containing the precinct shapefile components:
        <code>.shp, .shx, .dbf, .prj, .cpg</code>.
      </p>
      <form id="uploadForm" enctype="multipart/form-data">
        <label for="uploadState">State (FIPS or code):</label>
        <input type="text" id="uploadState" name="state" placeholder="NC or 37" required>

        <label for="precinctZip">Precinct Shapefile (ZIP):</label>
        <input type="file" id="precinctZip" name="precinctZip" accept=".zip" required>

        <button type="submit">Upload / Replace</button>
      </form>
      <div id="uploadProgressContainer" class="upload-progress-container">
        <div class="upload-progress-bar">
          <div class="progress-fill" id="uploadProgressFill"></div>
          <span class="progress-text" id="uploadProgressText">0%</span>
        </div>
      </div>
      <div id="uploadStatus"></div>

      <h3>Enhanced Data Import</h3>
      <p style="font-size:0.85rem;">
        Import precinct data directly from GeoJSON or merge CSV data.
        <strong>Includes built-in support for Redistricting Data Hub files!</strong>
      </p>
      <form id="enhancedImportForm" enctype="multipart/form-data">
        <label for="importState">State (FIPS or code):</label>
        <input type="text" id="importState" name="state" placeholder="NC or 37" required>

        <label for="importType">Import Type:</label>
        <select id="importType" name="import_type" required>
          <option value="">Select import type</option>
          <optgroup label="Standard Import">
            <option value="geojson">GeoJSON File</option>
            <option value="csv_merge">CSV Merge (with existing GeoJSON)</option>
          </optgroup>
          <optgroup label="Redistricting Data Hub">
            <option value="rdh_geojson">RDH GeoJSON (auto-maps fields)</option>
            <option value="rdh_csv">RDH CSV Merge (auto-maps fields)</option>
          </optgroup>
        </select>

        <div id="geojsonImportOptions" style="display:none;">
          <label for="geojsonFile">GeoJSON File:</label>
          <input type="file" id="geojsonFile" name="geojson_file" accept=".geojson,.json">
          
          <label for="idField">ID Field (optional):</label>
          <input type="text" id="idField" name="id_field" placeholder="id">
        </div>

        <div id="csvImportOptions" style="display:none;">
          <label for="csvFile">CSV File:</label>
          <input type="file" id="csvFile" name="csv_file" accept=".csv">
          
          <label for="joinField">Join Field in GeoJSON:</label>
          <input type="text" id="joinField" name="join_field" placeholder="id">
          
          <label for="csvKeyColumn">CSV Key Column:</label>
          <input type="text" id="csvKeyColumn" name="csv_key_column" placeholder="id">
        </div>

        <div id="rdhInfo" style="display:none;margin-top:0.5rem;padding:0.5rem;background:#e0f2fe;border-radius:4px;font-size:0.75rem;">
          <strong>RDH Auto-Mapping:</strong> Fields like UNIQUE_ID, GEOID20, TOTPOP, VAP, 
          G20PREDBID, G20PRERTRU are automatically recognized and mapped.
        </div>

        <details style="margin-top:0.5rem;">
          <summary style="cursor:pointer;font-size:0.85rem;">Advanced: Field Mapping</summary>
          <p style="font-size:0.75rem;margin:0.25rem 0;">
            JSON mapping from source field names to target field names.
          </p>
          <textarea id="fieldMapping" name="field_mapping" rows="3" 
            placeholder='{"PRECINCT_NAME": "name", "TOTAL_POP": "population"}'
            style="width:100%;font-size:0.75rem;"></textarea>
        </details>

        <button type="submit" style="margin-top:0.5rem;">Import Data</button>
      </form>
      <div id="importProgressContainer" class="upload-progress-container">
        <div class="upload-progress-bar">
          <div class="progress-fill" id="importProgressFill"></div>
          <span class="progress-text" id="importProgressText">0%</span>
        </div>
      </div>
      <div id="importStatus"></div>

      <h3>System Status</h3>
      <p style="font-size:0.8rem;">
        If something isn’t working, run the <a href="setup.php" target="_blank">Setup & Diagnostics</a>.
      </p>
    </section>

    <section class="map-panel">
      <div class="map-toolbar">
        <span>Click precincts to assign districts. Zoom with wheel, pan with Shift+drag.</span>
      </div>

      <!-- Leaflet map for basemap + precinct overlay -->
      <div id="leafletMap" style="width: 100%; height: 600px;"></div>

      <!-- Existing canvas map (used by map.js for district drawing) -->
      <canvas id="mapCanvas"></canvas>
      <div id="hoverInfo" class="hover-info"></div>
    </section>
  </main>

  <!-- Leaflet JS (no integrity so it is not blocked) -->
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

  <script src="public/js/map.js"></script>
  <script src="public/js/metrics.js"></script>
  <script src="public/js/automap.js"></script>
  <script src="public/js/storage.js"></script>
  <script src="public/js/app.js"></script>
</body>
</html>