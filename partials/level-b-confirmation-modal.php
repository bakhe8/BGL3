<!-- 
    Phase 4: Level B Confirmation Modal
    Mandatory for all Level B (Arabic) suggestions
-->
<div id="levelB-confirmation-modal" class="modal" style="display: none;">
    <div class="modal-overlay" onclick="closeLevelBModal()"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3>⚠️ تأكيد الاقتراح</h3>
            <button class="modal-close" onclick="closeLevelBModal()">×</button>
        </div>
        
        <div class="modal-body">
            <p class="modal-intro">اخترت اقتراحاً بمستوى ثقة متوسط. يرجى التأكيد:</p>
            
            <!-- Suggested Supplier Card -->
            <div class="suggestion-card">
                <div class="suggestion-name" id="modal-supplier-name"></div>
                
                <!-- Explainability Section -->
                <div class="explainability">
                    <div class="explainability-item">
                        <span class="explainability-label">نوع الاقتراح:</span>
                        <span class="explainability-value">
                            <span class="badge badge-level-b">Level B</span>
                            <span id="modal-confidence"></span>
                        </span>
                    </div>
                    
                    <div class="explainability-item">
                        <span class="explainability-label">سبب الاقتراح:</span>
                        <span class="explainability-value" id="modal-reason"></span>
                    </div>
                    
                    <div class="explainability-item anchor-highlight">
                        <span class="explainability-label">الكلمة المميزة:</span>
                        <span class="explainability-value">
                            <code id="modal-anchor"></code>
                        </span>
                    </div>
                    
                    <div class="explainability-item" id="modal-anchor-type-container" style="display: none;">
                        <span class="explainability-label">نوع الكلمة:</span>
                        <span class="explainability-value" id="modal-anchor-type"></span>
                    </div>
                </div>
            </div>
            
            <!-- Warning Message -->
            <div class="modal-warning">
                <span class="warning-icon">⚠️</span>
                <span>هل أنت متأكد أن هذا هو المورد الصحيح؟</span>
            </div>
        </div>
        
        <div class="modal-actions">
            <button class="btn btn-confirm" onclick="confirmLevelBSelection()">
                ✓ نعم، هذا صحيح
            </button>
            <button class="btn btn-reject" onclick="rejectLevelBSelection()">
                ✗ لا، غير صحيح
            </button>
            <button class="btn btn-cancel" onclick="closeLevelBModal()">
                إلغاء
            </button>
        </div>
    </div>
</div>

<style>
/* Modal Base */
.modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 10000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-overlay {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(2px);
}

.modal-content {
    position: relative;
    background: white;
    border-radius: 12px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    max-width: 500px;
    width: 90%;
    max-height: 90vh;
    overflow: auto;
    animation: modalSlideIn 0.3s ease;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Modal Header */
.modal-header {
    padding: 20px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    color: #1e293b;
}

.modal-close {
    background: none;
    border: none;
    font-size: 28px;
    color: #64748b;
    cursor: pointer;
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    transition: all 0.2s;
}

.modal-close:hover {
    background: #f1f5f9;
    color: #1e293b;
}

/* Modal Body */
.modal-body {
    padding: 20px;
}

.modal-intro {
    margin: 0 0 16px 0;
    font-size: 14px;
    color: #475569;
    text-align: center;
}

/* Suggestion Card */
.suggestion-card {
    background: #f8fafc;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 16px;
}

.suggestion-name {
    font-size: 16px;
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 16px;
    padding: 12px;
    background: white;
    border-radius: 6px;
    text-align: center;
}

/* Explainability */
.explainability {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.explainability-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 13px;
    padding: 8px;
    background: white;
   border-radius: 6px;
}

.explainability-label {
    font-weight: 500;
    color: #64748b;
}

.explainability-value {
    font-weight: 600;
    color: #1e293b;
}

.anchor-highlight {
    background: #fef3c7;
    border: 1px solid #fbbf24;
}

.anchor-highlight code {
    background: #fef9e1;
    padding: 4px 8px;
    border-radius: 4px;
    font-family: 'Courier New', monospace;
    font-weight: 700;
    color: #92400e;
    font-size: 14px;
}

/* Badges */
.badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.badge-level-b {
    background: #fbbf24;
    color: #78350f;
}

/* Warning */
.modal-warning {
    background: #fef3c7;
    border: 1px solid #fbbf24;
    border-radius: 6px;
    padding: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: #92400e;
}

.warning-icon {
    font-size: 20px;
}

/* Modal Actions */
.modal-actions {
    padding: 16px 20px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    gap: 8px;
    justify-content: stretch;
}

.modal-actions .btn {
    flex: 1;
    padding: 10px 16px;
    border-radius: 6px;
    border: none;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-confirm {
    background: #10b981;
    color: white;
}

.btn-confirm:hover {
    background: #059669;
}

.btn-reject {
    background: #ef4444;
   color: white;
}

.btn-reject:hover {
    background: #dc2626;
}

.btn-cancel {
    background: #e2e8f0;
    color: #475569;
}

.btn-cancel:hover {
    background: #cbd5e1;
}
</style>

<script>
// Phase 4: Level B Confirmation Modal Logic
// NO LEARNING IN THIS PHASE - just UI decision
let pendingLevelBSelection = null;

function showLevelBModal(supplierId, supplierName, suggestionData) {
    // Store pending selection
    pendingLevelBSelection = {
        supplierId: supplierId,
        supplierName: supplierName,
        data: suggestionData
    };
    
    // Populate modal
    document.getElementById('modal-supplier-name').textContent = supplierName;
    document.getElementById('modal-confidence').textContent = `(${suggestionData.confidence}% ثقة)`;
    document.getElementById('modal-reason').textContent = suggestionData.reason || 'تطابق اسم تجاري مميز';
    document.getElementById('modal-anchor').textContent = suggestionData.matched_anchor || '';
    
    // Show anchor type if available
    if (suggestionData.anchor_type) {
        document.getElementById('modal-anchor-type-container').style.display = 'flex';
        document.getElementById('modal-anchor-type').textContent = suggestionData.anchor_type;
    }
    
    // Phase 5: Start decision tracking
    if (typeof startLevelBDecisionTracking === 'function') {
        startLevelBDecisionTracking(suggestionData);
    }
    
    // Show modal
    document.getElementById('levelB-confirmation-modal').style.display = 'flex';
}

function closeLevelBModal() {
    document.getElementById('levelB-confirmation-modal').style.display = 'none';
    pendingLevelBSelection = null;
}

function confirmLevelBSelection() {
    if (!pendingLevelBSelection) return;
    
    // Phase 5: Log confirmation
    if (typeof logLevelBDecision === 'function') {
        logLevelBDecision('confirm', pendingLevelBSelection);
    }
    
    // Phase 4: NO LEARNING - just make the decision
    const { supplierId, supplierName } = pendingLevelBSelection;
    
    // Close modal first
    closeLevelBModal();
    
    // Proceed with normal supplier selection
    selectSupplierById(supplierId, supplierName);
}

function rejectLevelBSelection() {
    // Phase 5: Log rejection
    if (typeof logLevelBDecision === 'function') {
        logLevelBDecision('reject', pendingLevelBSelection);
    }
    
    // Phase 4: Just close - NO negative learning
    console.log('[Phase 4] User rejected Level B suggestion:', pendingLevelBSelection);
    closeLevelBModal();
}

// ESC key to close
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('levelB-confirmation-modal').style.display === 'flex') {
        closeLevelBModal();
    }
});
</script>
