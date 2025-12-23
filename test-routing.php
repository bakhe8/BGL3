<?php
// Test routing logic
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;
use App\Repositories\GuaranteeRepository;

$db = Database::connect();
$guaranteeRepo = new GuaranteeRepository($db);

$allGuarantees = $guaranteeRepo->getAll([], 100, 0);

echo "Total guarantees: " . count($allGuarantees) . "\n\n";

// Simulate routing logic
$requestedId = isset($_GET['id']) ? (int)$_GET['id'] : null;
echo "Requested ID from URL: " . ($requestedId ?? 'NULL') . "\n\n";

$currentRecord = null;

if ($requestedId) {
    foreach ($allGuarantees as $guarantee) {
        if ($guarantee->id === $requestedId) {
            $currentRecord = $guarantee;
            break;
        }
    }
}

if (!$currentRecord) {
    $currentRecord = $allGuarantees[0] ?? null;
    echo "Using fallback: first record\n";
} else {
    echo "Found requested record\n";
}

if ($currentRecord) {
    echo "\nSelected Record:\n";
    echo "ID: " . $currentRecord->id . "\n";
    echo "Guarantee Number: " . $currentRecord->guaranteeNumber . "\n";
    echo "Supplier: " . ($currentRecord->rawData['supplier'] ?? 'N/A') . "\n";
    echo "Bank: " . ($currentRecord->rawData['bank'] ?? 'N/A') . "\n";
}

echo "\n\nFirst 5 records in database:\n";
foreach (array_slice($allGuarantees, 0, 5) as $g) {
    echo "ID={$g->id}: {$g->guaranteeNumber} - {$g->rawData['supplier']}\n";
}
