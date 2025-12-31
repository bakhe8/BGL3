<?php
require_once __DIR__ . '/app/Support/autoload.php';
use App\Support\Database;

$db = Database::connect();

try {
    // Disable foreign key checks to allow truncation
    $db->exec("PRAGMA foreign_keys = OFF");

    $tables = [
        'guarantees', 
        'guarantee_decisions', 
        'guarantee_history', 
        'guarantee_notes', 
        'guarantee_attachments'
    ];

    foreach ($tables as $table) {
        $db->exec("DELETE FROM $table"); // SQLite doesn't have TRUNCATE
        $db->exec("DELETE FROM sqlite_sequence WHERE name='$table'"); // Reset auto-increment
        echo "✅ Cleared table: $table\n";
    }

    $db->exec("PRAGMA foreign_keys = ON");
    echo "🎉 Database reset complete.\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
