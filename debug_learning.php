<?php
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;

try {
    $db = Database::connect();
    echo "Database Connected\n";

    $learningRepo = new \App\Repositories\SupplierLearningRepository($db);
    $supplierRepo = new \App\Repositories\SupplierRepository();
    $learningService = new \App\Services\LearningService($learningRepo, $supplierRepo);

    // Test with an existing supplier name part
    $testName = "الشبكات"; 
    echo "Getting suggestions for: '$testName'...\n";
    
    $suggestions = $learningService->getSuggestions($testName);
    
    echo "Suggestions found: " . count($suggestions) . "\n";
    print_r($suggestions);

} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
