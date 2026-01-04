# Impact & Migration Study

**Purpose:** Analyze the cost and benefit of implementing explicit Active Action state.

**Date:** 2025-12-31  
**Decision Status:** Analysis complete - Implementation DEFERRED

---

## 1. What Would Change?

### Layer-by-Layer Impact

| Layer | Impact Level | Effort | Benefit |
|-------|-------------|--------|---------|
| **Database Schema** | 🔴 High | Medium | High (SSoT) |
| **API Endpoints** | 🟡 Medium | Low | High (Simplicity) |
| **Frontend Logic** | 🟢 Low | Low | High (Clarity) |
| **Timeline Logic** | 🟢 Low | Very Low | High (Decoupling) |
| **Testing** | 🟢 Low | Low | High (Direct checks) |

---

## 2. Database Changes

### Schema Migration

#### Add Column
```sql
-- Migration: 2025-01-XX_add_active_action_column.sql

ALTER TABLE guarantee_decisions 
ADD COLUMN active_action VARCHAR(20) NULL 
    COMMENT 'Current active action: extension, reduction, release, or NULL';

ALTER TABLE guarantee_decisions 
ADD COLUMN active_action_created_at TIMESTAMP NULL
    COMMENT 'When this action became active';

-- Optional: Add index for queries
CREATE INDEX idx_active_action 
ON guarantee_decisions(active_action);
```

#### Data Backfill (One-Time)
```sql
-- Backfill active_action from latest timeline event
-- (Run once after schema change)

UPDATE guarantee_decisions gd
SET active_action = (
    SELECT 
        CASE gh.event_type
            WHEN 'extension' THEN 'extension'
            WHEN 'reduction' THEN 'reduction'
            WHEN 'release' THEN 'release'
            ELSE NULL
        END
    FROM guarantee_history gh
    WHERE gh.guarantee_id = gd.guarantee_id
      AND gh.event_type IN ('extension', 'reduction', 'release')
    ORDER BY gh.created_at DESC
    LIMIT 1
);

-- For released guarantees
UPDATE guarantee_decisions 
SET active_action = 'release'
WHERE status = 'released' AND active_action IS NULL;
```

**Complexity:** Medium (requires careful testing)  
**Risk:** Low (non-destructive, backward compatible)

---

## 3. API Changes

### Current Flow (As-Is)
```php
// api/extend.php (current)

// 1. Gate check
if ($currentStatus !== 'approved') {
    exit('لا يمكن تمديد ضمان غير مكتمل');
}

// 2. Execute changes
$raw['expiry_date'] = $newExpiry;
$guaranteeRepo->updateRawData($guaranteeId, json_encode($raw));

// 3. Record in timeline
TimelineRecorder::recordExtensionEvent($guaranteeId, $oldSnapshot, $newExpiry);
```

### Proposed Flow (To-Be)
```php
// api/extend.php (proposed)

// 1. Gate check (UNCHANGED)
if ($currentStatus !== 'approved') {
    exit('لا يمكن تمديد ضمان غير مكتمل');
}

// 2. Execute changes (UNCHANGED)
$raw['expiry_date'] = $newExpiry;
$guaranteeRepo->updateRawData($guaranteeId, json_encode($raw));

// 3. NEW: Set Active Action
$decisionRepo->setActiveAction($guaranteeId, 'extension');

// 4. Record in timeline (UNCHANGED)
TimelineRecorder::recordExtensionEvent($guaranteeId, $oldSnapshot, $newExpiry);
```

### New Repository Method
```php
// app/Repositories/GuaranteeDecisionRepository.php

public function setActiveAction(int $guaranteeId, ?string $action): void
{
    $allowed = ['extension', 'reduction', 'release', null];
    
    if (!in_array($action, $allowed, true)) {
        throw new \InvalidArgumentException("Invalid action: $action");
    }
    
    $stmt = $this->db->prepare("
        UPDATE guarantee_decisions 
        SET active_action = ?,
            active_action_created_at = NOW()
        WHERE guarantee_id = ?
    ");
    
    $stmt->execute([$action, $guaranteeId]);
}
```

**Complexity:** Low (simple new method)  
**Risk:** Very Low (isolated change)

---

## 4. Frontend Changes

### Current Logic (As-Is)
```javascript
// public/js/records.controller.js (current)

// Read from temporary hidden input
const eventSubtypeInput = document.getElementById('eventSubtype');
const eventSubtype = eventSubtypeInput ? eventSubtypeInput.value : '';

if (eventSubtype === 'extension') {
    fullPhrase = 'طلب تمديد الضمان البنكي...';
}
```

### Proposed Logic (To-Be)
```javascript
// public/js/records.controller.js (proposed)

// Read directly from data attribute (set by server)
const activeAction = document.getElementById('activeAction')?.value;

if (activeAction === 'extension') {
    fullPhrase = 'طلب تمديد الضمان البنكي...';
}
```

### Server-Side Change
```php
// partials/record-form.php

<!-- BEFORE: Temporary event_subtype -->
<input type="hidden" id="eventSubtype" value="<?= $latestEventSubtype ?? '' ?>">

<!-- AFTER: Explicit active_action from DB -->
<input type="hidden" id="activeAction" 
       value="<?= $decision?->activeAction ?? '' ?>">
```

**Benefits:**
- ✅ No need for `hideEventContextBadge()` on return
- ✅ No temporary state management
- ✅ Simple direct read
- ✅ Server is source of truth

