# Known Trade-offs

## 1. Vanilla JS Complexity
*   **Decision**: Remove Alpine.js to reduce dependencies.
*   **Trade-off**: State management is manual. We rely on DOM querying (`document.querySelector`) and data attributes. This makes the JS code more verbose and potentially brittle if Class names change.

## 2. Dual Authority (Formatting Divergence Risk)
*   **Decision**: JS updates the preview instantly for UX (Optimistic UI).
*   **Trade-off**: Formatting logic (Arabic numerals, Date conversion, Intro phrase generation) is **Duplicated** between `records.controller.js` and `App/Services/LetterBuilder.php`.
    *   *Risk*: If PHP logic changes (e.g., new intro phrase for a type), JS must be manually updated to match.
    *   *Mitigation*: `TimelineRecorder` always uses the PHP `LetterBuilder` for the permanent history snapshot, ensuring the legal record is correct even if the live preview was slightly off.

## 6. Read-Only Bank Input
*   **Decision**: Bank is auto-matched during import or set via Batch Processing.
*   **Trade-off**: The Main Record Form (`record-form.php`) displays the Bank as **Read-Only text**. Users cannot manually select a bank from a dropdown in this specific interface. Correction requires re-import or admin intervention.

## 3. Database Remapping (Reset Script)
*   **Decision**: `reset_database.php` re-issues IDs (1, 2, 3...) for clean ordering.
*   **Trade-off**: Any external references (e.g., if a user wrote down "Supplier ID 55") become invalid after a reset. The system prioritizes internal consistency over external persistence validity during these resets.

## 4. Magic Strings
*   **Decision**: Actions are identified by strings: `'extension'`, `'reduction'`, `'release'`.
*   **Trade-off**: These strings are hardcoded in `records.controller.js`, `api/`, and `guarantee_decisions` table. A typo in any layer breaks the flow. No central Enum ensures consistency across languages (PHP/JS).

## 5. UI Locking
*   **Decision**: Released guarantees are locked via JS disabling inputs.
*   **Trade-off**: While the backend *also* rejects updates (Good), a savvy user could enable the inputs via DevTools. (Backend validation is the real safety net, UI is just UX).
