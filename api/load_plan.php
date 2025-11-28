<?php
header('Content-Type: application/json');
ini_set('display_errors', 1);
error_reporting(E_ALL);

$stateParam = $_GET['state'] ?? '';
$stateParam = trim($stateParam);
$listOnly = isset($_GET['list']);
$planId = $_GET['planId'] ?? '';

if ($stateParam === '') {
    echo json_encode(['error' => 'Missing state parameter.']);
    exit;
}

$stateCode = preg_replace('/[^0-9A-Za-z]/', '', $stateParam);
$plansDir = __DIR__ . "/../data/plans/{$stateCode}";
if (!is_dir($plansDir)) {
    if ($listOnly) {
        echo json_encode(['plans' => []]);
        exit;
    } else {
        echo json_encode(['error' => 'No plans for this state yet.']);
        exit;
    }
}

if ($listOnly) {
    $files = glob($plansDir . '/*.json') ?: [];
    $plans = [];
    foreach ($files as $file) {
        $content = json_decode(file_get_contents($file), true);
        if (!$content) continue;
        $plans[] = [
            'planId' => $content['planId'] ?? basename($file, '.json'),
            'name'   => $content['name'] ?? '(untitled)'
        ];
    }
    echo json_encode(['plans' => $plans]);
    exit;
}

if ($planId === '') {
    echo json_encode(['error' => 'Missing planId parameter.']);
    exit;
}

$filename = $plansDir . '/' . basename($planId) . '.json';
if (!file_exists($filename)) {
    echo json_encode(['error' => 'Plan not found.']);
    exit;
}

$plan = json_decode(file_get_contents($filename), true);
if (!$plan) {
    echo json_encode(['error' => 'Invalid plan file.']);
    exit;
}

echo json_encode(['plan' => $plan]);