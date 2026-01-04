<?php
/**
 * E2E Comparison Test
 * 
 * Purpose: Compare suggestions AFTER migration with baseline
 * Success: 99%+ match (allow 1-2 edge cases with documentation)
 * 
 * Run: AFTER migration and code updates
 */

require __DIR__ . '/../app/Support/Database.php';
require __DIR__ . '/../app/Services/Learning/AuthorityFactory.php';

use App\Support\Database;
use App\Services\Learning\AuthorityFactory;

echo "=================================================\n";
echo "E2E Comparison Test\n";
echo "=================================================\n\n";

// Load baseline
$baselineFile = __DIR__ . '/../baselines/e2e_baseline_100.json';

if (!file_exists($baselineFile)) {
    echo "❌ ERROR: Baseline file not found: $baselineFile\n";
    echo "Run: php scripts/create_e2e_baseline.php first\n";
    exit(1);
}

$baseline = json_decode(file_get_contents($baselineFile), true);
echo "Loaded baseline with " . count($baseline) . " inputs\n\n";

// Initialize authority (now using NEW code with indexed columns)
$authority = AuthorityFactory::create();

$errors = [];
$matches = 0;
$tested = 0;

foreach ($baseline as $rawInput => $baselineData) {
    if (isset($baselineData['error'])) {
        echo "⚠️  Skipping '$rawInput' (had error in baseline)\n";
        continue;
    }
    
    $expected = $baselineData['suggestions'];
    $tested++;
    
    try {
        $actual = $authority->getSuggestions($rawInput);
        
        // Serialize actual (same format as baseline)
        $serialized = array_map(function($s) {
            return [
                'supplier_id' => $s->supplier_id,
                'confidence' => $s->confidence,
                'level' => $s->level,
                'official_name' => $s->official_name,
                'primary_source' => $s->primary_source ?? null
            ];
        }, $actual);
        
        // Compare
        if (count($expected) !== count($serialized)) {
            echo "✗ Count mismatch for '$rawInput': expected " . count($expected) . ", got " . count($serialized) . "\n";
            $errors[] = [
                'input' => $rawInput,
                'reason' => 'count_mismatch',
                'expected_count' => count($expected),
                'actual_count' => count($serialized)
            ];
            continue;
        }
        
        // Compare each suggestion
        $mismatch = false;
        for ($i = 0; $i < count($expected); $i++) {
            if ($expected[$i]['supplier_id'] !== $serialized[$i]['supplier_id']) {
                echo "✗ Supplier mismatch at position $i for '$rawInput'\n";
                $errors[] = [
                    'input' => $rawInput,
                    'reason' => 'supplier_mismatch',
                    'position' => $i,
                    'expected_id' => $expected[$i]['supplier_id'],
                    'actual_id' => $serialized[$i]['supplier_id']
                ];
                $mismatch = true;
                break;
            }
            
            // Allow confidence difference of ±1 (rounding)
            if (abs($expected[$i]['confidence'] - $serialized[$i]['confidence']) > 1) {
                echo "✗ Confidence mismatch for '$rawInput': expected {$expected[$i]['confidence']}, got {$serialized[$i]['confidence']}\n";
                $errors[] = [
                    'input' => $rawInput,
                    'reason' => 'confidence_mismatch',
                    'supplier_id' => $expected[$i]['supplier_id'],
                    'expected_conf' => $expected[$i]['confidence'],
                    'actual_conf' => $serialized[$i]['confidence']
                ];
                $mismatch = true;
                break;
            }
        }
        
        if (!$mismatch) {
            echo "✓ Match: $rawInput (" . count($serialized) . " suggestions)\n";
            $matches++;
        }
        
    } catch (Exception $e) {
        echo "✗ ERROR for '$rawInput': {$e->getMessage()}\n";
        $errors[] = [
            'input' => $rawInput,
            'reason' => 'exception',
            'message' => $e->getMessage()
        ];
    }
}

echo "\n";
echo "=================================================\n";
echo "E2E Test Results\n";
echo "=================================================\n";
echo "Tested: $tested inputs\n";
echo "Matches: $matches\n";
echo "Errors: " . count($errors) . "\n";

$matchRate = $tested > 0 ? ($matches / $tested) * 100 : 0;
echo "Match Rate: " . number_format($matchRate, 1) . "%\n";
echo "\n";

// Acceptance criteria: 99%+ match (allow 1-2 edge cases)
if (count($errors) === 0) {
    echo "✅ E2E Test PASSED (100% match)\n";
    exit(0);
} elseif (count($errors) <= 2 && $matchRate >= 99.0) {
    echo "⚠️  E2E Test PASSED with exceptions (≥99% match)\n";
    echo "\nEdge cases found ($errors):\n";
    foreach ($errors as $error) {
        echo "  - {$error['input']}: {$error['reason']}\n";
    }
    echo "\nREVIEW REQUIRED: Document these edge cases before proceeding.\n";
    exit(0);
} else {
    echo "❌ E2E Test FAILED (< 99% match)\n";
    echo "\nErrors:\n";
    foreach ($errors as $error) {
        echo "  - {$error['input']}: {$error['reason']}\n";
    }
    echo "\n";
    echo "CRITICAL: Too many mismatches or systematic issue detected.\n";
    echo "RECOMMENDATION: ROLLBACK and investigate.\n";
    exit(1);
}
