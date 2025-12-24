<?php
require_once __DIR__ . '/app/Support/autoload.php';
use App\Support\Database;

$db = Database::connect();
echo "Actions for ID 351:\n";
print_r($db->query('SELECT * FROM guarantee_actions WHERE guarantee_id = 351')->fetchAll(PDO::FETCH_ASSOC));

echo "\nDecisions for ID 351:\n";
print_r($db->query('SELECT * FROM guarantee_decisions WHERE guarantee_id = 351 ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC));
