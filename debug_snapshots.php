<?php
require_once __DIR__ . '/app/Support/autoload.php';
use App\Support\Database;

$db = Database::connect();
echo "Approved Snapshots:\n";
print_r($db->query("SELECT id, action, snapshot_data FROM guarantee_history WHERE action='approved' ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC));
