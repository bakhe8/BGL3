# UI Behavior Contract

## Main Interface (Index)

### 1. Record Display
*   **Appearance**: Split screen. Data form on left/center, Letter Preview on right.
*   **Loading**: Server-side rendered (SSR) initial state.
*   **Navigation**: `Previous` / `Next` buttons.
    *   **Behavior**: Preserves current URL filter (`?filter=pending` stays `pending`).
    *   **Boundary**: Buttons disabled at start/end of list.

### 2. Form Interactions
*   **Supplier Input**:
    *   **Behavior**: "Type-ahead" search.
    *   **Data Source**: Fetches HTML fragments from `/api/suggestions-learning.php`.
    *   **Selection**: Clicking a chip sets hidden `supplier_id`. Typing new text clears `supplier_id`.
*   **Bank Input**:
    *   **Behavior**: Displayed as Read-Only Text (`info-value`).
    *   **Editing**: Not editable in this interface (Requires Admin/Re-import).
    *   **Evidence**: `partials/record-form.php` renders it as `div.info-value` (Lines 230-231).
*   **Toast Notifications**:
    *   **Trigger**: Save success/error, Action completion.
    *   **Style**: Top-center, auto-dismiss (3s).

### 3. Action Buttons
*   **Common Behavior**: All require confirmation via Modal.
*   **Extend/Reduce/Release**:
    *   **Prerequisite**: Record must be saved.
    *   **Effect**: Triggers API call -> Reloads page (SSR refresh).
*   **Save & Next**:
    *   **Behavior**: Saves current state -> Navigates to next ID in filter.
    *   **Completion**: Shows toast if no more records.

### 4. Locked State (Released)
*   **Trigger**: `is_locked === true`.
*   **Behavior**: 
    *   Specific "Released" banner injected via JS.
    *   All inputs (`#supplierInput`, `#bankSelect`) set to `disabled`.
    *   Action buttons set to `disabled`.
    *   Suggestions hidden.

## Modals
*   **Manual Entry**: Simple form, POSTs to `api/manual-entry.php`.
*   **Paste Import**: Text area parsing, POSTs to `api/parse-paste.php`.
## Evidence Index
*   **Bank Read-Only**: `partials/record-form.php` (Lines 230-231).
*   **Supplier Suggestions**: `public/js/records.controller.js` (Line 727 `fetch`).
*   **Toast Notifications**: `public/js/records.controller.js` (Lines 574-592 `showToast`).
*   **SSR Loading**: `index.php` (via `partials/record-form.php`).
