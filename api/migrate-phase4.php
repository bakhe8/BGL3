<?php
/**
 * PHASE 4 CLEANUP + COMPLETE: Drop Old Columns + Optimize
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../app/Support/autoload.php';
use App\Support\Database;

try {
    $db = Database::connect();
    
    $results = [];
    
    // Cleanup: Drop temp table if exists
    try {
        $db->exec("DROP TABLE IF EXISTS guarantee_history_new");
        $results[] = "→ Cleaned up temp table";
    } catch (Exception $e) {
        // Ignore
    }
    
    // Step 1: Create new table with new schema only
    $db->exec("
        CREATE TABLE guarantee_history_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            guarantee_id INTEGER NOT NULL,
            event_type TEXT,
            snapshot_data TEXT,
            event_details TEXT,
            created_at DATETIME NOT NULL,
            created_by TEXT NOT NULL
        )
    ");
    $results[] = "✓ Created new table schema";
    
    // Step 2: Copy data (handle NULL created_by with COALESCE)
    $db->exec("
        INSERT INTO guarantee_history_new 
            (id, guarantee_id, event_type, snapshot_data, event_details, created_at, created_by)
        SELECT 
            id, 
            guarantee_id, 
            event_type, 
            snapshot_data, 
            event_details, 
            created_at, 
            COALESCE(created_by, 'System')
        FROM guarantee_history
    ");
    $count = $db->query("SELECT COUNT(*) FROM guarantee_history_new")->fetchColumn();
    $results[] = "✓ Copied $count events to new table";
    
    // Step 3: Drop old table
    $db->exec("DROP TABLE guarantee_history");
    $results[] = "✓ Dropped old table (removed action & change_reason columns)";
    
    // Step 4: Rename new table
    $db->exec("ALTER TABLE guarantee_history_new RENAME TO guarantee_history");
    $results[] = "✓ Renamed table to guarantee_history";
    
    // Step 5: Create indexes for performance
    $db->exec("
        CREATE INDEX idx_guarantee_timeline 
        ON guarantee_history(guarantee_id, created_at)
    ");
    $results[] = "✓ Created timeline index (guarantee_id, created_at)";
    
    $db->exec("
        CREATE INDEX idx_event_type 
        ON guarantee_history(event_type)
    ");
    $results[] = "✓ Created event_type index";
    
    // Step 6: VACUUM to reclaim space
    $db->exec("VACUUM");
    $results[] = "✓ VACUUM complete - database optimized";
    
    echo json_encode([
        'success' => true,
        'phase' => 4,
        'message' => 'Migration complete! Old schema removed.',
        'old_columns_removed' => ['action', 'change_reason'],
        'new_schema' => ['event_type', 'snapshot_data', 'event_details'],
        'events_migrated' => $count,
        'results' => $results
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
