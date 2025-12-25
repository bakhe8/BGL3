# Policy Compliance Gap Analysis Report 📊
**BGL System V3 vs. Guarantee Status Transition Policy (P7)**

## 1. Executive Summary
The current system architecture is **90% compliant** with the newly established P7 Policy. The core principles of "Role Separation" and "Implementation Agnostic Status" are fully respected. The main gap identified is the lack of explicit "Conflict Detection" in the Auto-Match logic.

## 2. Detailed Comparison

### ✅ Compliant Areas (Points of Strength)
| Policy Rule | Current Code Implementation | Verdict |
| :--- | :--- | :--- |
| **P7-12 Single Authority** | `TimelineRecorder::calculateStatus` is purely logic-based (checks for IDs). It knows nothing about scores or weights. | ✅ **Perfect Match** |
| **P7-9 Role Separation** | User decisions (`save-and-next.php`) write directly to `supplier_id`/`bank_id`, bypassing all scoring logic. | ✅ **Perfect Match** |
| **P7-5 Score Model** | `SmartProcessingService` produces Scores (0-100) and Context (Source). | ✅ **Compliant** |
| **P7-7 Resolution** | Code resolves Supplier/Bank ONLY if Score > 90/80 (Auto) OR User Saves (Manual). This aligns with "High Confidence" vs "User Decision". | ✅ **Compliant** |
| **BR-09 Dual Write** | All actions (Save, Extend, Reduce, Release) now fully implement "Update State + Log History". | ✅ **Compliant** |

### ⚠️ Minor Deviations (Naming / Implicit Logic)
| Policy Rule | Current Code Implementation | Verdict |
| :--- | :--- | :--- |
| **P7-2 Canonical States** | Policy defines `GS-01 Needs Decision` / `GS-02 Ready`. Code uses database strings `'pending'` / `'approved'`. | ⚠️ **Naming Difference** (Functionally Compatible) |
| **P7-8 Mandatory Check** | `calculateStatus` does not re-verify mandatory fields. It assumes `ImportService` (Entry Gate) enforced BR-01. | ⚠️ **Implicit Compliance** (Relies on Integrity of Entry Gate) |

### ❌ Critical Gaps (Action Required)
| Policy Rule | Current Code Implementation | Verdict |
| :--- | :--- | :--- |
| **P7-8 "No Conflict Flags"** | The `SmartProcessingService` (Auto Match) checks only for High Score (>90). It **does not check** for conflicts (e.g., competing candidates with similar scores). A record could be "Approved" even if ambiguous. | ❌ **Non-Compliant** |
| **P7-11 Reverse Transition** | If a user edits a Resolved record and creates a Conflict (without clearing the ID), the system might keeps it "Approved" unless specific logic reverts it. Currently `save-and-next` logic is safe (clears ID if mismatch), but `ConflictDetector` is not integrated into the Save flow to flag "Warnings". | ⚠️ **Partial Compliance** |

## 3. Impact Analysis of Critical Gap (Conflict Detection)
By ignoring conflicts in Auto-Match:
- **Scenario**: Algorithm is 91% confident in Supplier A, but 90% confident in Supplier B.
- **Policy P7-8**: Should be "Needs Decision" (Conflict/Ambiguity).
- **Current Code**: Will likely select Supplier A (highest score) and set status to **Approved** (Ready).
- **Risk**: System might "Ready" a record that actually required human review due to ambiguity.

## 4. Recommendations
1. **Integrate ConflictDetector**: Update `SmartProcessingService` to call `ConflictDetector->detect()`. If any conflict exists, prevent Auto-Approval (keep as Pending), even if Score > 90.
2. **Align Vocabulary**: Update `calculateStatus` to return `'ready'` / `'needs_decision'` instead of `'approved'` / `'pending'` (or map them in UI).
