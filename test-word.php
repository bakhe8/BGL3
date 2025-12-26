<?php
require_once 'setup/SimpleWordReader.php';

$file = 'setup/input/word/الإفراج عن ضمان البنك الأهلي - C194750.docx';

echo "=== اختبار ملف Word ===\n\n";
echo "الملف: " . basename($file) . "\n\n";

try {
    // Extract text
    $text = SimpleWordReader::extractText($file);
    
    echo "النص المستخرج:\n";
    echo "================\n";
    echo $text;
    echo "\n\n";
    
    // Extract suppliers and banks
    $data = SimpleWordReader::extractSupplierAndBank($text);
    
    echo "الموردين المستخرجين:\n";
    echo "================\n";
    foreach ($data['suppliers'] as $supplier) {
        echo "• $supplier\n";
    }
    
    echo "\n\nالبنوك المستخرجة:\n";
    echo "================\n";
    foreach ($data['banks'] as $bank) {
        echo "• $bank\n";
    }
    
} catch (Exception $e) {
    echo "خطأ: " . $e->getMessage() . "\n";
}
