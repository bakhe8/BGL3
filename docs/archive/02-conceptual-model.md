# Guarantee Conceptual Model (To-Be)

**Purpose:** Define the correct mental model for guarantee lifecycle, independent of current implementation.

**Date:** 2025-12-31  
**Status:** Agreed conceptual model (not yet implemented)

---

## 1. Core Definitions

### Status (PENDING / READY / RELEASED)

**Definition:**
```
Status = Data Confidence Level + Legal Safety Gate
```

**NOT:**
- ❌ Just "eligibility"
- ❌ Just "fields are filled"
- ❌ Indication of active action

**IS:**
- ✅ Trust level in data accuracy
- ✅ Safety gate for legal liability
- ✅ Human review checkpoint

---

### PENDING State

**Meaning:**  
> "Data is unverified and cannot be trusted for official/legal use"

**Causes:**
- Imported from Excel (raw data)
- Auto-matched by system (uncertain)
- Manual-matched but not confirmed (pending review)

**Implications:**
- ❌ NO letter preview allowed
- ❌ NO legal actions allowed (extend/reduce/release)
- ❌ NO legal liability accepted
- ⚠️ Requires human review

**Reason for Restrictions:**  
System does not trust data enough to generate official documents or perform legal actions.

---

### READY State

**Meaning:**  
> "Data has been verified and reviewed by human - safe for official use"

**Requirements:**
- ✅ Supplier ID confirmed
- ✅ Bank ID confirmed
- ✅ Human review completed (implicit)
- ✅ Critical fields validated

**Implications:**
- ✅ Letter preview allowed
- ✅ Legal actions allowed
- ✅ System accepts liability
- ✅ Official documents can be generated

**Reason for Permissions:**  
Data is trusted enough to carry legal/official weight.

---

### RELEASED State

**Meaning:**  
> "Guarantee lifecycle completed - data locked"

**Characteristics:**
- 🔒 Immutable
- 📜 Archived
- ❌ No further actions allowed

---

## 2. Active Action (Proposed Concept)

### Definition

**Active Action:**  
> "The current official procedure/intent that determines letter content"

**Values:**
- `NULL` - No active action (standard guarantee)
- `EXTENSION` - Extension request active
- `REDUCTION` - Reduction request active
- `RELEASE` - Release request active

**Key Point:**
```
READY ≠ Action exists
READY = Action is now SAFE to perform
```

---

## 3. Timeline (Audit Trail)

### Definition

**Timeline:**  
> "Immutable history of all events - for audit only"

**Purpose:**
- ✅ Record what happened
- ✅ Track who did what
- ✅ Compliance & audit trail

**NOT Used For:**
- ❌ Determining current state
- ❌ Deriving active action
- ❌ Business logic decisions

**Rule:**
```
Timeline = READ-ONLY history
Timeline ≠ Source of Truth for current state
```

---

## 4. The Three Pillars

```
┌─────────────────────────────────────────────────────────┐
│                    Guarantee State                       │
├─────────────────────────────────────────────────────────┤
│                                                          │
│  1. Status (Data Confidence)                            │
│     PENDING → READY → RELEASED                          │
│        ↑       ↑         ↑                              │
│        │       │         │                              │
│    Unverified│ Verified │ Locked                        │
│              │          │                               │
│  2. Active Action (Intent)                              │
│     NULL | EXTENSION | REDUCTION | RELEASE              │
│       ↑                                                  │
│       │                                                  │
│   Only if Status = READY                                │
│                                                          │
│  3. Timeline (History)                                  │
│     Immutable audit trail                               │
│       ↑                                                  │
│       │                                                  │
│   Read-only, never queried for state                    │
│                                                          │
└─────────────────────────────────────────────────────────┘
```

---

## 5. Fundamental Rules

### Rule #1: No Letter Without Verified Data
```
IF Status != READY
    THEN NO PREVIEW
    
Reason: Cannot generate official document from unverified data
```

### Rule #2: No Letter Without Active Action
```
IF Active_Action IS NULL
    THEN NO PREVIEW (or show generic template)
    
Reason: Letter content depends on action type
```

### Rule #3: Status is Independent of Action
```
READY does NOT mean "action exists"
READY means "action is now SAFE"

Example:
- Guarantee can be READY with Active_Action = NULL
- This is a valid state (standard guarantee, no action)
```

