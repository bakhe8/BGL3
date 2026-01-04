<?php
// Test script to verify rejection logging works

require __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

echo "=== Testing Rejection Logging System ===\n\n";

// Check total rejections before test
$stmt = $db->query("
    SELECT COUNT(*) as count
    FROM learning_confirmations
    WHERE action = 'reject'
");
$beforeCount = $stmt->fetchColumn();

echo "Rejections in database BEFORE test: $beforeCount\n\n";

// Show recent confirmations and rejections
echo "=== Recent Learning Activity (last 10) ===\n";
$stmt = $db->query("
    SELECT 
        lc.id,
        lc.action,
        lc.raw_supplier_name,
        s.official_name as supplier,
        lc.confidence,
        lc.created_at
    FROM learning_confirmations lc
    LEFT JOIN suppliers s ON lc.supplier_id = s.id
    ORDER BY lc.id DESC
    LIMIT 10
");
$recent = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($recent as $row) {
    $action = $row['action'] === 'confirm' ? '✅' : '❌';
    echo "$action ID {$row['id']}: {$row['action']} - Pattern: \"{$row['raw_supplier_name']}\" → {$row['supplier']} ({$row['confidence']}%) at {$row['created_at']}\n";
}

echo "\n=== Instructions for Testing ===\n";
echo "1. Navigate to a pending record with suggestions\n";
echo "2. Note the TOP suggestion\n";
echo "3. Choose a DIFFERENT supplier\n";
echo "4. Save the decision\n";
echo "5. Run this script again to see if rejection was logged\n";
