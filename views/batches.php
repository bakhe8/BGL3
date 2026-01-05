<?php
/**
 * Batches List Page
 * Shows all batches (active and completed)
 */

require_once __DIR__ . '/../app/Support/autoload.php';
use App\Support\Database;

$db = Database::connect();

// Get all batches (implicit + explicit)
$batches = $db->query("
    SELECT 
        g.import_source,
        COALESCE(bm.batch_name, 'دفعة ' || SUBSTR(g.import_source, 1, 25)) as batch_name,
        COALESCE(bm.status, 'active') as status,
        COALESCE(bm.batch_notes, '') as batch_notes,
        COUNT(g.id) as guarantee_count,
        MIN(g.imported_at) as created_at,
        GROUP_CONCAT(DISTINCT g.imported_by) as imported_by
    FROM guarantees g
    LEFT JOIN batch_metadata bm ON bm.import_source = g.import_source
    GROUP BY g.import_source
    ORDER BY MIN(g.imported_at) DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Separate active and completed
$active = array_filter($batches, fn($b) => $b['status'] === 'active');
$completed = array_filter($batches, fn($b) => $b['status'] === 'completed');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الدفعات - نظام إدارة الضمانات</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Tajawal', sans-serif; }
    </style>
</head>
<body class="bg-gray-50">
    <div class="container mx-auto p-6 max-w-7xl">
        <div class="mb-6">
            <h1 class="text-4xl font-bold text-gray-800 mb-2">الدفعات</h1>
            <p class="text-gray-600">إدارة مجموعات الضمانات للعمل الجماعي</p>
        </div>
        
        <!-- Active Batches -->
        <section class="mb-10">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-2xl font-bold text-gray-800">دفعات مفتوحة</h2>
                <span class="bg-green-100 text-green-800 px-3 py-1 rounded-full text-sm font-semibold">
                    <?= count($active) ?> دفعة
                </span>
            </div>
            
            <?php if (empty($active)): ?>
                <div class="bg-white rounded-lg shadow p-8 text-center text-gray-500">
                    لا توجد دفعات مفتوحة حالياً
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($active as $batch): ?>
                    <div class="bg-white rounded-lg shadow hover:shadow-lg transition p-5 border-r-4 border-green-500">
                        <h3 class="font-bold text-lg mb-2 text-gray-800">
                            <?= htmlspecialchars($batch['batch_name']) ?>
                        </h3>
                        <div class="space-y-2 text-sm text-gray-600 mb-4">
                            <p>📦 <?= $batch['guarantee_count'] ?> ضمان</p>
                            <p>📅 <?= date('Y-m-d H:i', strtotime($batch['created_at'])) ?></p>
                            <?php if ($batch['batch_notes']): ?>
                            <p class="text-xs text-gray-500">
                                <?= htmlspecialchars(substr($batch['batch_notes'], 0, 50)) ?>
                                <?= strlen($batch['batch_notes']) > 50 ? '...' : '' ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <a href="/views/batch-detail.php?import_source=<?= urlencode($batch['import_source']) ?>" 
                           class="block w-full bg-blue-600 hover:bg-blue-700 text-white text-center px-4 py-2 rounded transition">
                            فتح الدفعة
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        
        <!-- Completed Batches -->
        <section>
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-2xl font-bold text-gray-800">دفعات مغلقة</h2>
                <span class="bg-gray-100 text-gray-800 px-3 py-1 rounded-full text-sm font-semibold">
                    <?= count($completed) ?> دفعة
                </span>
            </div>
            
            <?php if (empty($completed)): ?>
                <div class="bg-white rounded-lg shadow p-8 text-center text-gray-500">
                    لا توجد دفعات مغلقة
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($completed as $batch): ?>
                    <div class="bg-gray-100 rounded-lg shadow p-5 border-r-4 border-gray-400">
                        <div class="flex justify-between items-start mb-2">
                            <h3 class="font-bold text-lg text-gray-700">
                                <?= htmlspecialchars($batch['batch_name']) ?>
                            </h3>
                            <span class="bg-gray-400 text-white px-2 py-1 rounded text-xs">مغلقة</span>
                        </div>
                        <div class="space-y-2 text-sm text-gray-600 mb-4">
                            <p>📦 <?= $batch['guarantee_count'] ?> ضمان</p>
                            <p>📅 <?= date('Y-m-d', strtotime($batch['created_at'])) ?></p>
                        </div>
                        <a href="/views/batch-detail.php?import_source=<?= urlencode($batch['import_source']) ?>" 
                           class="block w-full bg-gray-600 hover:bg-gray-700 text-white text-center px-4 py-2 rounded transition">
                            عرض
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        
        <!-- Back to home -->
        <div class="mt-8 text-center">
            <a href="/index.php" class="text-blue-600 hover:underline">← العودة للصفحة الرئيسية</a>
        </div>
    </div>
</body>
</html>
