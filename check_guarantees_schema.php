<?php
require_once __DIR__ . '/app/Support/Database.php';
use App\Support\Database;

try {
    $db = Database::connect();
    $stmt = $db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='guarantees'");
    $schema = $stmt->fetchColumn();
    echo "Schema for guarantees table:\n" . $schema . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
