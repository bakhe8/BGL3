<?php
require_once __DIR__ . '/app/Support/autoload.php';
use App\Support\Database;
use App\Repositories\GuaranteeRepository;

$db = Database::connect();

// 1. Pick a guarantee
$guaranteeId = $db->query("SELECT id FROM guarantees LIMIT 1")->fetchColumn();
echo "Testing Guarantee ID: $guaranteeId\n";

// 2. Mock a decision with a custom supplier
// First create a mock supplier
$db->query("INSERT INTO suppliers (official_name, normalized_name) VALUES ('Test Arabic Supplier', 'test arabic supplier')");
$supId = $db->lastInsertId();
echo "Created Mock Supplier ID: $supId ('Test Arabic Supplier')\n";

// Insert decision
$db->prepare("REPLACE INTO guarantee_decisions (guarantee_id, supplier_id, bank_id, status) VALUES (?, ?, 1, 'approved')")
   ->execute([$guaranteeId, $supId]);

// 3. Run the logic from api/get-record.php (simplified)
$stmtDec = $db->prepare('SELECT status, supplier_id FROM guarantee_decisions WHERE guarantee_id = ? ORDER BY id DESC LIMIT 1');
$stmtDec->execute([$guaranteeId]);
$lastDecision = $stmtDec->fetch(PDO::FETCH_ASSOC);

$recordStub = ['supplier_name' => 'OLD ENGLISH NAME']; // Simulate raw data

if ($lastDecision) {
    echo "Found Decision! Supplier ID: {$lastDecision['supplier_id']}\n";
    $recordStub['supplier_id'] = $lastDecision['supplier_id'];

    if ($recordStub['supplier_id']) {
        $sStmt = $db->prepare('SELECT official_name FROM suppliers WHERE id = ?');
        $sStmt->execute([$recordStub['supplier_id']]);
        $sName = $sStmt->fetchColumn();
        if ($sName) {
            $recordStub['supplier_name'] = $sName;
        }
    }
}

echo "Final Resolved Name: " . $recordStub['supplier_name'] . "\n";