**Complexity:** Very Low (simple refactor)  
**Risk:** Very Low (straightforward replacement)

---

## 5. Timeline Changes

### Current Usage (As-Is)
```javascript
// Timeline is used to:
// 1. Display history (correct) ✅
// 2. Derive event_subtype for preview (implicit) ⚠️
```

### Proposed Usage (To-Be)
```javascript
// Timeline is used ONLY to:
// 1. Display history ✅

// Preview reads from active_action field directly
// Timeline becomes purely audit-only
```

**Complexity:** Very Low (removing logic, not adding)  
**Risk:** None (simplification)

---

## 6. Migration Plan (If Implemented)

### Phase 1: Schema (Week 1)
```
Day 1-2: Create migration script
Day 3:   Test on dev database
Day 4:   Backfill existing data
Day 5:   Deploy to staging
```

### Phase 2: Backend (Week 2)
```
Day 1-2: Add setActiveAction() method
Day 3-4: Update API endpoints (extend/reduce/release)
Day 5:   Integration tests
```

### Phase 3: Frontend (Week 3)
```
Day 1:   Update record-form.php to output active_action
Day 2:   Update records.controller.js preview logic
Day 3:   Remove event_context temporary state
Day 4-5: E2E testing
```

### Phase 4: Cleanup (Week 4)
```
Day 1-2: Remove event_subtype logic from Timeline
Day 3:   Update documentation
Day 4-5: Final QA
```

**Total Effort:** 3-4 weeks (part-time)  
**Risk Level:** Low (gradual, backward compatible)

---

## 7. Why NOT Implement Now?

### Current System Status
✅ System works correctly  
✅ No user complaints  
✅ No blocking issues  
✅ All requirements met

### Missing Use Cases
The following scenarios would justify implementation:

#### Use Case #1: Cancel Action
```
User initiates extension → Changes mind → Wants to cancel
Current: Cannot do this easily
With Active Action: Set active_action = NULL
```

#### Use Case #2: Replace Action
```
User has extension active → Decides to release instead
Current: Ambiguous (which action is "active"?)
With Active Action: Clear state transition
```

#### Use Case #3: Multi-Step Workflows
```
Extension request → Pending approval → Approved → Executed
Current: Hard to model
With Active Action: Natural state machine
```

### Current Workaround
- Users can still perform all actions
- Preview works via event_subtype (temporary state)
- No data loss or corruption

**Conclusion:** No pressing need NOW.

---

## 8. When to Implement?

### Triggers for Migration

Implement Active Action when **ANY** of these occur:

1. ✅ User requests "Cancel Action" feature
2. ✅ Need for action approval workflow
3. ✅ Multiple users editing same guarantee (race conditions)
4. ✅ Audit requirements need explicit state tracking
5. ✅ System complexity from Timeline inference becomes painful

**Estimated:** Within 6-12 months based on user feedback

---

## 9. Cost-Benefit Analysis

### Costs
| Item | Effort | Risk |
|------|--------|------|
| Schema migration | Medium | Low |
| Backend changes | Low | Very Low |
| Frontend refactor | Low | Very Low |
| Testing | Medium | Low |
| **Total** | **~3-4 weeks** | **Low** |

### Benefits
| Item | Impact |
|------|--------|
| Code clarity | High ✅ |
| Maintainability | High ✅ |
| Testability | High ✅ |
| Timeline decoupling | High ✅ |
| Scalability | High ✅ |
| **ROI** | **Positive** |

---

## 10. Backward Compatibility

### During Migration
```php
// Hybrid approach during transition:

function getActiveAction($guaranteeId) {
    // Try new field first
    $action = $decisionRepo->getActiveAction($guaranteeId);
    
    if ($action !== null) {
        return $action;  // New system
    }
    
    // Fallback to Timeline inference (legacy)
    return inferFromTimeline($guaranteeId);
}
```

### After Migration
```php
// Remove fallback, use active_action directly
function getActiveAction($guaranteeId) {
    return $decisionRepo->getActiveAction($guaranteeId);
}
```

**Strategy:** Gradual transition, no big-bang deployment

---

## 11. Rollback Plan

### If Migration Fails

#### Step 1: Keep Timeline Logic
```
Do NOT remove Timeline inference until new system is proven
```

#### Step 2: Feature Flag
```php
define('USE_ACTIVE_ACTION', false);  // Quick disable switch
```

#### Step 3: Data Intact
```sql
-- active_action column remains (no data loss)
-- Can revert code without schema rollback
```

**Risk Mitigation:** Very Low (non-destructive approach)

---

## 12. Summary

### Decision Matrix

| Factor | Current (Timeline-based) | Proposed (Active Action) |
|--------|-------------------------|--------------------------|
| **Works?** | ✅ Yes | ✅ Yes |
| **Maintainable?** | ⚠️ Medium | ✅ High |
| **Scalable?** | ⚠️ Medium | ✅ High |
| **Cost to change?** | N/A | 🟡 3-4 weeks |
| **Risk?** | N/A | 🟢 Low |
| **ROI?** | N/A | ✅ Positive |

### Final Recommendation

**✅ Defer implementation**

**Reason:**
- Current system works
- No blocking issues
- Migration is safe and gradual when needed
- Benefits are clear but not urgent

**When to revisit:**
- User requests cancel/replace action
- Timeline inference becomes maintenance burden
- Team bandwidth available (3-4 weeks)

---

**Next:** See `04-adr-action-state.md` for architectural decision record.
