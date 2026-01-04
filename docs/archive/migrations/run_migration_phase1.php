<?php
/**
 * Run SQLite Migration - Phase 1 (Direct)
 */

require_once __DIR__ . '/../app/Support/Database.php';

use App\Support\Database;

echo "=== Phase 1: Schema Migration (SQLite) ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $db = Database::connect();
    
    echo "Step 1: Adding active_action column...\n";
    $db->exec("ALTER TABLE guarantee_decisions ADD COLUMN active_action TEXT NULL");
    echo "  ✅ Column added\n\n";
    
    echo "Step 2: Adding active_action_set_at column...\n";
    $db->exec("ALTER TABLE guarantee_decisions ADD COLUMN active_action_set_at TEXT NULL");
    echo "  ✅ Column added\n\n";
    
    echo "Step 3: Creating index...\n";
    $db->exec("CREATE INDEX IF NOT EXISTS idx_active_action ON guarantee_decisions(active_action)");
    echo "  ✅ Index created\n\n";
    
    // Verification
    echo "Verification:\n";
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total_records,
            SUM(CASE WHEN active_action IS NULL THEN 1 ELSE 0 END) as null_actions
        FROM guarantee_decisions
    ");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "  Total Records: {$result['total_records']}\n";
    echo "  NULL Actions: {$result['null_actions']}\n\n";
    
    echo "✅ Phase 1 Migration Complete!\n\n";
    echo "Next: Run Phase 2 backfill\n";
    echo "  php migrations/2025_12_31_backfill_active_action_sqlite.php\n";
    
} catch (Exception $e) {
    echo "\n❌ Migration Failed!\n";
    echo "Error: " . $e->getMessage() . "\n";
    
    // Check if column already exists
    if (str_contains($e->getMessage(), 'duplicate column name')) {
        echo "\nℹ️ Columns may already exist. Proceeding to backfill...\n";
        exit(0);
    }
    
    exit(1);
}
