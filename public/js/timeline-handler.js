/**
 * Timeline Handler - Time Machine Functionality
 * Handles click interactions on timeline events
 * Shows historical state of guarantee at any point in time
 */

class TimelineMachine {
    constructor() {
        this.currentEventId = null;
        this.isHistoricalView = false;
        this.originalState = null;
        this.init();
    }

    init() {
        // Use event delegation for reliability
        // This works even if timeline cards are added dynamically
        document.addEventListener('click', (e) => {
            const eventWrapper = e.target.closest('.timeline-event-wrapper');
            if (eventWrapper) {
                this.handleTimelineClick(eventWrapper);
            }
        });

        console.log('✅ Timeline Machine initialized');
    }

    handleTimelineClick(element) {
        const eventId = element.dataset.eventId;
        const snapshotData = element.dataset.snapshot;
        const isLatest = element.dataset.isLatest === '1';

        try {
            const snapshot = JSON.parse(snapshotData);

            // Remove active class from all cards
            document.querySelectorAll('.timeline-event-wrapper').forEach(card => {
                card.querySelector('.timeline-event-card')?.classList.remove('active-event');
            });

            // Add active class to clicked card
            element.querySelector('.timeline-event-card')?.classList.add('active-event');

            if (isLatest) {
                // Latest event - show current state
                this.loadCurrentState();
            } else {
                // Historical event - show snapshot (state BEFORE this event)
                this.displayHistoricalState(snapshot, eventId);
            }
        } catch (error) {
            console.error('Error handling timeline click:', error);
            this.showError('حدث خطأ في عرض الحالة التاريخية');
        }
    }

    displayHistoricalState(snapshot, eventId) {
        console.log('📜 Displaying historical state:', snapshot);

        // Save current state if first time entering historical view
        if (!this.isHistoricalView) {
            this.saveCurrentState();
        }

        this.isHistoricalView = true;
        this.currentEventId = eventId;

        // Parse snapshot if it's a string
        let snapshotData = snapshot;
        if (typeof snapshot === 'string') {
            try {
                snapshotData = JSON.parse(snapshot);
            } catch (e) {
                console.error('Failed to parse snapshot:', e);
                return;
            }
        }

        // Update form fields with snapshot data
        this.updateFormFields(snapshotData);

        // Show historical banner
        this.showHistoricalBanner();

        // Disable editing
        this.disableEditing();
    }

    updateFormFields(snapshot) {
        console.log('🔄 Updating fields with snapshot:', snapshot);

        // Update supplier input (ID: supplierInput)
        const supplierInput = document.getElementById('supplierInput');
        if (supplierInput && snapshot.supplier_name) {
            supplierInput.value = snapshot.supplier_name;
            console.log('✓ Updated supplier:', snapshot.supplier_name);
        }

        // Update hidden supplier ID (ID: supplierIdHidden)
        const supplierIdHidden = document.getElementById('supplierIdHidden');
        if (supplierIdHidden && snapshot.supplier_id) {
            supplierIdHidden.value = snapshot.supplier_id;
            console.log('✓ Updated supplier ID:', snapshot.supplier_id);
        }

        // Update bank name input (ID: bankNameInput)
        const bankNameInput = document.getElementById('bankNameInput');
        if (bankNameInput && snapshot.bank_name) {
            bankNameInput.value = snapshot.bank_name;
            console.log('✓ Updated bank name:', snapshot.bank_name);
        }

        // Update hidden bank ID (ID: bankSelect)
        const bankSelect = document.getElementById('bankSelect');
        if (bankSelect && snapshot.bank_id) {
            bankSelect.value = snapshot.bank_id;
            console.log('✓ Updated bank ID:', snapshot.bank_id);
        }

        // Update info-value elements by matching labels
        document.querySelectorAll('.info-item').forEach(item => {
            const label = item.querySelector('.info-label')?.textContent || '';
            const valueEl = item.querySelector('.info-value');

            if (!valueEl) return;

            // Amount
            if (label.includes('المبلغ') && snapshot.amount) {
                const formattedAmount = new Intl.NumberFormat('ar-SA').format(snapshot.amount);
                valueEl.textContent = formattedAmount + ' ر.س';
                console.log('✓ Updated amount:', formattedAmount);
            }

            // Expiry date
            if (label.includes('تاريخ الانتهاء') && snapshot.expiry_date) {
                valueEl.textContent = snapshot.expiry_date;
                console.log('✓ Updated expiry:', snapshot.expiry_date);
            }

            // Issue date
            if (label.includes('تاريخ الإصدار') && snapshot.issue_date) {
                valueEl.textContent = snapshot.issue_date;
                console.log('✓ Updated issue date:', snapshot.issue_date);
            }
        });

        // Update status badge
        const statusBadge = document.querySelector('.status-badge');
        if (statusBadge && snapshot.status) {
            this.updateStatusBadge(statusBadge, snapshot.status);
            console.log('✓ Updated status:', snapshot.status);
        }
    }

