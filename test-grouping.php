<?php
require_once 'setup/SetupDatabase.php';

$db = SetupDatabase::connect();

// Get all banks
$stmt = $db->query('SELECT * FROM temp_banks ORDER BY occurrence_count DESC');
$banks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by normalized name
$grouped = [];
foreach ($banks as $bank) {
    $norm = $bank['normalized_name'];
    if (!isset($grouped[$norm])) {
        $grouped[$norm] = [
            'arabic' => '',
            'english' => '',
            'ids' => [],
            'total_count' => 0
        ];
    }
    
    // Detect if it's Arabic or English
    if (preg_match('/[\p{Arabic}]/u', $bank['bank_name'])) {
        $grouped[$norm]['arabic'] = $bank['bank_name'];
    } else {
        $grouped[$norm]['english'] = $bank['bank_name'];
    }
    
    $grouped[$norm]['ids'][] = $bank['id'];
    $grouped[$norm]['total_count'] += $bank['occurrence_count'];
}

echo "=== البنوك المجمعة ===\n\n";
foreach ($grouped as $norm => $data) {
    echo "عربي: " . ($data['arabic'] ?: '-') . "\n";
    echo "إنجليزي: " . ($data['english'] ?: '-') . "\n";
    echo "تكرار: " . $data['total_count'] . "\n";
    echo "---\n";
}
