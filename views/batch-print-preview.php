<?php
/**
 * Batch Print Preview
 * Shows ready vs not-ready guarantees before printing
 * Decision #3: Preview before print
 */

require_once __DIR__ . '/../app/Support/autoload.php';
use App\Support\Database;

$db = Database::connect();
$importSource = $_GET['import_source'] ?? '';

if (!$importSource) {
    die('import_source مطلوب');
}

// Get all guarantees in batch
$stmt = $db->prepare("
    SELECT g.*, d.status, d.supplier_id, d.bank_id
    FROM guarantees g
    LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
    WHERE g.import_source = ?
");
$stmt->execute([$importSource]);
$guarantees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Classify: ready vs not ready
$ready = [];
$notReady = [];

foreach ($guarantees as $g) {
    // Parse raw data
    $parsed = json_decode($g['raw_data'], true);
    $g['parsed'] = $parsed;
    
    // Check if ready for printing
    if ($g['status'] === 'approved' && $g['supplier_id'] && $g['bank_id']) {
        $ready[] = $g;
    } else {
        $reason = 'غير معتمد';
        if (!$g['supplier_id']) {
            $reason = 'لم يُختر المورد';
        } elseif (!$g['bank_id']) {
            $reason = 'لم يُختر البنك';
        }
        
        $notReady[] = [
            'guarantee' => $g,
            'reason' => $reason
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>معاينة الطباعة</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Tajawal', sans-serif; }
        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body class="bg-gray-100 p-6">
    <div class="max-w-5xl mx-auto bg-white rounded-lg shadow-lg p-8">
        <h1 class="text-3xl font-bold mb-6 text-gray-800">معاينة الطباعة</h1>
        
        <!-- Summary -->
        <div class="grid grid-cols-2 gap-4 mb-6">
            <div class="bg-green-50 border border-green-200 rounded p-4">
                <div class="text-green-800 text-2xl font-bold"><?= count($ready) ?></div>
                <div class="text-green-600">خطاب جاهز للطباعة ✓</div>
            </div>
            <div class="bg-red-50 border border-red-200 rounded p-4">
                <div class="text-red-800 text-2xl font-bold"><?= count($notReady) ?></div>
                <div class="text-red-600">خطاب غير جاهز ✗</div>
            </div>
        </div>
        
        <!-- Not Ready Details -->
        <?php if (!empty($notReady)): ?>
        <div class="mb-8">
            <h2 class="text-xl font-bold mb-3 text-red-700">⚠️ الخطابات غير الجاهزة</h2>
            <div class="bg-red-50 border border-red-200 rounded overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-red-100">
                        <tr>
                            <th class="border-b border-red-200 p-3 text-right">رقم الضمان</th>
                            <th class="border-b border-red-200 p-3 text-right">المورد</th>
                            <th class="border-b border-red-200 p-3 text-right">السبب</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($notReady as $item): ?>
                        <tr>
                            <td class="border-b border-red-100 p-3 font-semibold">
                                <?= htmlspecialchars($item['guarantee']['guarantee_number']) ?>
                            </td>
                            <td class="border-b border-red-100 p-3">
                                <?= htmlspecialchars($item['guarantee']['parsed']['supplier'] ?? '-') ?>
                            </td>
                            <td class="border-b border-red-100 p-3 text-red-700">
                                <?= htmlspecialchars($item['reason']) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Ready List (Preview) -->
        <?php if (!empty($ready)): ?>
        <div class="mb-8">
            <h2 class="text-xl font-bold mb-3 text-green-700">✓ الخطابات الجاهزة</h2>
            <div class="bg-green-50 border border-green-200 rounded p-4">
                <div class="grid grid-cols-3 gap-2 text-sm">
                    <?php foreach ($ready as $g): ?>
                    <div class="text-gray-700">
                        • <?= htmlspecialchars($g['guarantee_number']) ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Actions -->
        <div class="flex gap-3 no-print">
            <?php if (count($ready) > 0): ?>
            <button onclick="printReady()" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded text-lg font-semibold transition">
                طباعة الجاهز فقط (<?= count($ready) ?> خطاب)
            </button>
            <?php else: ?>
            <div class="flex-1 bg-gray-300 text-gray-600 px-6 py-3 rounded text-center">
                لا توجد خطابات جاهزة للطباعة
            </div>
            <?php endif; ?>
            <button onclick="window.close()" class="bg-gray-600 hover:bg-gray-700 text-white px-6 py-3 rounded transition">
                إلغاء
            </button>
        </div>
    </div>
    
    <script>
    function printReady() {
        const ids = <?= json_encode(array_column($ready, 'id')) ?>;
        if (ids.length === 0) {
            alert('لا توجد خطابات جاهزة');
            return;
        }
        
        // TODO: Integrate with existing batch-print.php
        // Assuming it accepts ?ids=1,2,3&action=extension
        window.open(`/views/batch-print.php?ids=${ids.join(',')}&action=extension`);
    }
    </script>
</body>
</html>
