<?php

/**
 * Backfill normalized_supplier_name in learning_confirmations
 * 
 * Phase 6: Database cleanup
 * 
 * This script populates the new normalized_supplier_name column
 * with normalized versions of existing raw_supplier_name values.
 * 
 * Run once after migration: 2026_01_03_add_normalized_to_learning.sql
 * 
 * Usage:
 * php database/scripts/backfill_normalized_names.php
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Support\Database;
use App\Support\Normalizer;

echo "========================================\n";
echo "Backfilling normalized_supplier_name\n";
echo "========================================\n\n";

$pdo = Database::connect();
$normalizer = new Normalizer();

// Get all unique raw names
$stmt = $pdo->query(
    'SELECT DISTINCT raw_supplier_name FROM learning_confirmations WHERE raw_supplier_name IS NOT NULL'
);
$rawNames = $stmt->fetchAll(PDO::FETCH_COLUMN);

$total = count($rawNames);
echo "Found {$total} unique raw names to normalize...\n\n";

$updated = 0;
$errors = 0;

foreach ($rawNames as $index => $rawName) {
    try {
        $normalized = $normalizer->normalizeSupplierName($rawName);
        
        $update = $pdo->prepare('
            UPDATE learning_confirmations 
            SET normalized_supplier_name = :normalized 
            WHERE raw_supplier_name = :raw
        ');
        
        $update->execute([
            'normalized' => $normalized,
            'raw' => $rawName
        ]);
        
        $updated += $update->rowCount();
        
        // Progress indicator
        if (($index + 1) % 100 === 0) {
            $progress = round((($index + 1) / $total) * 100);
            echo "Progress: {$progress}% ({$updated} rows updated)\n";
        }
        
    } catch (\Exception $e) {
        $errors++;
        echo "Error normalizing '{$rawName}': " . $e->getMessage() . "\n";
    }
}

echo "\n========================================\n";
echo "Backfill Complete!\n";
echo "========================================\n";
echo "Total unique names: {$total}\n";
echo "Rows updated: {$updated}\n";
echo "Errors: {$errors}\n";
echo "\n";

if ($errors === 0) {
    echo "✅ All names normalized successfully!\n";
} else {
    echo "⚠️  Some errors occurred. Review above output.\n";
}
