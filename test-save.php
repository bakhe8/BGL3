<?php
// Simulate what process-word.php does
require __DIR__ . '/setup/SetupDatabase.php';
require __DIR__ . '/setup/SetupNormalizer.php';
require __DIR__ . '/setup/SimpleWordReader.php';

$wordFolder = __DIR__ . '/setup/input/word';
$files = glob($wordFolder . '/*.docx');

if (empty($files)) {
    die("No Word files found\n");
}

$file = $files[0];
echo "Processing: " . basename($file) . "\n\n";

$text = SimpleWordReader::extractText($file);
$extracted = SimpleWordReader::extractSupplierAndBank($text);

echo "Banks with info: " . count($extracted['banks_with_info']) . "\n\n";

if (!empty($extracted['banks_with_info'])) {
    $bankData = $extracted['banks_with_info'][0];
    $normalized = SetupNormalizer::normalize($bankData['bank_name']);
    
    echo "Bank: " . $bankData['bank_name'] . "\n";
    echo "Normalized: " . $normalized . "\n";
    echo "Department: " . ($bankData['department'] ?? 'NULL') . "\n";
    echo "Email: " . ($bankData['email'] ?? 'NULL') . "\n";
    echo "Address: " . ($bankData['address'] ?? 'NULL') . "\n\n";
    
    // Prepare JSON
    $bankInfoJson = json_encode([
        'department' => $bankData['department'] ?? null,
        'email' => $bankData['email'] ?? null,
        'address' => $bankData['address'] ?? null
    ], JSON_UNESCAPED_UNICODE);
    
    echo "JSON to save: " . $bankInfoJson . "\n\n";
    
    // Try to save
    $db = SetupDatabase::connect();
    $stmt = $db->prepare('SELECT id FROM temp_banks WHERE bank_name = ? LIMIT 1');
    $stmt->execute([$bankData['bank_name']]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        echo "Bank exists in DB - would UPDATE\n";
        $stmt = $db->prepare('UPDATE temp_banks SET bank_info = ? WHERE id = ?');
        $stmt->execute([$bankInfoJson, $existing['id']]);
        echo "Updated!\n";
    } else {
        echo "Bank NOT in DB - would INSERT with bank_info\n";
    }
}
