<?php
// Restore backup, then clear all data

try {
    // Find most recent backup
    $backups = glob('storage/database/app.sqlite.backup_*');
    if (empty($backups)) {
        throw new Exception("No backup found");
    }
    $backupFile = end($backups);
    echo "Using backup: $backupFile\n";
    
    // Copy backup to active database
    copy($backupFile, 'storage/database/app.sqlite');
    echo "Restored backup to storage/database/app.sqlite\n";
    
    // Connect and clear all data
    $db = new PDO('sqlite:storage/database/app.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get all tables
    $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "\nClearing data from " . count($tables) . " tables...\n";
    
    // Disable foreign keys temporarily
    $db->exec('PRAGMA foreign_keys = OFF');
    
    // Delete all data from each table
    foreach ($tables as $table) {
        $count = $db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        if ($count > 0) {
            $db->exec("DELETE FROM $table");
            echo "  - Cleared $table ($count records)\n";
        }
    }
    
    // Reset auto-increment counters
    $db->exec("DELETE FROM sqlite_sequence");
    
    // Re-enable foreign keys
    $db->exec('PRAGMA foreign_keys = ON');
    
    // Verify all tables are empty
    echo "\n✅ Empty database created! Verification:\n";
    foreach ($tables as $table) {
        $count = $db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        echo "  - $table: $count records\n";
    }
    
    echo "\n✅ SUCCESS: Database has complete schema, zero data!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
