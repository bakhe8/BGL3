<?php
require_once 'setup/SimpleXlsxReader.php';

$file = 'setup/input/excel/ضمانات للتمديد يناير 2025.xlsx';
$data = SimpleXlsxReader::read($file);

// Get only non-empty columns from first row
$headers = $data[0];
$validColumns = [];

foreach ($headers as $index => $header) {
    if (!empty(trim($header))) {
        $validColumns[$index] = $header;
    }
}

echo "=== الأعمدة غير الفارغة ===\n\n";
foreach ($validColumns as $index => $header) {
    echo "[$index] = \"$header\"\n";
}

echo "\n\n=== عينة من البيانات (أول 3 صفوف) ===\n";
for ($i = 1; $i <= min(3, count($data) - 1); $i++) {
    echo "\nالصف $i:\n";
    foreach ($validColumns as $index => $header) {
        $value = isset($data[$i][$index]) ? $data[$i][$index] : '';
        if (!empty(trim($value))) {
            echo "  [$header]: $value\n";
        }
    }
}
