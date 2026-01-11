<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

try {
    echo "Creating guarantee_occurrences table...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS guarantee_occurrences (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            guarantee_id INTEGER NOT NULL,
            batch_identifier VARCHAR(255) NOT NULL,
            batch_type VARCHAR(50) NOT NULL,
            occurred_at DATETIME NOT NULL,
            raw_hash CHAR(64),
            FOREIGN KEY (guarantee_id) REFERENCES guarantees(id)
        )
    ");
    
    echo "Creating indexes...\n";
    $db->exec("CREATE INDEX IF NOT EXISTS idx_occurrences_batch ON guarantee_occurrences(batch_identifier)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_occurrences_guarantee ON guarantee_occurrences(guarantee_id)");
    
    echo "Backfilling legacy occurrences...\n";
    // Insert initial occurrence for every existing guarantee based on its import_source
    // We infer batch_type from import_source string prefix or default to 'legacy'
    $sql = "
        INSERT INTO guarantee_occurrences (guarantee_id, batch_identifier, batch_type, occurred_at)
        SELECT 
            id, 
            import_source, 
            CASE 
                WHEN import_source LIKE 'excel_%' THEN 'excel'
                WHEN import_source LIKE 'manual_%' THEN 'manual'
                WHEN import_source LIKE 'paste_%' THEN 'smart_paste'
                ELSE 'legacy' 
            END,
            imported_at
        FROM guarantees
        WHERE id NOT IN (SELECT guarantee_id FROM guarantee_occurrences)
    ";
    
    $result = $db->exec($sql);
    echo "Backfilled $result records.\n";
    
    echo "Migration completed successfully.\n";
    
} catch (PDOException $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
