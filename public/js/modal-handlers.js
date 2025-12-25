/**
 * Modal Handlers for Input Actions
 * Handles: Manual Entry, Smart Paste, Import Excel
 */

// دالة لفتح modal الإدخال اليدوي
function showManualInput() {
    const modal = document.getElementById('manualEntryModal');
    if (modal) {
        modal.style.display = 'flex';
        document.getElementById('manualSupplier')?.focus();
    }
}

// دالة لفتح modal اللصق الذكي
function showPasteModal() {
    const modal = document.getElementById('smartPasteModal');
    if (modal) {
        modal.style.display = 'flex';
        document.getElementById('smartPasteInput')?.focus();
    }
}

// دالة لفتح صفحة الاستيراد
function showImportModal() {
    window.location.href = 'views/import.php';
}

// دالة لإغلاق جميع الـ modals
function closeAllModals() {
    const modals = ['manualEntryModal', 'smartPasteModal'];
    modals.forEach(id => {
        const modal = document.getElementById(id);
        if (modal) modal.style.display = 'none';
    });
}

// دالة لمعالجة الإدخال اليدوي
async function handleManualEntry() {
    const supplier = document.getElementById('manualSupplier')?.value;
    const bank = document.getElementById('manualBank')?.value;
    const guarantee = document.getElementById('manualGuarantee')?.value;
    const contract = document.getElementById('manualContract')?.value;
    const amount = document.getElementById('manualAmount')?.value;

    if (!supplier || !bank || !guarantee || !contract || !amount) {
        alert('يرجى ملء جميع الحقول المطلوبة');
        return;
    }

    const payload = {
        supplier,
        bank,
        guarantee_number: guarantee,
        contract_number: contract,
        amount: parseFloat(amount),
        expiry_date: document.getElementById('manualExpiry')?.value,
        type: document.getElementById('manualType')?.value,
        issue_date: document.getElementById('manualIssue')?.value,
        comment: document.getElementById('manualComment')?.value
    };

    try {
        const response = await fetch('api/create-guarantee.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const data = await response.json();
        if (data.success) {
            alert('تم إضافة الضمان بنجاح');
            window.location.reload();
        } else {
            alert('خطأ: ' + (data.error || 'فشل الحفظ'));
        }
    } catch (error) {
        console.error('Error:', error);
        alert('حدث خطأ في الاتصال');
    }
}

// دالة لمعالجة البيانات الملصوقة
async function handlePasteData() {
    const text = document.getElementById('smartPasteInput')?.value;

    if (!text || !text.trim()) {
        alert('يرجى لصق النص أولاً');
        return;
    }

    try {
        const response = await fetch('api/parse-paste.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ text })
        });

        const data = await response.json();
        if (data.success) {
            alert(`تم استخراج البيانات بنجاح`);
            window.location.reload();
        } else {
            alert('خطأ: ' + (data.error || 'فشل تحليل النص'));
        }
    } catch (error) {
        console.error('Error:', error);
        alert('حدث خطأ في الاتصال');
    }
}

// إعداد الـ event listeners عند تحميل الصفحة
document.addEventListener('DOMContentLoaded', function () {
    // Manual Entry Modal handlers
    const btnCloseManual = document.getElementById('btnCloseManualEntry');
    const btnCancelManual = document.getElementById('btnCancelManualEntry');
    const btnSaveManual = document.getElementById('btnSaveManualEntry');

    if (btnCloseManual) {
        btnCloseManual.addEventListener('click', closeAllModals);
    }

    if (btnCancelManual) {
        btnCancelManual.addEventListener('click', closeAllModals);
    }

    if (btnSaveManual) {
        btnSaveManual.addEventListener('click', handleManualEntry);
    }

    // Paste Modal handlers
    const btnClosePaste = document.getElementById('btnClosePasteModal');
    const btnCancelPaste = document.getElementById('btnCancelPaste');
    const btnProcessPaste = document.getElementById('btnProcessPaste');

    if (btnClosePaste) {
        btnClosePaste.addEventListener('click', closeAllModals);
    }

    if (btnCancelPaste) {
        btnCancelPaste.addEventListener('click', closeAllModals);
    }

    if (btnProcessPaste) {
        btnProcessPaste.addEventListener('click', handlePasteData);
    }

    // Close modals on ESC key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeAllModals();
        }
    });

    // Add modal functions to unified controller
    if (window.unifiedController) {
        window.unifiedController.showManualInput = showManualInput;
        window.unifiedController.showPasteModal = showPasteModal;
        window.unifiedController.showImportModal = showImportModal;
    }

    // Handle file selection
    const fileInput = document.getElementById('hiddenFileInput');
    if (fileInput) {
        fileInput.addEventListener('change', async function (e) {
            const file = e.target.files[0];
            if (!file) return;

            // Show loading indicator
            const loadingMsg = document.createElement('div');
            loadingMsg.id = 'uploadProgress';
            loadingMsg.style.cssText = 'position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 24px 48px; border-radius: 12px; box-shadow: 0 25px 50px rgba(0,0,0,0.3); z-index: 10000; text-align: center;';
            loadingMsg.innerHTML = '<div style="font-size: 18px; font-weight: 700; color: #1f2937;">جاري تحميل الملف...</div><div style="margin-top: 12px; font-size: 14px; color: #6b7280;">' + file.name + '</div>';
            document.body.appendChild(loadingMsg);

            // Create FormData
            const formData = new FormData();
            formData.append('excel_file', file);

            try {
                const response = await fetch('api/import-excel.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                // Remove loading
                loadingMsg.remove();

                if (data.success) {
                    alert(`تم الاستيراد بنجاح!\n${data.imported || 0} سجل تم إضافته.`);
                    window.location.reload();
                } else {
                    alert('خطأ: ' + (data.error || 'فشل الاستيراد'));
                }
            } catch (error) {
                loadingMsg.remove();
                console.error('Error:', error);
                alert('حدث خطأ في الاتصال');
            }

            // Reset input for next time
            e.target.value = '';
        });
    }
});
