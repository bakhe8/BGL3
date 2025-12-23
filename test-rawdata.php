<?php
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;
use App\Repositories\GuaranteeRepository;

$db = Database::connect();
$guaranteeRepo = new GuaranteeRepository($db);

// Get guarantee ID 1
$allGuarantees = $guaranteeRepo->getAll([], 100, 0);
$guarantee = null;

foreach ($allGuarantees as $g) {
    if ($g->id === 1) {
        $guarantee = $g;
        break;
    }
}

if ($guarantee) {
    echo "=== Guarantee ID: 1 ===\n\n";
    
    echo "rawData content:\n";
    echo str_repeat("=", 80) . "\n";
    print_r($guarantee->rawData);
    
    echo "\n\n" . str_repeat("=", 80) . "\n";
    echo "Checking specific fields:\n";
    echo str_repeat("=", 80) . "\n";
    
    $raw = $guarantee->rawData;
    
    echo "guarantee_number : " . ($guarantee->guaranteeNumber ?? 'N/A') . "\n";
    echo "raw['supplier']  : " . ($raw['supplier'] ?? 'NOT FOUND') . "\n";
    echo "raw['bank']      : " . ($raw['bank'] ?? 'NOT FOUND') . "\n";
    echo "raw['amount']    : " . ($raw['amount'] ?? 'NOT FOUND') . "\n";
    echo "raw['expiry_date']: " . ($raw['expiry_date'] ?? 'NOT FOUND') . "\n";
    echo "raw['contract_number']: " . ($raw['contract_number'] ?? 'NOT FOUND') . "\n";
    echo "raw['issue_date']: " . ($raw['issue_date'] ?? 'NOT FOUND') . "\n";
    echo "raw['type']      : " . ($raw['type'] ?? 'NOT FOUND') . "\n";
    
    echo "\n\nAll keys in rawData:\n";
    echo str_repeat("=", 80) . "\n";
    if (is_array($raw)) {
        foreach (array_keys($raw) as $key) {
            echo "- $key\n";
        }
    }
}
