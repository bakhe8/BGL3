<?php
/**
 * Capture E2E Suggestion Baseline
 * 
 * Purpose: Record full suggestion results BEFORE migration
 * Captures: supplier_id, confidence, order for 100 real inputs
 * 
 * Run: BEFORE schema migration
 */

// Use proper autoloader
require __DIR__ . '/../index.php';  // This loads all classes properly

// Also direct requires for safety
require_once __DIR__ . '/../app/Support/Database.php';


echo "=================================================\n";
echo "Capturing E2E Suggestion Baseline\n";
echo "=================================================\n\n";

// Extract real supplier names from database
echo "Extracting 100 real supplier names from database...\n";
$db = Database::connect();
$stmt = $db->query("
    SELECT DISTINCT json_extract(raw_data, '$.supplier') as name
    FROM guarantees
    WHERE json_extract(raw_data, '$.supplier') IS NOT NULL
    ORDER BY RANDOM()
    LIMIT 100
");

$testInputs = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if ($row['name']) {
        $testInputs[] = $row['name'];
    }
}

if (count($testInputs) < 10) {
    echo "❌ ERROR: Not enough supplier names in database (found " . count($testInputs) . ")\n";
    echo "Need at least 10 for meaningful baseline.\n";
    exit(1);
}

echo "Using " . count($testInputs) . " test inputs\n\n";

// Initialize authority
$authority = AuthorityFactory::create();
$baseline = [];
$errors = 0;

// Capture suggestions for each input
foreach ($testInputs as $index => $rawInput) {
    $num = $index + 1;
    
    try {
        $suggestions = $authority->getSuggestions($rawInput);
        
        // Serialize suggestions (only relevant fields)
        $serialized = array_map(function($s) {
            return [
                'supplier_id' => $s->supplier_id,
                'confidence' => $s->confidence,
                'level' => $s->level,
                'official_name' => $s->official_name,
                'primary_source' => $s->primary_source ?? null
            ];
        }, $suggestions);
        
        $baseline[$rawInput] = [
            'suggestions' => $serialized,
            'count' => count($suggestions),
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        $count = count($suggestions);
        echo "[$num/" . count($testInputs) . "] $rawInput: $count suggestions\n";
        
    } catch (Exception $e) {
        echo "[$num/" . count($testInputs) . "] ✗ Error for '$rawInput': {$e->getMessage()}\n";
        $baseline[$rawInput] = [
            'error' => $e->getMessage()
        ];
        $errors++;
    }
}

// Save baseline
$baselineDir = __DIR__ . '/../baselines';
if (!is_dir($baselineDir)) {
    mkdir($baselineDir, 0755, true);
}

$baselineFile = $baselineDir . '/e2e_baseline_100.json';
$json = json_encode($baseline, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
file_put_contents($baselineFile, $json);

// Also save test inputs separately
$inputsFile = $baselineDir . '/test_inputs_100.json';
file_put_contents($inputsFile, json_encode($testInputs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo "\n";
echo "=================================================\n";
echo "✅ E2E Baseline Captured\n";
echo "=================================================\n";
echo "Saved to: $baselineFile\n";
echo "Test inputs saved to: $inputsFile\n";
echo "Total inputs: " . count($baseline) . "\n";
echo "Errors: $errors\n";
echo "\n";
echo "Next: Run migration, then compare with this baseline\n";
