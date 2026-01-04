# Deep Comparison: ActionService vs Current APIs
## Technical Analysis for Service Integration Feasibility

> **Date**: 2026-01-04  
> **Purpose**: تحديد إمكانية استخدام ActionService لتقليل duplication في APIs

---

## Executive Summary

**Result**: ❌ **ActionService لا يمكن استخدامه مباشرة**

**السبب الجوهري**: التعارض المنهجي الكامل بين:
- **ActionService approach**: Old dual-table system
- **Current APIs approach**: New unified timeline system

**التوصية**: إما تحديث ActionService كاملاً، أو تركه كما هو

---

## Detailed Comparison

### 1. Data Storage Approach

#### ActionService (Old):
```php
// Line 58-64 in ActionService.php
$actionId = $this->actions->create([
    'guarantee_id' => $guaranteeId,
    'action_type' => 'extension',
    'previous_expiry_date' => $currentExpiry,
    'new_expiry_date' => $newExpiry,
    'action_status' => 'pending',  // ← Saves to guarantee_actions
]);
```

**Where**: `guarantee_actions` table  
**Purpose**: Separate actions tracking  
**Status**: Old approach (pre-unified timeline)

---

#### Current APIs (New):
```php
// Lines 73-77 in extend.php
\App\Services\TimelineRecorder::recordExtensionEvent(
    $guaranteeId, 
    $oldSnapshot, 
    $newExpiry
);  // ← Saves to guarantee_history ONLY
```

**Where**: `guarantee_history` table  
**Purpose**: Unified timeline (all events in one place)  
**Status**: New approach (post-ADR-007)

---

### 2. Validation Logic

#### ActionService:
```php
// Lines 33-39
$decision = $this->decisions->findByGuarantee($guaranteeId);
if (!$decision || empty($decision->supplierId) || empty($decision->bankId)) {
    throw new \RuntimeException(
        'لا يمكن تنفيذ التمديد - يجب اختيار المورد والبنك أولاً'
    );
}
```

**What it checks**: Decision exists + supplier + bank

---

#### Current API (extend.php):
```php
// Lines 31-45
$statusCheck = $db->prepare("
    SELECT status 
    FROM guarantee_decisions 
    WHERE guarantee_id = ?
");
$statusCheck->execute([$guaranteeId]);
$currentStatus = $statusCheck->fetchColumn();

if ($currentStatus !== 'ready') {
    // Error + HTML response
    exit;
}
```

**What it checks**: Status = 'ready'

**Difference**: 
- ✅ Same goal (validate readiness)
- ⚠️ Different implementation (Service checks fields, API checks status)
- ⚠️ Different error handling (Service throws, API returns HTML)

---

### 3. Timeline/History Recording

#### ActionService:
```php
// Does NOT record timeline!
// Only creates action in guarantee_actions table
// No snapshot, no event details
```

**Timeline**: ❌ **NO**  
**Snapshot**: ❌ **NO**  
**Event details**: ❌ **NO**

---

#### Current API:
```php
// Line 53: Snapshot BEFORE
$oldSnapshot = \App\Services\TimelineRecorder::createSnapshot($guaranteeId);

// Lines 62-66: Update data
$raw['expiry_date'] = $newExpiry;
$guaranteeRepo->updateRawData($guaranteeId, json_encode($raw));

// Line 69: Set active action
$decisionRepo->setActiveAction($guaranteeId, 'extension');

// Lines 73-77: Record event
\App\Services\TimelineRecorder::recordExtensionEvent(
    $guaranteeId, 
    $oldSnapshot, 
    $newExpiry
);
```

**Timeline**: ✅ **YES** (guarantee_history)  
**Snapshot**: ✅ **YES** (before-state captured)  
**Event details**: ✅ **YES** (full context)

---

### 4. Response Format

#### ActionService:
```php
// Lines 66-70
return [
    'action_id' => $actionId,
    'previous_expiry_date' => $currentExpiry,
    'new_expiry_date' => $newExpiry,
];  // ← Returns array
```

**Format**: JSON array  
**Use case**: API/programmatic

---

#### Current API:
```php
// Lines 101-103
echo '<div id="record-form-section" class="decision-card">';
include __DIR__ . '/../partials/record-form.php';
echo '</div>';  // ← Returns HTML
```

**Format**: HTML partial  
**Use case**: Server-driven UI update (HTMX-style)

---

### 5. Active Action Setting

