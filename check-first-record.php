<?php
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;
use App\Repositories\GuaranteeRepository;

$db = Database::connect();
$guaranteeRepo = new GuaranteeRepository($db);

// Get first record (what shows when opening without ?id=)
$allGuarantees = $guaranteeRepo->getAll([], 1, 0);
$firstRecord = $allGuarantees[0] ?? null;

if ($firstRecord) {
    echo "First record (shown at http://localhost:8000/V3/):\n";
    echo str_repeat("=", 80) . "\n";
    echo "ID: " . $firstRecord->id . "\n";
    echo "Guarantee Number: " . $firstRecord->guaranteeNumber . "\n\n";
    
    echo "Raw Data:\n";
    print_r($firstRecord->rawData);
    
    echo "\n\n" . str_repeat("=", 80) . "\n";
    echo "What SHOULD be displayed:\n";
    $raw = $firstRecord->rawData;
    echo "المورد (supplier): " . ($raw['supplier'] ?? 'N/A') . "\n";
    echo "البنك (bank): " . ($raw['bank'] ?? 'N/A') . "\n";
    echo "المبلغ (amount): " . ($raw['amount'] ?? 'N/A') . "\n";
    echo "تاريخ الانتهاء (expiry_date): " . ($raw['expiry_date'] ?? 'N/A') . "\n";
    echo "رقم العقد (contract_number): " . ($raw['contract_number'] ?? 'N/A') . "\n";
    echo "تاريخ الإصدار (issue_date): " . ($raw['issue_date'] ?? 'N/A') . "\n";
    echo "النوع (type): " . ($raw['type'] ?? 'N/A') . "\n";
    
    echo "\n\n" . str_repeat("=", 80) . "\n";
    echo "All keys in rawData:\n";
    foreach (array_keys($raw) as $key) {
        echo "- $key: " . $raw[$key] . "\n";
    }
}
