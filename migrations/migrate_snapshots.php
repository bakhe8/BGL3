<?php
/**
 * Migration Script: Populate snapshot_data for legacy events
 * 
 * This script fills empty snapshot_data for old events in guarantee_history
 * by reading current guarantee data and working backwards through events.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/TimelineHelper.php';

echo "=== Timeline Snapshot Migration ===\n\n";

try {
    $db = Database::getInstance()->getConnection();
    
    // Step 1: Get all guarantees
    $stmt = $db->query("SELECT id FROM guarantees ORDER BY id");
    $guarantees = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Found " . count($guarantees) . " guarantees to process\n\n";
    
    $totalUpdated = 0;
    $totalSkipped = 0;
    
    foreach ($guarantees as $guaranteeId) {
        echo "Processing Guarantee #$guaranteeId...\n";
        
        // Get all events for this guarantee, ordered by ID DESC (newest first)
        $stmt = $db->prepare("
            SELECT id, event_type, snapshot_data, event_details, created_at
            FROM guarantee_history
            WHERE guarantee_id = ?
            ORDER BY id DESC
        ");
        $stmt->execute([$guaranteeId]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($events)) {
            echo "  No events found\n";
            continue;
        }
        
        echo "  Found " . count($events) . " events\n";
        
        // For the FIRST event (latest), use current guarantee data as snapshot
        $firstEvent = $events[0];
        
        if (empty($firstEvent['snapshot_data']) || $firstEvent['snapshot_data'] === '{}') {
            // Get current state from guarantees + guarantee_decisions
            $currentSnapshot = TimelineHelper::createSnapshot($guaranteeId);
            
            if (!empty($currentSnapshot)) {
                // Update the latest event with current snapshot
                $updateStmt = $db->prepare("
                    UPDATE guarantee_history 
                    SET snapshot_data = ?
                    WHERE id = ?
                ");
                $updateStmt->execute([
                    json_encode($currentSnapshot),
                    $firstEvent['id']
                ]);
                
                echo "  ✓ Updated latest event #" . $firstEvent['id'] . " with current snapshot\n";
                $totalUpdated++;
            }
        } else {
            echo "  → Latest event already has snapshot\n";
            $totalSkipped++;
        }
        
        // For older events, we cannot reliably reconstruct snapshots
        // Mark them with a special empty snapshot indicator
        for ($i = 1; $i < count($events); $i++) {
            $event = $events[$i];
            
            if (empty($event['snapshot_data']) || $event['snapshot_data'] === '{}') {
                // Insert a marker that indicates "no historical data available"
                $updateStmt = $db->prepare("
                    UPDATE guarantee_history 
                    SET snapshot_data = ?
                    WHERE id = ?
                ");
                $updateStmt->execute([
                    json_encode(['_no_snapshot' => true, '_reason' => 'Legacy event - historical data not captured']),
                    $event['id']
                ]);
                
                $totalUpdated++;
            } else {
                $totalSkipped++;
            }
        }
        
        echo "\n";
    }
    
    echo "=== Migration Complete ===\n";
    echo "Total events updated: $totalUpdated\n";
    echo "Total events skipped: $totalSkipped\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