#### ActionService:
```php
// Does NOT set active_action
// This field didn't exist when Service was written
```

**Sets active_action**: ❌ **NO**

---

#### Current API:
```php
// Line 69
$decisionRepo->setActiveAction($guaranteeId, 'extension');
```

**Sets active_action**: ✅ **YES**  
**Purpose**: Track current pending action for UI

---

## Summary Table

| Aspect | ActionService | Current APIs | Compatible? |
|--------|---------------|--------------|-------------|
| **Data storage** | `guarantee_actions` | `guarantee_history` | ❌ Different tables |
| **Validation** | Check fields | Check status | ⚠️ Different approach |
| **Timeline recording** | ❌ None | ✅ Full | ❌ Missing critical feature |
| **Snapshot** | ❌ None | ✅ Yes | ❌ Missing critical feature |
| **Active action** | ❌ None | ✅ Yes | ❌ Missing critical feature |
| **Response format** | JSON array | HTML partial | ❌ Different contract |
| **Error handling** | Exception | HTML error + 400 | ❌ Different contract |

---

## Root Cause Analysis

### Why This Mismatch?

**Historical Evolution**:

```
Timeline:
├─ Phase 1 (Old): ActionService created
│  └─ Uses guarantee_actions table
│  └─ No unified timeline concept
│
├─ Phase 2 (ADR-007): Unified Timeline introduced
│  └─ All events → guarantee_history
│  └─ APIs updated to new approach
│  └─ ActionService NOT updated ← problem
│
└─ Phase 3 (Current): APIs fully migrated
   └─ ActionService left behind (legacy)
```

**Result**: ActionService is **legacy code** that predates the unified timeline architecture.

---

## Can We Use ActionService?

### Option 1: Use As-Is
**Answer**: ❌ **NO**

**Why not?**
1. ❌ Saves to wrong table (`guarantee_actions` vs `guarantee_history`)
2. ❌ Doesn't record timeline events
3. ❌ Doesn't capture snapshots
4. ❌ Doesn't set `active_action`
5. ❌ Returns wrong format (JSON vs HTML)
6. ❌ Wrong error handling (exception vs HTML)

**Impact**: Would **break** the unified timeline architecture

---

### Option 2: Update ActionService First
**Answer**: ⚠️ **Possible but complex**

**What needs updating**:
1. Change from `guarantee_actions` → `guarantee_history`
2. Add snapshot logic
3. Add timeline recording
4. Add `active_action` setting
5. Support HTML response (or keep APIs handling that)
6. Match validation approach

**Effort**: 4-6 hours  
**Risk**: Medium (touching core business logic)  
**Value**: Medium (reduces duplication after update)

---

### Option 3: Leave ActionService, Extract Helpers
**Answer**: ✅ **Better approach**

**What to do**:
```php
// Create shared validation helper
class ActionValidator {
    public static function requireReady($guaranteeId) {
        // Shared validation logic
    }
}

// Create shared timeline helper (already exists)
// TimelineRecorder (already good)

// Keep APIs using helpers, not full Service
```

**Effort**: 1-2 hours  
**Risk**: Low  
**Value**: Medium (some duplication reduced)

---

## Recommendation

### **Don't use ActionService as-is**

**Reasons**:
1. It's **architecturally incompatible** with current approach
2. It would **break unified timeline**
3. It would **lose audit trail** (no snapshots)
4. It would **break UI contracts** (wrong response format)

### **If you want to reduce duplication**:

**Short-term** (Low effort, low risk):
- Extract validation to helper function
- Extract common patterns (but keep timeline logic in APIs)
- Keep current APIs structure

**Long-term** (If duplication becomes painful):
- Rewrite ActionService to match unified timeline approach
- Then migrate APIs to use it
- **But**: Current duplication isn't painful enough to justify this

---

## Conclusion

**Question**: "هل ActionService يعكس المنطق المعتمد؟"

**Answer**: ❌ **لا**

**Details**:
- ActionService **predates** unified timeline
- APIs **follow** unified timeline (ADR-007)
- **Architectural mismatch** = cannot integrate directly
- **Would need complete rewrite** to make compatible

**Recommendation**: 
- **Skip Service Integration** for now
- **Keep Phase 1 success** (59% reduction achieved)
- **Only update ActionService** if real pain point emerges

---

**Status**: ✅ Analysis Complete  
**Decision**: Cannot use ActionService for integration  
**Next**: Close session as successful (Phase 1 complete)
