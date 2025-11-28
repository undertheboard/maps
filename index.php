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
      <div id="uploadStatus"></div>

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
  <script src="public/js/storage.js"></script>
  <script src="public/js/app.js"></script>
</body>
</html>