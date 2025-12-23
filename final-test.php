<?php
$_GET['id'] = 1;

require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;
use App\Repositories\GuaranteeRepository;
use App\Repositories\GuaranteeDecisionRepository;

$db = Database::connect();
$guaranteeRepo = new GuaranteeRepository($db);
$decisionRepo = new GuaranteeDecisionRepository($db);

$requestedId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$currentRecord = null;

if ($requestedId) {
    $currentRecord = $guaranteeRepo->find($requestedId);
}

if (!$currentRecord) {
    $allGuarantees = $guaranteeRepo->getAll([], 1, 0);
    $currentRecord = $allGuarantees[0] ?? null;
}

$totalRecords = $guaranteeRepo->count();

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
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Final Test</title>
</head>
<body>
    <h1>What will be injected into JavaScript:</h1>
    <pre>
record: {
    id: <?= $mockRecord['id'] ?? 0 ?>,
    guarantee_number: '<?= $mockRecord['guarantee_number'] ?>',
    issue_date: '<?= $mockRecord['issue_date'] ?>',
    amount: <?= $mockRecord['amount'] ?>,
    supplier_name: '<?= $mockRecord['supplier_name'] ?>',
    excel_supplier: '<?= $mockRecord['supplier_name'] ?>',
    bank_name: '<?= $mockRecord['bank_name'] ?>',
    contract_number: '<?= $mockRecord['contract_number'] ?? '' ?>',
    expiry_date: '<?= $mockRecord['expiry_date'] ?>',
    type: '<?= $mockRecord['type'] ?>',
    supplier_id: null
}
    </pre>
</body>
</html>
