/**
 * Unified Workflow Controller - Vanilla JavaScript
 * No dependencies on Alpine.js or any external libraries
 * Pure DOM manipulation and event handling
 */

class UnifiedController {
    constructor() {
        this.init();
    }

    init() {
        console.log('Unified Controller initialized (Vanilla JS - No Alpine)');
        this.bindEvents();
        this.initializeState();
    }

    initializeState() {
        // Preview is hidden by default
        this.previewVisible = false;
        this.printDropdownVisible = false;
    }

    bindEvents() {
        // Delegate all clicks with data-action
        document.addEventListener('click', (e) => {
            const target = e.target.closest('[data-action]');
            if (!target) return;

            e.preventDefault();
            const action = target.dataset.action;

            // Call the corresponding method
            if (this[action]) {
                // Pass the target element in case we need data attributes
                this[action](target);
            } else {
                console.warn(`No handler for action: ${action}`);
            }
        });

        // Handle input changes with data-model
        document.addEventListener('input', (e) => {
            if (e.target.dataset.model) {
                this.handleInputChange(e.target);
            }
        });

        // Close dropdowns when clicking outside
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
    togglePreview() {
        this.previewVisible = !this.previewVisible;
        const previewEl = document.getElementById('preview-section');
        if (previewEl) {
            previewEl.style.display = this.previewVisible ? 'block' : 'none';
        }
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
        const recordIdEl = document.querySelector('[data-record-id]');
        if (!recordIdEl) {
            alert('لا يوجد سجل للطباعة');
            return;
        }

        const recordId = recordIdEl.dataset.recordId;
        const printType = target.dataset.printType || 'extension';

        const url = `views/print.php?id=${recordId}&action=${printType}`;
        window.open(url, '_blank', 'width=1000,height=1200');

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
            alert('خطأ: لا يوجد سجل');
            return;
        }

        const payload = {
            guarantee_id: recordIdEl.dataset.recordId,
            supplier_id: document.getElementById('supplierIdHidden')?.value || null,
            supplier_name: document.getElementById('supplierInput')?.value || '',
            bank_name: document.getElementById('bankNameInput')?.value || ''
        };

        try {
            const response = await fetch('/V3/api/save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const data = await response.json();

            if (data.success) {
                if (data.completed) {
                    alert(data.message || 'تم الانتهاء من جميع السجلات');
                    return;
                }

                // Reload page with next record
                if (data.record && data.record.id) {
                    window.location.href = `?id=${data.record.id}`;
                } else {
                    window.location.reload();
                }
            } else {
                alert('خطأ: ' + (data.error || 'فشل الحفظ'));
            }
        } catch (error) {
            console.error('Save error:', error);
            alert('فشل الحفظ');
        }
    }

    // Actions
    async extend() {
        if (!confirm('هل تريد تمديد هذا الضمان لمدة سنة؟')) return;

        const recordIdEl = document.querySelector('[data-record-id]');
        if (!recordIdEl) {
            alert('خطأ: لا يوجد سجل');
            return;
        }

        try {
            const response = await fetch('/V3/api/extend.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ guarantee_id: recordIdEl.dataset.recordId })
            });

            await response.json();
            window.location.reload();
        } catch (e) {
            console.error(e);
            alert('خطأ');
        }
    }

    async release() {
        if (!confirm('هل تريد الإفراج عن هذا الضمان؟')) return;

        const recordIdEl = document.querySelector('[data-record-id]');
        if (!recordIdEl) {
            alert('خطأ: لا يوجد سجل');
            return;
        }

        try {
            const response = await fetch('/V3/api/release.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ guarantee_id: recordIdEl.dataset.recordId })
            });

            await response.json();
            window.location.reload();
        } catch (e) {
            console.error(e);
            alert('خطأ');
        }
    }

    reduce() {
        alert('وظيفة التخفيض قيد التطوير');
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
            // Fallback to old behavior
            window.location.href = 'views/import.php';
        }
    }
}

// Initialize on DOM ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        window.unifiedController = new UnifiedController();
    });
} else {
    window.unifiedController = new UnifiedController();
}
