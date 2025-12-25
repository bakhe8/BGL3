<?php
/**
 * PHASE 1: Database Migration - Add New Columns
 * Adds event_type, snapshot_data, event_details to guarantee_history
 */

$dbPath = __DIR__ . '/storage/database/app.sqlite';
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== PHASE 1: Adding New Columns ===\n\n";

try {
    // Add event_type column
    echo "Adding event_type column...";
    $pdo->exec("ALTER TABLE guarantee_history ADD COLUMN event_type TEXT");
    echo " ✓\n";
    
    // Add snapshot_data column
    echo "Adding snapshot_data column...";
    $pdo->exec("ALTER TABLE guarantee_history ADD COLUMN snapshot_data TEXT");
    echo " ✓\n";
    
    // Add event_details column
    echo "Adding event_details column...";
    $pdo->exec("ALTER TABLE guarantee_history ADD COLUMN event_details TEXT");
    echo " ✓\n";
    
    echo "\n=== PHASE 1 COMPLETE ✓ ===\n";
    echo "New schema ready!\n";
    
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'duplicate column name') !== false) {
        echo "\n⚠️ Columns already exist - skipping\n";
    } else {
        die("ERROR: " . $e->getMessage() . "\n");
    }
}
