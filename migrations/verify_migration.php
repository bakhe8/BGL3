<?php
/**
 * Verify Migration Results
 */

require_once __DIR__ . '/../app/Support/Database.php';

use App\Support\Database;

echo "=== Migration Verification ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $db = Database::connect();
    
    // Check schema
    echo "1. Schema Check:\n";
    echo str_repeat('-', 60) . "\n";
    
    $pragma = $db->query("PRAGMA table_info(guarantee_decisions)");
    $columns = $pragma->fetchAll(PDO::FETCH_ASSOC);
    
    $hasActiveAction = false;
    $hasActiveActionSetAt = false;
    
    foreach ($columns as $col) {
        if ($col['name'] === 'active_action') {
            $hasActiveAction = true;
            echo "  ✅ Column 'active_action' exists (Type: {$col['type']})\n";
        }
        if ($col['name'] === 'active_action_set_at') {
            $hasActiveActionSetAt = true;
            echo "  ✅ Column 'active_action_set_at' exists (Type: {$col['type']})\n";
        }
    }
    
    if (!$hasActiveAction || !$hasActiveActionSetAt) {
        echo "  ❌ Migration incomplete!\n";
        exit(1);
    }
    
    // Check index
    echo "\n2. Index Check:\n";
    echo str_repeat('-', 60) . "\n";
    
    $indexes = $db->query("PRAGMA index_list(guarantee_decisions)");
    $indexList = $indexes->fetchAll(PDO::FETCH_ASSOC);
    
    $hasIndex = false;
    foreach ($indexList as $idx) {
        if ($idx['name'] === 'idx_active_action') {
            $hasIndex = true;
            echo "  ✅ Index 'idx_active_action' exists\n";
        }
    }
    
    if (!$hasIndex) {
        echo "  ⚠️ Index not found (may not be critical)\n";
    }
    
    // Check data distribution
    echo "\n3. Data Distribution:\n";
    echo str_repeat('-', 60) . "\n";
    
    $dist = $db->query("
        SELECT 
            status,
            active_action,
            COUNT(*) as count
        FROM guarantee_decisions
        GROUP BY status, active_action
        ORDER BY status, active_action
    ");
    
    printf("  %-15s %-15s %s\n", "Status", "Active Action", "Count");
    echo "  " . str_repeat('-', 56) . "\n";
    
    while ($row = $dist->fetch(PDO::FETCH_ASSOC)) {
        printf(
            "  %-15s %-15s %d\n",
            $row['status'] ?? 'NULL',
            $row['active_action'] ?? 'NULL',
            $row['count']
        );
    }
    
    // Summary statistics
    echo "\n4. Summary Statistics:\n";
    echo str_repeat('-', 60) . "\n";
    
    $summary = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN active_action IS NOT NULL THEN 1 ELSE 0 END) as with_action,
            SUM(CASE WHEN active_action IS NULL THEN 1 ELSE 0 END) as without_action,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status IN ('approved', 'ready') THEN 1 ELSE 0 END) as ready,
            SUM(CASE WHEN status = 'released' THEN 1 ELSE 0 END) as released
        FROM guarantee_decisions
    ")->fetch(PDO::FETCH_ASSOC);
    
    echo "  Total Guarantees: {$summary['total']}\n";
    echo "  With Active Action: {$summary['with_action']}\n";
    echo "  Without Active Action: {$summary['without_action']}\n";
    echo "  - PENDING: {$summary['pending']}\n";
    echo "  - READY: {$summary['ready']}\n";
    echo "  - RELEASED: {$summary['released']}\n";
    
    // Sample data
    echo "\n5. Sample Records:\n";
    echo str_repeat('-', 60) . "\n";
    
    $samples = $db->query("
        SELECT id, guarantee_id, status, active_action, active_action_set_at
        FROM guarantee_decisions
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($samples as $sample) {
        echo "  Guarantee #{$sample['guarantee_id']}: ";
        echo "status={$sample['status']}, ";
        echo "action=" . ($sample['active_action'] ?? 'NULL') . "\n";
    }
    
    echo "\n" . str_repeat('=', 60) . "\n";
    echo "✅ MIGRATION VERIFICATION COMPLETE\n";
    echo str_repeat('=', 60) . "\n\n";
    
    echo "Next Steps:\n";
    echo "1. Test in browser (see TESTING_GUIDE.md)\n";
    echo "2. Verify acceptance criteria\n";
    echo "3. If all pass → Commit and merge\n";
    
} catch (Exception $e) {
    echo "\n❌ Verification Failed!\n";
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
