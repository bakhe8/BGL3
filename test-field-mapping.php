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
    echo "Database Fields:\n";
    echo str_repeat("=", 80) . "\n";
    
    // Get all properties
    $reflection = new ReflectionObject($guarantee);
    $properties = $reflection->getProperties();
    
    foreach ($properties as $prop) {
        $prop->setAccessible(true);
        $name = $prop->getName();
        $value = $prop->getValue($guarantee);
        
        // Format value
        if (is_null($value)) {
            $displayValue = 'NULL';
        } elseif (is_bool($value)) {
            $displayValue = $value ? 'true' : 'false';
        } elseif (is_array($value) || is_object($value)) {
            $displayValue = json_encode($value);
        } else {
            $displayValue = $value;
        }
        
        printf("%-25s : %s\n", $name, $displayValue);
    }
    
    echo "\n" . str_repeat("=", 80) . "\n";
    echo "\nExpected Display Mapping:\n";
    echo str_repeat("=", 80) . "\n";
    echo "رقم الضمان (guarantee_number) : " . ($guarantee->guaranteeNumber ?? 'N/A') . "\n";
    echo "المورد (supplier_name)        : " . ($guarantee->supplierName ?? 'N/A') . "\n";
    echo "البنك (bank_name)              : " . ($guarantee->bankName ?? 'N/A') . "\n";
    echo "المبلغ (amount)                : " . ($guarantee->amount ?? 'N/A') . "\n";
    echo "تاريخ الانتهاء (expiry_date)   : " . ($guarantee->expiryDate ?? 'N/A') . "\n";
    echo "رقم العقد (contract_number)    : " . ($guarantee->contractNumber ?? 'N/A') . "\n";
    echo "تاريخ الإصدار (issue_date)     : " . ($guarantee->issueDate ?? 'N/A') . "\n";
    echo "النوع (type)                   : " . ($guarantee->type ?? 'N/A') . "\n";
}
