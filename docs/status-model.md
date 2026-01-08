# Status Model (Truth Table)

## Status Types

| Status | Meaning | Authority |
| :--- | :--- | :--- |
| **Pending** | Data incomplete. Action blocked. | Default if rules not met. |
| **Ready** | Data complete. Action allowed. | `StatusEvaluator`. |
| **Released** | Final state. Read-only. | `is_locked = 1` in DB. |

## Truth Table (`StatusEvaluator`)

| Supplier ID | Bank ID | Result Status | UI Badge |
| :--- | :--- | :--- | :--- |
| `NULL` | `NULL` | `pending` | ⚠️ يحتاج قرار |
| `123` | `NULL` | `pending` | ⚠️ يحتاج قرار |
| `NULL` | `456` | `pending` | ⚠️ يحتاج قرار |
| `123` | `456` | **`ready`** | ✅ جاهز |

## The "Active Action" State
The `active_action` column in `guarantee_decisions` does **NOT** change the primary status (Pending/Ready). It is a secondary state that modifies the View (Preview Generation).

*   `Status = Ready` + `Active Action = NULL` -> **Placeholder View** ("No Action").
*   `Status = Ready` + `Active Action = 'extension'` -> **Extension Letter View**.

## Evidence Index
*   **Status Logic**: `App/Services/StatusEvaluator.php` (Lines 27-35).
*   **Database Source**: `App/Services/StatusEvaluator.php` (Lines 43-63 `evaluateFromDatabase`).
*   **Pending Definition**: `App/Services/StatusEvaluator.php` (Lines 91-106).
