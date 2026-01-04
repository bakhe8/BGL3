# UX Lock Policy
**User Experience Locking Rules**

---

## 1. Purpose

The **UX Lock Policy** defines how the system prevents unintended modifications to:

- Guarantees that have exited the operational lifecycle (Released / Archived)
- Historical views of a guarantee (Timeline Snapshots)

This policy enforces protection **purely through User Experience (UI/UX)**  
without introducing additional back-end guards or altering data-layer logic.

---

## 2. Core Principle

> **If something must not be modified, the user must not see modification tools.**

Locking in this system is:
- Not a security mechanism
- Not a data integrity constraint
- A **user-experience constraint**

The system relies on:
- Hiding controls
- Disabling inputs
- Clear visual indicators

---

## 3. Lock Scenarios

### A. Released Guarantee (Archived State)

**Technical Indicator:**  
`is_locked = 1`

**UI Behavior:**
- Guarantee does **not** appear in default listings
- Excluded from navigation (Prev / Next)
- Accessible only via direct search (e.g. `?id=X`)
- Displayed in **read-only mode**
- Action buttons are hidden:
  - Extend
  - Reduce
  - Release
  - Save
- A clear visual banner is shown (e.g. 🔒 *Released Guarantee*)

**Intent:**
- Indicate that the guarantee:
  - Has completed its lifecycle
  - Is available for reference only

---

### B. Timeline Snapshot (Historical View)

**Indicator:**  
User is viewing a snapshot from the Timeline (not the current state)

**UI Behavior:**
- All editable fields are disabled (Supplier, Bank, etc.)
- **All action buttons are hidden**
- A visual banner indicates historical context (e.g. 🕓 *Historical Snapshot*)

**Intent:**
- Prevent any perception that:
  - Historical data can be modified
  - Actions may affect past states

---

### C. Active Guarantee (Current State)

**Indicator:**  
Latest snapshot + `is_locked = 0`

**UI Behavior:**
- Fields are editable
- Action buttons are visible and active

---

## 4. What This Policy Guarantees

- No modification of released guarantees
- No modification of historical snapshots
- Clear distinction between:
  - Active (live) state
  - Archived state
  - Historical views
- Consistent locking behavior across different contexts

---

## 5. What This Policy Explicitly Does NOT Cover

- No back-end API guards
- No service-layer restrictions
- No StatusEvaluator changes
- No auto-creation logic changes

> All restrictions are **UX-driven by design**, per system decisions.

---

## 6. Design Summary

> **UX Lock = Read-only by Design**  
> **Not a security lock**

This policy:
- Reduces architectural complexity
- Prevents human error
- Preserves system stability without over-engineering

---

**End of Document**
