<?php
require_once 'setup/SetupDatabase.php';

$db = SetupDatabase::connect();

echo "=== البنوك المستخرجة ===\n\n";

$stmt = $db->query('SELECT bank_name, occurrence_count FROM temp_banks ORDER BY occurrence_count DESC');
$banks = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "إجمالي البنوك: " . count($banks) . "\n\n";

foreach ($banks as $bank) {
    echo "• " . $bank['bank_name'] . " (تكرر " . $bank['occurrence_count'] . " مرة)\n";
}

echo "\n\n=== الموردين المستخرجين ===\n\n";

$stmt = $db->query('SELECT supplier_name, occurrence_count FROM temp_suppliers ORDER BY occurrence_count DESC');
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "إجمالي الموردين: " . count($suppliers) . "\n\n";

foreach ($suppliers as $supplier) {
    echo "• " . $supplier['supplier_name'] . " (تكرر " . $supplier['occurrence_count'] . " مرة)\n";
}
