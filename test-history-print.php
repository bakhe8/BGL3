<?php
/**
 * Test History Print System
 */
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;
use App\Models\Guarantee;
use App\Repositories\GuaranteeRepository;
use App\Services\DecisionService;
use App\Repositories\GuaranteeDecisionRepository;
use App\Repositories\GuaranteeHistoryRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\BankRepository;

$db = Database::connect();
$guaranteeRepo = new GuaranteeRepository($db);
$decisionRepo = new GuaranteeDecisionRepository();
$historyRepo = new GuaranteeHistoryRepository();
$supplierRepo = new SupplierRepository();
$bankRepo = new BankRepository();

// Setup Service with full deps
$decisionService = new DecisionService(
    $decisionRepo,
    $guaranteeRepo,
    null, // Learning
    $historyRepo,
    $supplierRepo,
    $bankRepo
);

$baseUrl = 'http://localhost:8000/V3';

echo "--- Starting History Print Test ---\n";

// 1. Cleanup
$db->exec("DELETE FROM guarantee_history WHERE guarantee_id IN (SELECT id FROM guarantees WHERE guarantee_number = 'HIST-001')");
$db->exec("DELETE FROM guarantee_decisions WHERE guarantee_id IN (SELECT id FROM guarantees WHERE guarantee_number = 'HIST-001')");
$db->exec("DELETE FROM guarantees WHERE guarantee_number = 'HIST-001'");
$db->exec("DELETE FROM suppliers WHERE supplier_normalized_key = 'MK TB T'");
$db->exec("DELETE FROM banks WHERE short_code = 'ARC'");

// Create Guarantee
$g = $guaranteeRepo->create(new Guarantee(
    null,
    'HIST-001',
    [
        'supplier' => 'مكتبة التاريخ',
        'bank' => 'بنك الأرشيف',
        'amount' => 1000,
        'type' => 'Initial'
    ],
    'test_history'
));
echo "Created Guarantee: " . $g->id . "\n";

// 2. Create Supplier & Bank
$supId = $db->query("INSERT INTO suppliers (official_name, normalized_name, supplier_normalized_key) VALUES ('مكتبة التاريخ', 'مكتبة التاريخ', 'MK TB T') RETURNING id")->fetchColumn();
$bnkId = $db->query("INSERT INTO banks (official_name, normalized_name, short_code) VALUES ('بنك الأرشيف', 'BNK ARCH', 'ARC') RETURNING id")->fetchColumn();

// 3. Save Decision (Should trigger history log)
$decisionService->save($g->id, [
    'supplier_id' => $supId,
    'bank_id' => $bnkId,
    'status' => 'ready',
    'decision_source' => 'manual',
    'decided_by' => 'Tester'
]);
echo "Saved Decision.\n";

// 4. Check History Record
$history = $historyRepo->getHistory($g->id);
if (count($history) > 0) {
    echo "SUCCESS: History record found (ID: " . $history[0]['id'] . ")\n";
    $histId = $history[0]['id'];
    
    // 5. Test Print View with history_id
    $url = "$baseUrl/views/print.php?history_id=$histId";
    echo "Fetching: $url\n";
    $html = file_get_contents($url);
    
    // Check for Historical Warning Banner text
    file_put_contents('V3/history_print_debug.html', $html);
    
    if (str_contains($html, 'عرض تاريخي') && str_contains($html, 'مكتبة التاريخ')) {
         echo "SUCCESS: Print view shows historical banner and correct data.\n";
    } else {
         echo "FAIL: Print view missing historical indicator or data.\n";
         echo "Snapshot Data Length: " . strlen($history[0]['snapshot_data']) . "\n";
         echo "See V3/history_print_debug.html\n";
    }

} else {
    echo "FAIL: No history record created.\n";
}

// Cleanup
//$db->exec("DELETE FROM guarantees WHERE id = " . $g->id);
//$db->exec("DELETE FROM suppliers WHERE id = $supId");
//$db->exec("DELETE FROM banks WHERE id = $bnkId");
