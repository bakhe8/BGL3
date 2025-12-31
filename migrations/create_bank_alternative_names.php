<?php
/**
 * Create bank_alternative_names table
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/Support/autoload.php';
use App\Support\Database;

try {
    $db = Database::connect();
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    
    echo "📋 Creating bank_alternative_names table...\n\n";
    
    $db->exec("DROP TABLE IF EXISTS bank_alternative_names");
    
    $db->exec("
        CREATE TABLE bank_alternative_names (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bank_id INTEGER NOT NULL,
            alternative_name TEXT NOT NULL,
            normalized_name TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (bank_id) REFERENCES banks(id) ON DELETE CASCADE
        )
    ");
    
    $db->exec("CREATE INDEX idx_bank_alt_normalized ON bank_alternative_names(normalized_name)");
    $db->exec("CREATE INDEX idx_bank_alt_bank_id ON bank_alternative_names(bank_id)");
    
    echo "✅ Table created successfully!\n\n";
    
} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n\n";
    exit(1);
}
