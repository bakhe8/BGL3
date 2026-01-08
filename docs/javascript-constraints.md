# JavaScript Constraints

## Tech Stack
*   **Framework**: **NONE** (Vanilla JavaScript).
*   **Forbidden**: Alpine.js, Vue, React, jQuery.

## Responsibilities

### 1. What JS Does
*   **DOM Manipulation**: Toggling classes, updating text content (Preview).
*   **Event Handling**: Global delegation pattern (`document.addEventListener('click', ...)`).
*   **Server Communication**: `fetch()` API for all actions.
*   **Formatting**:
    *   Converting Western numerals (123) to Arabic (١٢٣) in the Preview (`convertToArabicNumerals`).
    *   Formatting dates (YYYY-MM-DD -> Arabic textual date) (`formatArabicDate`).
    *   **Constraint**: JS formatting **MUST NOT diverge** from server logic in `LetterBuilder.php` (Risk: Dual Authority).

### 2. What JS Does NOT Do (By Design)
*   **HTML Generation**: JS does **not** generate complex HTML arrays.
    *   *Rule*: Complex UI (like Supplier Suggestions `supplier-suggestions`) is fetched as **HTML Fragments** from `/api/suggestions-learning.php` and injected via `.innerHTML`.
*   **Business Logic**:
    *   JS does not calculate "Is this approved?". It checks the DOM state (badges) rendered by the server.

### 3. Logic Placement
*   **Validation**: Minimal client-side checks (e.g., "Is amount a number?").
*   **State**: Held in DOM `data-attributes` (`data-record-id`, `data-action`). The DOM **is** the state.
*   **Network Failure**:
    *   JS uses `try...catch` blocks around `fetch` calls.
    *   **Behavior**: Displays a Toast notification ("حدث خطأ في الاتصال") to the user.
    *   **Retry**: No automatic retry mechanism. User must click the button again.
