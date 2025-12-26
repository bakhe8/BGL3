<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مراجعة البيانات - BGL Setup</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/setup.css">
</head>
<body class="setup-page">
    <div class="setup-container">
        <div class="setup-card">
            <div class="setup-header">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <h1>👁️ مراجعة البيانات</h1>
                        <p>راجع ووافق على البيانات قبل الحفظ النهائي</p>
                    </div>
                    <button onclick="quickImport()" class="btn btn-primary" style="font-size: 16px; padding: 10px 25px;">
                        ⚡ استيراد جديد
                    </button>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" data-tab="suppliers">
                    الموردين (<span id="suppliersCount">0</span>)
                </button>
                <button class="tab" data-tab="banks">
                    البنوك (<span id="banksCount">0</span>)
                </button>
            </div>

            <!-- Suppliers Tab -->
            <div id="suppliers-tab" class="tab-content">
                <!-- Sticky Action Bar -->
                <div id="suppliersActionBar" style="position: sticky; top: 0; z-index: 100; background: white; padding: 15px 0; margin-bottom: 20px; border-bottom: 2px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
                    <div style="display: flex; gap: 10px;">
                        <button onclick="confirmAll('suppliers')" class="btn btn-success btn-sm">
                            ✓ تأكيد الكل
                        </button>
                        <button onclick="console.log('Button clicked!'); mergeSelectedSuppliers();" id="mergeSuppliersBtn" class="btn btn-sm" style="background: #f59e0b; color: white; display: none;">
                            🔗 دمج المحدد (<span id="selectedSuppliersCount">0</span>)
                        </button>
                    </div>
                    <div style="color: #64748b;">
                        مؤكد: <strong id="confirmedSuppliers">0</strong>
                    </div>
                </div>
                
                <table style="width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden;">
                    <thead>
                        <tr style="background: #f1f5f9;">
                            <th style="padding: 12px; text-align: center; font-weight: 600; border-bottom: 2px solid #e2e8f0; width: 3%;">
                                <input type="checkbox" id="selectAllSuppliers" onchange="toggleSelectAllSuppliers(this)">
                            </th>
                            <th style="padding: 12px; text-align: right; font-weight: 600; border-bottom: 2px solid #e2e8f0;">الاسم العربي</th>
                            <th style="padding: 12px; text-align: right; font-weight: 600; border-bottom: 2px solid #e2e8f0;">الاسم الإنجليزي</th>
                            <th style="padding: 12px; text-align: center; font-weight: 600; border-bottom: 2px solid #e2e8f0; width: 150px;">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody id="suppliersList">
                        <!-- Will be populated by JavaScript -->
                    </tbody>
                </table>
            </div>

            <!-- Banks Tab -->
            <div id="banks-tab" class="tab-content" style="display: none;">
                <!-- Sticky Action Bar -->
                <div id="banksActionBar" style="position: sticky; top: 0; z-index: 100; background: white; padding: 15px 0; margin-bottom: 20px; border-bottom: 2px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
                    <div style="display: flex; gap: 10px;">
                        <button onclick="confirmAll('banks')" class="btn btn-success btn-sm">
                            ✓ تأكيد الكل
                        </button>
                        <button onclick="console.log('Banks button clicked!'); mergeSelectedBanks();" id="mergeBtn" class="btn btn-sm" style="background: #f59e0b; color: white; display: none;">
                            🔗 دمج المحدد (<span id="selectedCount">0</span>)
                        </button>
                    </div>
                    <div style="color: #64748b;">
                        مؤكد: <strong id="confirmedBanks">0</strong>
                    </div>
                </div>

                <table style="width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden;">
                    <thead>
                        <tr style="background: #f1f5f9;">
                            <th id="selectColumnHeader" style="padding: 12px; text-align: center; font-weight: 600; border-bottom: 2px solid #e2e8f0; width: 3%;">
                                <input type="checkbox" id="selectAllBanks" onchange="toggleSelectAll(this)">
                            </th>
                            <th style="padding: 12px; text-align: right; font-weight: 600; border-bottom: 2px solid #e2e8f0; width: 18%;">الاسم العربي</th>
                            <th style="padding: 12px; text-align: right; font-weight: 600; border-bottom: 2px solid #e2e8f0; width: 18%;">الاسم الإنجليزي</th>
                            <th style="padding: 12px; text-align: right; font-weight: 600; border-bottom: 2px solid #e2e8f0; width: 10%;">المختصر</th>
                            <th style="padding: 12px; text-align: right; font-weight: 600; border-bottom: 2px solid #e2e8f0; width: 15%;">القسم/المركز</th>
                            <th style="padding: 12px; text-align: right; font-weight: 600; border-bottom: 2px solid #e2e8f0; width: 15%;">البريد الإلكتروني</th>
                            <th style="padding: 12px; text-align: right; font-weight: 600; border-bottom: 2px solid #e2e8f0; width: 12%;">العنوان</th>
                            <th style="padding: 12px; text-align: center; font-weight: 600; border-bottom: 2px solid #e2e8f0; width: 12%;">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody id="banksList">
                        <!-- Will be populated by JavaScript -->
                    </tbody>
                </table>
            </div>

            <!-- Actions -->
            <div style="display: flex; justify-content: space-between; margin-top: 40px; padding-top: 20px; border-top: 2px solid #e2e8f0;">
                <button onclick="window.location.href='import.php'" class="btn" style="background: #64748b; color: white;">
                    ← الرجوع
                </button>
                <button onclick="migrate()" class="btn btn-success" style="font-size: 18px; padding: 16px 40px;">
                    حفظ ونقل البيانات ✔
                </button>
            </div>
        </div>
    </div>

    <style>
        /* Make checkboxes larger and more visible */
        input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #16a34a;
        }

        /* Ensure checkbox cells are visible */
        .bank-select-checkbox,
        .supplier-select-checkbox {
            min-width: 40px;
        }

        /* Table header checkbox */
        #selectAllBanks,
        #selectAllSuppliers {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
    </style>

    <script>
        let suppliersData = [];
        let banksData = [];
        let selectedBankGroups = new Set();
        let selectedSupplierGroups = new Set();

        // Load data on page load
        document.addEventListener('DOMContentLoaded', () => {
            loadSuppliers();
            loadBanks();
            setupTabs();
        });

        // Suppliers merge functions
        function toggleSelectAllSuppliers(checkbox) {
            const allCheckboxes = document.querySelectorAll('.supplier-group-checkbox');
            allCheckboxes.forEach(cb => {
                cb.checked = checkbox.checked;
                if (checkbox.checked) {
                    selectedSupplierGroups.add(cb.dataset.groupKey);
                } else {
                    selectedSupplierGroups.delete(cb.dataset.groupKey);
                }
            });
            updateSuppliersMergeButton();
        }

        function toggleSupplierSelection(checkbox, groupKey) {
            console.log('toggleSupplierSelection called', {checkbox, groupKey, checked: checkbox.checked});
            if (checkbox.checked) {
                selectedSupplierGroups.add(groupKey);
            } else {
                selectedSupplierGroups.delete(groupKey);
                document.getElementById('selectAllSuppliers').checked = false;
            }
            console.log('selectedSupplierGroups size:', selectedSupplierGroups.size);
            updateSuppliersMergeButton();
        }

        function updateSuppliersMergeButton() {
            const mergeBtn = document.getElementById('mergeSuppliersBtn');
            const count = document.getElementById('selectedSuppliersCount');
            
            if (selectedSupplierGroups.size >= 2) {
                mergeBtn.style.display = 'inline-block';
                count.textContent = selectedSupplierGroups.size;
            } else {
                mergeBtn.style.display = 'none';
                // Don't clear selections when size is 1, only when explicitly deselecting
            }
        }

        async function mergeSelectedSuppliers() {
            console.log('mergeSelectedSuppliers called! Selected count:', selectedSupplierGroups.size);
            
            if (selectedSupplierGroups.size < 2) {
                showToast('اختر صفين على الأقل للدمج', 'error');
                return;
            }

            // Collect all supplier IDs from selected groups
            const selectedKeys = Array.from(selectedSupplierGroups);
            const allSupplierIds = [];
            
            // Find the supplier IDs for each selected group
            const supplierGroups = {};
            
            suppliersData.forEach(supplier => {
                const groupKey = supplier.normalized_name;
                
                if (!supplierGroups[groupKey]) {
                    supplierGroups[groupKey] = { ids: [] };
                }
                supplierGroups[groupKey].ids.push(supplier.id);
            });

            // Get IDs for selected groups
            Object.entries(supplierGroups).forEach(([key, group], index) => {
                const groupKey = `supplier_${index}`;
                if (selectedKeys.includes(groupKey)) {
                    allSupplierIds.push(...group.ids);
                }
            });

            console.log('Supplier IDs to merge:', allSupplierIds);

            // Temporarily removed confirmation for testing
            const confirmed = true; // Auto-confirm for testing

            try {
                console.log('Sending merge request...');
                const response = await fetch('../api/merge-suppliers.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        supplier_ids: allSupplierIds
                    })
                });

                const data = await response.json();
                console.log('Merge response:', data);

                if (data.success) {
                    showToast('تم الدمج بنجاح!', 'success');
                    selectedSupplierGroups.clear();
                    document.getElementById('mergeSuppliersBtn').style.display = 'none';
                    await loadSuppliers();
                } else {
                    showToast(data.error || 'فشل الدمج', 'error');
                }
            } catch (error) {
                console.error('Merge error:', error);
                showToast('فشل الدمج', 'error');
            }
        }

        // Banks merge functions

        function toggleSelectAll(checkbox) {
            const allCheckboxes = document.querySelectorAll('.bank-group-checkbox');
            allCheckboxes.forEach(cb => {
                cb.checked = checkbox.checked;
                if (checkbox.checked) {
                    selectedBankGroups.add(cb.dataset.groupKey);
                } else {
                    selectedBankGroups.delete(cb.dataset.groupKey);
                }
            });
            updateMergeButton();
        }

        function toggleBankSelection(checkbox, groupKey) {
            if (checkbox.checked) {
                selectedBankGroups.add(groupKey);
            } else {
                selectedBankGroups.delete(groupKey);
                document.getElementById('selectAllBanks').checked = false;
            }
            updateMergeButton();
        }

        function updateMergeButton() {
            const mergeBtn = document.getElementById('mergeBtn');
            const count = document.getElementById('selectedCount');
            
            if (selectedBankGroups.size >= 2) {
                mergeBtn.style.display = 'inline-block';
                count.textContent = selectedBankGroups.size;
            } else {
                mergeBtn.style.display = 'none';
                // Don't clear selections when size is 1, only when explicitly deselecting
            }
        }

        async function mergeSelectedBanks() {
            console.log('mergeSelectedBanks called! Selected count:', selectedBankGroups.size);
            
            if (selectedBankGroups.size < 2) {
                showToast('اختر صفين على الأقل للدمج', 'error');
                return;
            }

            // Collect all bank IDs from selected groups
            const selectedKeys = Array.from(selectedBankGroups);
            const allBankIds = [];
            
            // Use SAME grouping logic as renderBanks - by normalized_name directly
            const bankGroups = {};
            
            banksData.forEach(bank => {
                const groupKey = bank.normalized_name; // Same as renderBanks!
                
                if (!bankGroups[groupKey]) {
                    bankGroups[groupKey] = { ids: [] };
                }
                bankGroups[groupKey].ids.push(bank.id);
            });

            // Get IDs for selected groups
            Object.entries(bankGroups).forEach(([key, group], index) => {
                const groupKey = `group_${index}`;
                if (selectedKeys.includes(groupKey)) {
                    allBankIds.push(...group.ids);
                }
            });

            console.log('Bank IDs to merge:', allBankIds);

            // Temporarily removed confirmation for testing
            const confirmed = true;

            try {
                console.log('Sending merge request...');
                const response = await fetch('../api/merge-banks.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        bank_ids: allBankIds
                    })
                });

                const data = await response.json();
                console.log('Merge response:', data);

                if (data.success) {
                    showToast('تم الدمج بنجاح!', 'success');
                    selectedBankGroups.clear();
                    document.getElementById('mergeBtn').style.display = 'none';
                    await loadBanks(); // Reload data
                } else {
                    showToast(data.error || 'فشل الدمج', 'error');
                }
            } catch (error) {
                console.error('Merge error:', error);
                showToast('فشل الدمج', 'error');
            }
        }

        // Tab switching
        function setupTabs() {
            document.querySelectorAll('.tab').forEach(tab => {
                tab.addEventListener('click', () => {
                    const tabName = tab.dataset.tab;
                    
                    // Update active tab
                    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                    tab.classList.add('active');
                    
                    // Show corresponding content
                    document.querySelectorAll('.tab-content').forEach(content => {
                        content.style.display = 'none';
                    });
                    document.getElementById(`${tabName}-tab`).style.display = 'block';
                });
            });
        }

        async function loadSuppliers() {
            try {
                const response = await fetch('../api/get-suppliers.php');
                const data = await response.json();
                
                if (data.success) {
                    suppliersData = data.data;
                    renderSuppliers();
                }
            } catch (error) {
                showToast('فشل تحميل الموردين', 'error');
            }
        }

        async function loadBanks() {
            try {
                const response = await fetch('../api/get-banks.php');
                const data = await response.json();
                
                if (data.success) {
                    banksData = data.data;
                    renderBanks();
                }
            } catch (error) {
                showToast('فشل تحميل البنوك', 'error');
            }
        }

        function renderSuppliers() {
            const tbody = document.getElementById('suppliersList');
            tbody.innerHTML = '';
            
            if (suppliersData.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 40px; color: #64748b;">لا توجد بيانات (جرب الاستيراد أولاً)</td></tr>';
                document.getElementById('suppliersCount').textContent = 0;
                document.getElementById('confirmedSuppliers').textContent = 0;
                return;
            }

            let confirmedCount = 0;

            suppliersData.forEach((supplier, index) => {
                const tr = document.createElement('tr');
                tr.style.borderBottom = '1px solid #e2e8f0';
                
                if (supplier.status === 'confirmed') confirmedCount++;

                const arName = supplier.supplier_name || '';
                const enName = supplier.supplier_name_en || '';
                const notes = supplier.notes || '';
                
                tr.innerHTML = `
                    <td style="padding: 12px; text-align: center;">
                        <input type="checkbox" class="supplier-checkbox" value="${supplier.id}" onchange="updateSelectedSuppliersCount()">
                    </td>
                    <td style="padding: 12px;">
                        <div style="font-weight: 500; color: #1e293b;">${arName}</div>
                        ${notes ? `<div style="font-size: 12px; color: #f59e0b; margin-top: 4px;">⚠️ ${notes}</div>` : ''}
                    </td>
                    <td style="padding: 12px; direction: ltr; text-align: left;">
                        <div style="font-weight: 500; color: #3b82f6;">${enName}</div>
                    </td>
                    <td style="padding: 12px; text-align: center;">
                        ${supplier.status === 'confirmed' 
                            ? '<span style="background: #dcfce7; color: #166534; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">مؤكد</span>' 
                            : '<span style="background: #f1f5f9; color: #64748b; padding: 4px 8px; border-radius: 4px; font-size: 12px;">جديد</span>'}
                        <div style="font-size: 11px; color: #94a3b8; margin-top: 4px;">تكرار: ${supplier.occurrence_count}</div>
                    </td>
                `;
                tbody.appendChild(tr);
            });
            
            document.getElementById('suppliersCount').textContent = suppliersData.length;
            document.getElementById('confirmedSuppliers').textContent = confirmedCount;
        }


        async function confirmSupplierGroup(ids) {
            for (const id of ids) {
                await updateStatus(id, 'confirmed', 'supplier');
            }
        }

        async function rejectSupplierGroup(ids) {
            for (const id of ids) {
                await updateStatus(id, 'rejected', 'supplier');
            }
        }

        function renderBanks() {
            const tbody = document.getElementById('banksList');
            
            if (banksData.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; padding: 40px; color: #64748b;">لا توجد بيانات</td></tr>';
                document.getElementById('banksCount').textContent = 0;
                document.getElementById('confirmedBanks').textContent = 0;
                return;
            }

            // Simple grouping by normalized_name from database
            const bankGroups = {};

            banksData.forEach(bank => {
                const groupKey = bank.normalized_name; // Use DB normalized_name directly
                
                if (!bankGroups[groupKey]) {
                    bankGroups[groupKey] = {
                        arabic: '',
                        english: '',
                        short: '',
                        department: '',
                        email: '',
                        address: '',
                        ids: [],
                        status: bank.status
                    };
                }
                
                const name = bank.bank_name;
                const isArabic = /[\u0600-\u06FF]/.test(name);
                const isShort = name.length <= 10 && /^[A-Z\s&]+$/.test(name);
                
                if (isArabic) {
                    bankGroups[groupKey].arabic = name;
                } else if (isShort) {
                    bankGroups[groupKey].short = name;
                } else {
                    bankGroups[groupKey].english = name;
                }
                
                // Extract additional info from bank_info if available
                if (bank.bank_info) {
                    try {
                        const info = JSON.parse(bank.bank_info);
                        if (info.department) bankGroups[groupKey].department = info.department;
                        if (info.email) bankGroups[groupKey].email = info.email;
                        if (info.address) bankGroups[groupKey].address = info.address;
                    } catch (e) {
                        // Ignore JSON parse errors
                    }
                }
                
                bankGroups[groupKey].ids.push(bank.id);
            });

            // Update counts based on GROUPS, not total rows
            const groupCount = Object.keys(bankGroups).length;
            const confirmedGroupsCount = Object.values(bankGroups).filter(g => g.status === 'confirmed').length;
            
            document.getElementById('banksCount').textContent = groupCount;
            document.getElementById('confirmedBanks').textContent = confirmedGroupsCount;

            tbody.innerHTML = Object.values(bankGroups).map((group, index) => {
                // Calculate total occurrences for this group
                const totalOccurrences = group.ids.reduce((sum, id) => {
                    const bank = banksData.find(b => b.id === id);
                    return sum + (bank ? parseInt(bank.occurrence_count) : 0);
                }, 0);
                
                const groupKey = `group_${index}`;
                
                return `
                    <tr style="border-bottom: 1px solid #e2e8f0;">
                        <td class="bank-select-checkbox" style="padding: 12px; text-align: center;">
                            <input type="checkbox" class="bank-group-checkbox" data-group-key="${groupKey}" onchange="toggleBankSelection(this, '${groupKey}')">
                        </td>
                        <td style="padding: 12px; font-size: 14px;">
                            ${group.arabic || '-'}
                            ${totalOccurrences > 0 ? `<div style="font-size: 11px; color: #10b981; margin-top: 2px;">🔢 ${totalOccurrences} تكرار</div>` : ''}
                        </td>
                        <td style="padding: 12px; font-size: 14px;">${group.english || '-'}</td>
                        <td style="padding: 12px; font-size: 14px;">${group.short || '-'}</td>
                        <td style="padding: 12px; font-size: 13px; color: #64748b;">${group.department || '-'}</td>
                        <td style="padding: 12px; font-size: 13px; color: #64748b;">${group.email || '-'}</td>
                        <td style="padding: 12px; font-size: 13px; color: #64748b;">${group.address || '-'}</td>
                        <td style="padding: 12px; text-align: center;">
                            ${group.status === 'confirmed' ?
                                '<span style="color: #10b981; font-weight: 600;">✓ مؤكد</span>' :
                                `<button onclick="confirmBankGroup([${group.ids.join(',')}])" class="btn btn-success btn-sm">✓ تأكيد</button>`
                            }
                            <button onclick="rejectBankGroup([${group.ids.join(',')}])" class="btn btn-danger btn-sm">✗ حذف</button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        async function confirmBankGroup(ids) {
            for (const id of ids) {
                await updateStatus(id, 'confirmed', 'bank');
            }
        }

        async function rejectBankGroup(ids) {
            for (const id of ids) {
                await updateStatus(id, 'rejected', 'bank');
            }
        }

        async function updateStatus(id, status, type) {
            try {
                const endpoint = type === 'supplier' ? 'update-supplier.php' : 'update-bank.php';
                
                const response = await fetch(`../api/${endpoint}`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({id, status})
                });

                const data = await response.json();

                if (data.success) {
                    if (type === 'supplier') {
                        suppliersData = suppliersData.filter(s => s.id !== id || status !== 'rejected');
                        if (status === 'confirmed') {
                            const supplier = suppliersData.find(s => s.id === id);
                            if (supplier) supplier.status = 'confirmed';
                        }
                        renderSuppliers();
                    } else {
                        banksData = banksData.filter(b => b.id !== id || status !== 'rejected');
                        if (status === 'confirmed') {
                            const bank = banksData.find(b => b.id === id);
                            if (bank) bank.status = 'confirmed';
                        }
                        renderBanks();
                    }
                    
                    showToast('تم التحديث', 'success');
                } else {
                    showToast(data.error, 'error');
                }
            } catch (error) {
                showToast('فشل التحديث', 'error');
            }
        }

        async function confirmAll(type) {
            const items = type === 'suppliers' ? suppliersData : banksData;
            
            for (const item of items) {
                if (item.status !== 'confirmed') {
                    await updateStatus(item.id, 'confirmed', type === 'suppliers' ? 'supplier' : 'bank');
                }
            }
        }

        async function migrate() {
            // Count confirmed items
            const confirmedSuppliers = suppliersData.filter(s => s.status === 'confirmed').length;
            const confirmedBanks = banksData.filter(b => b.status === 'confirmed').length;
            
            if (confirmedSuppliers === 0 && confirmedBanks === 0) {
                showToast('لا توجد بيانات مؤكدة للنقل', 'error');
                return;
            }
            
            // First confirmation - detailed preview
            const previewMsg = `
⚠️ أنت على وشك نقل البيانات إلى قاعدة البيانات الأساسية

سيتم نقل:
• ${confirmedSuppliers} مورد
• ${confirmedBanks} بنك

هل أنت متأكد؟ هذه الخطوة الأولى من التأكيد.`;
            
            if (!confirm(previewMsg)) {
                return;
            }
            
            // Second confirmation - final warning
            const finalMsg = `
⚠️⚠️ تأكيد نهائي ⚠️⚠️

سيتم إضافة البيانات المؤكدة إلى قاعدة البيانات الأساسية للبرنامج.

هل أنت متأكد 100% من جاهزية البيانات؟

الضغط على "موافق" سيبدأ عملية النقل الفعلية.`;
            
            if (!confirm(finalMsg)) {
                showToast('تم الإلغاء', 'error');
                return;
            }

            try {
                const response = await fetch('../api/migrate.php', {
                    method: 'POST'
                });

                const data = await response.json();

                if (data.success) {
                    window.location.href = `complete.php?suppliers=${data.data.suppliers.migrated}&banks=${data.data.banks.migrated}`;
                } else {
                    showToast(data.error, 'error');
                }
            } catch (error) {
                showToast('فشل النقل', 'error');
            }
        }

        async function quickImport() {
            // Create custom confirmation modal
            const confirmModal = document.createElement('div');
            confirmModal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.8);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 10000;
                animation: fadeIn 0.2s;
            `;
            
            confirmModal.innerHTML = `
                <div style="background: white; padding: 40px; border-radius: 16px; max-width: 500px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); animation: slideUp 0.3s;">
                    <div style="font-size: 48px; text-align: center; margin-bottom: 20px;">📁</div>
                    <h2 style="color: #1e40af; text-align: center; margin-bottom: 15px;">خيارات الاستيراد</h2>
                    <div style="background: #eff6ff; padding: 20px; border-radius: 8px; margin-bottom: 25px;">
                        <p style="margin: 0 0 10px; color: #1e40af; font-weight: 500;">سيتم معالجة جميع الملفات من:</p>
                        <code style="background: white; padding: 8px 12px; border-radius: 6px; display: block; margin-bottom: 15px;">setup/input/files/</code>
                    </div>
                    
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <button id="confirmYes" class="btn btn-primary" style="font-size: 16px; padding: 15px; display: flex; align-items: center; justify-content: center; gap: 10px;">
                            <span>⚡</span> استيراد (دمج مع الحالي)
                        </button>
                        
                        <button id="confirmReset" class="btn" style="background: #ef4444; color: white; font-size: 16px; padding: 15px; display: flex; align-items: center; justify-content: center; gap: 10px;">
                            <span>🗑️</span> تصفير البيانات ثم الاستيراد
                        </button>
                        
                        <button id="confirmNo" class="btn btn-secondary" style="margin-top: 10px;">
                            إلغاء
                        </button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(confirmModal);
            
            // Wait for user choice
            const userChoice = await new Promise((resolve) => {
                const modalContent = confirmModal.querySelector('div');
                const initialHTML = modalContent.innerHTML;

                document.getElementById('confirmYes').onclick = () => {
                    confirmModal.remove();
                    resolve('append');
                };

                document.getElementById('confirmReset').onclick = () => {
                    // Change modal content to warning
                    modalContent.innerHTML = `
                        <div style="font-size: 48px; text-align: center; margin-bottom: 20px;">⚠️</div>
                        <h2 style="color: #dc2626; text-align: center; margin-bottom: 15px;">هل أنت متأكد تماماً؟</h2>
                        <div style="background: #fef2f2; border: 1px solid #fee2e2; padding: 20px; border-radius: 8px; margin-bottom: 25px;">
                            <p style="margin: 0; color: #991b1b; text-align: center; font-weight: bold;">
                                سيتم مسح جميع البيانات الحالية نهائياً!<br>
                                لا يمكن التراجع عن هذه الخطوة.
                            </p>
                        </div>
                        
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <button id="finalDelete" class="btn" style="background: #dc2626; color: white; font-size: 16px; padding: 15px; width: 100%;">
                                نعم، احذف الكل وابدأ من جديد
                            </button>
                            
                            <button id="cancelDelete" class="btn btn-secondary" style="width: 100%;">
                                تراجع
                            </button>
                        </div>
                    `;

                    document.getElementById('finalDelete').onclick = () => {
                        confirmModal.remove();
                        resolve('reset');
                    };

                    document.getElementById('cancelDelete').onclick = () => {
                        // Restore initial view
                        modalContent.innerHTML = initialHTML;
                        // Re-attach initial event listeners
                        attachInitialListeners(resolve);
                    };
                };

                document.getElementById('confirmNo').onclick = () => {
                    confirmModal.remove();
                    resolve(null);
                };

                // Helper to re-attach listeners after going back
                function attachInitialListeners(res) {
                    document.getElementById('confirmYes').onclick = () => { confirmModal.remove(); res('append'); };
                    document.getElementById('confirmReset').onclick = document.getElementById('confirmReset').onclick; // Keep reference? No, need to re-assign logic
                    // Actually simpler to just recurse logic but let's stick to simple re-binding:
                    document.getElementById('confirmReset').onclick = () => {
                         // Copy-paste logic or make this cleaner?
                         // Let's restart the whole promise/modal logic if they go back? 
                         // No, just reload page is cleaner or simply close?
                         // Let's just re-run the 'confirmReset' logic from above.
                         document.getElementById('confirmReset').click(); // Recursive trick if re-bound? No.
                         // For simplicity in this quick edit, let's just Close if they cancel the delete warning, or properly restore.
                         // Proper restore:
                         modalContent.innerHTML = initialHTML;
                         // Since we are inside the promise, we need to bind strictly to the 'res' function
                         document.getElementById('confirmYes').onclick = () => { confirmModal.remove(); res('append'); };
                         document.getElementById('confirmReset').onclick = () => {
                             // Re-show warning (duplicate code :( ) - Better to just reload modal content
                             // Let's make it simpler: Cancel Delete -> Just close modal or Go back?
                             // User expects Go Back.
                             // To avoid code duplication in this tool call, I will define the warning logic as a function inside the promise.
                             showWarning(res);
                         };
                         document.getElementById('confirmNo').onclick = () => { confirmModal.remove(); res(null); };
                    }
                }

                function showWarning(res) {
                     modalContent.innerHTML = `
                        <div style="font-size: 48px; text-align: center; margin-bottom: 20px;">⚠️</div>
                        <h2 style="color: #dc2626; text-align: center; margin-bottom: 15px;">حذف جميع البيانات؟</h2>
                        <div style="background: #fef2f2; border: 1px solid #fee2e2; padding: 20px; border-radius: 8px; margin-bottom: 25px;">
                            <p style="margin: 0; color: #991b1b; text-align: center; font-weight: bold;">
                                سيتم مسح قاعدة البيانات المؤقتة بالكامل.<br>
                                هل أنت متأكد أنك تريد البدء من الصفر؟
                            </p>
                        </div>
                        <div style="display: flex; gap: 10px;">
                             <button id="finalDelete" class="btn" style="background: #dc2626; color: white; flex: 1; padding: 12px;">نعم، احذف</button>
                             <button id="cancelDelete" class="btn btn-secondary" style="flex: 1; padding: 12px;">تراجع</button>
                        </div>
                    `;
                    document.getElementById('finalDelete').onclick = () => { confirmModal.remove(); res('reset'); };
                    document.getElementById('cancelDelete').onclick = () => { 
                        modalContent.innerHTML = initialHTML;
                        bindMainButtons(res);
                    };
                }

                function bindMainButtons(res) {
                    document.getElementById('confirmYes').onclick = () => { confirmModal.remove(); res('append'); };
                    document.getElementById('confirmReset').onclick = () => showWarning(res);
                    document.getElementById('confirmNo').onclick = () => { confirmModal.remove(); res(null); };
                }

                bindMainButtons(resolve);
            });
            
            if (!userChoice) {
                return;
            }

            // Create loading modal...
            const modal = document.createElement('div');
            modal.id = 'importModal';
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.8);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 10000;
            `;
            
            modal.innerHTML = `
                <div style="background: white; padding: 50px; border-radius: 16px; text-align: center; max-width: 400px; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
                    <div style="width: 80px; height: 80px; border: 8px solid #e2e8f0; border-top-color: #3b82f6; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 25px;"></div>
                    <h3 style="color: #1e40af; margin-bottom: 10px; font-size: 22px;">⚡ جاري الاستيراد...</h3>
                    <p style="color: #64748b; margin-bottom: 0; font-size: 16px;">يرجى الانتظار، جاري معالجة الملفات</p>
                </div>
            `;
            
            document.body.appendChild(modal);

            try {
                const url = userChoice === 'reset' 
                    ? '../api/process-all.php?reset=true' 
                    : '../api/process-all.php';
                    
                const response = await fetch(url, {
                    method: 'POST'
                });

                const data = await response.json();

                // Remove loading modal
                modal.remove();

                if (data.success) {
                    // Success modal
                    const successModal = document.createElement('div');
                    successModal.style.cssText = `
                        position: fixed;
                        top: 0;
                        left: 0;
                        right: 0;
                        bottom: 0;
                        background: rgba(0, 0, 0, 0.8);
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        z-index: 10000;
                        animation: fadeIn 0.2s;
                    `;
                    successModal.innerHTML = `
                        <div style="background: white; padding: 50px; border-radius: 16px; text-align: center; max-width: 550px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); animation: slideUp 0.3s;">
                            <div style="font-size: 80px; margin-bottom: 25px;">✅</div>
                            <h3 style="color: #16a34a; margin-bottom: 20px; font-size: 26px;">تم الاستيراد بنجاح!</h3>
                            <div style="background: #f0fdf4; padding: 25px; border-radius: 12px; margin-bottom: 25px;">
                                <div style="font-size: 48px; font-weight: bold; color: #16a34a; margin-bottom: 10px;">${data.data.processed}</div>
                                <div style="color: #64748b; font-size: 16px; margin-bottom: 20px;">ملف تمت معالجته</div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; text-align: center;">
                                    <div style="background: white; padding: 15px; border-radius: 8px;">
                                        <div style="font-size: 32px; font-weight: bold; color: #3b82f6;">${data.data.suppliers}</div>
                                        <div style="color: #64748b; font-size: 14px;">موردين</div>
                                    </div>
                                    <div style="background: white; padding: 15px; border-radius: 8px;">
                                        <div style="font-size: 32px; font-weight: bold; color: #3b82f6;">${data.data.banks}</div>
                                        <div style="color: #64748b; font-size: 14px;">بنوك</div>
                                    </div>
                                </div>
                            </div>
                            <button onclick="this.parentElement.parentElement.remove(); loadSuppliers(); loadBanks();" class="btn btn-primary" style="font-size: 18px; padding: 15px 40px;">
                                ✓ تحديث القوائم
                            </button>
                        </div>
                    `;
                    document.body.appendChild(successModal);
                    
                    showToast(`✅ تم استيراد ${data.data.processed} ملف بنجاح!`, 'success');
                } else {
                    showToast(`❌ فشل الاستيراد: ${data.error}`, 'error');
                }
            } catch (error) {
                modal.remove();
                showToast('❌ خطأ في الاتصال', 'error');
            }
        }

        function showToast(message, type) {
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.textContent = message;
            document.body.appendChild(toast);

            setTimeout(() => toast.remove(), 3000);
        }
    </script>

    <style>
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    </style>
</body>
</html>
