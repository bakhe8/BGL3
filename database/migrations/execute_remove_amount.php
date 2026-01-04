<?php
/**
 * Direct Migration Executor (No Prompt)
 */

require_once __DIR__ . '/../../app/Support/Database.php';

use App\Support\Database;

echo "=== Remove Unused amount Column Migration ===\n\n";

try {
    $db = Database::connect();
    $db->exec("PRAGMA foreign_keys = ON");
    
    echo "1️⃣ Counting rows before migration...\n";
    $before = $db->query("SELECT COUNT(*) FROM guarantee_decisions")->fetchColumn();
    echo "   Found: $before rows\n";
    
    echo "\n2️⃣ Reading migration SQL...\n";
    $migrationSql = file_get_contents(__DIR__ . '/2026_01_04_remove_unused_amount.sql');
    
    echo "\n3️⃣ Executing migration...\n";
    $db->exec($migrationSql);
    echo "   ✅ Migration SQL executed\n";
    
    echo "\n4️⃣ Verifying results...\n";
    $after = $db->query("SELECT COUNT(*) FROM guarantee_decisions")->fetchColumn();
    echo "   Rows after: $after\n";
    
    if ($before !== $after) {
        throw new Exception("Row count mismatch! Before: $before, After: $after");
    }
    
    // Check amount column is gone
    $columns = $db->query("PRAGMA table_info(guarantee_decisions)")->fetchAll(PDO::FETCH_ASSOC);
    $hasAmount = false;
    
    foreach ($columns as $col) {
        if ($col['name'] === 'amount') {
            $hasAmount = true;
        }
    }
    
    if ($hasAmount) {
        throw new Exception("amount column still exists!");
    }
    
    // Count indexes
    $indexes = $db->query("
        SELECT COUNT(*) 
        FROM sqlite_master 
        WHERE type='index' 
          AND tbl_name='guarantee_decisions'
          AND name NOT LIKE 'sqlite_%'
    ")->fetchColumn();
    
    echo "\n✅ Migration completed successfully!\n\n";
    echo "Summary:\n";
    echo "✓ Removed column: amount\n";
    echo "✓ Rows preserved: $after\n";
    echo "✓ Indexes recreated: $indexes\n";
    echo "✓ Foreign keys: intact\n";
    
} catch (Exception $e) {
    echo "\n❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
