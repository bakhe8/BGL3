/**
 * Timeline Handler - Time Machine Functionality
 * Handles click interactions on timeline events
 * Shows historical state of guarantee at any point in time
 */

if (!window.TimelineController) {
    window.TimelineController = class TimelineController {
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
                    this.processTimelineClick(eventWrapper);
                }
            });

            console.log('✅ Timeline Controller initialized');
        }

        processTimelineClick(element) {
            const eventId = element.dataset.eventId;
            const snapshotData = element.dataset.snapshot;

            try {
                const snapshot = JSON.parse(snapshotData);

                // Remove active class from all cards
                document.querySelectorAll('.timeline-event-wrapper').forEach(card => {
                    card.querySelector('.timeline-event-card')?.classList.remove('active-event');
                });

                // Add active class to clicked card
                element.querySelector('.timeline-event-card')?.classList.add('active-event');

                // All events (including latest) show historical snapshot
                this.displayHistoricalState(snapshot, eventId);
            } catch (error) {
                console.error('Error handling timeline click:', error);
                this.showError('حدث خطأ في عرض الحالة التاريخية');
            }
        }

        displayHistoricalState(snapshot, eventId) {
            console.log('📜 Displaying historical state:', snapshot);

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

            // Check if snapshot is empty or legacy
            if (!snapshotData || Object.keys(snapshotData).length === 0 || snapshotData._no_snapshot) {
                console.warn('⚠️ No snapshot data available');
                if (window.showToast) window.showToast('لا توجد بيانات تاريخية لهذا الحدث', 'error');
                return;
            }


            // Mark as historical view (no client-side state saving - Server is source of truth)
            this.isHistoricalView = true;
            this.currentEventId = eventId;
            this.currentGuaranteeId = snapshotData.guarantee_id ||
                document.querySelector('[data-record-id]')?.dataset.recordId;

            // 🔥 BACKWARD COMPATIBILITY: Fill missing fields from previous snapshots
            // (for old events created before createSnapshot() fix)
            const completeSnapshot = this.fillMissingFields(eventId, snapshotData);

            // Update form fields with complete snapshot data
            this.updateFormFields(completeSnapshot);

            // Show historical banner
            this.showHistoricalBanner();

            // Disable editing
            this.disableEditing();

            // Update preview if it's currently open (Derived View sync)
            if (window.recordsController?.previewVisible) {
                window.recordsController.updatePreviewFromDOM();
            }
        }

        /**
         * Fill missing snapshot fields from previous snapshots
         * For backward compatibility with old events (before createSnapshot fix)
         */
        fillMissingFields(currentEventId, snapshot) {
            const merged = { ...snapshot };

            // Check if any critical field is missing
            const hasMissingFields = !merged.bank_name || !merged.supplier_name;

            if (!hasMissingFields) {
                return merged; // All fields present
            }

            console.log('⚠️ Snapshot has missing fields, inheriting from previous events...');

            // Find previous events in timeline
            const allEvents = document.querySelectorAll('.timeline-event-wrapper');
            let foundCurrent = false;

            for (const eventWrapper of allEvents) {
                const eventId = eventWrapper.dataset.eventId;

                if (eventId === currentEventId) {
                    foundCurrent = true;
                    continue;
                }

                if (!foundCurrent) {
                    // This is a previous event (before current in timeline)
                    const prevSnapshotJson = eventWrapper.dataset.snapshot;
                    if (!prevSnapshotJson) continue;

                    try {
                        const prevSnapshot = JSON.parse(prevSnapshotJson);

                        // Inherit missing bank_name
                        if (!merged.bank_name && prevSnapshot.bank_name) {
                            merged.bank_name = prevSnapshot.bank_name;
                            merged.bank_id = prevSnapshot.bank_id;
                            console.log(`✓ Inherited bank_name: ${prevSnapshot.bank_name}`);
                        }

                        // Inherit missing supplier_name
                        if (!merged.supplier_name && prevSnapshot.supplier_name) {
                            merged.supplier_name = prevSnapshot.supplier_name;
                            merged.supplier_id = prevSnapshot.supplier_id;
                            console.log(`✓ Inherited supplier_name: ${prevSnapshot.supplier_name}`);
                        }

                        // Stop if all fields filled
                        if (merged.bank_name && merged.supplier_name) {
                            break;
                        }
                    } catch (e) {
                        console.error('Failed to parse previous snapshot:', e);
                    }
                }
            }

            return merged;
        }

        updateFormFields(snapshot) {
            console.log('🔄 Updating fields with snapshot:', snapshot);

            // Update supplier input (ID: supplierInput)
            // Always update to prevent "leakage" from previous events
            const supplierInput = document.getElementById('supplierInput');
            if (supplierInput) {
                supplierInput.value = snapshot.supplier_name || '';
                console.log('✓ Updated supplier:', snapshot.supplier_name || '(cleared)');
            }

            // Update hidden supplier ID (ID: supplierIdHidden)
            const supplierIdHidden = document.getElementById('supplierIdHidden');
            if (supplierIdHidden) {
                supplierIdHidden.value = snapshot.supplier_id || '';
                console.log('✓ Updated supplier ID:', snapshot.supplier_id || '(cleared)');
            }

            // Update bank name input (ID: bankNameInput)
            // Always update to prevent "leakage" from previous events
            const bankNameInput = document.getElementById('bankNameInput');
            if (bankNameInput) {
                bankNameInput.value = snapshot.bank_name || '';
                console.log('✓ Updated bank name:', snapshot.bank_name || '(cleared)');
            }

            // Update hidden bank ID (ID: bankSelect)
            const bankSelect = document.getElementById('bankSelect');
            if (bankSelect) {
                bankSelect.value = snapshot.bank_id || '';
                console.log('✓ Updated bank ID:', snapshot.bank_id || '(cleared)');
            }

            // Update info-value elements by matching labels
            document.querySelectorAll('.info-item').forEach(item => {
                const label = item.querySelector('.info-label')?.textContent || '';
                const valueEl = item.querySelector('.info-value');

                if (!valueEl) return;

                // Amount - with NaN protection
                if (label.includes('المبلغ') && snapshot.amount) {
                    let amountValue = snapshot.amount;

                    // Convert string to number safely
                    if (typeof amountValue === 'string') {
                        amountValue = parseFloat(amountValue.replace(/[^\d.]/g, ''));
                    }

                    // Validate number
                    if (!isNaN(amountValue) && isFinite(amountValue)) {
                        const formattedAmount = new Intl.NumberFormat('ar-SA').format(amountValue);
                        valueEl.textContent = formattedAmount + ' ر.س';
                        console.log('✓ Updated amount:', formattedAmount);
                    } else {
                        console.warn('⚠️ Invalid amount value:', snapshot.amount);
                        valueEl.textContent = 'قيمة غير صحيحة ر.س';
                    }
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
                    <button data-action="timeline-load-current" 
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

            // Hide action buttons (not just disable - prevent accidental interaction with history)
            const buttons = document.querySelectorAll('[data-action="extend"], [data-action="reduce"], [data-action="release"], [data-action="save-next"], [data-action="saveAndNext"]');
            buttons.forEach(btn => {
                btn.style.display = 'none';
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

            // Show action buttons again
            const buttons = document.querySelectorAll('[data-action="extend"], [data-action="reduce"], [data-action="release"], [data-action="save-next"], [data-action="saveAndNext"]');
            buttons.forEach(btn => {
                btn.style.display = '';
            });
        }

        async loadCurrentState() {
            console.log('🔄 Loading current state from server');

            this.removeHistoricalBanner();

            // Get guarantee ID  
            const currentId = this.currentGuaranteeId ||
                document.querySelector('[data-record-id]')?.dataset.recordId;

            if (!currentId) {
                console.error('No guarantee ID found');
                return;
            }

            try {
                // Fetch current state from server (Server-Driven Architecture)
                const response = await fetch(`/api/get-current-state.php?id=${currentId}`);
                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.error || 'Failed to load current state');
                }

                // Update form fields with current state snapshot
                this.updateFormFields(data.snapshot || {});

                // Hide historical banner
                this.removeHistoricalBanner();

                // Enable editing (show buttons, enable inputs)
                this.enableEditing();

                // Reset timeline state
                this.isHistoricalView = false;
                this.currentEventId = null;
                this.currentGuaranteeId = null;

                // Re-activate latest timeline event
                document.querySelectorAll('.timeline-event-wrapper').forEach(card => {
                    card.querySelector('.timeline-event-card')?.classList.remove('active-event');
                });

                const latestEvent = document.querySelector('.timeline-event-wrapper[data-is-latest="1"]');
                if (latestEvent) {
                    latestEvent.querySelector('.timeline-event-card')?.classList.add('active-event');
                }

                // Update preview if it's currently open (Derived View sync)
                if (window.recordsController?.previewVisible) {
                    window.recordsController.updatePreviewFromDOM();
                }

                console.log('✅ Current state loaded from server');
            } catch (error) {
                console.error('Failed to load current state:', error);
                if (window.showToast) {
                    window.showToast('فشل تحميل الحالة الحالية', 'error');
                }
            }
        }

        showError(message) {
            if (window.showToast) window.showToast(message, 'error');
        }
    };
}

// Initialize Time Machine immediately
if (!window.timelineController) {
    if (window.TimelineController) {
        window.timelineController = new window.TimelineController();
        // Make globally accessible for onclick handlers
        // window.timelineController is already set above
    }
}
