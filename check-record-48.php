<?php
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

echo "Checking record ID=48 (the one showing in V3/):\n";
echo str_repeat("=", 80) . "\n\n";

$stmt = $db->prepare('SELECT * FROM guarantees WHERE id = ?');
$stmt->execute([48]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row) {
    echo "ID: " . $row['id'] . "\n";
    echo "Guarantee Number: " . $row['guarantee_number'] . "\n";
    echo "Import Source: " . $row['import_source'] . "\n";
    echo "Imported At: " . $row['imported_at'] . "\n\n";
    
    echo "Raw Data (JSON):\n";
    echo $row['raw_data'] . "\n\n";
    
    echo "Decoded:\n";
    $raw = json_decode($row['raw_data'], true);
    print_r($raw);
} else {
    echo "Record ID=48 not found!\n";
}