    updateStatusBadge(badge, status) {
        // Remove all status classes
        badge.classList.remove('status-pending', 'status-approved', 'status-extended', 'status-released');

        // Add appropriate class
        badge.classList.add(`status-${status}`);

        const statusLabels = {
            'pending': 'يحتاج قرار',
            'approved': 'معتمد',
            'extended': 'ممدد',
            'released': 'مُفرج عنه',
            'reduced': 'مخفض'
        };

        badge.textContent = statusLabels[status] || status;
    }

    showHistoricalBanner() {
        // Remove existing banner if any
        this.removeHistoricalBanner();

        // Create banner
        const banner = document.createElement('div');
        banner.id = 'historical-banner';
        banner.className = 'historical-banner';
        banner.innerHTML = `
            <div style="display: flex; align-items: center; justify-content: space-between; 
                        background: #fef3c7; border: 2px solid #f59e0b; border-radius: 8px; 
                        padding: 12px 16px; margin-bottom: 16px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 20px;">🕰️</span>
                    <div>
                        <div style="font-weight: 600; color: #92400e;">نسخة تاريخية</div>
                        <div style="font-size: 12px; color: #78350f;">تعرض الحالة قبل حدوث التغيير</div>
                    </div>
                </div>
                <button onclick="timelineMachine.loadCurrentState()" 
                        style="background: #f59e0b; color: white; border: none; 
                               padding: 8px 16px; border-radius: 6px; font-weight: 600; 
                               cursor: pointer; transition: background 0.2s;"
                        onmouseover="this.style.background='#d97706'"
                        onmouseout="this.style.background='#f59e0b'">
                    ↩️ العودة للوضع الحالي
                </button>
            </div>
        `;

        // Insert before record form
        const recordForm = document.querySelector('.decision-card, .card');
        if (recordForm && recordForm.parentNode) {
            recordForm.parentNode.insertBefore(banner, recordForm);
        }
    }

    removeHistoricalBanner() {
        const banner = document.getElementById('historical-banner');
        if (banner) {
            banner.remove();
        }
    }

    disableEditing() {
        // Disable all input fields
        const inputs = document.querySelectorAll('#supplierInput, #bankNameInput, #bankSelect');
        inputs.forEach(input => {
            input.disabled = true;
            input.style.opacity = '0.7';
            input.style.cursor = 'not-allowed';
        });

        // Disable action buttons
        const buttons = document.querySelectorAll('.btn-extend, .btn-reduce, .btn-release, .btn-save');
        buttons.forEach(btn => {
            btn.disabled = true;
            btn.style.opacity = '0.5';
            btn.style.cursor = 'not-allowed';
        });
    }

    enableEditing() {
        // Enable all input fields
        const inputs = document.querySelectorAll('#supplierInput, #bankNameInput, #bankSelect');
        inputs.forEach(input => {
            input.disabled = false;
            input.style.opacity = '1';
            input.style.cursor = '';
        });

        // Enable action buttons
        const buttons = document.querySelectorAll('.btn-extend, .btn-reduce, .btn-release, .btn-save');
        buttons.forEach(btn => {
            btn.disabled = false;
            btn.style.opacity = '1';
            btn.style.cursor = 'pointer';
        });
    }

    saveCurrentState() {
        // Save current form state
        this.originalState = {
            supplier: document.getElementById('supplierInput')?.value,
            supplierId: document.getElementById('supplier-id')?.value,
            bank: document.getElementById('bankNameInput')?.value,
            bankId: document.getElementById('bankSelect')?.value,
        };
    }

    loadCurrentState() {
        // Remove historical banner
        this.removeHistoricalBanner();

        // Restore original state or reload from server
        if (this.originalState) {
            this.updateFormFields({
                supplier_name: this.originalState.supplier,
                supplier_id: this.originalState.supplierId,
                bank_name: this.originalState.bank,
                bank_id: this.originalState.bankId
            });
        } else {
            // Reload the page to get fresh current state
            window.location.reload();
            return;
        }

        // Enable editing
        this.enableEditing();

        // Remove active class from all timeline cards
        document.querySelectorAll('.timeline-event-wrapper').forEach(card => {
            card.querySelector('.timeline-event-card')?.classList.remove('active-event');
        });

        // Activate latest event
        const latestEvent = document.querySelector('.timeline-event-wrapper[data-is-latest="1"]');
        if (latestEvent) {
            latestEvent.querySelector('.timeline-event-card')?.classList.add('active-event');
        }

        // Reset state
        this.isHistoricalView = false;
        this.currentEventId = null;
        this.originalState = null;
    }

    showError(message) {
        alert('⚠️ ' + message);
    }
}

// Initialize Time Machine immediately
const timelineMachine = new TimelineMachine();

// Make globally accessible for onclick handlers
window.timelineMachine = timelineMachine;

