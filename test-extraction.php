<?php
require __DIR__ . '/setup/SimpleWordReader.php';

$wordFolder = __DIR__ . '/setup/input/word';
$files = glob($wordFolder . '/*.docx');

if (empty($files)) {
    die("No Word files found in $wordFolder\n");
}

$file = $files[0];
echo "Testing: " . basename($file) . "\n\n";

$text = SimpleWordReader::extractText($file);
$extracted = SimpleWordReader::extractSupplierAndBank($text);

echo "=== Extraction Test ===\n\n";

echo "Banks found: " . count($extracted['banks']) . "\n";
echo "Banks with info: " . count($extracted['banks_with_info']) . "\n\n";

if (!empty($extracted['banks_with_info'])) {
    foreach ($extracted['banks_with_info'] as $bankData) {
        echo "Bank: " . $bankData['bank_name'] . "\n";
        echo "  Department: " . ($bankData['department'] ?: 'NULL') . "\n";
        echo "  Email: " . ($bankData['email'] ?: 'NULL') . "\n";
        echo "  Address: " . ($bankData['address'] ?: 'NULL') . "\n";
        echo "---\n";
    }
}
