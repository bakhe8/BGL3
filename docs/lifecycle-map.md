# BGL System v3.0 - Lifecycle Map

## Lifecycle Stages

This map documents the journey of a guarantee from the outside world to the infinite archive.

### Stage 1: The Void (Pre-Import)
*   **State**: Data exists in Excel files on a user's desktop.
*   **System Knowledge**: Zero.
*   **Transition**: User uploads file -> `ImportService` processes it.

### Stage 2: Raw Existence (The Import)
*   **Table**: `guarantees` (Row created).
*   **Table**: `guarantee_decisions` (Row **NOT** created yet - Null/Virtual State).
*   **Status**: Implicitly `Pending`.
*   **Audit**: `import` event recorded in `guarantee_history` (one-sided event source).

### Stage 3: Operational Decision (The Work)
*   **Trigger**: User opens record or System Scripts run.
*   **Action**: `GuaranteeDecisionRepository::createOrUpdate`.
*   **Table**: `guarantee_decisions` (Row created).
*   **State**: `Pending` -> User selects matching **Supplier** + **Bank** -> `Ready`.
*   **Gates**:
    *   `Pending`: Preview Hidden.
    *   `Ready`: Preview Visible (Placeholder "No Action").

### Stage 4: The Intent (Active Action)
*   **Trigger**: User clicks "Extend", "Reduce", or starts "Release".
*   **Data**: `guarantee_decisions.active_action` set to `'extension'` (for example).
*   **Verification**: Preview generates specific draft letter (e.g., "Extension Request").
*   **Audit**: `modified` event recorded with `letter_snapshot` (Draft state).

### Stage 5: The Finality (Release)
*   **Trigger**: User confirms "Release" action.
*   **Backend**: `release.php`.
*   **Logic**:
    1.  Validates `status === 'ready'`.
    2.  Sets `is_locked = 1`.
    3.  Records `release` event with Final Letter Snapshot.
*   **Effect**:
    *   Record becomes Read-Only (UI Disabled).
    *   No further edits allowed (Database Policy).

### Stage 6: The Archive (Post-Life)
*   **State**: Row exists forever.
*   **Access**: Read-only history.
*   **Mutability**: None (unless Admin manually unlocks via DB manipulation - out of scope for App).
