<?php
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

// Get raw data from database for guarantee_id = 1
$stmt = $db->prepare('SELECT * FROM guarantees WHERE id = ?');
$stmt->execute([1]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

echo "=== RAW DATABASE ROW FOR ID=1 ===\n\n";
echo "ID: " . $row['id'] . "\n";
echo "guarantee_number: " . $row['guarantee_number'] . "\n";
echo "import_source: " . $row['import_source'] . "\n";
echo "imported_at: " . $row['imported_at'] . "\n";
echo "\n=== RAW_DATA (JSON) ===\n";
echo $row['raw_data'] . "\n\n";

echo "=== DECODED RAW_DATA ===\n";
$rawData = json_decode($row['raw_data'], true);
print_r($rawData);

echo "\n\n=== EXPECTED VALUES ===\n";
echo "supplier: " . ($rawData['supplier'] ?? 'NOT FOUND') . "\n";
echo "bank: " . ($rawData['bank'] ?? 'NOT FOUND') . "\n";
echo "amount: " . ($rawData['amount'] ?? 'NOT FOUND') . "\n";
echo "expiry_date: " . ($rawData['expiry_date'] ?? 'NOT FOUND') . "\n";
echo "issue_date: " . ($rawData['issue_date'] ?? 'NOT FOUND') . "\n";
echo "contract_number: " . ($rawData['contract_number'] ?? 'NOT FOUND') . "\n";
echo "type: " . ($rawData['type'] ?? 'NOT FOUND') . "\n";
