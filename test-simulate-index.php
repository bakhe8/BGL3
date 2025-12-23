<?php
// Simulate exactly what index.php does
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;
use App\Repositories\GuaranteeRepository;
use App\Repositories\GuaranteeDecisionRepository;

$db = Database::connect();
$guaranteeRepo = new GuaranteeRepository($db);
$decisionRepo = new GuaranteeDecisionRepository($db);

$requestedId = 1;
$currentRecord = null;

if ($requestedId) {
    $currentRecord = $guaranteeRepo->find($requestedId);
}

if (!$currentRecord) {
    $allGuarantees = $guaranteeRepo->getAll([], 1, 0);
    $currentRecord = $allGuarantees[0] ?? null;
}

echo "Current Record ID: " . ($currentRecord ? $currentRecord->id : 'NULL') . "\n";
echo "Guarantee Number: " . ($currentRecord ? $currentRecord->guaranteeNumber : 'NULL') . "\n\n";

if ($currentRecord) {
    $raw = $currentRecord->rawData;
    
    echo "Raw Data:\n";
    print_r($raw);
    
    echo "\n\nBuilding mockRecord:\n";
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
    
    echo "\nmockRecord:\n";
    print_r($mockRecord);
}
