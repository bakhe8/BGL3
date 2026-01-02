/**
 * Phase 5: Auto-load Supplier Suggestions on Page Load
 * 
 * PILOT GLUE CODE - Temporary bridge to enable Phase 5
 * Automatically triggers suggestion search when guarantee page loads
 * 
 * This code can be removed after Pilot completion.
 */

(function () {
    'use strict';

    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', autoLoadSuggestions);
    } else {
        autoLoadSuggestions();
    }

    function autoLoadSuggestions() {
        // Only run on guarantee detail pages (not settings, etc.)
        if (!document.getElementById('supplierInput')) {
            return;
        }

        // Get the Excel supplier name from UI
        const excelSupplierEl = document.getElementById('excelSupplier');
        if (!excelSupplierEl) {
            console.log('[Pilot] No Excel supplier element found');
            return;
        }

        const rawSupplierName = excelSupplierEl.textContent.trim();
        if (!rawSupplierName || rawSupplierName === '-') {
            console.log('[Pilot] No supplier name to search for');
            return;
        }

        console.log('[Pilot] Auto-loading suggestions for:', rawSupplierName);

        // Trigger the existing suggestion mechanism
        const supplierInput = document.getElementById('supplierInput');
        if (supplierInput) {
            // Set value and dispatch input event to trigger records controller
            supplierInput.value = rawSupplierName;
            supplierInput.dispatchEvent(new Event('input', { bubbles: true }));

            console.log('[Pilot] Suggestion search triggered');
        }
    }

    console.log('[Phase 5] Auto-load glue code loaded');
})();
