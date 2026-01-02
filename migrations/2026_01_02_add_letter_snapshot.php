<?php
/**
 * Migration: Add letter_snapshot to guarantee_history
 * Date: 2026-01-02
 * Purpose: Store immutable letter data with each action event
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;

try {
    $db = Database::connect();
    
    echo "=== Migration: Add letter_snapshot column ===\n";
    echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";
    
    // Check if column already exists
    $checkStmt = $db->query("PRAGMA table_info(guarantee_history)");
    $columns = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
    $columnExists = false;
    
    foreach ($columns as $col) {
        if ($col['name'] === 'letter_snapshot') {
            $columnExists = true;
            break;
        }
    }
    
    if ($columnExists) {
        echo "⚠️  Column 'letter_snapshot' already exists. Skipping.\n";
    } else {
        echo "Adding 'letter_snapshot' column to guarantee_history table...\n";
        
        $db->exec("
            ALTER TABLE guarantee_history 
            ADD COLUMN letter_snapshot TEXT NULL
        ");
        
        echo "✅ Column added successfully\n";
    }
    
    echo "\n=== Migration Complete ===\n";
    echo "Finished at: " . date('Y-m-d H:i:s') . "\n";
    
} catch (Exception $e) {
    echo "❌ Migration Failed: " . $e->getMessage() . "\n";
    exit(1);
}
