<?php
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;
use App\Repositories\GuaranteeRepository;
use App\Repositories\GuaranteeDecisionRepository;

$db = Database::connect();
$guaranteeRepo = new GuaranteeRepository($db);
$decisionRepo = new GuaranteeDecisionRepository($db);

// Get guarantee ID 1
$allGuarantees = $guaranteeRepo->getAll([], 100, 0);
$currentRecord = null;

foreach ($allGuarantees as $g) {
    if ($g->id === 1) {
        $currentRecord = $g;
        break;
    }
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
    
    // Get decision if exists
    $decision = $decisionRepo->findByGuarantee($currentRecord->id);
    if ($decision) {
        $mockRecord['status'] = $decision->status;
    }
    
    echo "=== mockRecord that will be passed to JavaScript ===\n\n";
    echo json_encode($mockRecord, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    echo "\n\n=== Field by Field ===\n";
    echo str_repeat("=", 80) . "\n";
    foreach ($mockRecord as $key => $value) {
        printf("%-20s : %s\n", $key, $value);
    }
}
