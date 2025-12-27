<?php
/**
 * Migration Runner: v4_001_unify_timeline
 * Run this script to execute the timeline unification migration
 */

require_once __DIR__ . '/../app/Support/Database.php';

echo "=== Timeline Unification Migration ===\n\n";

try {
    $db = App\Support\Database::connect();
    
    // Read migration file
    $migrationFile = __DIR__ . '/v4_001_unify_timeline.sql';
    if (!file_exists($migrationFile)) {
        throw new Exception("Migration file not found: $migrationFile");
    }
    
    $sql = file_get_contents($migrationFile);
    
    echo "Starting migration...\n\n";
    
    // Execute migration
    // SQLite exec() can handle multiple statements
    $db->exec($sql);
    
    echo "\n✅ Migration completed successfully!\n\n";
    
    // Show summary
    echo "=== Summary ===\n";
    $stmt = $db->query("SELECT COUNT(*) FROM guarantee_actions_backup");
    $backupCount = $stmt->fetchColumn();
    echo "- Actions backed up: $backupCount\n";
    
    $stmt = $db->query("SELECT COUNT(*) FROM guarantee_history WHERE event_subtype IN ('extension', 'reduction', 'release')");
    $migratedCount = $stmt->fetchColumn();
    echo "- Events migrated: $migratedCount\n";
    
    $stmt = $db->query("SELECT COUNT(*) FROM guarantee_history");
    $totalHistory = $stmt->fetchColumn();
    echo "- Total history events: $totalHistory\n";
    
} catch (Exception $e) {
    echo "\n❌ Migration failed!\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "\nPlease restore from backup if needed.\n";
    exit(1);
}
