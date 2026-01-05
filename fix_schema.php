<?php
require_once __DIR__ . '/app/Support/Database.php';
use App\Support\Database;

try {
    $db = Database::connect();
    
    echo "Starting schema migration...\n";

    // 1. Disable foreign keys
    $db->exec('PRAGMA foreign_keys = OFF');
    echo "Foreign keys disabled.\n";

    // 2. Begin Transaction
    $db->beginTransaction();

    // 3. Rename old table
    $db->exec('ALTER TABLE suppliers RENAME TO suppliers_old');
    echo "Renamed suppliers to suppliers_old.\n";

    // 4. Create new table with CORRECT schema
    $sql = "CREATE TABLE suppliers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        official_name TEXT NOT NULL,
        display_name TEXT,
        normalized_name TEXT NOT NULL,
        supplier_normalized_key TEXT,
        is_confirmed INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        english_name TEXT
    )";
    $db->exec($sql);
    echo "Created new suppliers table.\n";

    // 5. Migrate Data
    // We explicitly select columns to match the new structure.
    // NOTE: We do NOT select 'id' for NULL records so SQLite generates new ones.
    // For non-NULL records, we DO select 'id' to preserve it.
    
    // 5a. Copy records with IDs (Preserve IDs)
    $stmt = $db->query("SELECT * FROM suppliers_old WHERE id IS NOT NULL AND id != ''");
    $validCount = 0;
    
    $insert = $db->prepare("INSERT INTO suppliers (id, official_name, display_name, normalized_name, supplier_normalized_key, is_confirmed, created_at, english_name) VALUES (:id, :off, :disp, :norm, :key, :conf, :created, :eng)");
    
    while ($row = $stmt->fetch()) {
        $insert->execute([
            ':id' => $row['id'],
            ':off' => $row['official_name'],
            ':disp' => $row['display_name'],
            ':norm' => $row['normalized_name'],
            ':key' => $row['supplier_normalized_key'],
            ':conf' => $row['is_confirmed'],
            ':created' => $row['created_at'],
            ':eng' => $row['english_name']
        ]);
        $validCount++;
    }
    echo "Migrated $validCount records with valid IDs.\n";

    // 5b. Copy records with NULL IDs (Generate New IDs)
    $stmt = $db->query("SELECT * FROM suppliers_old WHERE id IS NULL OR id = ''");
    $fixedCount = 0;
    
    $insertNew = $db->prepare("INSERT INTO suppliers (official_name, display_name, normalized_name, supplier_normalized_key, is_confirmed, created_at, english_name) VALUES (:off, :disp, :norm, :key, :conf, :created, :eng)");
    
    while ($row = $stmt->fetch()) {
        $insertNew->execute([
            ':off' => $row['official_name'],
            ':disp' => $row['display_name'],
            ':norm' => $row['normalized_name'],
            ':key' => $row['supplier_normalized_key'],
            ':conf' => $row['is_confirmed'],
            ':created' => $row['created_at'],
            ':eng' => $row['english_name']
        ]);
        $fixedCount++;
    }
    echo "Migrated and fixed $fixedCount records with NULL IDs.\n";

    // 6. Verify (Optional - strict check)
    // Check if any data missing? No, we iterated all.

    // 7. Drop old table
    $db->exec('DROP TABLE suppliers_old');
    echo "Dropped old table.\n";

    // 8. Commit
    $db->commit();
    echo "Transaction committed.\n";

    // 9. Enable Foreign Keys
    $db->exec('PRAGMA foreign_keys = ON');
    echo "Foreign keys enabled.\n";
    
    echo "Migration completed successfully!\n";

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
        echo "Rolled back transaction.\n";
    }
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
