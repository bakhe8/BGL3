<?php
/**
 * Batch Detail Page - Refactored for BGL3
 * Features: Modern UI, Toast Notifications, Modal Inputs, Loading States
 */

require_once __DIR__ . '/../app/Support/autoload.php';
use App\Support\Database;

$db = Database::connect();
$importSource = $_GET['import_source'] ?? '';

if (!$importSource) {
    die('<div class="p-4 text-red-600 bg-red-50 text-center font-bold">خطأ: import_source مطلوب</div>');
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
        <header class="batch-header">
            <div class="batch-header-content">
                <div>
                    <div class="batch-title-group">
                        <h1 class="batch-name"><?= htmlspecialchars($batchName) ?></h1>
                        <span class="badge <?= $isClosed ? 'badge-neutral' : 'badge-success' ?>">
                            <?= $isClosed ? 'مغلقة' : 'نشطة' ?>
                        </span>
                    </div>
                    <div class="batch-meta">
                        <span class="batch-meta-item"><i data-lucide="package" style="width: 16px; height: 16px;"></i> <?= count($guarantees) ?> ضمان</span>
                        <span class="batch-meta-item"><i data-lucide="file-spreadsheet" style="width: 16px; height: 16px;"></i> <?= htmlspecialchars($importSource) ?></span>
                    </div>
                    <?php if ($batchNotes): ?>
                        <div class="batch-notes-box">
                            💡 <?= htmlspecialchars($batchNotes) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="batch-actions">
                    <button onclick="openMetadataModal()" class="btn btn-secondary">
                        <i data-lucide="edit-3" style="width: 16px; height: 16px;"></i> تعديل البيانات
                    </button>
                    
                    <?php if (!$isClosed): ?>
                        <button onclick="handleBatchAction('close')" class="btn btn-danger">
                            <i data-lucide="lock" style="width: 16px; height: 16px;"></i> إغلاق الدفعة
                        </button>
                        <button onclick="printReadyGuarantees()" class="btn btn-success">
                            <i data-lucide="printer" style="width: 16px; height: 16px;"></i> طباعة الجاهز
                        </button>
                    <?php else: ?>
                        <button onclick="handleBatchAction('reopen')" class="btn" style="background: var(--accent-warning-light); color: var(--accent-warning); border-color: var(--accent-warning);">
                            <i data-lucide="unlock" style="width: 16px; height: 16px;"></i> إعادة فتح
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <!-- Actions Toolbar -->
        <div class="actions-toolbar">
            <div class="toolbar-left">
                <?php if (!$isClosed): ?>
                    <button id="btn-extend" onclick="executeBulkAction('extend')" class="btn" style="background: var(--accent-info-light); color: var(--accent-info);">
                        <i data-lucide="calendar-plus" style="width: 16px; height: 16px;"></i> تمديد المحدد
                    </button>
                    <button id="btn-release" onclick="executeBulkAction('release')" class="btn btn-success">
                        <i data-lucide="check-circle-2" style="width: 16px; height: 16px;"></i> إفراج المحدد
                    </button>
                <?php endif; ?>
            </div>
            
            <div class="toolbar-right">
                <button onclick="TableManager.toggleSelectAll(true)" class="toolbar-link">تحديد الكل</button>
                <span class="toolbar-separator">|</span>
                <button onclick="TableManager.toggleSelectAll(false)" class="toolbar-link">إلغاء التحديد</button>
            </div>
        </div>

        <!-- Guarantees Table -->
        <div class="table-container">
            <div id="table-loading" class="loading-overlay" style="display: none;">
                <div class="spinner"></div>
            </div>

            <div class="table-wrapper">
                <table class="batch-table">
                    <thead>
                        <tr>
                            <?php if (!$isClosed): ?>
                                <th style="padding: var(--space-md); width: 40px;">
                                    <input type="checkbox" onchange="TableManager.toggleSelectAll(this.checked)" class="batch-checkbox">
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
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($guarantees as $g): ?>
                            <tr>
                                <?php if (!$isClosed): ?>
                                    <td style="padding: var(--space-md); text-align: center;">
                                        <input type="checkbox" value="<?= $g['id'] ?>" class="batch-checkbox guarantee-checkbox">
                                    </td>
                                <?php endif; ?>
                                <td class="font-medium"><?= htmlspecialchars($g['guarantee_number']) ?></td>
                                <td><?= htmlspecialchars($g['supplier_name']) ?></td>
                                <td><?= htmlspecialchars($g['bank_name']) ?></td>
                                <td style="text-align: center;">
                                    <?php if ($g['active_action'] == 'release'): ?>
                                        <span class="action-badge release">إفراج</span>
                                    <?php elseif ($g['active_action'] == 'extension'): ?>
                                        <span class="action-badge extension">تمديد</span>
                                    <?php else: ?>
                                        <span style="color: var(--text-light);">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="font-mono" dir="ltr" style="text-align: left;">
                                    <?= number_format((float)($g['parsed']['amount'] ?? 0), 2) ?>
                                </td>
                                <td style="text-align: center;">
                                    <?php if ($g['decision_status'] === 'ready'): ?>
                                        <div class="status-ready">
                                            <i data-lucide="check" style="width: 12px; height: 12px;"></i> جاهز
                                        </div>
                                    <?php else: ?>
                                        <span style="color: var(--text-light); font-size: var(--font-size-xs);">غير جاهز</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <a href="/index.php?id=<?= $g['id'] ?>" class="link-icon">
                                        <i data-lucide="arrow-left"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if (empty($guarantees)): ?>
                <div class="table-empty">
                    <i data-lucide="inbox" style="width: 48px; height: 48px; stroke-width: 1; margin-bottom: var(--space-sm);"></i>
                    <p>لا توجد بيانات للعرض</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- JavaScript Application Logic -->
    <script>
        // --- 1. System Components ---

        const Toast = {
            show(message, type = 'info', duration = 3000) {
                const container = document.getElementById('toast-container');
                const toast = document.createElement('div');
                toast.className = `flex items-center gap-3 px-4 py-3 rounded-lg shadow-lg text-sm font-medium transform transition-all duration-300 translate-x-full opacity-0 bg-white border-r-4`;
                
                // Styling based on type
                const styles = {
                    success: 'border-green-500 text-gray-700',
                    error: 'border-red-500 text-gray-700',
                    warning: 'border-yellow-500 text-gray-700',
                    info: 'border-blue-500 text-gray-700'
                };
                toast.classList.add(...styles[type].split(' '));

                // Icon
                const icons = {
                    success: '<i data-lucide="check-circle" class="text-green-500 w-5 h-5"></i>',
                    error: '<i data-lucide="alert-circle" class="text-red-500 w-5 h-5"></i>',
                    warning: '<i data-lucide="alert-triangle" class="text-yellow-500 w-5 h-5"></i>',
                    info: '<i data-lucide="info" class="text-blue-500 w-5 h-5"></i>'
                };
                toast.innerHTML = `${icons[type]} <span>${message}</span>`;
                
                container.appendChild(toast);
                lucide.createIcons();

                // Animate In
                requestAnimationFrame(() => {
                    toast.classList.remove('translate-x-full', 'opacity-0');
                });

                // Remove after duration
                setTimeout(() => {
                    toast.classList.add('translate-x-full', 'opacity-0');
                    setTimeout(() => toast.remove(), 300);
                }, duration);
            }
        };

        const Modal = {
            el: document.getElementById('modal-backdrop'),
            content: document.getElementById('modal-content'),
            
            open(html) {
                this.content.innerHTML = html;
                this.el.classList.remove('hidden');
                // Animation
                requestAnimationFrame(() => {
                    this.el.classList.remove('opacity-0');
                    this.content.classList.remove('scale-95');
                    this.content.classList.add('scale-100');
                });
            },
            
            close() {
                this.el.classList.add('opacity-0');
                this.content.classList.remove('scale-100');
                this.content.classList.add('scale-95');
                setTimeout(() => {
                    this.el.classList.add('hidden');
                    this.content.innerHTML = '';
                }, 200);
            }
        };

        const API = {
            async post(action, data = {}) {
                try {
                    const formData = new FormData();
                    formData.append('action', action);
                    formData.append('import_source', <?= json_encode($importSource) ?>);
                    
                    for (const [key, value] of Object.entries(data)) {
                        formData.append(key, typeof value === 'object' ? JSON.stringify(value) : value);
                    }

                    // Since backend expects JSON for some endpoints and Form for others, let's stick to fetch standard
                    // However, existing backend seems to handle mixed. Let's use pure JSON for complex ones to match previous extend code
                    
                    let options = {};
                    if (action === 'extend' || action === 'release') {
                         options = {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ 
                                action, 
                                import_source: <?= json_encode($importSource) ?>, 
                                ...data 
                            })
                        };
                    } else {
                        options = {
                            method: 'POST',
                            body: formData // Form Data for simple actions
                        };
                    }

                    const res = await fetch('/api/batches.php', options);
                    const json = await res.json();
                    
                    if (!json.success) throw new Error(json.error || 'Server Error');
                    return json;
                } catch (e) {
                    throw e; // Propagate to caller
                }
            }
        };

        // --- 2. Feature Logic ---

        const TableManager = {
            toggleSelectAll(checked) {
                document.querySelectorAll('.guarantee-checkbox').forEach(cb => cb.checked = checked);
                updateActionButtons();
            },
            
            getSelected() {
                return Array.from(document.querySelectorAll('.guarantee-checkbox:checked')).map(cb => cb.value);
            }
        };

        async function handleBatchAction(action) {
            try {
                // Show loading on page
                document.getElementById('table-loading').classList.remove('hidden');
                
                await API.post(action);
                
                Toast.show('تم تنفيذ العملية بنجاح', 'success');
                setTimeout(() => location.reload(), 1000); // Friendly reload
                
            } catch (e) {
                document.getElementById('table-loading').classList.add('hidden');
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
                document.getElementById('table-loading').classList.remove('hidden');
                
                let data = { guarantee_ids: ids };
                
                if (type === 'extend') {
                    // Auto calculate +1 year
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
                document.getElementById('table-loading').classList.add('hidden');
                Toast.show(e.message, 'error');
            }
        }

        function openMetadataModal() {
            Modal.open(`
                <div class="p-6">
                    <h3 class="text-xl font-bold mb-4">تعديل بيانات الدفعة</h3>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">اسم الدفعة</label>
                            <input type="text" id="modal-batch-name" value="<?= htmlspecialchars($batchName) ?>" class="w-full border rounded-lg p-2 focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">ملاحظات</label>
                            <textarea id="modal-batch-notes" rows="3" class="w-full border rounded-lg p-2 focus:ring-2 focus:ring-blue-500"><?= htmlspecialchars($batchNotes) ?></textarea>
                        </div>
                        <div class="flex justify-end gap-2 mt-6">
                            <button onclick="Modal.close()" class="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg">إلغاء</button>
                            <button onclick="saveMetadata()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">حفظ التغييرات</button>
                        </div>
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
            
            // Filter ready guarantees (Has supplier, bank, and generic active action)
            const ready = guarantees.filter(g => 
                g.supplier_id && 
                g.bank_id && 
                g.active_action // Just needs ANY action
            );
            
            if (ready.length === 0) {
                Toast.show('لا توجد ضمانات جاهزة للطباعة (يجب تحديد مورد، بنك، وإجراء)', 'warning');
                return;
            }

            // Collect ALL IDs regardless of action type
            const ids = ready.map(g => g.id);
            
            // Open ONE window with ALL IDs
            window.open(`/views/batch-print.php?ids=${ids.join(',')}`);
            
            Toast.show(`تم فتح نافذة الطباعة لـ ${ids.length} خطاب`, 'success');
        }

        // --- 3. Initialization ---
        
        // Tailwind Base Classes
        document.querySelectorAll('.btn-primary').forEach(b => b.className += ' px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors flex items-center gap-2 shadow-sm');
        document.querySelectorAll('.btn-secondary').forEach(b => b.className += ' px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors flex items-center gap-2 shadow-sm');
        document.querySelectorAll('.btn-danger').forEach(b => b.className += ' px-4 py-2 bg-red-50 text-red-600 border border-red-100 rounded-lg hover:bg-red-100 transition-colors flex items-center gap-2');
        document.querySelectorAll('.btn-warning').forEach(b => b.className += ' px-4 py-2 bg-yellow-50 text-yellow-600 border border-yellow-100 rounded-lg hover:bg-yellow-100 transition-colors flex items-center gap-2');
        
        // Icons
        lucide.createIcons();
        
        // Selection Logic Update
        function updateActionButtons() {
            // Optional: disable action buttons if nothing selected
            const count = TableManager.getSelected().length;
            const btns = document.querySelectorAll('.btn-action');
            btns.forEach(b => {
                if (count > 0) b.classList.remove('opacity-50', 'cursor-not-allowed');
                // else b.classList.add('opacity-50', 'cursor-not-allowed'); 
                // Keep clickable to show "Select at least one" warning via Toast
            });
        }
        document.addEventListener('change', (e) => {
            if (e.target.matches('.guarantee-checkbox')) updateActionButtons();
        });

    </script>
</body>
</html>