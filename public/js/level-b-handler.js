/**
 * Phase 4: Level B Suggestion Handler
 * Intercepts Level B selections and shows confirmation modal
 * NO LEARNING in this phase - just UI decision flow
 */

// Store current suggestions with their metadata
window.currentSupplierSuggestions = [];

/**
 * Enhanced supplier suggestion selector
 * Checks if suggestion is Level B and shows modal if needed
 */
function selectSupplierSuggestion(supplierId, supplierName, suggestionData) {
    // Check if this is a Level B suggestion (requires confirmation)
    if (suggestionData && suggestionData.level === 'B') {
        // Show confirmation modal instead of immediate selection
        showLevelBModal(supplierId, supplierName, suggestionData);
        return;
    }

    // Level A or regular suggestion - proceed immediately
    selectSupplierById(supplierId, supplierName);
}

/**
 * Direct selection (for Level A, or after Level B confirmation)
 */
function selectSupplierById(supplierId, supplierName) {
    // Update the supplier input field (visible text input)
    const supplierInput = document.getElementById('supplierInput');
    if (supplierInput) {
        supplierInput.value = supplierName;
    }

    // Update the hidden supplier ID field
    const supplierIdHidden = document.getElementById('supplierIdHidden');
    if (supplierIdHidden) {
        supplierIdHidden.value = supplierId;
    }

    // Update chip styling (mark as selected)
    document.querySelectorAll('.chip').forEach(chip => {
        chip.classList.remove('chip-selected');
        chip.classList.add('chip-candidate');
    });

    // Mark the selected chip
    const selectedChip = document.querySelector(`[data-supplier-id="${supplierId}"], [data-id="${supplierId}"]`);
    if (selectedChip) {
        selectedChip.classList.remove('chip-candidate');
        selectedChip.classList.add('chip-selected');
    }

    console.log('[Phase 4] Supplier selected:', { id: supplierId, name: supplierName });
}

/**
 * Update suggestion chips with Level B/C/D metadata
 * Called when suggestions are loaded from API
 */
function renderSupplierSuggestions(suggestions) {
    const container = document.getElementById('supplier-suggestions');
    if (!container) return;

    // Store suggestions globally for modal access
    window.currentSupplierSuggestions = suggestions;

    if (!suggestions || suggestions.length === 0) {
        container.innerHTML = '<div style="font-size: 11px; color: #94a3b8; padding: 4px;">لا توجد اقتراحات</div>';
        return;
    }

    let html = '';
    suggestions.forEach(sugg => {
        const level = sugg.level || 'A';
        const isModalRequired = ['B', 'C', 'D'].includes(level);

        let chipClass = 'chip';
        if (level === 'B') chipClass = 'chip chip-level-b';
        if (level === 'C') chipClass = 'chip chip-level-c';
        if (level === 'D') chipClass = 'chip chip-level-d';

        if (isModalRequired) {
            // Level B/C/D: Use data attributes + handleSuggestionClick
            const suggestionData = {
                level: level,
                confidence: sugg.confidence || 100,
                matched_anchor: sugg.matched_anchor || '',
                reason: sugg.reason_ar || sugg.reason || '',
                source: sugg.source_type || '', // New field
                confirmation_count: sugg.confirmation_count || 0,
                historical_count: sugg.historical_count || 0
            };

            const encodedData = btoa(encodeURIComponent(JSON.stringify(suggestionData)));
            const badgeHtml = getLevelBadge(level);

            html += `
                <button
                    type="button"
                    class="${chipClass}"
                    data-supplier-id="${sugg.id}"
                    data-supplier-name="${sugg.official_name.replace(/"/g, '&quot;')}"
                    data-suggestion-data="${encodedData}"
                    data-is-learning="true"
                    onclick="handleSuggestionClick(this)"
                >
                    <span class="chip-text">
                        ${sugg.official_name}
                        ${badgeHtml}
                    </span>
                    ${sugg.score >= 90 ? `<span class="chip-stars">⭐</span>` : ''}
                </button>
            `;
        } else {
            // Level A: Use existing data-action pattern
            html += `
                <button
                    type="button"
                    class="chip"
                    data-action="selectSupplier"
                    data-id="${sugg.id}"
                    data-name="${sugg.official_name.replace(/"/g, '&quot;')}"
                >
                    <span class="chip-text">
                        ${sugg.official_name}
                    </span>
                </button>
            `;
        }
    });

    container.innerHTML = html;
}

function getLevelBadge(level) {
    if (level === 'B') return '<span class="badge badge-level-b" style="margin-left: 8px;">Level B</span>';
    if (level === 'C') return '<span class="badge badge-level-c" style="margin-left: 8px;">تعلم آلي</span>';
    if (level === 'D') return '<span class="badge badge-level-d" style="margin-left: 8px;">اقتراح ضعيف</span>';
    return '';
}

// Add styles for Level B/C/D chips
const learningStyles = document.createElement('style');
learningStyles.textContent = `
.chip-level-b {
    border: 2px solid #fbbf24 !important;
    background: #fef3c7 !important;
}
.chip-level-b:hover {
    background: #fde68a !important;
    border-color: #f59e0b !important;
}
.badge-level-b {
    background: #fbbf24;
    color: #78350f;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 9px;
    font-weight: 700;
}

/* Level C: Learning */
.chip-level-c {
    border: 2px solid #f97316 !important;
    background: #ffedd5 !important;
}
.chip-level-c:hover {
    background: #fed7aa !important;
    border-color: #f97316 !important;
}
.badge-level-c {
    background: #f97316;
    color: white;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 9px;
    font-weight: 700;
}

/* Level D: Historical Low Confidence */
.chip-level-d {
    border: 1px dashed #9ca3af !important;
    background: #f3f4f6 !important;
    opacity: 0.9;
}
.chip-level-d:hover {
    background: #e5e7eb !important;
    border-color: #6b7280 !important;
}
.badge-level-d {
    background: #9ca3af;
    color: white;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 9px;
    font-weight: 500;
}
`;
document.head.appendChild(learningStyles);

console.log('[Phase 4] Level B Suggestion Handler loaded');
