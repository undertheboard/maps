<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$baseDir   = __DIR__;
$dataDir   = $baseDir . '/data';
$precDir   = $dataDir . '/precincts';
$plansDir  = $dataDir . '/plans';
$statesFile = $dataDir . '/states.json';

$steps = [];
$errors = [];
$warnings = [];

// PHP version
$phpVersion = PHP_VERSION;
if (version_compare($phpVersion, '7.4.0', '<')) {
    $errors[] = "PHP 7.4 or higher required. Current: $phpVersion";
} else {
    $steps[] = "PHP version OK: $phpVersion";
}

// Required extensions
$requiredExts = ['json', 'zip'];
foreach ($requiredExts as $ext) {
    if (!extension_loaded($ext)) {
        $errors[] = "Required PHP extension missing: $ext";
    } else {
        $steps[] = "PHP extension '$ext' loaded.";
    }
}

// Check php-shapefile library
$shpLib = __DIR__ . '/lib/php-shapefile/src/Shapefile/ShapefileAutoloader.php';
if (!file_exists($shpLib)) {
    $errors[] = "php-shapefile library not found at $shpLib. Upload gasparesganga/php-shapefile src/Shapefile there.";
} else {
    $steps[] = "php-shapefile library found.";
}

// data directory
if (!is_dir($dataDir)) {
    if (!mkdir($dataDir, 0775, true)) {
        $errors[] = "Could not create data directory at '$dataDir'. Check permissions.";
    } else {
        $steps[] = "Created data directory: $dataDir";
    }
} else {
    $steps[] = "data directory exists: $dataDir";
}

// precincts / plans
if (!is_dir($precDir)) {
    if (!mkdir($precDir, 0775, true)) {
        $errors[] = "Could not create precincts directory '$precDir'.";
    } else {
        $steps[] = "Created precincts directory: $precDir";
    }
} else {
    $steps[] = "precincts directory exists: $precDir";
}

if (!is_dir($plansDir)) {
    if (!mkdir($plansDir, 0775, true)) {
        $errors[] = "Could not create plans directory '$plansDir'.";
    } else {
        $steps[] = "Created plans directory: $plansDir";
    }
} else {
    $steps[] = "plans directory exists: $plansDir";
}

// states.json
if (!file_exists($statesFile)) {
    $defaultStates = [
        ["code" => "06", "abbr" => "CA", "name" => "California",      "defaultNumDistricts" => 52],
        ["code" => "48", "abbr" => "TX", "name" => "Texas",           "defaultNumDistricts" => 38],
        ["code" => "36", "abbr" => "NY", "name" => "New York",        "defaultNumDistricts" => 26],
        ["code" => "12", "abbr" => "FL", "name" => "Florida",         "defaultNumDistricts" => 28],
        ["code" => "37", "abbr" => "NC", "name" => "North Carolina",  "defaultNumDistricts" => 14]
    ];

    if (file_put_contents($statesFile, json_encode($defaultStates, JSON_PRETTY_PRINT)) === false) {
        $errors[] = "Could not create states.json at '$statesFile'.";
    } else {
        $steps[] = "Created default states.json with CA, TX, NY, FL, NC.";
    }
} else {
    $json = file_get_contents($statesFile);
    $arr = json_decode($json, true);
    if (!is_array($arr)) {
        $errors[] = "states.json exists but is not valid JSON. Please fix or delete it.";
    } else {
        $steps[] = "states.json exists and is valid.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Redistricting Tool - Setup & Diagnostics</title>
  <link rel="stylesheet" href="public/css/style.css">
  <style>
    body { background:#0b1726; color:#e5e7eb; }
    .setup-container {
      max-width: 900px;
      margin: 1.5rem auto;
      background:#111827;
      border-radius:8px;
      padding:1rem 1.25rem;
      border:1px solid #374151;
    }
    h1 { font-size:1.5rem; margin-bottom:0.5rem; }
    .status-ok { color:#22c55e; }
    .status-error { color:#f97373; }
    .status-warning { color:#facc15; }
    ul { margin-left:1.2rem; margin-top:0.25rem; }
    a.button {
      display:inline-block;
      padding:0.35rem 0.75rem;
      border-radius:4px;
      background:#2563eb;
      color:#f9fafb;
      text-decoration:none;
      border:1px solid #1d4ed8;
      margin-top:0.5rem;
    }
    a.button:hover { background:#1d4ed8; }
  </style>
</head>
<body>
<div class="setup-container">
  <h1>Redistricting Tool - Setup & Diagnostics</h1>

  <h2>Summary</h2>
  <?php if ($errors): ?>
    <p class="status-error"><strong>Blocking issues detected:</strong></p>
    <ul>
      <?php foreach ($errors as $e): ?>
        <li><?php echo htmlspecialchars($e, ENT_QUOTES, 'UTF-8'); ?></li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="status-ok"><strong>No blocking errors detected.</strong></p>
  <?php endif; ?>

  <?php if ($warnings): ?>
    <p class="status-warning"><strong>Warnings:</strong></p>
    <ul>
      <?php foreach ($warnings as $w): ?>
        <li><?php echo nl2br(htmlspecialchars($w, ENT_QUOTES, 'UTF-8')); ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <h2>Checks performed</h2>
  <ul>
    <?php foreach ($steps as $s): ?>
      <li><?php echo htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); ?></li>
    <?php endforeach; ?>
  </ul>

  <h2>Next steps</h2>
  <ol>
    <li>Ensure <code>lib/php-shapefile/src/Shapefile</code> exists with the library files.</li>
    <li>Edit <code>data/states.json</code> if you want all 50 states.</li>
    <li>From the main app, upload each state's shapefile ZIP under "Data Upload".</li>
  </ol>

  <?php if (!$errors): ?>
    <p class="status-ok">You can now use the app:</p>
    <p><a href="index.php" class="button">Go to Application</a></p>
  <?php else: ?>
    <p class="status-error">Fix the errors above, then refresh this page.</p>
  <?php endif; ?>
</div>
</body>
</html>