<?php
/**
 * Quick Migration: Fill snapshot_data for LATEST events only
 * This gives Time Machine functionality for the most recent event per guarantee
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../lib/TimelineHelper.php';

echo "=== Quick Snapshot Migration (Latest Events Only) ===\n\n";

$db = Database::getInstance()->getConnection();

// Get all guarantees
$guarantees = $db->query("SELECT id FROM guarantees ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
echo "Found " . count($guarantees) . " guarantees\n\n";

$updated = 0;
$skipped = 0;

foreach ($guarantees as $gid) {
    // Get latest event for this guarantee
    $stmt = $db->prepare("
        SELECT id, snapshot_data 
        FROM guarantee_history 
        WHERE guarantee_id = ? 
        ORDER BY id DESC 
        LIMIT 1
    ");
    $stmt->execute([$gid]);
    $latest = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$latest) {
        continue;
    }
    
    // Check if snapshot is empty
    $snapshot = json_decode($latest['snapshot_data'] ?? '{}', true);
    if (empty($snapshot) || count($snapshot) == 0) {
        // Create snapshot from current state
        $currentSnapshot = TimelineHelper::createSnapshot($gid);
        
        if (!empty($currentSnapshot)) {
            $db->prepare("UPDATE guarantee_history SET snapshot_data = ? WHERE id = ?")
               ->execute([json_encode($currentSnapshot), $latest['id']]);
            
            echo "✓ Updated guarantee #$gid (event #{$latest['id']})\n";
            $updated++;
        }
    } else {
        $skipped++;
    }
}

echo "\n=== Complete ===\n";
echo "Updated: $updated\n";
echo "Skipped: $skipped\n";
