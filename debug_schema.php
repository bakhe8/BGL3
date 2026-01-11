<?php
require_once __DIR__ . '/app/Support/Database.php';
use App\Support\Database;

try {
    $db = Database::connect();
    $stmt = $db->query("PRAGMA table_info(suppliers)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($columns);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
