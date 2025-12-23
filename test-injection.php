<?php
// Simple test to see what's actually in the HTML
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;
use App\Repositories\GuaranteeRepository;
use App\Repositories\GuaranteeDecisionRepository;

$db = Database::connect();
$guaranteeRepo = new GuaranteeRepository($db);
$decisionRepo = new GuaranteeDecisionRepository($db);

$allGuarantees = $guaranteeRepo->getAll([], 100, 0);
$currentRecord = null;

$requestedId = 1;
foreach ($allGuarantees as $guarantee) {
    if ($guarantee->id === $requestedId) {
        $currentRecord = $guarantee;
        break;
    }
}

if (!$currentRecord) {
    $currentRecord = $allGuarantees[0] ?? null;
}

if ($currentRecord) {
    $raw = $currentRecord->rawData;
    
    $mockRecord = [
        'id' => $currentRecord->id,
        'session_id' => $raw['session_id'] ?? 0,
        'guarantee_number' => $currentRecord->guaranteeNumber ?? 'N/A',
        'supplier_name' => htmlspecialchars($raw['supplier'] ?? '', ENT_QUOTES),
        'bank_name' => htmlspecialchars($raw['bank'] ?? '', ENT_QUOTES),
        'amount' => is_numeric($raw['amount'] ?? 0) ? floatval($raw['amount']) : 0,
        'expiry_date' => $raw['expiry_date'] ?? date('Y-m-d'),
        'issue_date' => $raw['issue_date'] ?? date('Y-m-d'),
        'contract_number' => htmlspecialchars($raw['contract_number'] ?? '', ENT_QUOTES),
        'type' => htmlspecialchars($raw['type'] ?? 'ابتدائي', ENT_QUOTES),
        'status' => 'pending'
    ];
    
    echo "=== What will be injected into JavaScript ===\n\n";
    echo "record: {\n";
    echo "    id: {$mockRecord['id']},\n";
    echo "    guarantee_number: '{$mockRecord['guarantee_number']}',\n";
    echo "    issue_date: '{$mockRecord['issue_date']}',\n";
    echo "    amount: {$mockRecord['amount']},\n";
    echo "    supplier_name: '{$mockRecord['supplier_name']}',\n";
    echo "    excel_supplier: '{$mockRecord['supplier_name']}',\n";
    echo "    bank_name: '{$mockRecord['bank_name']}',\n";
    echo "    contract_number: '{$mockRecord['contract_number']}',\n";
    echo "    expiry_date: '{$mockRecord['expiry_date']}',\n";
    echo "    type: '{$mockRecord['type']}',\n";
    echo "}\n\n";
    
    echo "=== Expected Display ===\n";
    echo "رقم الضمان: {$mockRecord['guarantee_number']}\n";
    echo "المورد: {$mockRecord['supplier_name']}\n";
    echo "البنك: {$mockRecord['bank_name']}\n";
    echo "المبلغ: " . number_format($mockRecord['amount']) . " ر.س\n";
    echo "تاريخ الانتهاء: {$mockRecord['expiry_date']}\n";
    echo "تاريخ الإصدار: {$mockRecord['issue_date']}\n";
    echo "رقم العقد: {$mockRecord['contract_number']}\n";
    echo "النوع: {$mockRecord['type']}\n";
}
