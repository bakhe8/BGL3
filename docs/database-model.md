# Database Model

## Core Philosophy
The database is designed around **Immunity vs. Mutability**. We separate what came from the outside world (Raw) from what we decided internally (Decision).

## Tables & Semantics

### 1. `guarantees` (Immutable Raw Data)
*   **Role**: Container for the original imported data.
*   **Semantics**: "What the file said".
*   **Key Fields**:
    *   `id` (PK): System ID.
    *   `guarantee_number`: Unique Identifier (Business Key).
    *   `raw_data` (**JSON**): The complete set of fields from the Excel file/Import source. This is the **Source of Truth** for the imported state.
    *   `import_source`: Traceability of origin.

### 2. `guarantee_decisions` (Mutable State)
*   **Role**: The operational state of the guarantee.
*   **Semantics**: "What we are doing about it".
*   **Creation Strategy (Lazy)**:
    *   Records are **NOT** created immediately upon Import.
    *   They are created **Lazily** upon the first User Action (Save/Update) or by specific batch scripts (Auto-Match).
    *   *implication*: A record in `guarantees` without a corresponding `guarantee_decisions` row is implicitly purely "Pending" (Raw state).
*   **Key Fields**:
    *   `guarantee_id` (FK): 1:1 link to `guarantees`.
    *   `status`: `'pending'` (Needs work) or `'ready'` (Ready for action).
    *   `supplier_id`, `bank_id`: The **Resolved** entities. These may differ from raw data if the user corrected them.
    *   `is_locked` (Boolean): **Critical Gate**. If 1, the guarantee is released/finalized and cannot be edited.
    *   `active_action` (Text): The current action being performed (e.g., 'extension', 'reduction', 'release'). Drives the Letter Preview content.
    *   `decision_source`: Meta-data on how the decision was made ('manual', 'ai_match').

### 3. `guarantee_history` (Audit Trail)
*   **Role**: Unified timeline of all events.
*   **Semantics**: "What happened and when".
*   **Key Fields**:
    *   `event_type`: High-level category ('import', 'modified', 'status_change').
    *   `snapshot_data` (JSON): A snapshot of the state *at that moment*.
    *   `letter_snapshot` (Text): The actual text of the letter generated (if applicable).

### 4. `suppliers` & `banks` (Entities)
*   **Role**: Normalized registries.
*   **Semantics**: Canonical list of valid entities.
*   **Key Fields**:
    *   `normalized_name`: Used for deduplication and matching.

### 5. `supplier_learning_cache` (Decision Intelligence)
*   **Role**: Optimize supplier suggestions.
*   **Key Fields**:
    *   `fuzzy_score`, `usage_count`, `block_count`.
    *   **Generated Columns**: `total_score`, `star_rating` are calculated by the database engine, not app logic.

## Relationships
*   **Implicit 1:1**: `guarantees` <-> `guarantee_decisions`. Every guarantee *should* have a decision record (created lazily or at import).
*   **Explicit N:1**: `guarantee_decisions` -> `suppliers`.
*   **Explicit N:1**: `guarantee_decisions` -> `banks`.

## Data Storage vs. Derivation
*   **Stored**: Raw Data, Selected Entities, Lock Status.
*   **Derived**: "Status Badge" in UI is derived from `status` + `active_action`.
*   **Snapshot**: History preserves the state *as it was*, independent of current reference tables.
