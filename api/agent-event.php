<?php
// Lightweight event bridge: receives browser sensor events and persists to knowledge.db
// Keeps logic minimal to avoid impacting runtime.

// Allow GET for basic health/status; POST for event ingest
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'message' => 'Agent event bridge ready']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
if (!$raw) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Empty payload']);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

$eventType = $data['event_type'] ?? null;
if (!$eventType) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing event_type']);
    exit;
}

$ts = isset($data['timestamp']) ? floatval($data['timestamp']) : microtime(true);
$session = substr((string)($data['session'] ?? ''), 0, 64);
$route = substr((string)($data['route'] ?? ''), 0, 255);
$method = substr((string)($data['method'] ?? ''), 0, 16);
$target = substr((string)($data['target'] ?? ''), 0, 255);
$payload = isset($data['payload']) ? json_encode($data['payload']) : null;
$status = isset($data['status']) ? intval($data['status']) : null;
$latency = isset($data['latency_ms']) ? floatval($data['latency_ms']) : null;
$error = isset($data['error']) ? substr((string)$data['error'], 0, 500) : null;

$dbPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.bgl_core' . DIRECTORY_SEPARATOR . 'brain' . DIRECTORY_SEPARATOR . 'knowledge.db';

try {
    $db = new SQLite3($dbPath);
    // Ensure table exists (idempotent)
    $db->exec("
        CREATE TABLE IF NOT EXISTS runtime_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp REAL NOT NULL,
            session TEXT,
            event_type TEXT NOT NULL,
            route TEXT,
            method TEXT,
            target TEXT,
            payload TEXT,
            status INTEGER,
            latency_ms REAL,
            error TEXT
        );
    ");

    $stmt = $db->prepare("
        INSERT INTO runtime_events (timestamp, session, event_type, route, method, target, payload, status, latency_ms, error)
        VALUES (:ts, :session, :type, :route, :method, :target, :payload, :status, :latency, :error)
    ");
    $stmt->bindValue(':ts', $ts, SQLITE3_FLOAT);
    $stmt->bindValue(':session', $session);
    $stmt->bindValue(':type', $eventType);
    $stmt->bindValue(':route', $route);
    $stmt->bindValue(':method', $method);
    $stmt->bindValue(':target', $target);
    $stmt->bindValue(':payload', $payload);
    $stmt->bindValue(':status', $status, SQLITE3_INTEGER);
    $stmt->bindValue(':latency', $latency, SQLITE3_FLOAT);
    $stmt->bindValue(':error', $error);
    $stmt->execute();

    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Storage failure', 'detail' => $e->getMessage()]);
    exit;
}
