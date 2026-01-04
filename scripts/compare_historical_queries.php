<?php
/**
 * Compare Historical Queries (Before vs After)
 * 
 * Purpose: Verify new query (indexed column) returns same results as old (JSON LIKE)
 * Run: AFTER migration and population
 */

require __DIR__ . '/../app/Support/Database.php';
require __DIR__ . '/../app/Repositories/GuaranteeDecisionRepository.php';

use App\Support\Database;
use App\Repositories\GuaranteeDecisionRepository;

echo "=================================================\n";
echo "Comparing Historical Queries\n";
echo "=================================================\n\n";

// Load baseline
$baselineFile = __DIR__ . '/../baselines/historical_baseline.json';

if (!file_exists($baselineFile)) {
    echo "❌ ERROR: Baseline file not found: $baselineFile\n";
    echo "Run: php scripts/capture_historical_baseline.php first\n";
    exit(1);
}

$baseline = json_decode(file_get_contents($baselineFile), true);
echo "Loaded baseline with " . count($baseline) . " inputs\n\n";

// Initialize repository (now using NEW query with indexed column)
$repo = new GuaranteeDecisionRepository();

$errors = [];
$matches = 0;

foreach ($baseline as $normalized => $baselineData) {
    if (isset($baselineData['error'])) {
        echo "⚠️  Skipping '$normalized' (had error in baseline)\n";
        continue;
    }
    
    $expected = $baselineData['selections'];
    
    try {
        $actual = $repo->getHistoricalSelections($normalized);
        
        // Compare
        if (json_encode($expected) === json_encode($actual)) {
            echo "✓ Match: $normalized\n";
            $matches++;
        } else {
            echo "✗ MISMATCH: $normalized\n";
            echo "  Expected: " . json_encode($expected) . "\n";
            echo "  Actual:   " . json_encode($actual) . "\n";
            $errors[] = $normalized;
        }
        
    } catch (Exception $e) {
        echo "✗ ERROR for '$normalized': {$e->getMessage()}\n";
        $errors[] = $normalized;
    }
}

echo "\n";
echo "=================================================\n";

if (count($errors) === 0) {
    echo "✅ Comparison PASSED\n";
    echo "=================================================\n";
    echo "All queries return identical results.\n";
    echo "Matches: $matches\n";
    exit(0);
} else {
    echo "❌ Comparison FAILED\n";
    echo "=================================================\n";
    echo "Found " . count($errors) . " mismatches:\n";
    foreach ($errors as $input) {
        echo "  - $input\n";
    }
    echo "\n";
    echo "CRITICAL: Query results changed after migration.\n";
    echo "RECOMMENDATION: ROLLBACK immediately and investigate.\n";
    exit(1);
}
