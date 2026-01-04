<?php
/**
 * Backfill Script: Populate active_action from Timeline History
 * For SQLite Database
 * 
 * Date: 2025-12-31
 * Phase: 2 (One-time Backfill)
 */

require_once __DIR__ . '/../app/Support/Database.php';

use App\Support\Database;

echo "=== Active Action Backfill Script (SQLite) ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $db = Database::connect();
    $db->beginTransaction();
    
    // ========================================================================
    // Step 1: Set active_action = NULL for all PENDING guarantees
    // ========================================================================
    
    echo "Step 1: Setting PENDING guarantees to NULL...\n";
    
    $stmt1 = $db->prepare("
        UPDATE guarantee_decisions
        SET active_action = NULL,
            active_action_set_at = NULL
        WHERE status = 'pending'
    ");
    $stmt1->execute();
    $affected1 = $stmt1->rowCount();
    
    echo "  ✅ Updated $affected1 PENDING guarantees\n\n";
    
    // ========================================================================
    // Step 2: For READY guarantees, backfill from latest timeline event
    // ========================================================================
    
    echo "Step 2: Backfilling READY guarantees from timeline...\n";
    
    // Get all READY guarantees
    $readyStmt = $db->query("
        SELECT guarantee_id 
        FROM guarantee_decisions 
        WHERE status IN ('approved', 'ready')
    ");
    
    $readyGuarantees = $readyStmt->fetchAll(PDO::FETCH_COLUMN);
    echo "  Found " . count($readyGuarantees) . " READY guarantees\n";
    
    $updated = 0;
    
    foreach ($readyGuarantees as $guaranteeId) {
        // Find latest legal action event
        $eventStmt = $db->prepare("
            SELECT event_subtype, event_type, created_at
            FROM guarantee_history
            WHERE guarantee_id = ?
              AND (
                  event_subtype IN ('extension', 'reduction', 'release')
                  OR event_type IN ('extension', 'reduction', 'release')
              )
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $eventStmt->execute([$guaranteeId]);
        $event = $eventStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($event) {
            $action = null;
            
            // Determine action from event
            if ($event['event_subtype'] === 'extension' || $event['event_type'] === 'extension') {
                $action = 'extension';
            } elseif ($event['event_subtype'] === 'reduction' || $event['event_type'] === 'reduction') {
                $action = 'reduction';
            } elseif ($event['event_subtype'] === 'release' || $event['event_type'] === 'release') {
                $action = 'release';
            }
            
            if ($action) {
                $updateStmt = $db->prepare("
                    UPDATE guarantee_decisions
                    SET active_action = ?,
                        active_action_set_at = ?
                    WHERE guarantee_id = ?
                ");
                $updateStmt->execute([$action, $event['created_at'], $guaranteeId]);
                $updated++;
            }
        }
    }
    
    echo "  ✅ Updated $updated READY guarantees with actions\n";
    echo "  ℹ️  " . (count($readyGuarantees) - $updated) . " READY guarantees remain NULL (no action)\n\n";
    
    // ========================================================================
    // Step 3: Handle RELEASED guarantees
    // ========================================================================
    
    echo "Step 3: Setting RELEASED guarantees to 'release'...\n";
    
    $stmt3 = $db->prepare("
        UPDATE guarantee_decisions
        SET active_action = 'release',
            active_action_set_at = decided_at
        WHERE status = 'released' AND active_action IS NULL
    ");
    $stmt3->execute();
    $affected3 = $stmt3->rowCount();
    
    echo "  ✅ Updated $affected3 RELEASED guarantees\n\n";
    
    // ========================================================================
    // Verification
    // ========================================================================
    
    echo "=== BACKFILL COMPLETE ===\n\n";
    
    echo "Distribution by Status and Action:\n";
    echo str_repeat('-', 60) . "\n";
    
    $distStmt = $db->query("
        SELECT 
            status,
            active_action,
            COUNT(*) as count
        FROM guarantee_decisions
        GROUP BY status, active_action
        ORDER BY status, active_action
    ");
    
    printf("%-15s %-15s %s\n", "Status", "Active Action", "Count");
    echo str_repeat('-', 60) . "\n";
    
    while ($row = $distStmt->fetch(PDO::FETCH_ASSOC)) {
        printf(
            "%-15s %-15s %d\n",
            $row['status'] ?? 'NULL',
            $row['active_action'] ?? 'NULL',
            $row['count']
        );
    }
    
    echo "\n";
    
    // Summary
    $summaryStmt = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN active_action IS NOT NULL THEN 1 ELSE 0 END) as with_action,
            SUM(CASE WHEN active_action IS NULL THEN 1 ELSE 0 END) as without_action
        FROM guarantee_decisions
    ");
    
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
    
    echo "Summary:\n";
    echo "  Total Guarantees: {$summary['total']}\n";
    echo "  With Active Action: {$summary['with_action']}\n";
    echo "  Without Active Action: {$summary['without_action']}\n\n";
    
    // Commit transaction
    $db->commit();
    echo "✅ Transaction committed successfully\n";
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
        echo "❌ Transaction rolled back\n";
    }
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n=== MIGRATION PHASE 2 COMPLETE ===\n";
echo "Next: Deploy code and test using TESTING_GUIDE.md\n";
