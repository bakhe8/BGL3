# Timeline Contract

## Event Definitions
The timeline is the single audit trail for the system.

### Event Types
Based on `guarantee_history` and `TimelineDisplayService`:

1.  **Import (`import`)**
    *   **Source**: System (Excel Import) or User (Manual/Paste).
    *   **Meaning**: Record entered the system.
    *   **Subtypes**: `excel`, `smart_paste`, `manual`.

2.  **Auto Match (`auto_matched`)**
    *   **Source**: AI / System Logic.
    *   **Meaning**: System automatically linked a Supplier or Bank.
    *   **Subtypes**: `bank_match`, `ai_match`.

3.  **Modification (`modified`)**
    *   **Source**: User.
    *   **Meaning**: User changed data or triggered an action.
    *   **Granularity**: Atomic. An "Extension" action records **one** event of type `modified` (subtype `extension`). It does *not* record a separate `status_change` event unless the status explicitly transitions from Pending to Ready.
    *   **Subtypes**:
        *   `manual_edit`: Changed text fields.
        *   `extension`: Triggered Extension Action.
        *   `reduction`: Triggered Reduction Action.

4.  **Status Change (`status_change`)**
    *   **Source**: User (implicitly via saving).
    *   **Meaning**: Record moved from 'pending' to 'ready' (or vice versa).

5.  **Release (`release`)**
    *   **Source**: User (Release Action).
    *   **Meaning**: Guarantee locked and marked as released.

## Snapshot Strategy
*   **Storage**: `snapshot_data` column (JSON).
*   **Content**: Contains the state of the record *at the time of the event*.
*   **Verification**: The UI displays the history list, allowing users to see *what* changed (via `description` field in JSON).

## View Reconstruction
*   **Service**: `TimelineDisplayService`.
*   **Logic**:
    *   Fetches all rows from `guarantee_history` (Real Events).
    *   Sorts by `created_at` DESC.
    *   **Legacy Fabrication (Virtual Event)**:
        *   If `history` array is empty, the Service **fabricates** a virtual import event in memory (ID: `import_1`).
        *   **Source**: `guarantees.imported_at` and `guarantees.import_source`.
        *   **Purpose**: Visual continuity for legacy records imported before V3.
        *   **Warning**: This event does **NOT** exist in the `guarantee_history` table.
