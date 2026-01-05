<?php
require_once __DIR__ . '/app/Support/Database.php';
use App\Support\Database;

try {
    $db = Database::connect();
    $stmt = $db->query("SELECT name, sql FROM sqlite_master WHERE type='table' AND name != 'sqlite_sequence'");
    while ($row = $stmt->fetch()) {
        echo "Table: " . $row['name'] . "\n";
        echo $row['sql'] . "\n";
        echo "---------------------------------------------------\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
