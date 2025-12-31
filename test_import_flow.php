<?php
// Simulate POST to parse-paste.php
$url = 'http://localhost:8001/api/parse-paste.php';
$data = ['text' => "G-TEST-009 Supplier-Test Bank-Test 5000 SAR 31-12-2025 PO-12345"];

$options = [
    'http' => [
        'header'  => "Content-type: application/json\r\n",
        'method'  => 'POST',
        'content' => json_encode($data),
        'ignore_errors' => true // Capture non-200 responses
    ]
];

$context  = stream_context_create($options);
$result = file_get_contents($url, false, $context);
$headers = $http_response_header;

echo "Response Code: " . $headers[0] . "\n";
echo "Response Body: " . $result . "\n";

// Check DB immediately
require_once __DIR__ . '/app/Support/autoload.php';
$db = \App\Support\Database::connect();
$stmt = $db->query("SELECT id FROM guarantees WHERE guarantee_number = 'G-TEST-009'");
$id = $stmt->fetchColumn();

if ($id) {
    echo "Guarantee ID: $id\n";
    $hist = $db->query("SELECT event_type FROM guarantee_history WHERE guarantee_id = $id")->fetchAll(PDO::FETCH_COLUMN);
    echo "Events: " . implode(', ', $hist) . "\n";
} else {
    echo "Guarantee NOT created.\n";
}
