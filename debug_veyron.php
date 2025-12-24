<?php
require_once __DIR__ . '/app/Support/autoload.php';
use App\Support\Database;

$db = Database::connect();

echo "--- Debugging 'Veyron' ---" . PHP_EOL;

// 1. Search Suppliers Table
$stmt = $db->prepare("SELECT * FROM suppliers WHERE official_name LIKE ?");
$stmt->execute(['%Veyron%']);
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($suppliers) . " suppliers matching 'Veyron':" . PHP_EOL;
foreach ($suppliers as $s) {
    echo "[ID: {$s['id']}] Name: {$s['official_name']} | Norm: {$s['normalized_name']}" . PHP_EOL;
}

// 2. Search 'فيرون'
$stmt = $db->prepare("SELECT * FROM suppliers WHERE official_name LIKE ?");
$stmt->execute(['%فيرون%']);
$arabic = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Found " . count($arabic) . " suppliers matching 'فيرون':" . PHP_EOL;
foreach ($arabic as $s) {
    echo "[ID: {$s['id']}] Name: {$s['official_name']} | Norm: {$s['normalized_name']}" . PHP_EOL;
}

// 3. Check Learning Data (If Veyron maps to ID of Arabic name)
// This is harder to check directly without repository, but we can query raw data learning
// Check `guarantee_decisions` for the Guarantee mentioned "RLG6812927"
$gNum = "RLG6812927";
$gStmt = $db->prepare("SELECT id FROM guarantees WHERE guarantee_number = ?");
$gStmt->execute([$gNum]);
$gId = $gStmt->fetchColumn();

if ($gId) {
    echo "Guarantee ID for $gNum is: $gId" . PHP_EOL;
    $dStmt = $db->prepare("SELECT * FROM guarantee_decisions WHERE guarantee_id = ?");
    $dStmt->execute([$gId]);
    $dec = $dStmt->fetch(PDO::FETCH_ASSOC);
    echo "Current Decision: Status={$dec['status']} SupplierID={$dec['supplier_id']}" . PHP_EOL;
    
    // Check History Last 3
    $hStmt = $db->prepare("SELECT action, change_reason, snapshot_data FROM guarantee_history WHERE guarantee_id = ? ORDER BY id DESC LIMIT 3");
    $hStmt->execute([$gId]);
    $hist = $hStmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($hist);
} else {
    echo "Guarantee NOT FOUND!" . PHP_EOL;
}
