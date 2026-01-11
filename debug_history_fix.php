<?php
require_once __DIR__ . '/app/Support/autoload.php';
use App\Support\Database;

try {
    // Correct Path from Root
    $dbPath = __DIR__ . '/storage/database/app.sqlite';
    $backupPath = __DIR__ . '/storage/database/app_backup_' . date('Ymd_His') . '.sqlite';
    
    // 1. Backup
    if (file_exists($dbPath)) {
        if (!copy($dbPath, $backupPath)) {
            throw new Exception("Failed to copy database to $backupPath");
        }
        echo "Backup created at: $backupPath\n";
    } else {
        throw new Exception("Database file not found at: $dbPath");
    }
    
    // 2. Analyze
    $db = Database::connect();
    echo "Connected to DB.\n";
    
    $stmt = $db->query("
        SELECT event_type, event_subtype, created_by, COUNT(*) as count 
        FROM guarantee_history 
        GROUP BY event_type, event_subtype, created_by
        ORDER BY event_type
    ");
    
    echo "Current Distribution:\n";
    echo str_pad("Type", 20) . str_pad("Subtype", 20) . str_pad("Created By", 20) . "Count\n";
    echo str_repeat("-", 70) . "\n";
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo str_pad($row['event_type'] ?? '-', 20) . 
             str_pad($row['event_subtype'] ?? '-', 20) . 
             str_pad($row['created_by'] ?? '-', 20) . 
             $row['count'] . "\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
