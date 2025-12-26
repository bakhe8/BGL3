<?php
// Copy schema from backup, create empty database

try {
    // Find the most recent backup
    $backups = glob('storage/database/app.sqlite.backup_*');
    if (empty($backups)) {
        throw new Exception("No backup found");
    }
    $backupFile = end($backups);
    echo "Using backup: $backupFile\n";
    
    // Connect to backup
    $backup = new PDO("sqlite:$backupFile");
    $backup->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Extract complete schema
    $stmt = $backup->query("SELECT sql FROM sqlite_master WHERE type='table' AND sql NOT NULL ORDER BY name");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $stmt = $backup->query("SELECT sql FROM sqlite_master WHERE type='index' AND sql NOT NULL ORDER BY name");
    $indexes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $stmt = $backup->query("SELECT sql FROM sqlite_master WHERE type='trigger' AND sql NOT NULL ORDER BY name");
    $triggers = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Extracted " . count($tables) . " tables, " . count($indexes) . " indexes, " . count($triggers) . " triggers\n";
    
    // Remove old database
    if (file_exists('storage/database/app.sqlite')) {
        unlink('storage/database/app.sqlite');
        echo "Removed old database\n";
    }
    
    // Create new empty database
    $db = new PDO('sqlite:storage/database/app.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create all tables
    foreach ($tables as $sql) {
        $db->exec($sql);
    }
    echo "Created " . count($tables) . " tables\n";
    
    // Create all indexes
    foreach ($indexes as $sql) {
        try {
            $db->exec($sql);
        } catch (PDOException $e) {
            // Some indexes may already exist
            if (strpos($e->getMessage(), 'already exists') === false) {
                echo "Warning: " . $e->getMessage() . "\n";
            }
        }
    }
    echo "Created " . count($indexes) . " indexes\n";
    
    // Create all triggers
    foreach ($triggers as $sql) {
        try {
            $db->exec($sql);
        } catch (PDOException $e) {
            echo "Warning creating trigger: " . $e->getMessage() . "\n";
        }
    }
    echo "Created " . count($triggers) . " triggers\n";
    
    // Verify empty database
    $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
    $allTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "\n✅ Empty database created with complete schema!\n\n";
    echo "Tables and record counts:\n";
    foreach ($allTables as $table) {
        if ($table === 'sqlite_sequence') continue;
        $stmt = $db->query("SELECT COUNT(*) FROM $table");
        $count = $stmt->fetchColumn();
        echo "  - $table: $count records\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
