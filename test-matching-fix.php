<?php
// Fix Matching Verification Script
require_once __DIR__ . '/app/Support/autoload.php';

use App\Services\MatchingService;
use App\Repositories\SupplierRepository;
use App\Repositories\SupplierAlternativeNameRepository;
use App\Repositories\BankRepository;
use App\Support\Database;

try {
    echo "1. Connecting to DB...\n";
    $db = Database::connect();
    
    echo "2. Instantiating Services...\n";
    // Using default arguments which should now trigger the correct internal instantiation of SupplierLearningCacheRepository
    $service = new MatchingService(
        new SupplierRepository($db),
        new SupplierAlternativeNameRepository($db),
        new BankRepository($db)
    );
    
    echo "3. Testing matchSupplier('Test')...\n";
    // This previously crashed because it tried to new up SupplierSuggestionRepository
    $result = $service->matchSupplier('Test Supplier Name');
    
    echo "✅ Success! MatchingService works.\n";
    echo "Result status: " . $result['match_status'] . "\n";
    
} catch (\Throwable $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
