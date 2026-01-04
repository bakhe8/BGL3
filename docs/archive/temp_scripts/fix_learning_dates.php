<?php
// Fix null dates in learning_confirmations

require __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

echo "=== Fixing NULL dates in learning_confirmations ===\n\n";

try {
    // Update rows where updated_at is NULL
    // Use datetime('now') for SQLite compatibility
    $stmt = $db->exec("
        UPDATE learning_confirmations 
        SET updated_at = COALESCE(created_at, datetime('now'))
        WHERE updated_at IS NULL
    ");
    
    echo "✅ Updated $stmt rows with NULL dates\n\n";
    
    // Verify
    $stmt2 = $db->query("
        SELECT COUNT(*) 
        FROM learning_confirmations 
        WHERE updated_at IS NULL
    ");
    $remaining = $stmt2->fetchColumn();
    
    if ($remaining == 0) {
        echo "✅ All dates fixed! No NULL dates remaining.\n";
    } else {
        echo "⚠️  Still have $remaining rows with NULL dates\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