### Rule #4: Timeline Never Determines State
```
Current State comes from:
- Status field (guarantee_decisions.status)
- Active Action field (guarantee_decisions.active_action) [proposed]

NOT from:
- Latest timeline event
- Counting timeline events
```

---

## 6. Letter Preview Logic (Formal)

### Correct Logic

```
FUNCTION shouldShowPreview(status, activeAction):
    IF status != READY:
        RETURN FALSE  // Data not verified
    
    IF activeAction IS NULL:
        RETURN FALSE  // No active action
    
    RETURN TRUE  // Both conditions met

FUNCTION getLetterContent(activeAction):
    SWITCH activeAction:
        CASE 'EXTENSION':
            RETURN "طلب تمديد الضمان البنكي..."
        CASE 'REDUCTION':
            RETURN "طلب تخفيض الضمان البنكي..."
        CASE 'RELEASE':
            RETURN "طلب الإفراج عن الضمان البنكي..."
        CASE NULL:
            RETURN NULL  // No preview
        DEFAULT:
            RETURN "إشارة إلى الضمان البنكي..."
```

### Why This is Correct

- ✅ Status = Trust Gate (data safety)
- ✅ Active Action = Content Source (what to say)
- ✅ No inference from Timeline
- ✅ Single source of truth for each concern

---

## 7. State Transitions

### Valid Transitions

```
PENDING → READY
├─ Trigger: User confirms supplier + bank
├─ Validation: Both IDs must be set
└─ Effect: Actions become available

READY → READY (with Action change)
├─ Trigger: User clicks "تمديد" / "تخفيض" / "إفراج"
├─ Effect: Active_Action changes
└─ Note: Status remains READY

READY → RELEASED
├─ Trigger: Release action completed
├─ Effect: Data locked
└─ Note: Immutable after this
```

### Invalid Transitions

```
❌ PENDING → RELEASED (must pass through READY)
❌ RELEASED → Any other state (immutable)
```

---

## 8. Data Model (Proposed)

```sql
guarantee_decisions {
    id: INT PRIMARY KEY,
    guarantee_id: INT,
    
    -- Data Confidence + Legal Gate
    status: ENUM('pending', 'ready', 'released'),
    
    -- Current Official Action (NEW)
    active_action: ENUM('extension', 'reduction', 'release') NULL,
    active_action_created_at: TIMESTAMP NULL,
    
    -- Decision Details
    supplier_id: INT NULL,
    bank_id: INT NULL,
    decision_source: ENUM('auto', 'manual', 'system'),
    decided_by: VARCHAR(255),
    decided_at: TIMESTAMP,
    
    -- Lock mechanism
    is_locked: BOOLEAN DEFAULT FALSE,
    locked_reason: VARCHAR(255) NULL
}
```

---

## 9. Example Scenarios

### Scenario A: Standard Guarantee (No Action)
```
Status: READY
Active_Action: NULL
Preview: NO (no action to preview)
Actions Available: extend, reduce, release buttons enabled
```

### Scenario B: Extension Request
```
Status: READY
Active_Action: EXTENSION
Preview: YES (shows "طلب تمديد...")
Actions Available: Can change to different action or cancel
```

### Scenario C: Unverified Import
```
Status: PENDING
Active_Action: NULL (not allowed)
Preview: NO (data not verified)
Actions Available: None (blocked by gate)
```

---

## 10. Why This Model is Better

### Clarity
- ✅ Each concept has single responsibility
- ✅ No overlap between Status and Action
- ✅ Timeline is clearly audit-only

### Maintainability
- ✅ Adding new action types = add ENUM value
- ✅ No need to parse Timeline
- ✅ State is explicit, not inferred

### Testability
- ✅ Direct field checks
- ✅ No complex inference logic
- ✅ Predictable state

### Scalability
- ✅ Easy to add "Cancel Action" feature
- ✅ Easy to add "Replace Action" feature
- ✅ Easy to add multi-step workflows

---

## 11. Summary

### The Correct Mental Model

```
Status = "Is data trustworthy?"
Active Action = "What official procedure is active?"
Timeline = "What happened historically?"

These are THREE SEPARATE CONCERNS.
Mixing them creates implicit coupling.
```

### Non-Negotiable Rules

1. ✅ PENDING = Unverified → No legal actions
2. ✅ READY = Verified → Actions are safe
3. ✅ Timeline = History only (never queried for state)
4. ✅ Letter content = Direct function of Active Action

---

**Next:** See `03-impact-analysis.md` for migration study.
