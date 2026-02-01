<?php
require __DIR__ . '/../app/Support/autoload.php';
use App\Support\Database;

try {
    echo "Testing Database Connection...\n";
    $db = Database::connect();
    echo "Running Query...\n";
    $stmt = $db->query("SELECT 1 as test_val");
    $res = $stmt->fetch();
    echo "Result: " . print_r($res, true) . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
