<?php
/**
 * Test Batch Print System
 */
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;
use App\Models\Guarantee;
use App\Repositories\GuaranteeRepository;

$db = Database::connect();
$guaranteeRepo = new GuaranteeRepository($db);

$baseUrl = 'http://localhost:8000/V3';

echo "--- Starting Batch Print Test ---\n";

// 1. Get existing guarantee 26
$g1 = $guaranteeRepo->find(26);
if (!$g1) {
    die("Guarantee 26 missing. Run test-print.php first.\n");
}
echo "Found Guarantee 1: " . $g1->id . "\n";

// 2. Create another guarantee
$db->exec("DELETE FROM guarantees WHERE guarantee_number = 'BATCH-002'");
$g2 = $guaranteeRepo->create(new Guarantee(
    null,
    'BATCH-002',
    [
        'supplier' => 'شركة الطباعة الحديثة',
        'bank' => 'بنك الرياض',
        'amount' => 50000,
        'type' => 'Final'
    ],
    'test_batch'
));
echo "Created Guarantee 2: " . $g2->id . "\n";

// Create Decision for G2 (Reuse existing supplier/bank from G1 logic if possible, or insert new)
// We'll reuse Supplier 53 and Bank 39 from previous run (hardcoded assumptions based on previous output or fetch dynamically)
// Let's fetch IDs dynamically
$supId = $db->query("SELECT id FROM suppliers WHERE supplier_normalized_key = 'SHRKA ALTB'")->fetchColumn();
$bnkId = $db->query("SELECT id FROM banks WHERE short_code = 'RZY'")->fetchColumn();

if ($supId && $bnkId) {
    $stmt = $db->prepare("INSERT INTO guarantee_decisions (guarantee_id, supplier_id, bank_id, decision_source, status, created_at) VALUES (?, ?, ?, 'manual', 'ready', ?)");
    $stmt->execute([$g2->id, $supId, $bnkId, date('Y-m-d H:i:s')]);
    echo "Linked G2 to Supplier $supId / Bank $bnkId\n";
} else {
    echo "Warning: Supplier/Bank not found, G2 will print with raw data.\n";
}

// 3. Test Batch URL
$ids = [$g1->id, $g2->id];
$url = "$baseUrl/views/batch-print.php?ids=" . implode(',', $ids) . "&action=extension";
echo "Fetching: $url\n";

$html = file_get_contents($url);

if (substr_count($html, 'letter-preview') >= 2) {
    echo "SUCCESS: Found multiple letters in batch view.\n";
    file_put_contents('V3/batch_print_test_output.html', $html);
} else {
    echo "FAIL: Expected 2 letters.\n";
}
