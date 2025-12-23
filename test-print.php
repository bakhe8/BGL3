<?php
/**
 * Test Print System
 */
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;
use App\Models\Bank;
use App\Repositories\BankRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\GuaranteeRepository;

$baseUrl = 'http://localhost:8000/V3';

echo "--- Starting Print System Test ---\n";

// 1. Setup Data
$db = Database::connect();
$supplierRepo = new SupplierRepository();
$bankRepo = new BankRepository();
$guaranteeRepo = new GuaranteeRepository($db);

// Cleanup
$db->exec("DELETE FROM guarantee_decisions WHERE guarantee_id IN (SELECT id FROM guarantees WHERE guarantee_number = 'PRT-001')");
$db->exec("DELETE FROM guarantees WHERE guarantee_number = 'PRT-001'");
$db->exec("DELETE FROM suppliers WHERE supplier_normalized_key = 'SHRKA ALTB'");
$db->exec("DELETE FROM banks WHERE normalized_name = 'BNK ALRYAD' OR short_code = 'RZY'");

// Create Supplier
$supplierId = $db->query("INSERT INTO suppliers (official_name, normalized_name, supplier_normalized_key) VALUES ('شركة الطباعة الحديثة', 'شركة الطباعة الحديثة', 'SHRKA ALTB') RETURNING id")->fetchColumn();
echo "Created Supplier ID: $supplierId\n";

// Create Bank
// We use the new columns
$stmt = $db->prepare("INSERT INTO banks (official_name, normalized_name, department, address_line_1, address_line_2, short_code) VALUES ('بنك الرياض', 'BNK ALRYAD', 'إدارة الضمانات - الرياض', 'طريق الملك فهد', 'الرياض 11411', 'RZY') RETURNING id");
$stmt->execute();
$bankId = $stmt->fetchColumn();
echo "Created Bank ID: $bankId\n";

// Update Bank Address (Manual Update for test)
// Note: This might fail if columns don't exist in DB, but we updated the Model/Repo assume columns exist?
// Wait, I updated Model/Repo but I didn't verify Schema!
// If columns department, address_line_1, etc. don't exist in DB, print view will fail or show defaults.
// Let's check schema/migration first? 
// Schema was imported from V2. In V2 banks table had address? 
// I'll assume they might not exist, but let's try to proceed. 

// Create Guarantee
$guaranteeId = $guaranteeRepo->create(new \App\Models\Guarantee(
    null, 
    'PRT-001', 
    [
        'supplier' => 'شركة الطباعة الحديثة',
        'bank' => 'بنك الرياض',
        'amount' => 75000,
        'type' => 'Initial'
    ],
    'test_print'
))->id;
echo "Created Guarantee ID: $guaranteeId\n";

// Create Decision
$stmt = $db->prepare("INSERT INTO guarantee_decisions (guarantee_id, supplier_id, bank_id, decision_source, status, created_at) VALUES (?, ?, ?, 'manual', 'ready', ?)");
$stmt->execute([$guaranteeId, $supplierId, $bankId, date('Y-m-d H:i:s')]);

echo "Created Decision linked to Supplier/Bank\n";

// 2. Test Print View
$url = "$baseUrl/views/print.php?id=$guaranteeId&action=extension";
echo "Fetching: $url\n";

$html = file_get_contents($url);

if (str_contains($html, 'شركة الطباعة الحديثة') && str_contains($html, 'بنك الرياض')) {
    echo "SUCCESS: Print view contains correct data.\n";
    file_put_contents('V3/print_test_output.html', $html);
    echo "Saved output to V3/print_test_output.html\n";
} else {
    echo "FAIL: Print view missing data.\n";
    echo substr($html, 0, 500) . "...\n";
}
