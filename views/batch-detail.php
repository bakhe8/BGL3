<?php
/**
 * Batch Detail Page - Main workspace for a batch
 * Decision #1: Essential page for batch operations
 */

require_once __DIR__ . '/../app/Support/autoload.php';
use App\Support\Database;

$db = Database::connect();
$importSource = $_GET['import_source'] ?? '';

if (!$importSource) {
    die('import_source مطلوب');
}

// Get batch metadata (if exists)
$metadataStmt = $db->prepare("SELECT * FROM batch_metadata WHERE import_source = ?");
$metadataStmt->execute([$importSource]);
$metadata = $metadataStmt->fetch(PDO::FETCH_ASSOC);

// Get all guarantees in batch
$guaranteesStmt = $db->prepare("
    SELECT g.*, d.status as decision_status, d.supplier_id, d.bank_id
    FROM guarantees g
    LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
    WHERE g.import_source = ?
    ORDER BY g.id
");
$guaranteesStmt->execute([$importSource]);
$guarantees = $guaranteesStmt->fetchAll(PDO::FETCH_ASSOC);

// Derived info
$batchName = $metadata['batch_name'] ?? 'دفعة ' . substr($importSource, 0, 30);
$status = $metadata['status'] ?? 'active';
$isClosed = ($status === 'completed');
$batchNotes = $metadata['batch_notes'] ?? '';

// Parse guarantee data
foreach ($guarantees as &$g) {
    $g['parsed'] = json_decode($g['raw_data'], true);
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($batchName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Tajawal', sans-serif; }
    </style>
</head>
<body class="bg-gray-50">
    <div class="container mx-auto p-6 max-w-7xl">
        <!-- Header -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <div class="flex justify-between items-start mb-4">
                <div class="flex-1">
                    <div class="flex items-center gap-3 mb-2">
                        <h1 class="text-3xl font-bold text-gray-800"><?= htmlspecialchars($batchName) ?></h1>
                        <?php if ($isClosed): ?>
                        <span class="bg-gray-400 text-white px-3 py-1 rounded text-sm">مغلقة</span>
                        <?php else: ?>
                        <span class="bg-green-500 text-white px-3 py-1 rounded text-sm">مفتوحة</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-gray-600 text-sm">
                        📦 <?= count($guarantees) ?> ضمان • 
                        🔖 <?= htmlspecialchars($importSource) ?>
                    </p>
                    <?php if ($batchNotes): ?>
                    <p class="mt-2 text-sm text-gray-700 bg-yellow-50 p-2 rounded">
                        📝 <?= htmlspecialchars($batchNotes) ?>
                    </p>
                    <?php endif; ?>
                </div>
                <div class="flex gap-2">
                    <?php if ($isClosed): ?>
                    <button onclick="reopenBatch()" class="bg-yellow-600 hover:bg-yellow-700 text-white px-4 py-2 rounded transition">
                        إعادة فتح
                    </button>
                    <?php else: ?>
                    <button onclick="closeBatch()" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded transition">
                        إغلاق الدفعة
                    </button>
                    <?php endif; ?>
                    <button onclick="editMetadata()" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded transition">
                        تعديل
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Operations Bar -->
        <div class="bg-white rounded-lg shadow-md p-4 mb-6">
            <div class="flex gap-3 flex-wrap">
                <?php if (!$isClosed): ?>
                <button onclick="extendSelected()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded transition">
                    تمديد المحدد
                </button>
                <button onclick="releaseSelected()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded transition">
                    إفراج المحدد
                </button>
                <?php endif; ?>
                <button onclick="printPreview()" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded transition">
                    معاينة الطباعة
                </button>
                <button onclick="selectAll()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded transition">
                    تحديد الكل
                </button>
                <button onclick="deselectAll()" class="bg-gray-400 hover:bg-gray-500 text-white px-4 py-2 rounded transition">
                    إلغاء التحديد
                </button>
            </div>
        </div>
        
        <!-- Guarantees Table -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold mb-4 text-gray-800">الضمانات</h2>
            
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-100">
                        <tr>
                            <?php if (!$isClosed): ?>
                            <th class="border p-2 text-center w-12">
                                <input type="checkbox" id="select-all-header" onchange="toggleAll(this)">
                            </th>
                            <?php endif; ?>
                            <th class="border p-2">رقم الضمان</th>
                            <th class="border p-2">المورد</th>
                            <th class="border p-2">البنك</th>
                            <th class="border p-2">القيمة</th>
                            <th class="border p-2">الحالة</th>
                            <th class="border p-2">العمليات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($guarantees as $g): ?>
                        <tr class="hover:bg-gray-50">
                            <?php if (!$isClosed): ?>
                            <td class="border p-2 text-center">
                                <input type="checkbox" class="guarantee-checkbox" value="<?= $g['id'] ?>">
                            </td>
                            <?php endif; ?>
                            <td class="border p-2 font-semibold"><?= htmlspecialchars($g['guarantee_number']) ?></td>
                            <td class="border p-2"><?= htmlspecialchars($g['parsed']['supplier'] ?? '-') ?></td>
                            <td class="border p-2"><?= htmlspecialchars($g['parsed']['bank'] ?? '-') ?></td>
                            <td class="border p-2"><?= htmlspecial chars($g['parsed']['value'] ?? '-') ?></td>
                            <td class="border p-2">
                                <?php if ($g['decision_status'] === 'approved'): ?>
                                <span class="text-green-700 font-semibold">✓ معتمد</span>
                                <?php else: ?>
                                <span class="text-gray-500">معلق</span>
                                <?php endif; ?>
                            </td>
                            <td class="border p-2">
                                <a href="/index.php?id=<?= $g['id'] ?>" class="text-blue-600 hover:underline">عرض</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if (empty($guarantees)): ?>
            <div class="text-center py-8 text-gray-500">
                لا توجد ضمانات في هذه الدفعة
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Back Button -->
        <div class="mt-6">
            <a href="/views/batches.php" class="text-blue-600 hover:underline">← العودة لقائمة الدفعات</a>
        </div>
    </div>
    
    <script>
    const importSource = <?= json_encode($importSource) ?>;
    const isClosed = <?= $isClosed ? 'true' : 'false' ?>;
    
    function toggleAll(checkbox) {
        document.querySelectorAll('.guarantee-checkbox').forEach(cb => {
            cb.checked = checkbox.checked;
        });
    }
    
    function selectAll() {
        document.querySelectorAll('.guarantee-checkbox').forEach(cb => cb.checked = true);
        if (document.getElementById('select-all-header')) {
            document.getElementById('select-all-header').checked = true;
        }
    }
    
    function deselectAll() {
        document.querySelectorAll('.guarantee-checkbox').forEach(cb => cb.checked = false);
        if (document.getElementById('select-all-header')) {
            document.getElementById('select-all-header').checked = false;
        }
    }
    
    function printPreview() {
        window.open(`/views/batch-print-preview.php?import_source=${encodeURIComponent(importSource)}`, '_blank');
    }
    
    function closeBatch() {
        if (!confirm('هل تريد إغلاق هذه الدفعة؟\n\nلن يمكن تنفيذ عمليات جماعية بعد الإغلاق.')) return;
        
        fetch('/api/batches.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=close&import_source=${encodeURIComponent(importSource)}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('خطأ: ' + data.error);
            }
        });
    }
    
    function reopenBatch() {
        if (!confirm('هل تريد إعادة فتح هذه الدفعة؟')) return;
        
        fetch('/api/batches.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=reopen&import_source=${encodeURIComponent(importSource)}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('خطأ: ' + data.error);
            }
        });
    }
    
    function editMetadata() {
        const newName = prompt('اسم الدفعة:', <?= json_encode($batchName) ?>);
        if (newName === null) return;
        
        const newNotes = prompt('ملاحظات:', <?= json_encode($batchNotes) ?>);
        
        const params = new URLSearchParams();
        params.append('action', 'update_metadata');
        params.append('import_source', importSource);
        params.append('batch_name', newName);
        if (newNotes !== null) params.append('batch_notes', newNotes);
        
        fetch('/api/batches.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: params.toString()
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('خطأ: ' + data.error);
            }
        });
    }
    
    function extendSelected() {
        alert('TODO: Integrate with existing extend logic');
    }
    
    function releaseSelected() {
        alert('TODO: Integrate with existing release logic');
    }
    </script>
</body>
</html>
