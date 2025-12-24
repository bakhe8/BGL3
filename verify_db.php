<?php
require __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

echo "=== Database Verification ===\n\n";

// Check supplier_alternative_names
try {
    $count = $db->query('SELECT COUNT(*) FROM supplier_alternative_names')->fetchColumn();
    echo "✓ supplier_alternative_names: $count records\n";
    
    if ($count > 0) {
        $sample = $db->query('SELECT * FROM supplier_alternative_names LIMIT 3')->fetchAll(PDO::FETCH_ASSOC);
        echo "  Sample data:\n";
        foreach ($sample as $row) {
            echo "    - {$row['alternative_name']} -> supplier_id: {$row['supplier_id']}\n";
        }
    }
} catch (Exception $e) {
    echo "✗ supplier_alternative_names: " . $e->getMessage() . "\n";
}

echo "\n";

// Check bank_alternative_names
try {
    $count = $db->query('SELECT COUNT(*) FROM bank_alternative_names')->fetchColumn();
    echo "✓ bank_alternative_names: $count records\n";
} catch (Exception $e) {
    echo "✗ bank_alternative_names: Table doesn't exist\n";
    echo "  Need to create this table\n";
}

echo "\n";

// Check suppliers table
try {
    $count = $db->query('SELECT COUNT(*) FROM suppliers')->fetchColumn();
    echo "✓ suppliers: $count records\n";
    
    $sample = $db->query('SELECT id, official_name, normalized_name FROM suppliers LIMIT 3')->fetchAll(PDO::FETCH_ASSOC);
    echo "  Sample data:\n";
    foreach ($sample as $row) {
        echo "    - ID {$row['id']}: {$row['official_name']} (normalized: {$row['normalized_name']})\n";
    }
} catch (Exception $e) {
    echo "✗ suppliers: " . $e->getMessage() . "\n";
}

echo "\n";

// Check banks table
try {
    $count = $db->query('SELECT COUNT(*) FROM banks')->fetchColumn();
    echo "✓ banks: $count records\n";
    
    $sample = $db->query('SELECT id, official_name, normalized_name FROM banks LIMIT 3')->fetchAll(PDO::FETCH_ASSOC);
    echo "  Sample data:\n";
    foreach ($sample as $row) {
        echo "    - ID {$row['id']}: {$row['official_name']} (normalized: {$row['normalized_name']})\n";
    }
} catch (Exception $e) {
    echo "✗ banks: " . $e->getMessage() . "\n";
}
