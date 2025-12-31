# Current System Behavior (As-Is)

**Purpose:** Document the current system implementation based on actual code, not assumptions.

**Date:** 2025-12-31  
**Version:** 3.0 (Current)

---

## 1. Sources of Truth

| Element | Current Source | Location in Code | Notes |
|---------|---------------|------------------|-------|
| **Status** (READY/PENDING) | `guarantee_decisions.status` (DB) | `app/Models/GuaranteeDecision.php` | Used as Gate for preview/actions |
| **Letter Content** | Timeline `event_subtype` | `partials/timeline-section.php` | Inferred, not explicit |
| **Preview State** | DOM + JavaScript | `public/js/records.controller.js` | Derived view, not source of truth |
| **Action History** | `guarantee_history` table | `app/Services/TimelineRecorder.php` | Audit trail only |

---

## 2. How Letter Preview is Currently Blocked

### Implementation

```javascript
// File: public/js/records.controller.js (lines 109-124)
// ===== LIFECYCLE GATE: Status Check =====

const statusBadge = document.querySelector('.status-badge, .badge');
if (statusBadge) {
    const isPending = statusBadge.classList.contains('badge-pending') ||
        statusBadge.textContent.includes('يحتاج قرار');
    
    if (isPending) {
        console.log('⚠️ Preview update blocked: guarantee status is pending');
        return; // Exit early - no preview update
    }
}
```

### Logic

- **Gate:** `Status === PENDING`
- **Reason (Implicit):** Data is unverified/unconfirmed
- **Effect:** No preview update allowed

### What Triggers READY?

```php
// File: app/Services/StatusEvaluator.php
public static function evaluate($supplierId, $bankId): string
{
    if (!empty($supplierId) && !empty($bankId)) {
        return 'ready';  // Both critical fields confirmed
    }
    return 'pending';
}
```

**Requirement:** Both `supplier_id` AND `bank_id` must be set.

---

## 3. How Letter Content is Currently Determined

### Implementation

```javascript
// File: public/js/records.controller.js (lines 162-186)

// Read event_subtype from hidden input
const eventSubtypeInput = document.getElementById('eventSubtype');
const eventSubtype = eventSubtypeInput ? eventSubtypeInput.value : '';

// Determine letter phrase
if (isHistoricalView && eventSubtype) {
    if (eventSubtype === 'extension') {
        fullPhrase = 'طلب تمديد الضمان البنكي الموضح أعلاه';
    } else if (eventSubtype === 'reduction') {
        fullPhrase = 'طلب تخفيض الضمان البنكي الموضح أعلاه';
    } else if (eventSubtype === 'release') {
        fullPhrase = 'طلب الإفراج عن الضمان البنكي الموضح أعلاه';
    }
} else {
    // Fallback to type-based logic
    if (typeRaw.includes('Final')) {
        fullPhrase = 'إشارة إلى الضمان البنكي النهائي الموضح أعلاه';
    } else if (typeRaw.includes('Advance')) {
        fullPhrase = 'إشارة إلى ضمان الدفعة المقدمة البنكي الموضح أعلاه';
    }
}
```

### Logic Flow

1. **Source:** `event_subtype` from latest timeline event
2. **Storage:** Temporary hidden input (`#eventSubtype`)
3. **Usage:** Preview reads from hidden input
4. **Cleanup:** Removed when returning to current state

**Key Point:** Letter content is **inferred from Timeline**, not stored explicitly.

---

## 4. Action APIs (extend/reduce/release)

### Gate Implementation

```php
// File: api/extend.php (lines 30-45)

$statusCheck = $db->prepare("
    SELECT status 
    FROM guarantee_decisions 
    WHERE guarantee_id = ?
");
$statusCheck->execute([$guaranteeId]);
$currentStatus = $statusCheck->fetchColumn();

if ($currentStatus !== 'approved') {
    http_response_code(400);
    echo 'لا يمكن تمديد ضمان غير مكتمل. يجب اختيار المورد والبنك أولاً.';
    exit;
}
```

### Pattern

- Same gate in: `api/extend.php`, `api/reduce.php`, `api/release.php`
- **Requirement:** `status === 'approved'` (alias for READY)
- **Reason:** Prevent legal actions on unverified data

---

## 5. Where Conceptual Mixing Exists

### Issue #1: Status Serves Dual Purpose

```
PENDING/READY is used as:
├─ Data Confidence Indicator (implicit)
└─ Legal Action Gate (explicit)

But "Data Confidence" is NOT documented anywhere in code.
```

### Issue #2: Action is Inferred, Not Explicit

```
Current Flow:
User clicks "تمديد" → API executes → Timeline records event
                                    └─ event_subtype = 'extension'

Missing:
No explicit "active_action" field in guarantee_decisions table
```

**Result:** System works, but lacks clear separation between:
- "Data is trustworthy" (Status)
- "An official action is active" (missing concept)

### Issue #3: Timeline Used for Two Purposes

```
Timeline currently serves as:
├─ Audit Trail (correct usage)
└─ Source for deriving active action (implicit usage)
```

---

## 6. Data Flow Diagram (Current)

```
┌─────────────┐
│   Import    │
└──────┬──────┘
       │
       v
┌─────────────┐         ┌──────────────┐
│  PENDING    │────────>│  Timeline    │
│  (Status)   │         │  (History)   │
└──────┬──────┘         └──────┬───────┘
       │                       │
       │                       │
  User selects                 │
  Supplier+Bank                │
       │                       │
       v                       │
┌─────────────┐                │
│   READY     │                │
│  (Status)   │                │
└──────┬──────┘                │
       │                       │
       ├─────> Preview reads ──┘ (via event_subtype)
       │
       └─────> Actions allowed
```

---

## 7. Key Files Reference

| File | Purpose | Lines of Interest |
|------|---------|-------------------|
| `public/js/records.controller.js` | Preview logic + Status gate | 109-124, 162-186 |
| `public/js/timeline.controller.js` | Event context handling | 98-107, 337-339 |
| `app/Services/StatusEvaluator.php` | Status determination | 13-20 |
| `api/extend.php`, `reduce.php`, `release.php` | Action gates | 30-45 (each) |
| `partials/record-form.php` | Event context badge | 36-51 |

---

## 8. Summary

### What Works Well
✅ Status gate prevents premature preview/actions  
✅ Timeline records all events immutably  
✅ Preview updates from Data Card (Card-Driven)

### What is Implicit
⚠️ READY = "Data Confidence" (not documented)  
⚠️ Action state inferred from Timeline  
⚠️ event_subtype as temporary View State

### No Bugs, But...
This is a working MVP.  
Conceptual separation between "Status" and "Active Action" exists in logic, but not in schema.

---

**Next:** See `02-conceptual-model.md` for the ideal mental model.
