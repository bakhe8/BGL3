<?php
// Simulate POST to create-guarantee.php
$url = 'http://localhost:8001/api/create-guarantee.php';
$data = [
    'guarantee_number' => 'G-MANUAL-005',
    'supplier' => 'Manual Supplier',
    'bank' => 'Manual Bank',
    'amount' => 5000,
    'expiry_date' => '2025-12-31',
    'contract_number' => 'CTR-999',
    'type' => 'Initial'
];

$options = [
    'http' => [
        'header'  => "Content-type: application/json\r\n",
        'method'  => 'POST',
        'content' => json_encode($data),
        'ignore_errors' => true
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
$stmt = $db->query("SELECT id FROM guarantees WHERE guarantee_number = 'G-MANUAL-005'");
$id = $stmt->fetchColumn();

if ($id) {
    echo "Guarantee ID: $id\n";
    $hist = $db->query("SELECT event_type FROM guarantee_history WHERE guarantee_id = $id")->fetchAll(PDO::FETCH_COLUMN);
    echo "Events: " . implode(', ', $hist) . "\n";
} else {
    echo "Guarantee NOT created.\n";
}
