<?php
/**
 * Phase 5: Pilot Metrics API
 * 
 * Simple logging endpoint - stores metrics to JSON file
 * NO DATABASE CHANGES - just file-based logging
 */

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Validate required fields
$required = ['action', 'supplier_id', 'supplier_name', 'timestamp'];
foreach ($required as $field) {
    if (!isset($data[$field])) {
        http_response_code(400);
        echo json_encode(['error' => "Missing field: $field"]);
        exit;
    }
}

// Validate action
if (!in_array($data['action'], ['confirm', 'reject', 'cancel'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
    exit;
}

// Store in JSON file (simple file-based storage)
$logFile = __DIR__ . '/../storage/pilot_metrics.json';

// Ensure storage directory exists
$storageDir = dirname($logFile);
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0755, true);
}

// Load existing data
$metrics = [];
if (file_exists($logFile)) {
    $existing = file_get_contents($logFile);
    $metrics = json_decode($existing, true) ?: [];
}

// Append new entry
$metrics[] = array_merge($data, [
    'logged_at' => date('Y-m-d H:i:s')
]);

// Save back
file_put_contents($logFile, json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// Response
echo json_encode([
    'success' => true,
    'total_logged' => count($metrics)
]);
