<?php
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

echo "Searching for supplier='15' and bank='Integrated Gulf Biosystems LLC':\n";
echo str_repeat("=", 80) . "\n\n";

$stmt = $db->query('SELECT id, guarantee_number, raw_data FROM guarantees ORDER BY imported_at DESC');

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $raw = json_decode($row['raw_data'], true);
    
    if (isset($raw['supplier']) && $raw['supplier'] == '15') {
        echo "✅ FOUND!\n";
        echo "ID: " . $row['id'] . "\n";
        echo "Guarantee Number: " . $row['guarantee_number'] . "\n";
        echo "Supplier: " . $raw['supplier'] . "\n";
        echo "Bank: " . ($raw['bank'] ?? 'N/A') . "\n";
        echo "Amount: " . ($raw['amount'] ?? 'N/A') . "\n";
        echo "\nFull raw_data:\n";
        print_r($raw);
        break;
    }
}

echo "\n\n" . str_repeat("=", 80) . "\n";
echo "First record in database (most recent):\n";
$stmt = $db->query('SELECT id, guarantee_number, raw_data FROM guarantees ORDER BY imported_at DESC LIMIT 1');
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo "ID: " . $row['id'] . "\n";
echo "Guarantee Number: " . $row['guarantee_number'] . "\n";
$raw = json_decode($row['raw_data'], true);
echo "Supplier: " . ($raw['supplier'] ?? 'N/A') . "\n";
echo "Bank: " . ($raw['bank'] ?? 'N/A') . "\n";
