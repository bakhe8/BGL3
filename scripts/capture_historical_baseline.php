<?php
/**
 * Capture Historical Query Baseline
 * 
 * Purpose: Record results of historical selection queries BEFORE migration
 * This baseline will be used to verify queries return same results after migration
 * 
 * Run: BEFORE schema migration
 */

require __DIR__ . '/../app/Support/Database.php';
require __DIR__ . '/../app/Repositories/GuaranteeDecisionRepository.php';
require __DIR__ . '/../app/Utils/ArabicNormalizer.php';

use App\Support\Database;
use App\Repositories\GuaranteeDecisionRepository;
use App\Utils\ArabicNormalizer;

echo "=================================================\n";
echo "Capturing Historical Query Baseline\n";
echo "=================================================\n\n";

// Test inputs: Real supplier names from your system
// TODO: Replace with actual supplier names from your database
$testInputs = [
    'شركة النورس',
    'شركة الصقر',
    'مؤسسة البناء',
    'شركة المستقبل',
    'مؤسسة التطوير',
    'شركة الأمل',
    'شركة النجاح',
    'مؤسسة الرواد',
    'شركة الإنجاز',
    'شركة التميز',
    // Add more real supplier names
];

// Alternative: Extract from database
echo "Extracting real supplier names from database...\n";
$db = Database::connect();
$stmt = $db->query("
    SELECT DISTINCT json_extract(raw_data, '$.supplier') as name
    FROM guarantees
    WHERE json_extract(raw_data, '$.supplier') IS NOT NULL
    LIMIT 100
");

$extractedNames = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if ($row['name']) {
        $extractedNames[] = $row['name'];
    }
}

// Use extracted names if available, otherwise use manual list
$testInputs = !empty($extractedNames) ? $extractedNames : $testInputs;
echo "Using " . count($testInputs) . " test inputs\n\n";

// Initialize repository
$repo = new GuaranteeDecisionRepository();
$baseline = [];

// Capture baseline for each input
foreach ($testInputs as $rawInput) {
    $normalized = ArabicNormalizer::normalize($rawInput);
    
    try {
        $selections = $repo->getHistoricalSelections($normalized);
        
        $baseline[$normalized] = [
            'raw_input' => $rawInput,
            'normalized_input' => $normalized,
            'selections' => $selections,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        $count = count($selections);
        echo "✓ $normalized: $count selections\n";
        
    } catch (Exception $e) {
        echo "✗ Error for '$rawInput': {$e->getMessage()}\n";
        $baseline[$normalized] = [
            'raw_input' => $rawInput,
            'normalized_input' => $normalized,
            'error' => $e->getMessage()
        ];
    }
}

// Save baseline
$baselineDir = __DIR__ . '/../baselines';
if (!is_dir($baselineDir)) {
    mkdir($baselineDir, 0755, true);
}

$baselineFile = $baselineDir . '/historical_baseline.json';
$json = json_encode($baseline, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
file_put_contents($baselineFile, $json);

echo "\n";
echo "=================================================\n";
echo "✅ Baseline Captured\n";
echo "=================================================\n";
echo "Saved to: $baselineFile\n";
echo "Inputs: " . count($baseline) . "\n";
echo "\n";
echo "Next: Run schema migration, then compare results\n";
