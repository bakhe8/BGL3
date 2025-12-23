<?php
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;
use App\Repositories\GuaranteeRepository;

$db = Database::connect();
$guaranteeRepo = new GuaranteeRepository($db);

echo "Testing find() method:\n";
echo str_repeat("=", 80) . "\n\n";

$guarantee = $guaranteeRepo->find(1);

if ($guarantee) {
    echo "Found guarantee with ID=1:\n";
    echo "ID: " . $guarantee->id . "\n";
    echo "Guarantee Number: " . $guarantee->guaranteeNumber . "\n";
    echo "\nRaw Data:\n";
    print_r($guarantee->rawData);
} else {
    echo "NOT FOUND!\n";
}

echo "\n\n" . str_repeat("=", 80) . "\n";
echo "Direct database query:\n";
$stmt = $db->prepare('SELECT * FROM guarantees WHERE id = ?');
$stmt->execute([1]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row) {
    echo "Found in database:\n";
    echo "ID: " . $row['id'] . "\n";
    echo "Guarantee Number: " . $row['guarantee_number'] . "\n";
    echo "Raw Data: " . $row['raw_data'] . "\n";
} else {
    echo "NOT FOUND in database!\n";
}
