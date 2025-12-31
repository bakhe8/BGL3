<?php
/**
 * Database Backup Script
 * Creates a backup before migration
 */

require_once __DIR__ . '/app/Support/Database.php';

use App\Support\Database;

echo "=== Database Backup Script ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $db = Database::connect();
    
    // Get all tables
    $tables = [];
    $result = $db->query("SHOW TABLES");
    while ($row = $result->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }
    
    echo "Found " . count($tables) . " tables\n";
    echo "Tables: " . implode(', ', $tables) . "\n\n";
    
    // Create backup directory if not exists
    $backupDir = __DIR__ . '/backups';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    
    $backupFile = $backupDir . '/backup_before_active_action_' . date('Ymd_His') . '.sql';
    $fp = fopen($backupFile, 'w');
    
    if (!$fp) {
        throw new Exception("Cannot create backup file: $backupFile");
    }
    
    // Write header
    fwrite($fp, "-- Database Backup\n");
    fwrite($fp, "-- Date: " . date('Y-m-d H:i:s') . "\n");
    fwrite($fp, "-- Before: Active Action State Migration\n\n");
    fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n\n");
    
    // Backup each table
    foreach ($tables as $table) {
        echo "Backing up table: $table... ";
        
        // DROP TABLE
        fwrite($fp, "-- Table: $table\n");
        fwrite($fp, "DROP TABLE IF EXISTS `$table`;\n\n");
        
        // CREATE TABLE
        $createResult = $db->query("SHOW CREATE TABLE `$table`");
        $createRow = $createResult->fetch(PDO::FETCH_ASSOC);
        fwrite($fp, $createRow['Create Table'] . ";\n\n");
        
        // INSERT DATA
        $countResult = $db->query("SELECT COUNT(*) FROM `$table`");
        $count = $countResult->fetchColumn();
        
        if ($count > 0) {
            $dataResult = $db->query("SELECT * FROM `$table`");
            
            fwrite($fp, "-- Data for table: $table\n");
            
            while ($row = $dataResult->fetch(PDO::FETCH_ASSOC)) {
                $columns = array_keys($row);
                $values = array_values($row);
                
                // Escape values
                $escapedValues = array_map(function($val) use ($db) {
                    if ($val === null) return 'NULL';
                    return $db->quote($val);
                }, $values);
                
                $sql = "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $escapedValues) . ");\n";
                fwrite($fp, $sql);
            }
            
            fwrite($fp, "\n");
            echo "$count rows\n";
        } else {
            echo "empty\n";
        }
    }
    
    fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fp);
    
    $fileSize = filesize($backupFile);
    $fileSizeMB = round($fileSize / 1024 / 1024, 2);
    
    echo "\n✅ Backup completed successfully!\n";
    echo "File: $backupFile\n";
    echo "Size: $fileSizeMB MB\n";
    
} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
