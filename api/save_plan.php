<?php
header('Content-Type: application/json');
ini_set('display_errors', 1);
error_reporting(E_ALL);

$raw = file_get_contents('php://input');
if (!$raw) {
    echo json_encode(['error' => 'No data sent.']);
    exit;
}
$data = json_decode($raw, true);
if (!$data || !isset($data['state'])) {
    echo json_encode(['error' => 'Invalid plan data.']);
    exit;
}

$state = preg_replace('/[^0-9A-Za-z]/', '', $data['state']);
if ($state === '') {
    echo json_encode(['error' => 'Invalid state in plan.']);
    exit;
}

$plansDir = __DIR__ . "/../data/plans/{$state}";
if (!is_dir($plansDir) && !mkdir($plansDir, 0775, true)) {
    echo json_encode(['error' => 'Failed to create plans directory.']);
    exit;
}

$planId = $data['planId'] ?? null;
if (!$planId) {
    $planId = 'plan_' . time();
    $data['planId'] = $planId;
}
$data['lastUpdated'] = date('c');

$filename = $plansDir . '/' . basename($planId) . '.json';
if (file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT)) === false) {
    echo json_encode(['error' => 'Failed to save plan.']);
    exit;
}

echo json_encode(['ok' => true, 'planId' => $planId]);