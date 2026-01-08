# Authority Model

## Decision Ownership
Who owns the state of a guarantee?

1.  **The User (Analyst)**
    *   **Authority**: Absolute over `GuaranteeDecision`.
    *   **Capabilities**:
        *   Can override AI suggestions.
        *   Can select Supplier/Bank.
        *   Can trigger Actions (Extend, Reduce, Release).
    *   **Limitations**: 
        *   **Cannot modify `raw_data`** in `guarantees` table (Immutable).
        *   **Cannot delete `guarantee_history`** (Audit Trail is readonly).
        *   **Cannot edit a Released Guarantee** (`is_locked` prevents this).

2.  **The System (Importer)**
    *   **Authority**: Absolute over `guarantees` (Raw Data).
    *   **Capabilities**: Creates new records.
    *   **Limitations**: Does not overwrite existing `guarantee_decisions` unless a "Re-import/Update" is explicitly triggered (and even then, decision preservation logic applies).

## Gates and Locks

### 1. The Release Lock (`is_locked`)
*   **Mechanism**: `guarantee_decisions.is_locked = 1`.
*   **Effect**:
    *   **UI**: `records.controller.js` disables inputs based on DOM badges/state.
    *   **Backend (Security)**: 
        *   `api/extend.php`, `api/release.php`, `api/reduce.php` explicitly check `if ($decision['is_locked'])` and abort with 400 Bad Request.
        *   *Note*: `api/save-and-next.php` technically allows updates if called directly, but is functionally isolated from Released records by the "Ready -> Released" status flow.
*   **Authority**: Only the "Release" action can set this.

### 2. The Preview Gate
*   **Mechanism**: Logic in `records.controller.js` + `LetterBuilder`.
*   **Condition**: Preview is **BLOCKED** if:
    *   `status` is 'pending' (Supplier/Bank not selected).
    *   `active_action` is NULL (No action selected).
*   **Authority**: Enforced by Client-side controller logic (hiding section) and Server-side builder (placeholder return).

### 3. The Action Gate
*   **Mechanism**: `active_action` field.
*   **Effect**: Determines *which* letter is generated.
*   **Authority**: User explicitly selecting an action button (Extend/Reduce/Release).

## Contradictions / Overrides
*   **Manual Override**: The field `manual_override` in `guarantee_decisions` flags when a user explicitly chooses a supplier different from the AI/Raw suggestion. This creates a "Learning confirmation" event.
