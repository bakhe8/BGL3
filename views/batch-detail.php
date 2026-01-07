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
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { font-family: 'Tajawal', sans-serif; }
        
        /* Loading Overlay */
        .loading-overlay {
            position: absolute; inset: 0; background: rgba(255,255,255,0.7);
            display: flex; align-items: center; justify-content: center; z-index: 10;
        }
        
        /* Transitions */
        .fade-enter { opacity: 0; transform: translateY(10px); }
        .fade-enter-active { opacity: 1; transform: translateY(0); transition: opacity 300ms, transform 300ms; }
    </style>
</head>
<body class="bg-gray-50 text-gray-800">

    <!-- Toast Container -->
    <div id="toast-container" class="fixed top-4 right-4 z-50 flex flex-col gap-2"></div>

    <!-- Modal Container -->
    <div id="modal-backdrop" class="fixed inset-0 bg-black/50 z-40 hidden flex items-center justify-center backdrop-blur-sm transition-opacity opacity-0">
        <div id="modal-content" class="bg-white rounded-xl shadow-2xl w-full max-w-md mx-4 transform scale-95 transition-all duration-200">
            <!-- Dynamic Content -->
        </div>
    </div>

    <!-- Main Content -->
    <div class="container mx-auto px-4 py-8 max-w-7xl">
        
        <!-- Top Header -->
        <header class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6 relative overflow-hidden">
            <div class="absolute top-0 right-0 w-2 h-full bg-blue-500"></div>
            
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <div>
                    <div class="flex items-center gap-3 mb-2">
                        <h1 class="text-3xl font-bold text-gray-900"><?= htmlspecialchars($batchName) ?></h1>
                        <span class="px-3 py-1 rounded-full text-xs font-bold <?= $isClosed ? 'bg-gray-100 text-gray-500' : 'bg-green-100 text-green-700' ?>">
                            <?= $isClosed ? 'مغلقة' : 'نشطة' ?>
                        </span>
                    </div>
                    <div class="flex gap-4 text-sm text-gray-500">
                        <span class="flex items-center gap-1"><i data-lucide="package" class="w-4 h-4"></i> <?= count($guarantees) ?> ضمان</span>
                        <span class="flex items-center gap-1"><i data-lucide="file-spreadsheet" class="w-4 h-4"></i> <?= htmlspecialchars($importSource) ?></span>
                    </div>
                    <?php if ($batchNotes): ?>
                        <div class="mt-3 bg-yellow-50 text-yellow-800 px-3 py-2 rounded-lg text-sm border border-yellow-100 inline-block">
                            💡 <?= htmlspecialchars($batchNotes) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button onclick="openMetadataModal()" class="btn-secondary">
                        <i data-lucide="edit-3" class="w-4 h-4"></i> تعديل البيانات
                    </button>
                    
                    <?php if (!$isClosed): ?>
                        <button onclick="handleBatchAction('close')" class="btn-danger">
                            <i data-lucide="lock" class="w-4 h-4"></i> إغلاق الدفعة
                        </button>
                        <button onclick="printReadyGuarantees()" class="btn-primary bg-green-600 hover:bg-green-700">
                            <i data-lucide="printer" class="w-4 h-4"></i> طباعة الجاهز
                        </button>
                    <?php else: ?>
                        <button onclick="handleBatchAction('reopen')" class="btn-warning">
                            <i data-lucide="unlock" class="w-4 h-4"></i> إعادة فتح
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <!-- Actions Toolbar -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-6 flex flex-wrap gap-3 items-center justify-between">
            <div class="flex gap-2">
                <?php if (!$isClosed): ?>
                    <button id="btn-extend" onclick="executeBulkAction('extend')" class="btn-action bg-blue-50 text-blue-700 hover:bg-blue-100">
                        <i data-lucide="calendar-plus" class="w-4 h-4"></i> تمديد المحدد
                    </button>
                    <button id="btn-release" onclick="executeBulkAction('release')" class="btn-action bg-green-50 text-green-700 hover:bg-green-100">
                        <i data-lucide="check-circle-2" class="w-4 h-4"></i> إفراج المحدد
                    </button>
                <?php endif; ?>
            </div>
            
            <div class="flex gap-2 text-sm">
                <button onclick="TableManager.toggleSelectAll(true)" class="text-gray-500 hover:text-gray-700">تحديد الكل</button>
                <span class="text-gray-300">|</span>
                <button onclick="TableManager.toggleSelectAll(false)" class="text-gray-500 hover:text-gray-700">إلغاء التحديد</button>
            </div>
        </div>

        <!-- Guarantees Table -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden relative min-h-[300px]">
            <div id="table-loading" class="loading-overlay hidden">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <?php if (!$isClosed): ?>
                                <th class="p-4 w-10">
                                    <input type="checkbox" onchange="TableManager.toggleSelectAll(this.checked)" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                </th>
                            <?php endif; ?>
                            <th class="p-4 text-right">رقم الضمان</th>
                            <th class="p-4 text-right">المورد</th>
                            <th class="p-4 text-right">البنك</th>
                            <th class="p-4 text-center">الإجراء</th>
                            <th class="p-4 text-left font-mono">القيمة</th>
                            <th class="p-4 text-center">الحالة</th>
                            <th class="p-4 text-center">تفاصيل</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($guarantees as $g): ?>
                            <tr class="hover:bg-blue-50/50 transition-colors group">
                                <?php if (!$isClosed): ?>
                                    <td class="p-4 text-center">
                                        <input type="checkbox" value="<?= $g['id'] ?>" class="guarantee-checkbox rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    </td>
                                <?php endif; ?>
                                <td class="p-4 font-medium text-gray-900"><?= htmlspecialchars($g['guarantee_number']) ?></td>
                                <td class="p-4 text-gray-600"><?= htmlspecialchars($g['supplier_name']) ?></td>
                                <td class="p-4 text-gray-600"><?= htmlspecialchars($g['bank_name']) ?></td>
                                <td class="p-4 text-center">
                                    <?php if ($g['active_action'] == 'release'): ?>
                                        <span class="px-2 py-1 rounded text-xs bg-green-100 text-green-700">إفراج</span>
                                    <?php elseif ($g['active_action'] == 'extension'): ?>
                                        <span class="px-2 py-1 rounded text-xs bg-blue-100 text-blue-700">تمديد</span>
                                    <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 text-left font-mono text-gray-700" dir="ltr">
                                    <?= number_format((float)($g['parsed']['amount'] ?? 0), 2) ?>
                                </td>
                                <td class="p-4 text-center">
                                    <?php if ($g['decision_status'] === 'ready'): ?>
                                        <div class="flex items-center justify-center gap-1 text-green-600 font-medium text-xs">
                                            <i data-lucide="check" class="w-3 h-3"></i> جاهز
                                        </div>
                                    <?php else: ?>
                                        <span class="text-gray-400 text-xs">غير جاهز</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 text-center">
                                    <a href="/index.php?id=<?= $g['id'] ?>" class="p-2 text-gray-400 hover:text-blue-600 hover:bg-blue-50 rounded-full inline-block transition-colors">
                                        <i data-lucide="arrow-left" class="w-4 h-4"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if (empty($guarantees)): ?>
                <div class="py-12 flex flex-col items-center justify-center text-gray-400">
                    <i data-lucide="inbox" class="w-12 h-12 mb-3 stroke-1"></i>
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