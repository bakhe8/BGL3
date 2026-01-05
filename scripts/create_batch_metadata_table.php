<?php
/**
 * Create batch_metadata table
 * Run once: php scripts/create_batch_metadata_table.php
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

// Begin transaction
$db->beginTransaction();

try {
    // Create table (WITHOUT archived - Decision #5)
    $db->exec("
        CREATE TABLE IF NOT EXISTS batch_metadata (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            import_source TEXT NOT NULL UNIQUE,
            
            -- User fields only
            batch_name TEXT,
            batch_notes TEXT,
            status TEXT DEFAULT 'active' CHECK(status IN ('active', 'completed')),
            
            -- Timestamps
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // Create indexes
    $db->exec("CREATE INDEX IF NOT EXISTS idx_batch_metadata_source ON batch_metadata(import_source)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_batch_metadata_status ON batch_metadata(status)");
    
    $db->commit();
    
    echo "✅ batch_metadata table created successfully\n";
    
    // Verify
    $count = $db->query("SELECT COUNT(*) FROM batch_metadata")->fetchColumn();
    echo "✅  Current batch_metadata count: $count\n";
    
    // Show schema
    echo "\n📋 Schema:\n";
    $schema = $db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='batch_metadata'")->fetchColumn();
    echo $schema . "\n\n";
    
    // Show indexes
    echo "📋 Indexes:\n";
    $indexes = $db->query("SELECT name, sql FROM sqlite_master WHERE type='index' AND tbl_name='batch_metadata'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($indexes as $idx) {
        echo "- {$idx['name']}\n";
    }
    
    echo "\n✅ Phase 1 complete!\n";
    
} catch (Exception $e) {
    $db->rollBack();
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
