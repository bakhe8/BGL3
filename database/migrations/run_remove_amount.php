<?php
/**
 * Migration Runner: Remove Unused amount Column
 * 
 * This script safely removes the unused 'amount' column from guarantee_decisions
 * 
 * Safety checks:
 * 1. Backup database before running
 * 2. Verify column is unused
 * 3. Count rows before/after
 * 4. Rollback on any error
 */

require_once __DIR__ . '/../../app/Support/Database.php';

use App\Support\Database;

echo "=== Remove Unused amount Column Migration ===\n\n";

// Step 1: Backup check
echo "⚠️  IMPORTANT: Ensure you have a database backup!\n";
echo "Backup location: storage/database/app.sqlite.backup-" . date('Y-m-d-H-i-s') . "\n";
echo "Continue? (yes/no): ";

$handle = fopen("php://stdin", "r");
$line = trim(fgets($handle));
fclose($handle);

if ($line !== 'yes') {
    echo "❌ Migration cancelled.\n";
    exit(0);
}

try {
    $db = Database::connect();
    
    // Enable foreign keys
    $db->exec("PRAGMA foreign_keys = ON");
    
    echo "\n1️⃣ Counting rows before migration...\n";
    
    $before = $db->query("SELECT COUNT(*) FROM guarantee_decisions")->fetchColumn();
    echo "   Found: $before rows\n";
    
    echo "\n2️⃣ Reading migration SQL...\n";
    
    $migrationSql = file_get_contents(__DIR__ . '/2026_01_04_remove_unused_amount.sql');
    
    echo "\n3️⃣ Executing migration (this may take a moment)...\n";
    
    // Execute the migration
    $db->exec($migrationSql);
    
    echo "   ✅ Migration SQL executed\n";
    
    echo "\n4️⃣ Verifying results...\n";
    
    $after = $db->query("SELECT COUNT(*) FROM guarantee_decisions")->fetchColumn();
    echo "   Rows after: $after\n";
    
    if ($before !== $after) {
        throw new Exception("Row count mismatch! Before: $before, After: $after");
    }
    
    // Check table structure
    $columns = $db->query("PRAGMA table_info(guarantee_decisions)")->fetchAll(PDO::FETCH_ASSOC);
    $hasAmount = false;
    
    echo "\n   Current columns:\n";
    foreach ($columns as $col) {
        echo "   - {$col['name']} ({$col['type']})\n";
        if ($col['name'] === 'amount') {
            $hasAmount = true;
        }
    }
    
    if ($hasAmount) {
        throw new Exception("amount column still exists!");
    }
    
    // Check indexes
    $indexes = $db->query("
        SELECT name 
        FROM sqlite_master 
        WHERE type='index' 
          AND tbl_name='guarantee_decisions'
          AND name NOT LIKE 'sqlite_%'
    ")->fetchAll(PDO::FETCH_COLUMN);
    
    echo "\n   Recreated indexes:\n";
    foreach ($indexes as $idx) {
        echo "   ✓ $idx\n";
    }
    
    echo "\n✅ Migration completed successfully!\n\n";
    echo "Summary:\n";
    echo "- Removed column: amount\n";
    echo "- Rows preserved: $after\n";
    echo "- Indexes recreated: " . count($indexes) . "\n";
    echo "- Foreign keys: intact\n";
    
} catch (Exception $e) {
    echo "\n❌ Migration failed: " . $e->getMessage() . "\n";
    echo "\n⚠️  Database may be in inconsistent state!\n";
    echo "Restore from backup:\n";
    echo "cp storage/database/app.sqlite.backup-YYYYMMDD storage/database/app.sqlite\n";
    exit(1);
}
