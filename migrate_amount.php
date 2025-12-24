<?php
require_once __DIR__ . '/app/Support/autoload.php';
use App\Support\Database;

$db = Database::connect();

try {
    echo "Adding 'amount' column to guarantee_decisions...\n";
    $db->exec("ALTER TABLE guarantee_decisions ADD COLUMN amount REAL DEFAULT NULL");
    echo "Success!\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'duplicate column name') !== false) {
        echo "Column 'amount' already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
