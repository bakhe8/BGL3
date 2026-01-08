# Documentation Evidence Map

This map links key documentation claims to their source of truth in the codebase.
**Note**: Each specific document contains a detailed "Evidence Index" at the end of the file with line numbers. This map serves as an overview.

## 1. System Core
*   **Lazy Creation**: `docs/database-model.md` -> `api/save-and-next.php` (Lines 214-232: INSERT only if missing).
*   **Status Logic**: `docs/status-model.md` -> `App/Services/StatusEvaluator.php` (Lines 27-35).
*   **Legacy Fabrication**: `docs/timeline-contract.md` -> `App/Services/TimelineDisplayService.php` (Lines 91-106).

## 2. Preview & Formatting
*   **Hybrid Preview**: `docs/preview-contract.md` -> 
    *   **SSR Base**: `partials/letter-renderer.php`.
    *   **JS Updates**: `public/js/records.controller.js` (lines 116-249).
*   **Dual Formatting**: `docs/known-tradeoffs.md` -> 
    *   **JS**: `records.controller.js` (formatArabicDate).
    *   **PHP**: `App/Support/PreviewFormatter.php`.

## 3. Authority & Locking
*   **Backend Lock**: `docs/authority-model.md` -> `api/extend.php` (Lines 48-54 checks `is_locked`).
*   **Bank Input ReadOnly**: `docs/ui-behavior-contract.md` -> `partials/record-form.php` (Lines 230-231 `div.info-value`).

## 4. Architecture
*   **Server-Driven Fragments**: `docs/server-driven-fragments.md` -> `api/suggestions-learning.php`.
*   **API Contracts**: `docs/api-contracts.md` -> `api/*.php`.
