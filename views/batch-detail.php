<?php
/**
 * Batch Detail Page - Refactored for BGL3
 * Features: Modern UI, Toast Notifications, Modal Inputs, Loading States
 * Uses Standard Design System
 */

require_once __DIR__ . '/../app/Support/autoload.php';
use App\Support\Database;

$db = Database::connect();
$importSource = $_GET['import_source'] ?? '';

if (!$importSource) {
    die('<div class="p-5 text-center text-danger font-bold">خطأ: import_source مطلوب</div>');
}

// 1. Fetch Metadata
$metadataStmt = $db->prepare("SELECT * FROM batch_metadata WHERE import_source = ?");
$metadataStmt->execute([$importSource]);
$metadata = $metadataStmt->fetch(PDO::FETCH_ASSOC);

// 2. Fetch Guarantees with Relations
$guaranteesStmt = $db->prepare("
    SELECT 
        g.*,
        d.status as decision_status,
        d.active_action,
        d.supplier_id,
        d.bank_id,
        s.official_name as supplier_name,
        b.arabic_name as bank_name
    FROM guarantees g
    LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
    LEFT JOIN suppliers s ON s.id = d.supplier_id
    LEFT JOIN banks b ON b.id = d.bank_id
    WHERE g.import_source = ?
    ORDER BY g.id
");
$guaranteesStmt->execute([$importSource]);
$guarantees = $guaranteesStmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Process Data
$batchName = $metadata['batch_name'] ?? 'دفعة ' . substr($importSource, 0, 30);
$status = $metadata['status'] ?? 'active';
$isClosed = ($status === 'completed');
$batchNotes = $metadata['batch_notes'] ?? '';

// Helper to parse JSON safely
foreach ($guarantees as &$g) {
    $g['parsed'] = json_decode($g['raw_data'], true) ?? [];
    $g['supplier_name'] = $g['supplier_name'] ?: ($g['parsed']['supplier'] ?? '-');
    $g['bank_name'] = $g['bank_name'] ?: ($g['parsed']['bank'] ?? '-');
}
unset($g);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($batchName) ?></title>
    
    <!-- Design System CSS -->
    <link rel="stylesheet" href="../public/css/design-system.css">
    <link rel="stylesheet" href="../public/css/components.css">
    <link rel="stylesheet" href="../public/css/layout.css">
    
    <!-- Page Specific Overrides (Cleaned) -->
    <link rel="stylesheet" href="../public/css/batch-detail.css">
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>

    <!-- Unified Header -->
    <?php include __DIR__ . '/../partials/unified-header.php'; ?>
    
    <!-- Toast Container -->
    <div id="toast-container" style="position: fixed; top: var(--space-md); right: var(--space-md); z-index: var(--z-toast); display: flex; flex-direction: column; gap: var(--space-sm);"></div>

    <!-- Modal Container -->
    <div id="modal-backdrop" class="modal-backdrop" style="display: none;">
        <div id="modal-content" class="modal-content">
            <!-- Dynamic Content -->
        </div>
    </div>

    <!-- Main Content -->
    <div class="page-container">
        
        <!-- Batch Header -->
        <div class="card mb-5">
            <div class="card-body p-5">
                <div class="flex-between align-center wrap-gap">
                    <div>
                        <div class="flex-align-center gap-2 mb-2">
                            <h1 class="text-2xl font-bold"><?= htmlspecialchars($batchName) ?></h1>
                            <span class="badge <?= $isClosed ? 'badge-neutral' : 'badge-success' ?>">
                                <?= $isClosed ? 'مغلقة' : 'نشطة' ?>
                            </span>
                        </div>
                        <div class="text-secondary text-sm flex-align-center gap-4">
                            <span class="flex-align-center gap-1"><i data-lucide="package" style="width: 16px;"></i> <?= count($guarantees) ?> ضمان</span>
                            <span class="flex-align-center gap-1"><i data-lucide="file-spreadsheet" style="width: 16px;"></i> <?= htmlspecialchars($importSource) ?></span>
                        </div>
                        <?php if ($batchNotes): ?>
                            <div class="mt-3 p-2 bg-warning-light text-warning-dark rounded text-sm border border-warning-light">
                                💡 <?= htmlspecialchars($batchNotes) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="flex-align-center gap-2 mt-3-sm">
                        <button onclick="openMetadataModal()" class="btn btn-secondary">
                            <i data-lucide="edit-3" style="width: 16px;"></i> تعديل البيانات
                        </button>
                        
                        <?php if (!$isClosed): ?>
                            <button onclick="handleBatchAction('close')" class="btn btn-danger">
                                <i data-lucide="lock" style="width: 16px;"></i> إغلاق الدفعة
                            </button>
                            <button onclick="printReadyGuarantees()" class="btn btn-success">
                                <i data-lucide="printer" style="width: 16px;"></i> طباعة الجاهز
                            </button>
                        <?php else: ?>
                            <button onclick="handleBatchAction('reopen')" class="btn btn-warning">
                                <i data-lucide="unlock" style="width: 16px;"></i> إعادة فتح
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Actions Toolbar -->
        <div class="card mb-4">
            <div class="card-body p-3 flex-between align-center">
                <div class="flex-align-center gap-2">
                    <?php if (!$isClosed): ?>
                        <button id="btn-extend" onclick="executeBulkAction('extend')" class="btn btn-primary btn-sm">
                            <i data-lucide="calendar-plus" style="width: 16px;"></i> تمديد المحدد
                        </button>
                        <button id="btn-release" onclick="executeBulkAction('release')" class="btn btn-success btn-sm">
                            <i data-lucide="check-circle-2" style="width: 16px;"></i> إفراج المحدد
                        </button>
                    <?php endif; ?>
                </div>
                
                <div class="text-sm">
                    <button onclick="TableManager.toggleSelectAll(true)" class="btn-link">تحديد الكل</button>
                    <span class="text-muted mx-2">|</span>
                    <button onclick="TableManager.toggleSelectAll(false)" class="btn-link">إلغاء التحديد</button>
                </div>
            </div>
        </div>

        <!-- Guarantees Table -->
        <div class="card overflow-hidden">
            <div id="table-loading" class="loading-overlay" style="display: none;">
                <div class="spinner"></div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <?php if (!$isClosed): ?>
                                <th style="width: 40px;">
                                    <input type="checkbox" onchange="TableManager.toggleSelectAll(this.checked)" class="form-checkbox">
                                </th>
                            <?php endif; ?>
                            <th>رقم الضمان</th>
                            <th>المورد</th>
                            <th>البنك</th>
                            <th class="text-center">الإجراء</th>
                            <th class="text-left">القيمة</th>
                            <th class="text-center">الحالة</th>
                            <th class="text-center">تفاصيل</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($guarantees as $g): ?>
                            <tr>
                                <?php if (!$isClosed): ?>
                                    <td class="text-center">
                                        <input type="checkbox" value="<?= $g['id'] ?>" class="form-checkbox guarantee-checkbox">
                                    </td>
                                <?php endif; ?>
                                <td class="font-bold"><?= htmlspecialchars($g['guarantee_number']) ?></td>
                                <td><?= htmlspecialchars($g['supplier_name']) ?></td>
                                <td><?= htmlspecialchars($g['bank_name']) ?></td>
                                <td class="text-center">
                                    <?php if ($g['active_action'] == 'release'): ?>
                                        <span class="badge badge-success">إفراج</span>
                                    <?php elseif ($g['active_action'] == 'extension'): ?>
                                        <span class="badge badge-info">تمديد</span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="font-mono text-left" dir="ltr">
                                    <?= number_format((float)($g['parsed']['amount'] ?? 0), 2) ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($g['decision_status'] === 'ready'): ?>
                                        <div class="text-success flex-center gap-1 text-sm font-bold">
                                            <i data-lucide="check" style="width: 14px;"></i> جاهز
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted text-xs">غير جاهز</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <a href="/index.php?id=<?= $g['id'] ?>" class="btn-icon">
                                        <i data-lucide="arrow-left" style="width: 18px;"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if (empty($guarantees)): ?>
                <div class="p-5 text-center text-muted">
                    <i data-lucide="inbox" style="width: 48px; height: 48px; margin-bottom: 1rem; opacity: 0.5;"></i>
                    <p>لا توجد بيانات للعرض</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- JavaScript Application Logic -->
    <script>
        // --- 1. System Components (Toast, Modal, API) ---
        // Kept lightweight and clean

        const Toast = {
            show(message, type = 'info', duration = 3000) {
                const container = document.getElementById('toast-container');
                const toast = document.createElement('div');
                
                // Simple standard toast styling
                let typeColor = type === 'success' ? 'var(--accent-success)' : (type === 'error' ? 'var(--accent-danger)' : 'var(--accent-info)');
                
                toast.className = 'card p-3 shadow-md flex-align-center gap-3 animate-slide-in';
                toast.style.borderRight = `4px solid ${typeColor}`;
                toast.style.background = 'white';
                toast.style.minWidth = '300px';

                const icons = {
                    success: '<i data-lucide="check-circle" style="color:var(--accent-success)"></i>',
                    error: '<i data-lucide="alert-circle" style="color:var(--accent-danger)"></i>',
                    warning: '<i data-lucide="alert-triangle" style="color:var(--accent-warning)"></i>',
                    info: '<i data-lucide="info" style="color:var(--accent-info)"></i>'
                };
                
                toast.innerHTML = `${icons[type] || icons.info} <span class="font-medium">${message}</span>`;
                
                container.appendChild(toast);
                lucide.createIcons();

                setTimeout(() => {
                    toast.style.opacity = '0';
                    toast.style.transform = 'translateY(-20px)';
                    toast.style.transition = 'all 0.3s ease';
                    setTimeout(() => toast.remove(), 300);
                }, duration);
            }
        };

        const Modal = {
            el: document.getElementById('modal-backdrop'),
            content: document.getElementById('modal-content'),
            
            open(html) {
                this.content.innerHTML = html;
                this.el.style.display = 'flex';
            },
            
            close() {
                this.el.style.display = 'none';
                this.content.innerHTML = '';
            }
        };

        const API = {
            async post(action, data = {}) {
                try {
                    let options = {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ 
                            action, 
                            import_source: <?= json_encode($importSource) ?>, 
                            ...data 
                        })
                    };

                    if (action !== 'extend' && action !== 'release') {
                         const formData = new FormData();
                         formData.append('action', action);
                         formData.append('import_source', <?= json_encode($importSource) ?>);
                         for (const [key, value] of Object.entries(data)) {
                             formData.append(key, value);
                         }
                         options = { method: 'POST', body: formData };
                    }

                    const res = await fetch('/api/batches.php', options);
                    const json = await res.json();
                    
                    if (!json.success) throw new Error(json.error || 'Server Error');
                    return json;
                } catch (e) {
                    throw e;
                }
            }
        };

        // --- 2. Feature Logic ---

        const TableManager = {
            toggleSelectAll(checked) {
                document.querySelectorAll('.guarantee-checkbox').forEach(cb => cb.checked = checked);
            },
            
            getSelected() {
                return Array.from(document.querySelectorAll('.guarantee-checkbox:checked')).map(cb => cb.value);
            }
        };

        async function handleBatchAction(action) {
            if(!confirm('هل أنت متأكد من تنفيذ هذا الإجراء؟')) return;
            
            try {
                document.getElementById('table-loading').style.display = 'flex';
                await API.post(action);
                Toast.show('تم تنفيذ العملية بنجاح', 'success');
                setTimeout(() => location.reload(), 1000);
            } catch (e) {
                document.getElementById('table-loading').style.display = 'none';
                Toast.show(e.message, 'error');
            }
        }

        async function executeBulkAction(type) {
            const ids = TableManager.getSelected();
            if (ids.length === 0) {
                Toast.show('الرجاء اختيار ضمان واحد على الأقل', 'warning');
                return;
            }

            try {
                document.getElementById('table-loading').style.display = 'flex';
                
                let data = { guarantee_ids: ids };
                
                if (type === 'extend') {
                    data.new_expiry = new Date(new Date().setFullYear(new Date().getFullYear() + 1))
                        .toISOString().split('T')[0];
                } else if (type === 'release') {
                    data.reason = 'إفراج جماعي';
                }

                const res = await API.post(type, data);
                
                Toast.show(
                    type === 'extend' ? `تم تمديد ${res.extended} ضمان` : `تم إفراج ${res.released} ضمان`, 
                    'success'
                );
                setTimeout(() => location.reload(), 1000);

            } catch (e) {
                document.getElementById('table-loading').style.display = 'none';
                Toast.show(e.message, 'error');
            }
        }

        function openMetadataModal() {
            Modal.open(`
                <div class="p-4">
                    <h3 class="text-xl font-bold mb-4">تعديل بيانات الدفعة</h3>
                    <div class="form-group mb-3">
                        <label class="form-label">اسم الدفعة</label>
                        <input type="text" id="modal-batch-name" value="<?= htmlspecialchars($batchName) ?>" class="form-control">
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label">ملاحظات</label>
                        <textarea id="modal-batch-notes" rows="3" class="form-control"><?= htmlspecialchars($batchNotes) ?></textarea>
                    </div>
                    <div class="flex-end gap-2 mt-4">
                        <button onclick="Modal.close()" class="btn btn-secondary">إلغاء</button>
                        <button onclick="saveMetadata()" class="btn btn-primary">حفظ التغييرات</button>
                    </div>
                </div>
            `);
        }

        async function saveMetadata() {
            const name = document.getElementById('modal-batch-name').value;
            const notes = document.getElementById('modal-batch-notes').value;

            try {
                await API.post('update_metadata', { batch_name: name, batch_notes: notes });
                Modal.close();
                Toast.show('تم حفظ البيانات بنجاح', 'success');
                setTimeout(() => location.reload(), 800);
            } catch (e) {
                Toast.show(e.message, 'error');
            }
        }

        function printReadyGuarantees() {
            const guarantees = <?= json_encode($guarantees) ?>;
            const ready = guarantees.filter(g => g.supplier_id && g.bank_id && g.active_action);
            
            if (ready.length === 0) {
                Toast.show('لا توجد ضمانات جاهزة للطباعة', 'warning');
                return;
            }

            const ids = ready.map(g => g.id);
            window.open(`/views/batch-print.php?ids=${ids.join(',')}`);
            Toast.show(`تم فتح نافذة الطباعة لـ ${ids.length} خطاب`, 'success');
        }

        // Initialize Icons
        lucide.createIcons();

    </script>
</body>
</html>