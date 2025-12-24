<?php
require_once __DIR__ . '/app/Support/autoload.php';
use App\Support\Database;

$db = Database::connect();
echo "Schema for guarantee_actions:\n";
print_r($db->query("SELECT * FROM sqlite_master WHERE type='trigger' AND tbl_name='guarantee_actions'")->fetchAll(PDO::FETCH_ASSOC));
