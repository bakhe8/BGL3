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
    
    <!-- Design System CSS -->
    <link rel="stylesheet" href="../public/css/design-system.css">
    <link rel="stylesheet" href="../public/css/components.css">
    <link rel="stylesheet" href="../public/css/layout.css">
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    
    <style>
        /* Page-specific styles */
        .page-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: var(--space-lg);
        }
        
        .page-title {
            font-size: var(--font-size-3xl);
            font-weight: var(--font-weight-bold);
            color: var(--text-primary);
            margin-bottom: var(--space-xs);
        }
        
        .page-subtitle {
            color: var(--text-secondary);
            margin-bottom: var(--space-xl);
        }
        
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: var(--space-md);
        }
        
        .section-title {
            font-size: var(--font-size-2xl);
            font-weight: var(--font-weight-bold);
            color: var(--text-primary);
        }
        
        .batch-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: var(--space-md);
            margin-bottom: var(--space-2xl);
        }
        
        .batch-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-primary);
            box-shadow: var(--shadow-sm);
            padding: var(--space-lg);
            transition: all var(--transition-base);
        }
        
        .batch-card:hover {
            box-shadow: var(--shadow-lg);
            transform: translateY(-2px);
        }
        
        .batch-card.active {
            border-right: 4px solid var(--accent-success);
        }
        
        .batch-card.completed {
            border-right: 4px solid var(--border-neutral);
            background: var(--bg-secondary);
        }
        
        .batch-card-title {
            font-size: var(--font-size-lg);
            font-weight: var(--font-weight-bold);
            margin-bottom: var(--space-sm);
            color: var(--text-primary);
        }
        
        .batch-info {
            display: flex;
            flex-direction: column;
            gap: var(--space-sm);
            font-size: var(--font-size-sm);
            color: var(--text-secondary);
            margin-bottom: var(--space-md);
        }
        
        .batch-notes {
            font-size: var(--font-size-xs);
            color: var(--text-muted);
            margin-top: var(--space-sm);
        }
        
        .empty-state {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: var(--space-2xl);
            text-align: center;
            color: var(--text-muted);
            box-shadow: var(--shadow-sm);
        }
        
        .back-link {
            display: inline-block;
            margin-top: var(--space-xl);
            color: var(--accent-primary);
            text-decoration: none;
            transition: color var(--transition-base);
        }
        
        .back-link:hover {
            color: var(--accent-primary-hover);
            text-decoration: underline;
        }
    </style>
</head>
<body>
    
    <!-- Unified Header -->
    <?php include __DIR__ . '/../partials/unified-header.php'; ?>
    
    <div class="page-container">
        
        <div class="page-title">الدفعات</div>
        <p class="page-subtitle">إدارة مجموعات الضمانات للعمل الجماعي</p>
        
        <!-- Active Batches -->
        <section class="mb-5">
            <div class="section-header">
                <h2 class="section-title">دفعات مفتوحة</h2>
                <span class="badge badge-success">
                    <?= count($active) ?> دفعة
                </span>
            </div>
            
            <?php if (empty($active)): ?>
                <div class="empty-state">
                    لا توجد دفعات مفتوحة حالياً
                </div>
            <?php else: ?>
                <div class="batch-grid">
                    <?php foreach ($active as $batch): ?>
                    <div class="batch-card active">
                        <h3 class="batch-card-title">
                            <?= htmlspecialchars($batch['batch_name']) ?>
                        </h3>
                        <div class="batch-info">
                            <p>📦 <?= $batch['guarantee_count'] ?> ضمان</p>
                            <p>📅 <?= date('Y-m-d H:i', strtotime($batch['created_at'])) ?></p>
                            <?php if ($batch['batch_notes']): ?>
                            <p class="batch-notes">
                                <?= htmlspecialchars(substr($batch['batch_notes'], 0, 50)) ?>
                                <?= strlen($batch['batch_notes']) > 50 ? '...' : '' ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <a href="/views/batch-detail.php?import_source=<?= urlencode($batch['import_source']) ?>" 
                           class="btn btn-primary w-full text-center">
                            فتح الدفعة
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        
        <!-- Completed Batches -->
        <section>
            <div class="section-header">
                <h2 class="section-title">دفعات مغلقة</h2>
                <span class="badge badge-neutral">
                    <?= count($completed) ?> دفعة
                </span>
            </div>
            
            <?php if (empty($completed)): ?>
                <div class="empty-state">
                    لا توجد دفعات مغلقة
                </div>
            <?php else: ?>
                <div class="batch-grid">
                    <?php foreach ($completed as $batch): ?>
                    <div class="batch-card completed">
                        <div class="flex justify-between items-start mb-2">
                            <h3 class="batch-card-title text-muted">
                                <?= htmlspecialchars($batch['batch_name']) ?>
                            </h3>
                            <span class="badge badge-neutral" style="font-size: var(--font-size-xs);">مغلقة</span>
                        </div>
                        <div class="batch-info">
                            <p>📦 <?= $batch['guarantee_count'] ?> ضمان</p>
                            <p>📅 <?= date('Y-m-d', strtotime($batch['created_at'])) ?></p>
                        </div>
                        <a href="/views/batch-detail.php?import_source=<?= urlencode($batch['import_source']) ?>" 
                           class="btn btn-secondary w-full text-center">
                            عرض
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        
        <!-- Back to home -->
        <div style="text-align: center;">
            <a href="/index.php" class="back-link">← العودة للصفحة الرئيسية</a>
        </div>
    </div>
</body>
</html>
