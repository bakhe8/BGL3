<?php
require_once 'setup/SimpleXlsxReader.php';

$file = 'setup/input/excel/ضمانات للتمديد يناير 2025.xlsx';
$data = SimpleXlsxReader::read($file);

$headers = $data[0];

echo "=== الأعمدة المهمة ===\n\n";
echo "[1] CONTRACTOR NAME - عمود الموردين\n";
echo "[4] BANK NAME - عمود البنوك (إنجليزي)\n";
echo "[5] اسم البنك - عمود البنوك (عربي)\n\n";

echo "=== جميع الصفوف (28 صف) ===\n\n";

$contractors = [];
$banks_en = [];
$banks_ar = [];

for ($i = 1; $i < count($data); $i++) {
    $contractor = isset($data[$i][1]) ? trim($data[$i][1]) : '';
    $bank_en = isset($data[$i][4]) ? trim($data[$i][4]) : '';
    $bank_ar = isset($data[$i][5]) ? trim($data[$i][5]) : '';
    
    echo "الصف $i:\n";
    echo "  مورد: $contractor\n";
    echo "  بنك EN: $bank_en\n";
    echo "  بنك AR: $bank_ar\n\n";
    
    if (!empty($contractor)) $contractors[$contractor] = ($contractors[$contractor] ?? 0) + 1;
    if (!empty($bank_en)) $banks_en[$bank_en] = ($banks_en[$bank_en] ?? 0) + 1;
    if (!empty($bank_ar)) $banks_ar[$bank_ar] = ($banks_ar[$bank_ar] ?? 0) + 1;
}

echo "\n\n=== إحصائيات ===\n\n";
echo "الموردين الفريدين: " . count($contractors) . "\n";
arsort($contractors);
foreach ($contractors as $name => $count) {
    echo "  • $name ($count مرة)\n";
}

echo "\n\nالبنوك الإنجليزية: " . count($banks_en) . "\n";
arsort($banks_en);
foreach ($banks_en as $name => $count) {
    echo "  • $name ($count مرة)\n";
}

echo "\n\nالبنوك العربية: " . count($banks_ar) . "\n";
arsort($banks_ar);
foreach ($banks_ar as $name => $count) {
    echo "  • $name ($count مرة)\n";
}
