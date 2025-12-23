<?php
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

// Get the record that shows when opening V3/ without ?id=
$stmt = $db->query('SELECT id, guarantee_number, raw_data FROM guarantees ORDER BY imported_at DESC LIMIT 1');
$row = $stmt->fetch(PDO::FETCH_ASSOC);

echo "Record shown at http://localhost:8000/V3/ (Incognito):\n";
echo str_repeat("=", 80) . "\n\n";

echo "ID: " . $row['id'] . "\n";
echo "Guarantee Number: " . $row['guarantee_number'] . "\n\n";

echo "Raw Data (JSON):\n";
echo $row['raw_data'] . "\n\n";

echo str_repeat("=", 80) . "\n";
echo "Decoded:\n";
$raw = json_decode($row['raw_data'], true);
print_r($raw);

echo "\n" . str_repeat("=", 80) . "\n";
echo "Field Mapping:\n";
echo str_repeat("=", 80) . "\n";

foreach ($raw as $key => $value) {
    echo sprintf("%-20s : %s\n", $key, $value);
}
