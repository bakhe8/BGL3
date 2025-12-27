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
    // Trigger hidden file input
    const fileInput = document.getElementById('hiddenFileInput');
    if (fileInput) {
        fileInput.click();
    } else {
        // Fallback: Show error instead of redirecting to non-existent page
        console.error('File input element #hiddenFileInput not found');
        if (typeof showToast === 'function') {
            showToast('عفواً، خاصية الاستيراد غير متاحة حالياً', 'error');
        } else {
            alert('عفواً، خاصية الاستيراد غير متاحة حالياً');
        }
    }
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
async function submitManualEntry() {
    const supplier = document.getElementById('manualSupplier')?.value;
    const bank = document.getElementById('manualBank')?.value;
    const guarantee = document.getElementById('manualGuarantee')?.value;
    const contract = document.getElementById('manualContract')?.value;
    const amount = document.getElementById('manualAmount')?.value;

    if (!supplier || !bank || !guarantee || !contract || !amount) {
        showToast('يرجى ملء جميع الحقول المطلوبة', 'error');
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
        comment: document.getElementById('manualComment')?.value,
        related_to: document.querySelector('input[name="relatedTo"]:checked')?.value || 'contract' // 🔥 NEW
    };

    try {
        const response = await fetch('/api/create-guarantee.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const data = await response.json();
        if (data.success) {
            showToast('تم إضافة الضمان بنجاح', 'success');
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showToast('خطأ: ' + (data.error || 'فشل الحفظ'), 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('حدث خطأ في الاتصال', 'error');
    }
}

// دالة لمعالجة البيانات الملصوقة
async function parsePasteData() {
    const text = document.getElementById('smartPasteInput')?.value;

    if (!text || !text.trim()) {
        showToast('يرجى لصق النص أولاً', 'error');
        return;
    }

    try {
        const response = await fetch('/api/parse-paste.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ text })
        });

        const data = await response.json();
        if (data.success) {
            showToast('تم استخراج البيانات بنجاح', 'success');
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showToast('خطأ: ' + (data.error || 'فشل تحليل النص'), 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('حدث خطأ في الاتصال', 'error');
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
        btnSaveManual.addEventListener('click', submitManualEntry);
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
        btnProcessPaste.addEventListener('click', parsePasteData);
    }

    // Close modals on ESC key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeAllModals();
        }
    });

    // Add modal functions to records controller
    if (window.recordsController) {
        window.recordsController.showManualInput = showManualInput;
        window.recordsController.showPasteModal = showPasteModal;
        window.recordsController.showImportModal = showImportModal;
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
            formData.append('file', file);

            try {
                const response = await fetch('/api/import.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                // Remove loading
                loadingMsg.remove();

                if (data.success) {
                    const importedCount = data.data?.imported || data.imported || 0;
                    showToast(`تم الاستيراد بنجاح!\n${importedCount} سجل تم إضافته.`, 'success');
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showToast('خطأ: ' + (data.error || 'فشل الاستيراد'), 'error');
                }
            } catch (error) {
                loadingMsg.remove();
                console.error('Error:', error);
                showToast('حدث خطأ في الاتصال', 'error');
            }

            // Reset input for next time
            e.target.value = '';
        });
    }
});
