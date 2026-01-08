# Preview Contract

## Visibility Gates
The Letter Preview is NOT always visible. It is protected by strict logic gates.

### 1. The Readiness Gate
*   **Condition**: `GuarantueeDecision.status === 'ready'`.
*   **Logic**: The record must have a resolved **Supplier** and **Bank**.
*   **Behavior**:
    *   **Frontend (UX)**: JS hides the preview section if badges imply pending.
    *   **Backend (Security)**: PHP (`letter-renderer.php`) returns a placeholder (`preview-placeholder.php`) if the decision is not ready, regardless of JS manipulation. **Backend is the final authority**.

### 2. The Action Gate (ADR-007)
*   **Condition**: `GuaranteeDecision.active_action IS NOT NULL`.
*   **Logic**: A letter cannot be generated without a specific *intent* (Extend, Reduce, or Release).
*   **Behavior**: If status is ready but no action is selected, `preview-placeholder.php` is rendered ("No Action Taken").

### 3. Generation Strategy (Hybrid)
*   **Initial Load (SSR)**: The server (`letter-renderer.php`) renders the full HTML based on the database state. This is the **Trusted State**.
*   **User Interaction (Client-Side)**: `records.controller.js` (`updatePreviewFromDOM`) performs **Optimistic Updates**.
    *   *Mechanism*: Updates text content of specific spans (`[data-preview-field]`) to reflect form changes immediately.
    *   *Formatting*: JS replicates server formatting logic (e.g., Arabic numerals, dates). See `known-tradeoffs.md` regarding duplication risk.
*   **Snapshot**: The final PDF/HTML stored in `guarantee_history` is generated purely by `TimelineRecorder` calling `LetterBuilder` (Server Logic), completely bypassing JS.

## Data Sources
*   **Bank Name**: FROM `banks` table (via `guarantee_decisions.bank_id`).
*   **Supplier Name**: FROM `suppliers` table (via `guarantee_decisions.supplier_id`) OR `raw_data` if purely manual.

### Content Body
*   **Intro Phrase**: Derived **dynamically** in `records.controller.js` and `LetterBuilder`.
    *   *Extension*: "طلب تمديد..."
    *   *Reduction*: "طلب تخفيض..."
    *   *Release*: "طلب الإفراج..."
*   **Details**: FROM `guarantees.raw_data` (Amount, Number, Date).
*   **Amount Formatting**: Converted to **Arabic Numerals** (٠-٩) in `records.controller.js` (`applyLetterFormatting`).

## Snapshotting
*   When an action is finalized (e.g., Release), the **Generated Letter Text** is saved to `guarantee_history.letter_snapshot`.
*   *Note*: The UI Preview adds "DRAFT" watermarks or editability, but the snapshot is static text.
