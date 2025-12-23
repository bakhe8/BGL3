<?php
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

// Get first guarantee
$stmt = $db->query('SELECT * FROM guarantees LIMIT 1');
$guarantee = $stmt->fetch(PDO::FETCH_ASSOC);

echo "=== Guarantee Data ===\n";
echo "ID: " . $guarantee['id'] . "\n";
echo "Number: " . $guarantee['guarantee_number'] . "\n";
echo "Import Source: " . $guarantee['import_source'] . "\n";
echo "\nRaw Data:\n";
$rawData = json_decode($guarantee['raw_data'], true);
print_r($rawData);
