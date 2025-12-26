<?php
require_once 'setup/SimpleXlsxReader.php';

$file = 'setup/input/excel/ضمانات للتمديد يناير 2025.xlsx';

if (!file_exists($file)) {
    echo "الملف غير موجود!\n";
    exit;
}

echo "=== تحليل ملف Excel ===\n\n";
echo "الملف: " . basename($file) . "\n\n";

$data = SimpleXlsxReader::read($file);

if (empty($data)) {
    echo "لا توجد بيانات\n";
    exit;
}

$headers = $data[0];

echo "أسماء الأعمدة:\n";
echo "================\n";
foreach ($headers as $index => $header) {
    echo "[$index] = \"$header\"\n";
}

echo "\n\nعينة من البيانات (أول 5 صفوف):\n";
echo "================\n";
for ($i = 1; $i <= min(5, count($data) - 1); $i++) {
    echo "\nالصف $i:\n";
    foreach ($headers as $index => $header) {
        $value = isset($data[$i][$index]) ? $data[$i][$index] : '';
        echo "  $header: $value\n";
    }
}

echo "\n\nإحصائيات:\n";
echo "================\n";
echo "عدد الأعمدة: " . count($headers) . "\n";
echo "عدد الصفوف: " . (count($data) - 1) . "\n";
