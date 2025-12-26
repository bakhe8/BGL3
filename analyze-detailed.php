<?php
require_once 'setup/SimpleXlsxReader.php';

$file = 'setup/input/excel/ضمانات للتمديد يناير 2025.xlsx';
$data = SimpleXlsxReader::read($file);

$headers = $data[0];

// Find non-empty columns
$validCols = [];
foreach ($headers as $i => $h) {
    if (!empty(trim($h))) {
        $validCols[$i] = $h;
    }
}

echo "=== جميع الأعمدة غير الفارغة ===\n\n";
foreach ($validCols as $i => $h) {
    echo "[$i] $h\n";
}

echo "\n\n=== عينة من البيانات (10 صفوف) ===\n\n";

// Show data for specific columns
$contractorCol = 1; // CONTRACTOR NAME
$bankNameCol = 4;   // BANK NAME
$bankNameArCol = 5; // اسم البنك

for ($row = 1; $row <= min(10, count($data) - 1); $row++) {
    echo "الصف $row:\n";
    echo "  [CONTRACTOR NAME]: " . (isset($data[$row][$contractorCol]) ? trim($data[$row][$contractorCol]) : 'فارغ') . "\n";
    echo "  [BANK NAME]: " . (isset($data[$row][$bankNameCol]) ? trim($data[$row][$bankNameCol]) : 'فارغ') . "\n";
    echo "  [اسم البنك]: " . (isset($data[$row][$bankNameArCol]) ? trim($data[$row][$bankNameArCol]) : 'فارغ') . "\n";
    echo "\n";
}
