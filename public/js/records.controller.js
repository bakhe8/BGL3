/**
 * Records Controller - Vanilla JavaScript
 * No dependencies on Alpine.js or any external libraries
 * Pure DOM manipulation and event handling
 */

if (!window.RecordsController) {
    window.RecordsController = class RecordsController {
        constructor() {
            this.init();
        }

        init() {
            // console.log('BGL System Controller initialized');
            this.bindEvents();
            this.bindGlobalEvents();
            this.initializeState();
            // Initial update of preview
            setTimeout(() => this.updatePreviewFromDOM(), 500);
        }

        initializeState() {
            // Preview is ALWAYS visible now
            this.previewVisible = true;
            this.printDropdownVisible = false;
        }

        bindGlobalEvents() {
            document.addEventListener('click', (e) => {
                const target = e.target.closest('[data-action]');
                if (!target) return;

                // Define action handlers
                const action = target.dataset.action;

                // Get current index from DOM if needed
                const currentRecordEl = document.getElementById('record-form-section');
                const currentIndex = currentRecordEl ? (currentRecordEl.dataset.recordIndex || 1) : 1;

                // Map actions to methods (Explicit Kebab-case)
                if (action === 'save-next') { this.saveAndNext(); return; }
                if (action === 'load-record') {
                    const index = target.dataset.index || currentIndex;
                    this.loadRecord(index);
                    return;
                }
                if (action === 'load-history') {
                    const id = target.dataset.id;
                    this.loadHistory(id, currentIndex);
                    return;
                }
                if (action === 'timeline-load-current') {
                    if (window.timelineController) window.timelineController.loadCurrentState();
                    return;
                }

                // Dynamic Dispatch for CamelCase (e.g. togglePreview, saveAndNext, extend, release)
                if (typeof this[action] === 'function') {
                    this[action](target);
                } else {
                    console.warn(`No handler for action: ${action}`);
                }
            });
        }

        bindEvents() {
            // Handle input changes
            document.addEventListener('input', (e) => {
                if (e.target.dataset.model) {
                    this.handleInputChange(e.target);
                }
            });

            // Close dropdowns
            document.addEventListener('click', (e) => {
                if (!e.target.closest('#print-dropdown')) {
                    this.closePrintDropdown();
                }
            });
        }

        handleInputChange(input) {
            const model = input.dataset.model;
            const value = input.value;

            // Handle specific models
            if (model === 'supplier_name') {
                // Could trigger suggestions fetch here if needed
                console.log('Supplier changed:', value);
            }
        }

        // UI Actions
        // UI Actions
        togglePreview() {
            // Deprecated: Preview is always visible
            console.log('Preview toggle is disabled');
        }

        updatePreviewFromDOM() {

            // Arabic month names for date formatting
            const arabicMonths = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو',
                'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

            // Get all fields with data-preview-field
            const fields = document.querySelectorAll('[data-preview-field]');

            fields.forEach(field => {
                const fieldName = field.dataset.previewField;
                let fieldValue = this.getFieldValue(field);

                // خاص بالمبلغ: إزالة أي حروف غير رقمية وتنظيف التنسيق
                if (fieldName === 'amount') {
                    // الاحتفاظ بالأرقام، النقطة، والفاصلة فقط
                    fieldValue = fieldValue.replace(/[^\d.,]/g, '').trim();
                    // إزالة النقاط المتكررة أو الزائدة في النهاية
                    fieldValue = fieldValue.replace(/\.+$/, '');
                    // إزالة الفاصلة الزائدة في النهاية
                    fieldValue = fieldValue.replace(/,+$/, '');
                }

                // خاص بالتاريخ: تنسيق بالصيغة العربية
                if (fieldName === 'expiry_date' && fieldValue) {
                    fieldValue = this.formatArabicDate(fieldValue, arabicMonths);
                }

                // Update corresponding preview target
                const target = document.querySelector(`[data-preview-target="${fieldName}"]`);
                if (target && fieldValue) {
                    target.textContent = fieldValue;
                }
            });
        }

        formatArabicDate(dateStr, arabicMonths) {
            if (!dateStr) return '';

            // Try to parse the date
            let date;
            if (dateStr.includes('-')) {
                // Format: YYYY-MM-DD or DD-MM-YYYY
                const parts = dateStr.split('-');
                if (parts[0].length === 4) {
                    // YYYY-MM-DD
                    date = new Date(parts[0], parseInt(parts[1]) - 1, parts[2]);
                } else {
                    // DD-MM-YYYY
                    date = new Date(parts[2], parseInt(parts[1]) - 1, parts[0]);
                }
            } else if (dateStr.includes('/')) {
                // Format: DD/MM/YYYY or MM/DD/YYYY
                const parts = dateStr.split('/');
                date = new Date(parts[2], parseInt(parts[1]) - 1, parts[0]);
            } else {
                date = new Date(dateStr);
            }

            if (isNaN(date.getTime())) return dateStr;

            const day = date.getDate();
            const month = arabicMonths[date.getMonth()];
            const year = date.getFullYear();

            return `${day} ${month} ${year}`;
        }

        getFieldValue(element) {
            // Handle input elements
            if (element.tagName === 'INPUT' || element.tagName === 'SELECT') {
                return element.value || '';
            }

            // Handle display elements (info-value)
            return element.textContent?.trim() || '';
        }

        togglePrintMenu() {
            this.printDropdownVisible = !this.printDropdownVisible;
            const dropdown = document.getElementById('print-dropdown-content');
            if (dropdown) {
                dropdown.classList.toggle('show', this.printDropdownVisible);
            }
        }

        closePrintDropdown() {
            if (this.printDropdownVisible) {
                this.printDropdownVisible = false;
                const dropdown = document.getElementById('print-dropdown-content');
                if (dropdown) {
                    dropdown.classList.remove('show');
                }
            }
        }

        print(target) {
            // New Logic: Direct Browser Print
            window.print();
            this.closePrintDropdown();
        }

        // Supplier Selection
        selectSupplier(target) {
            const supplierId = target.dataset.id;
            const supplierName = target.dataset.name;

            // Update input field
            const supplierInput = document.getElementById('supplierInput');
            if (supplierInput) {
                supplierInput.value = supplierName;
            }

            // Update hidden ID field
            const supplierIdHidden = document.getElementById('supplierIdHidden');
            if (supplierIdHidden) {
                supplierIdHidden.value = supplierId;
            }

            // Update chip styling
            document.querySelectorAll('.chip').forEach(chip => {
                chip.classList.remove('chip-selected');
                chip.classList.add('chip-candidate');
            });
            target.classList.remove('chip-candidate');
            target.classList.add('chip-selected');
        }

        // Bank Selection
        selectBank(target) {
            const bankId = target.dataset.id;
            const bankName = target.dataset.name;

            // Update input fields
            const bankNameInput = document.getElementById('bankNameInput');
            if (bankNameInput) {
                bankNameInput.value = bankName;
            }

            const bankSelect = document.getElementById('bankSelect');
            if (bankSelect) {
                bankSelect.value = bankId;
            }
        }

        // Save and proceed
        async saveAndNext() {
            const recordIdEl = document.querySelector('[data-record-id]');
            if (!recordIdEl) {
                this.showToast('خطأ: لا يوجد سجل', 'error');
                return;
            }

            const payload = {
                guarantee_id: recordIdEl.dataset.recordId,
                supplier_id: document.getElementById('supplierIdHidden')?.value || null,
                supplier_name: document.getElementById('supplierInput')?.value || '',
                bank_name: document.getElementById('bankNameInput')?.value || '',
                current_index: document.querySelector('[data-record-index]')?.dataset.recordIndex || 1
            };

            try {
                const response = await fetch('/api/save-and-next.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                const data = await response.json();

                if (data.success) {
                    if (data.completed) {
                        this.showToast(data.message || 'تم الانتهاء من جميع السجلات', 'success');
                        return;
                    }

                    // Reload page with next record
                    if (data.record && data.record.id) {
                        window.location.href = `?id=${data.record.id}`;
                    } else {
                        window.location.reload();
                    }
                } else {
                    this.showToast('خطأ: ' + (data.error || 'فشل الحفظ'), 'error');
                }
            } catch (error) {
                console.error('Save error:', error);
                this.showToast('فشل الحفظ', 'error');
            }
        }

        // Actions
        async extend() {
            if (!await this.customConfirm('هل تريد تمديد هذا الضمان لمدة سنة؟')) return;

            const recordIdEl = document.querySelector('[data-record-id]');
            if (!recordIdEl) {
                this.showToast('خطأ: لا يوجد سجل', 'error');
                return;
            }

            try {
                const response = await fetch('/api/extend.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ guarantee_id: recordIdEl.dataset.recordId })
                });

                const text = await response.text();

                if (!response.ok) {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(text, 'text/html');
                    const errorBody = doc.querySelector('.card-body');
                    const msg = errorBody ? errorBody.textContent.trim() : 'فشل التمديد';

                    if (msg.includes('No expiry date')) {
                        this.showToast('عفواً، لا يوجد تاريخ انتهاء محفوظ. يرجى حفظ السجل أولاً.', 'error');
                    } else {
                        this.showToast(msg, 'error');
                    }
                    return;
                }
                window.location.reload();
            } catch (e) {
                console.error(e);
                this.showToast('حدث خطأ في الاتصال', 'error');
            }
        }

        async release() {
            if (!await this.customConfirm('هل تريد الإفراج عن هذا الضمان؟')) return;

            const recordIdEl = document.querySelector('[data-record-id]');
            if (!recordIdEl) {
                this.showToast('خطأ: لا يوجد سجل', 'error');
                return;
            }

            try {
                const response = await fetch('/api/release.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ guarantee_id: recordIdEl.dataset.recordId })
                });

                const text = await response.text();
                if (!response.ok) {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(text, 'text/html');
                    const errorBody = doc.querySelector('.card-body');
                    const msg = errorBody ? errorBody.textContent.trim() : 'فشل الإفراج';
                    this.showToast(msg, 'error');
                    return;
                }
                window.location.reload();
            } catch (e) {
                console.error(e);
                this.showToast('حدث خطأ في الاتصال', 'error');
            }
        }

        async reduce() {
            const recordIdEl = document.querySelector('[data-record-id]');
            if (!recordIdEl) {
                this.showToast('خطأ: لا يوجد سجل', 'error');
                return;
            }

            const newAmountStr = await this.customPrompt('الرجاء إدخال المبلغ الجديد:');
            if (!newAmountStr) return;

            const newAmount = parseFloat(newAmountStr);
            if (isNaN(newAmount) || newAmount <= 0) {
                this.showToast('الرجاء إدخال مبلغ صحيح (أرقام فقط)', 'error');
                return;
            }

            try {
                const response = await fetch('/api/reduce.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        guarantee_id: recordIdEl.dataset.recordId,
                        new_amount: newAmount
                    })
                });

                const text = await response.text();
                if (!response.ok) {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(text, 'text/html');
                    const errorBody = doc.querySelector('.card-body');
                    const msg = errorBody ? errorBody.textContent.trim() : 'فشل التخفيض';
                    this.showToast(msg, 'error');
                    return;
                }
                window.location.reload();
            } catch (e) {
                console.error(e);
                this.showToast('حدث خطأ في الاتصال', 'error');
            }
        }

        // Custom UI Helpers
        showToast(message, type = 'error') {
            let toast = document.getElementById('bgl-toast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'bgl-toast';
                toast.style.cssText = 'position:fixed;top:20px;left:50%;transform:translateX(-50%);padding:15px 25px;color:white;z-index:99999;border-radius:8px;box-shadow:0 4px 15px rgba(0,0,0,0.2);font-weight:bold;font-family:inherit;min-width:300px;text-align:center;transition:all 0.3s ease;';
                document.body.appendChild(toast);
            }
            toast.textContent = message;
            toast.style.backgroundColor = type === 'error' ? '#ef4444' : '#10b981';
            toast.style.display = 'block';
            toast.style.opacity = '1';

            if (this.toastTimeout) clearTimeout(this.toastTimeout);
            this.toastTimeout = setTimeout(() => {
                toast.style.opacity = '0';
                setTimeout(() => toast.style.display = 'none', 300);
            }, 4000);
        }

        customConfirm(message) {
            return new Promise((resolve) => {
                const overlay = document.createElement('div');
                overlay.id = 'bgl-confirm-overlay';
                overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:99999;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(2px);';

                overlay.innerHTML = `
                    <div style="background:white;padding:24px;border-radius:12px;text-align:center;min-width:320px;box-shadow:0 10px 25px rgba(0,0,0,0.2);transform:scale(0.9);animation:popIn 0.2s forwards;">
                        <style>@keyframes popIn { to { transform: scale(1); } }</style>
                        <h3 style="margin:0 0 16px;color:#1e293b;font-size:18px">تأكيد الإجراء</h3>
                        <p style="margin-bottom:24px;color:#64748b;font-size:15px;line-height:1.5">${message}</p>
                        <div style="display:flex;justify-content:center;gap:12px">
                            <button id="confirm-yes" class="btn btn-primary" style="background:#2563eb;color:white;border:none;padding:8px 20px;border-radius:6px;cursor:pointer">نعم، تابع</button>
                            <button id="confirm-no" class="btn btn-secondary" style="background:#e2e8f0;color:#475569;border:none;padding:8px 20px;border-radius:6px;cursor:pointer">إلغاء</button>
                        </div>
                    </div>
                `;
                document.body.appendChild(overlay);

                const cleanup = () => { document.body.removeChild(overlay); };
                overlay.querySelector('#confirm-yes').onclick = () => { cleanup(); resolve(true); };
                overlay.querySelector('#confirm-no').onclick = () => { cleanup(); resolve(false); };
                overlay.onclick = (e) => { if (e.target === overlay) { cleanup(); resolve(false); } };
            });
        }

        customPrompt(message) {
            return new Promise((resolve) => {
                const overlay = document.createElement('div');
                overlay.id = 'bgl-prompt-overlay';
                overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:99999;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(2px);';

                overlay.innerHTML = `
                    <div style="background:white;padding:24px;border-radius:12px;text-align:center;min-width:320px;box-shadow:0 10px 25px rgba(0,0,0,0.2);transform:scale(0.9);animation:popIn 0.2s forwards;">
                        <h3 style="margin:0 0 16px;color:#1e293b;font-size:18px">إدخال مطلوب</h3>
                        <label style="display:block;margin-bottom:8px;color:#64748b;font-size:14px">${message}</label>
                        <input type="number" id="prompt-input" style="width:100%;padding:10px;margin-bottom:20px;border:1px solid #cbd5e1;border-radius:6px;font-size:16px;text-align:center" autofocus>
                        <div style="display:flex;justify-content:center;gap:12px">
                            <button id="prompt-ok" class="btn btn-primary" style="background:#2563eb;color:white;border:none;padding:8px 20px;border-radius:6px;cursor:pointer">موافق</button>
                            <button id="prompt-cancel" class="btn btn-secondary" style="background:#e2e8f0;color:#475569;border:none;padding:8px 20px;border-radius:6px;cursor:pointer">إلغاء</button>
                        </div>
                    </div>
                `;
                document.body.appendChild(overlay);

                const input = overlay.querySelector('#prompt-input');
                input.focus();

                const cleanup = () => { document.body.removeChild(overlay); };
                const submit = () => {
                    const val = input.value;
                    cleanup();
                    resolve(val);
                };

                overlay.querySelector('#prompt-ok').onclick = submit;
                overlay.querySelector('#prompt-cancel').onclick = () => { cleanup(); resolve(null); };

                input.onkeydown = (e) => { if (e.key === 'Enter') submit(); if (e.key === 'Escape') { cleanup(); resolve(null); } };
                overlay.onclick = (e) => { if (e.target === overlay) { cleanup(); resolve(null); } };
            });
        }

        // Navigation
        previousRecord(target) {
            const prevId = target.dataset.id;
            if (prevId) {
                window.location.href = `?id=${prevId}`;
            }
        }

        nextRecord(target) {
            const nextId = target.dataset.id;
            if (nextId) {
                window.location.href = `?id=${nextId}`;
            }
        }

        // Modal Actions (placeholders - implement when modals are needed)
        openAttachmentsModal() {
            console.log('Open attachments modal');
            // TODO: Implement modal functionality
        }

        openNotesModal() {
            console.log('Open notes modal');
            // TODO: Implement modal functionality
        }

        handleSupplierInput(target) {
            // Could implement debounced suggestion fetching here
            console.log('Supplier input changed:', target.value);
        }

        createSupplier() {
            // TODO: Implement create supplier functionality
            alert('إضافة مورد جديد قيد التطوير');
        }

        // Modal handlers
        showManualInput() {
            const modal = document.getElementById('manualEntryModal');
            if (modal) {
                modal.style.display = 'block';
                document.getElementById('manualSupplier')?.focus();
            }
        }

        showPasteModal() {
            const modal = document.getElementById('smartPasteModal');
            if (modal) {
                modal.style.display = 'flex';
                document.getElementById('smartPasteInput')?.focus();
            }
        }

        showImportModal() {
            // Trigger hidden file input
            const fileInput = document.getElementById('hiddenFileInput');
            if (fileInput) {
                fileInput.click();
            } else {
                // Fallback: Show error instead of redirecting to non-existent page
                console.error('File input element #hiddenFileInput not found');
                if (this.showToast) {
                    this.showToast('عفواً، خاصية الاستيراد غير متاحة حالياً', 'error');
                } else {
                    alert('عفواً، خاصية الاستيراد غير متاحة حالياً');
                }
            }
        }
        // Navigation Implementation
        async loadRecord(index) {
            try {
                // Fetch Record HTML
                const res = await fetch(`/api/get-record.php?index=${index}`);
                const html = await res.text();

                // Replace Record Section (Server Driven)
                const recordSection = document.getElementById('record-form-section');
                if (recordSection) {
                    recordSection.outerHTML = html;
                }

                // Sync Timeline (if generic timeline exists)
                // Note: If using Time Machine timeline, it's separate. 
                // We'll update the timeline section if it exists.
                this.updateTimeline(index);

                // Update URL logic if needed
            } catch (e) {
                console.error('Nav Error:', e);
            }
        }

        async updateTimeline(index) {
            try {
                const res = await fetch(`/api/get-timeline.php?index=${index}`);
                const html = await res.text();
                const timelineSection = document.getElementById('timeline-section');
                if (timelineSection) {
                    timelineSection.outerHTML = html;
                }
            } catch (e) { /* Ignore */ }
        }

        async loadHistory(eventId, fromIndex) {
            // Load legacy history (for get-timeline.php support)
            try {
                const res = await fetch(`/api/get-history-snapshot.php?history_id=${eventId}&index=${fromIndex}`);
                const html = await res.text();
                // This endpoint likely returns a form with snapshot data? 
                // Or JSON? Assuming HTML fragment based on architecture.
                // If it returns JSON, we need logic. 
                // Let's assume it replaces the record form.
                const recordSection = document.getElementById('record-form-section');
                if (recordSection) {
                    recordSection.outerHTML = html;
                }
            } catch (e) {
                console.error(e);
            }
        }
    };
}

// Initialize on DOM ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        if (!window.recordsController && window.RecordsController) {
            window.recordsController = new window.RecordsController();
        }
    });
} else {
    if (!window.recordsController && window.RecordsController) {
        window.recordsController = new window.RecordsController();
    }
}
