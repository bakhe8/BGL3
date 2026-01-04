<?php
require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Settings;

// Load current settings
$settings = new Settings();
$currentSettings = $settings->all();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الإعدادات - BGL System v3.0</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        /* Previous CSS styles remain... */
        :root {
            --bg-body: #f1f5f9;
            --bg-card: #ffffff;
            --bg-secondary: #f8fafc;
            --border-primary: #e2e8f0;
            --text-primary: #1e293b;
            --text-secondary: #475569;
            --text-muted: #64748b;
            --accent-primary: #3b82f6;
            --accent-success: #16a34a;
            --accent-danger: #dc2626;
            --font-family: 'Tajawal', sans-serif;
            --radius-md: 8px;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.06);
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: var(--font-family);
            background: var(--bg-body);
            color: var(--text-primary);
            padding: 20px;
            line-height: 1.6;
        }
        
        .container { max-width: 95%; margin: 0 auto; } /* Increased width for tables */
        
        /* Header & Common Components */
        .header {
            background: var(--bg-card);
            padding: 20px 24px;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 { font-size: 24px; font-weight: 700; color: var(--text-primary); }
        
        .btn {
            padding: 10px 20px;
            background: var(--accent-primary);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s;
            display: inline-block;
            cursor: pointer;
            font-family: var(--font-family);
            font-size: 14px;
        }
        .btn:hover { opacity: 0.9; transform: translateY(-1px); }
        .btn-success { background: var(--accent-success); }
        .btn-danger { background: var(--accent-danger); }

        /* Tabs Styling */
        .tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid var(--border-primary); padding-bottom: 0; }
        .tab-btn {
            padding: 12px 24px;
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            color: var(--text-secondary);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 16px;
        }
        .tab-btn:hover { color: var(--accent-primary); background: rgba(59, 130, 246, 0.05); }
        .tab-btn.active {
            color: var(--accent-primary);
            border-bottom-color: var(--accent-primary);
            background: white;
            border-radius: 8px 8px 0 0;
        }
        
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

        /* Card & Forms */
        .card {
            background: var(--bg-card);
            border-radius: var(--radius-md);
            padding: 24px;
            box-shadow: var(--shadow-md);
            margin-bottom: 20px;
        }
        .card-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 20px;
            color: var(--text-primary);
            border-bottom: 2px solid var(--border-primary);
            padding-bottom: 12px;
        }
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; font-weight: 600; margin-bottom: 8px; color: var(--text-primary); font-size: 14px; }
        .form-help { display: block; font-size: 12px; color: var(--text-muted); margin-bottom: 8px; }
        .form-input {
            width: 100%; padding: 10px 12px; border: 1px solid var(--border-primary);
            border-radius: var(--radius-md); font-family: var(--font-family); font-size: 14px; transition: all 0.2s;
        }
        .form-input:focus { outline: none; border-color: var(--accent-primary); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
        .form-actions { display: flex; gap: 12px; margin-top: 24px; padding-top: 24px; border-top: 1px solid var(--border-primary); }
        
        /* Alerts */
        .alert { padding: 16px; border-radius: var(--radius-md); margin-bottom: 20px; font-weight: 500; }
        .alert-success { background: rgba(22, 163, 74, 0.1); color: var(--accent-success); border: 1px solid var(--accent-success); }
        .alert-error { background: rgba(220, 38, 38, 0.1); color: var(--accent-danger); border: 1px solid var(--accent-danger); }
        .alert-hidden { display: none; }

        .grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
        @media (max-width: 768px) { .grid-2 { grid-template-columns: 1fr; } }
        
        /* Tables */
        .data-table { width: 100%; border-collapse: separate; border-spacing: 0; margin-top: 10px; border-radius: 8px; overflow: hidden; border: 1px solid var(--border-primary); }
        .data-table th, .data-table td { padding: 10px 12px; text-align: right; border-bottom: 1px solid var(--border-primary); }
        .data-table th { background: var(--bg-secondary); font-weight: 700; color: var(--text-secondary); white-space: nowrap; }
        .data-table tr:last-child td { border-bottom: none; }
        .data-table tr:hover { background: #f8fafc; }
        
        /* Editable Inputs */
        .row-input {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #cbd5e1; 
            background: white;
            border-radius: 6px;
            font-family: inherit;
            font-size: 14px;
            transition: all 0.2s;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .row-input:focus {
            border-color: var(--accent-primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }
        .row-input:disabled { background: #f1f5f9; color: var(--text-muted); }
        
        select.row-input { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2364748b'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: left 8px center; background-size: 16px; padding-left: 30px; }

        /* Loading State */
        .loading { position: relative; opacity: 0.6; pointer-events: none; min-height: 100px; }
        .loading::after {
            content: "⏳ جاري التحميل...";
            position: absolute; top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(0,0,0,0.7); color: white;
            padding: 8px 16px; border-radius: 20px;
            font-size: 14px; font-weight: bold;
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        .modal {
            background: white;
            padding: 24px;
            border-radius: 12px;
            width: 100%;
            max-width: 500px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }
        .modal-header { font-size: 1.25rem; font-weight: 700; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; }
        .modal-body .form-group { margin-bottom: 12px; }
        .modal-footer { margin-top: 20px; display: flex; justify-content: flex-end; gap: 8px; }
        .close-modal { cursor: pointer; background: none; border: none; font-size: 1.5rem; }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>⚙️ إعدادات النظام</h1>
            <a href="../index.php" class="btn">العودة للرئيسية</a>
        </div>

        <!-- Alert Messages -->
        <div id="alertSuccess" class="alert alert-success alert-hidden"></div>
        <div id="alertError" class="alert alert-error alert-hidden"></div>

        <!-- Tabs Navigation -->
        <div class="tabs">
            <button class="tab-btn active" onclick="switchTab('general')">🛠️ الإعدادات العامة</button>
            <button class="tab-btn" onclick="switchTab('banks')">🏦 البنوك</button>
            <button class="tab-btn" onclick="switchTab('suppliers')">📦 الموردين</button>
            <button class="tab-btn" onclick="switchTab('learning')">🧠 التعلم الآلي</button>
        </div>
        
        <!-- Tab 1: General Settings -->
        <div id="general" class="tab-content active">
            <form id="settingsForm">
                <!-- Matching Thresholds -->
                <div class="card">
                    <h2 class="card-title">عتبات المطابقة</h2>
                    <div class="form-group">
                        <label class="form-label">عتبة القبول التلقائي</label>
                        <span class="form-help">MATCH_AUTO_THRESHOLD (>= 90%)</span>
                        <input type="number" class="form-input" name="MATCH_AUTO_THRESHOLD" value="<?= $currentSettings['MATCH_AUTO_THRESHOLD'] ?>" min="0" max="1" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">عتبة المراجعة</label>
                        <span class="form-help">MATCH_REVIEW_THRESHOLD (< 70%)</span>
                        <input type="number" class="form-input" name="MATCH_REVIEW_THRESHOLD" value="<?= $currentSettings['MATCH_REVIEW_THRESHOLD'] ?>" min="0" max="1" step="0.01" required>
                    </div>
                </div>

                <!-- Score Weights -->
                <div class="card">
                    <h2 class="card-title">أوزان النقاط</h2>
                    <div class="grid-2">
                        <div class="form-group">
                            <label class="form-label">وزن الاسم الرسمي</label>
                            <input type="number" class="form-input" name="WEIGHT_OFFICIAL" value="<?= $currentSettings['WEIGHT_OFFICIAL'] ?>" min="0.01" step="0.01" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">وزن الاسم البديل المؤكد</label>
                            <input type="number" class="form-input" name="WEIGHT_ALT_CONFIRMED" value="<?= $currentSettings['WEIGHT_ALT_CONFIRMED'] ?>" min="0.01" step="0.01" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">وزن الاسم البديل المتعلم</label>
                            <input type="number" class="form-input" name="WEIGHT_ALT_LEARNING" value="<?= $currentSettings['WEIGHT_ALT_LEARNING'] ?>" min="0.01" step="0.01" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">عقوبة المطابقة الضبابية</label>
                            <input type="number" class="form-input" name="WEIGHT_FUZZY" value="<?= $currentSettings['WEIGHT_FUZZY'] ?>" min="0.01" step="0.01" required>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-success">💾 حفظ التغييرات</button>
                    <button type="button" id="resetBtn" class="btn btn-danger">🔄 استعادة الافتراضيات</button>
                </div>
            </form>
        </div>

        <!-- Tab 2: Banks -->
        <div id="banks" class="tab-content">
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h2 class="card-title" style="margin-bottom: 0; border-bottom: none; padding-bottom: 0;">إدارة البنوك</h2>
                    <div style="display: flex; gap: 10px;">
                        <button class="btn btn-secondary" onclick="exportData('banks')">⬇️ تصدير JSON</button>
                        <button class="btn btn-secondary" onclick="document.getElementById('importBanksFile').click()">⬆️ استيراد JSON</button>
                        <input type="file" id="importBanksFile" hidden accept=".json" onchange="importData('banks', this)">
                        <button class="btn btn-primary" onclick="openModal('addBankModal')">+ إضافة بنك جديد</button>
                    </div>
                </div>
                <div id="banksTableContainer">جاري التحميل...</div>
            </div>
        </div>

        <!-- Tab 3: Suppliers -->
        <div id="suppliers" class="tab-content">
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h2 class="card-title" style="margin-bottom: 0; border-bottom: none; padding-bottom: 0;">إدارة الموردين</h2>
                    <div style="display: flex; gap: 10px;">
                        <button class="btn btn-secondary" onclick="exportData('suppliers')">⬇️ تصدير JSON</button>
                        <button class="btn btn-secondary" onclick="document.getElementById('importSuppliersFile').click()">⬆️ استيراد JSON</button>
                        <input type="file" id="importSuppliersFile" hidden accept=".json" onchange="importData('suppliers', this)">
                        <button class="btn btn-primary" onclick="openModal('addSupplierModal')">+ إضافة مورد جديد</button>
                    </div>
                </div>
                <div id="suppliersTableContainer">جاري التحميل...</div>
            </div>
        </div>

        <!-- Tab 4: Machine Learning -->
        <div id="learning" class="tab-content">
            <!-- Learning Stats -->
            <div class="card">
                <h2 class="card-title">🧠 حالة نظام التعلم</h2>
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">عدد الأنماط المكتسبة (Confirmations)</label>
                        <div id="confirmsCount" style="font-size: 24px; font-weight: bold; color: var(--accent-success);">...</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">عدد حالات الحظر/العقاب (Rejections)</label>
                        <div id="rejectsCount" style="font-size: 24px; font-weight: bold; color: var(--accent-danger);">...</div>
                    </div>
                </div>
            </div>

            <!-- Blocked/Penalized Table -->
            <div class="card">
                <h2 class="card-title" style="color: var(--accent-danger);">🚫 قائمة العقوبات (Lowest Confidence)</h2>
                <p class="form-help">هذه القائمة تحتوي على الاقتراحات التي رفضها المستخدمون. يتم تطبيق عقوبة 33.4% لكل رفض.</p>
                <div id="rejectionsTableContainer">جاري التحميل...</div>
            </div>

            <!-- Learned Patterns Table -->
            <div class="card">
                <h2 class="card-title" style="color: var(--accent-success);">✅ الأنماط المؤكدة (Learned Patterns)</h2>
                <p class="form-help">هذه الاقتراحات تم تأكيدها من قبل المستخدمين وتظهر بثقة عالية.</p>
                <div id="confirmationsTableContainer">جاري التحميل...</div>
            </div>
        </div>
    </div>
    
    <!-- Modals (AddBank, AddSupplier, Confirm) remain unchanged -->
    <!-- Add Bank Modal -->
    <div id="addBankModal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <span>إضافة بنك جديد</span>
                <button class="close-modal" onclick="closeModal('addBankModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addBankForm" onsubmit="event.preventDefault(); createBank();">
                    <div class="form-group"><label class="form-label">الاسم العربي *</label><input required name="arabic_name" class="form-input"></div>
                    <div class="form-group"><label class="form-label">الاسم الإنجليزي</label><input name="english_name" class="form-input"></div>
                    <div class="form-group"><label class="form-label">الاسم المختصر</label><input name="short_name" class="form-input"></div>
                    <div class="form-group"><label class="form-label">إدارة الضمانات</label><input name="department" class="form-input"></div>
                    <div class="form-group"><label class="form-label">صندوق البريد</label><input name="address_line1" class="form-input"></div>
                    <div class="form-group"><label class="form-label">البريد الإلكتروني</label><input type="email" name="contact_email" class="form-input"></div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('addBankModal')">إلغاء</button>
                <button class="btn btn-primary" onclick="document.getElementById('addBankForm').dispatchEvent(new Event('submit'))">حفظ</button>
            </div>
        </div>
    </div>

    <!-- Add Supplier Modal -->
    <div id="addSupplierModal" class="modal-overlay">
         <div class="modal">
            <div class="modal-header">
                <span>إضافة مورد جديد</span>
                <button class="close-modal" onclick="closeModal('addSupplierModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addSupplierForm" onsubmit="event.preventDefault(); createSupplier();">
                    <div class="form-group"><label class="form-label">الاسم الرسمي *</label><input required name="official_name" class="form-input"></div>
                    <div class="form-group"><label class="form-label">الاسم الإنجليزي</label><input name="english_name" class="form-input"></div>
                    <div class="form-group">
                        <label class="form-label">الحالة</label>
                        <select name="is_confirmed" class="form-input">
                            <option value="1">مؤكد</option>
                            <option value="0">غير مؤكد</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('addSupplierModal')">إلغاء</button>
                <button class="btn btn-primary" onclick="document.getElementById('addSupplierForm').dispatchEvent(new Event('submit'))">حفظ</button>
            </div>
        </div>
    </div>
    <!-- Confirmation Modal -->
    <div id="confirmModal" class="modal-overlay">
        <div class="modal" style="max-width: 400px;">
            <div class="modal-header">
                <span>تأكيد الإجراء</span>
                <button class="close-modal" onclick="closeModal('confirmModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p id="confirmMessage" style="color: var(--text-secondary); margin-bottom: 20px;">هل أنت متأكد؟</p>
                <div class="modal-footer">
                    <button class="btn btn-secondary" onclick="closeModal('confirmModal')">إلغاء</button>
                    <button id="confirmBtn" class="btn btn-danger">نعم، تابع</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const form = document.getElementById('settingsForm');
        const successAlert = document.getElementById('alertSuccess');
        const errorAlert = document.getElementById('alertError');
        const resetBtn = document.getElementById('resetBtn');
        
        function showAlert(type, message) {
            const alert = type === 'success' ? successAlert : errorAlert;
            alert.textContent = message;
            alert.classList.remove('alert-hidden');
            setTimeout(() => alert.classList.add('alert-hidden'), 5000);
        }
        
        function hideAlerts() {
            successAlert.classList.add('alert-hidden');
            errorAlert.classList.add('alert-hidden');
        }
        
        // --- Modals ---
        function openModal(id) { document.getElementById(id).style.display = 'flex'; }
        function closeModal(id) { document.getElementById(id).style.display = 'none'; }
        
        // Confirm Modal Logic
        let confirmCallback = null;
        function showConfirm(message, callback) {
            document.getElementById('confirmMessage').textContent = message;
            confirmCallback = callback;
            openModal('confirmModal');
        }
        
        document.getElementById('confirmBtn').addEventListener('click', () => {
            if (confirmCallback) confirmCallback();
            closeModal('confirmModal');
        });
        
        // Close modal on outside click
        window.onclick = function(event) {
            if (event.target.classList.contains('modal-overlay')) {
                event.target.style.display = 'none';
            }
        }

        async function createBank() {
            const form = document.getElementById('addBankForm');
            const data = Object.fromEntries(new FormData(form));
            
            try {
                const response = await fetch('../api/create-bank.php', {
                    method: 'POST', body: JSON.stringify(data), headers: {'Content-Type': 'application/json'}
                });
                const result = await response.json();
                if(result.success) {
                    showAlert('success', '✅ تم إضافة البنك بنجاح');
                    closeModal('addBankModal');
                    form.reset();
                    loadBanks(); // Refresh table
                } else throw new Error(result.error);
            } catch(e) { showAlert('error', '❌ فشل الإضافة: ' + e.message); }
        }

        async function createSupplier() {
             const form = document.getElementById('addSupplierForm');
            const data = Object.fromEntries(new FormData(form));
             data.is_confirmed = form.is_confirmed.value == '1';

            try {
                const response = await fetch('../api/create_supplier.php', {
                    method: 'POST', body: JSON.stringify(data), headers: {'Content-Type': 'application/json'}
                });
                const result = await response.json();
                if(result.success) {
                    showAlert('success', '✅ تم إضافة المورد بنجاح');
                    closeModal('addSupplierModal');
                    form.reset();
                    loadSuppliers(); // Refresh table
                } else throw new Error(result.error);
            } catch(e) { showAlert('error', '❌ فشل الإضافة: ' + e.message); }
        }

        // Tab Switching Logic
        function switchTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
            
            document.getElementById(tabId).classList.add('active');
            // Find the button that triggered this or match by tabId
            document.querySelector(`.tab-btn[onclick="switchTab('${tabId}')"]`).classList.add('active');

            // Lazy load content
            if (tabId === 'banks' && document.getElementById('banksTableContainer').innerText === 'جاري التحميل...') {
                loadBanks();
            }
            if (tabId === 'suppliers' && document.getElementById('suppliersTableContainer').innerText === 'جاري التحميل...') {
                loadSuppliers();
            }
            if (tabId === 'learning') {
                loadLearningData();
            }
        }


        // Mock Fetch Loaders (Will implement real fetch next)
        async function loadBanks(page = 1) {
            const container = document.getElementById('banksTableContainer');
            if (container.classList.contains('loading')) return; // Prevent double fetch
            
            container.classList.add('loading');
            try {
                const res = await fetch(`../api/get_banks.php?page=${page}`);
                const html = await res.text();
                // Policy: Use outerHTML replacement
                container.outerHTML = html;
            } catch (e) {
                showAlert('error', 'فشل تحميل البنوك: ' + e.message);
                container.classList.remove('loading');
            }
        }

        async function loadSuppliers(page = 1) {
            const container = document.getElementById('suppliersTableContainer');
            if (container.classList.contains('loading')) return;

            container.classList.add('loading');
            try {
                const res = await fetch(`../api/get_suppliers.php?page=${page}`);
                const html = await res.text();
                // Policy: Use outerHTML replacement
                container.outerHTML = html;
            } catch (e) {
                showAlert('error', 'فشل تحميل الموردين: ' + e.message);
                container.classList.remove('loading');
            }
        }
        
        async function loadLearningData() {
            const cContainer = document.getElementById('confirmationsTableContainer');
            const rContainer = document.getElementById('rejectionsTableContainer');
            
            try {
                const res = await fetch('../api/learning-data.php');
                const data = await res.json();
                
                if (data.success) {
                    // Update Stats
                    document.getElementById('confirmsCount').textContent = data.confirmations.length;
                    document.getElementById('rejectsCount').textContent = data.rejections.length;
                    
                    // Render Tables
                    cContainer.innerHTML = renderLearningTable(data.confirmations, 'confirm');
                    rContainer.innerHTML = renderLearningTable(data.rejections, 'reject');
                } else {
                    showAlert('error', 'فشل تحميل بيانات التعلم');
                }
            } catch (e) {
                showAlert('error', 'خطأ في الاتصال: ' + e.message);
            }
        }
        
        function renderLearningTable(items, type) {
            if (items.length === 0) return '<p style="padding:10px; color:#666;">لا توجد بيانات.</p>';
            
            const actionBtnClass = type === 'confirm' ? 'btn-secondary' : 'btn-success';
            const actionBtnText = type === 'confirm' ? '🗑️ نسيان' : '✅ إلغاء العقوبة';
            
            let html = `
            <table class="data-table">
                <thead>
                    <tr>
                        <th>النص المدخل (Pattern)</th>
                        <th>المورد المقترح (Supplier)</th>
                        <th>العدد</th>
                        <th>آخر تحديث</th>
                        <th>إجراءات</th>
                    </tr>
                </thead>
                <tbody>`;
                
            items.forEach(item => {
                html += `
                <tr>
                    <td>${item.pattern}</td>
                    <td>${item.official_name}</td>
                    <td>${item.count}</td>
                    <td>${item.updated_at}</td>
                    <td>
                        <button class="btn ${actionBtnClass}" style="padding: 4px 8px; font-size: 12px;" onclick="deleteLearningItem(${item.id})">${actionBtnText}</button>
                    </td>
                </tr>`;
            });
            
            html += '</tbody></table>';
            return html;
        }
        
        async function deleteLearningItem(id) {
            showConfirm('هل أنت متأكد من حذف هذا السجل؟ سيقوم النظام بنسيان ما تعلمه هنا.', async () => {
                try {
                    const response = await fetch('../api/learning-action.php', {
                        method: 'POST',
                        body: JSON.stringify({ id: id, action: 'delete' }),
                        headers: {'Content-Type': 'application/json'}
                    });
                     const result = await response.json();
                     if (result.success) {
                         showAlert('success', 'تم الحذف بنجاح');
                         loadLearningData(); // Refresh
                     } else {
                         showAlert('error', 'فشل الحذف');
                     }
                } catch (e) {
                     showAlert('error', 'خطأ في الاتصال');
                }
            });
        }

        /* Existing JS for Settings Form */
        
        function hideAlerts() {
            successAlert.classList.add('alert-hidden');
            errorAlert.classList.add('alert-hidden');
        }
        
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            hideAlerts();
            const formData = new FormData(form);
            const settings = {};
            for (let [key, value] of formData.entries()) {
                settings[key] = isNaN(value) ? value : parseFloat(value);
            }
            try {
                const response = await fetch('../api/settings.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(settings)
                });
                const data = await response.json();
                if (data.success) showAlert('success', '✅ تم حفظ الإعدادات بنجاح');
                else showAlert('error', '❌ خطأ: ' + (data.errors ? data.errors.join(', ') : data.error));
            } catch (error) {
                showAlert('error', '❌ خطأ في الاتصال: ' + error.message);
            }
        });

        // --- Action Handlers ---
        
        async function updateBank(id, btn) {
            const row = btn.closest('tr');
            if (!row) return;

            const inputs = row.querySelectorAll('.row-input');
            const data = { id: id };
            
            inputs.forEach(input => {
                data[input.name] = input.value;
            });

            // Visual feedback
            const originalText = btn.innerHTML;
            btn.innerHTML = '⏳ جار الحفظ...';
            btn.disabled = true;

            try {
                const response = await fetch('../api/update_bank.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    btn.innerHTML = '✅ تم الحفظ';
                    btn.classList.add('btn-success');
                    setTimeout(() => {
                        btn.innerHTML = originalText;
                        btn.classList.remove('btn-success');
                        btn.disabled = false;
                    }, 2000);
                } else {
                    showAlert('error', 'فشل التحديث: ' + (result.error || 'خطأ غير معروف'));
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            } catch (e) {
                showAlert('error', 'خطأ في الاتصال');
                console.error(e);
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }

        async function updateSupplier(id, btn) {
             const row = btn.closest('tr');
            if (!row) return;

            const inputs = row.querySelectorAll('.row-input');
            const data = { id: id };
            
            inputs.forEach(input => {
                data[input.name] = input.value;
            });

            const originalText = btn.innerHTML;
            btn.innerHTML = '⏳ جار الحفظ...';
            btn.disabled = true;

            try {
                const response = await fetch('../api/update_supplier.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    btn.innerHTML = '✅ تم الحفظ';
                    btn.classList.add('btn-success');
                    setTimeout(() => {
                        btn.innerHTML = originalText;
                        btn.classList.remove('btn-success');
                        btn.classList.remove('btn-success');
                        btn.disabled = false;
                    }, 2000);
                } else {
                    showAlert('error', 'فشل التحديث: ' + (result.error || 'خطأ غير معروف'));
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            } catch (e) {
                showAlert('error', 'خطأ في الاتصال');
                console.error(e);
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }
        
        async function deleteBank(id) {
            showConfirm('هل أنت متأكد من حذف هذا البنك؟ لا يمكن التراجع عن هذا الإجراء.', async () => {
                try {
                    const response = await fetch('../api/delete_bank.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: id })
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        // Remove row from DOM
                        const row = document.querySelector(`tr[data-id="${id}"]`);
                        if (row) row.remove();
                        showAlert('success', '✅ تم حذف البنك بنجاح');
                    } else {
                        showAlert('error', 'فشل الحذف: ' + (result.error || 'خطأ غير معروف'));
                    }
                } catch (e) {
                    showAlert('error', 'خطأ في الاتصال');
                    console.error(e);
                }
            });
        }
        
        async function deleteSupplier(id) {
            showConfirm('هل أنت متأكد من حذف هذا المورد؟ لا يمكن التراجع عن هذا الإجراء.', async () => {
                try {
                    const response = await fetch('../api/delete_supplier.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: id })
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        // Remove row from DOM
                        const row = document.querySelector(`tr[data-id="${id}"]`);
                        if (row) row.remove();
                        showAlert('success', '✅ تم حذف المورد بنجاح');
                    } else {
                        showAlert('error', 'فشل الحذف: ' + (result.error || 'خطأ غير معروف'));
                    }
                } catch (e) {
                    showAlert('error', 'خطأ في الاتصال');
                    console.error(e);
                }
            });
        }
        
        resetBtn.addEventListener('click', async () => {
            showConfirm('هل أنت متأكد من استعادة الإعدادات الافتراضية؟', async () => {
                // Implement Reset logic or fetch
            });
        });

        // --- Export / Import ---
        function exportData(type) {
            const url = type === 'banks' ? '../api/export_banks.php' : '../api/export_suppliers.php';
            window.location.href = url;
        }

        async function importData(type, input) {
            if (!input.files || input.files.length === 0) return;
            const file = input.files[0];
            const formData = new FormData();
            formData.append('file', file);
            
            const url = type === 'banks' ? '../api/import_banks.php' : '../api/import_suppliers.php';
            const btn = input.previousElementSibling; // The Import button
            const originalText = btn.innerText;

            btn.innerText = '⏳ جار الرفع...';
            btn.disabled = true;

            try {
                const response = await fetch(url, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success) {
                    showAlert('success', result.message);
                    // Refresh Table
                    if (type === 'banks') loadBanks();
                    else loadSuppliers();
                } else {
                    showAlert('error', 'فشل الاستيراد: ' + result.error);
                }
            } catch (e) {
                showAlert('error', 'خطأ في الاتصال: ' + e.message);
            } finally {
                btn.innerText = originalText;
                btn.disabled = false;
                input.value = ''; // Reset input to allow re-upload same file
            }
        }
    </script>
</body>
</html>

