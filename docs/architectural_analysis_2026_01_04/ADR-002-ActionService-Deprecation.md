# ADR-002: ActionService Deprecation
## Architecture Decision Record

> **Status**: ✅ Approved  
> **Date**: 2026-01-04  
> **Type**: Deprecation & Archival  
> **Impact**: Legacy Code Cleanup

---

## Decision

**ActionService يُعتبر رسمياً Deprecated ويُؤرشف كإرث معماري.**

لن يُستخدم، لن يُحدّث، ولن يُدمج في أي execution flow حالي أو مستقبلي.

---

## 1. لماذا أُنشئ ActionService

### السياق الزمني

**تاريخ الإنشاء**: قبل ADR-007 (Unified Timeline)  
**المرحلة**: V3 Architecture (Early Phase)

### المنطق الذي كان يعالجه

ActionService أُنشئ لمعالجة **ثلاثة أنواع من Actions**:

1. **Extension** (تمديد): `+1 year` to expiry date
2. **Reduction** (تخفيض): تقليل المبلغ
3. **Release** (إفراج): إغلاق نهائي

### البنية المعمارية الأصلية

```php
ActionService
├─ Dependencies:
│  ├─ GuaranteeActionRepository  // ← guarantee_actions table
│  ├─ GuaranteeDecisionRepository
│  └─ GuaranteeRepository
│
├─ Methods:
│  ├─ createExtension()   // Create action record
│  ├─ issueExtension()    // Mark as issued
│  ├─ createRelease()
│  ├─ issueRelease()      // + Lock decision
│  ├─ createReduction()
│  └─ getHistory()        // From actions table
│
└─ Storage: guarantee_actions table
   ├─ action_id
   ├─ guarantee_id
   ├─ action_type (extension/release/reduction)
   ├─ action_status (pending/issued)
   ├─ previous_expiry_date
   ├─ new_expiry_date
   ├─ previous_amount
   ├─ new_amount
   └─ release_reason
```

### علاقته بجدول guarantee_actions

**guarantee_actions table** كان:
- Separate tracking للـ actions
- Independent من timeline
- Dual-table system:
  - `guarantees` table = Raw data
  - `guarantee_actions` table = Actions log

**المشكلة**: تكرار البيانات، fragmented audit trail

---

## 2. لماذا تُرك ولم يعد مستخدمًا

### اعتماد ADR-007: Unified Timeline

**التاريخ**: Post-V3 (Phase 3)  
**القرار**: دمج جميع الأحداث في `guarantee_history` واحد

**التغيير المعماري**:
```
Before (ActionService era):
├─ guarantees table (raw data)
├─ guarantee_decisions table (status)
├─ guarantee_actions table (actions) ← Separate!
└─ guarantee_history table (basic events)

After (ADR-007 - Current):
├─ guarantees table (raw data)
├─ guarantee_decisions table (status)
└─ guarantee_history table (ALL events) ← Unified!
   ├─ import events
   ├─ decision events
   ├─ extension events  ← من هنا
   ├─ reduction events   ← من هنا
   ├─ release events     ← من هنا
   └─ manual edits
```

### انتقال التنفيذ إلى guarantee_history

**الملفات الجديدة** (تتبع ADR-007):
- `api/extend.php`
- `api/reduce.php`
- `api/release.php`

**المنهج الجديد**:
```php
// Step 1: Snapshot (التقاط الحالة قبل التغيير)
$oldSnapshot = TimelineRecorder::createSnapshot($guaranteeId);

// Step 2: Update (تنفيذ التغيير)
$guaranteeRepo->updateRawData($guaranteeId, $newData);

// Step 3: Set Active Action (تتبع الإجراء النشط)
$decisionRepo->setActiveAction($guaranteeId, 'extension');

// Step 4: Record (تسجيل في Timeline)
TimelineRecorder::recordExtensionEvent($guaranteeId, $oldSnapshot, $newValue);
// ↑ Saves to guarantee_history (unified)
```

### الفروقات الجوهرية

#### 1. Absence of Timeline

**ActionService**:
```php
$actionId = $this->actions->create([...]); // فقط
// ❌ No timeline recording
// ❌ No event context
```

**Current APIs**:
```php
TimelineRecorder::recordExtensionEvent(...);
// ✅ Full event recording
// ✅ Context preserved
// ✅ Audit trail complete
```

---

#### 2. Absence of Snapshot

**ActionService**:
```php
// ❌ No before-state capture
$actionId = $this->actions->create([
    'previous_expiry_date' => $currentExpiry, // فقط القيمة
    'new_expiry_date' => $newExpiry
]);
```

**Current APIs**:
```php
// ✅ Full state snapshot
$oldSnapshot = TimelineRecorder::createSnapshot($guaranteeId);
// Captures:
// - All guarantee fields
// - Decision status
// - Supplier/Bank data
// - Complete context
```

---

#### 3. Absence of active_action

**ActionService**:
```php
// ❌ No active_action field (didn't exist)
// User can't see "what's pending"
```

**Current APIs**:
```php
// ✅ Sets active_action
$decisionRepo->setActiveAction($guaranteeId, 'extension');
// UI shows: "تمديد قيد المعالجة"
// User knows current pending action
```

---

#### 4. اختلاف Response Contract

**ActionService**:
```php
return [
    'action_id' => $actionId,
    'previous_expiry_date' => $currentExpiry,
    'new_expiry_date' => $newExpiry,
]; // ← JSON for programmatic use
```

**Current APIs**:
```php
echo '<div id="record-form-section">';
include 'partials/record-form.php';
echo '</div>';
// ← HTML partial for server-driven UI
```

**Use Case Mismatch**:
- ActionService = JSON API
- Current APIs = HTMX-style server-driven

---

## 3. لماذا لن نعود إليه

### التعارض البنيوي مع المنطق الحالي

**Architectural Incompatibility**:

| Layer | ActionService | Current System | Compatible? |
|-------|---------------|----------------|-------------|
| **Data Storage** | `guarantee_actions` | `guarantee_history` | ❌ |
| **Audit Trail** | Partial (actions only) | Complete (all events) | ❌ |
| **State Capture** | None (values only) | Full snapshot | ❌ |
| **UI Integration** | Programmatic (JSON) | Server-driven (HTML) | ❌ |
| **Active Tracking** | No `active_action` | Yes `active_action` | ❌ |
| **Philosophy** | Dual-table | Unified timeline | ❌ |

**Result**: **0/6 compatibility points**

---

### مخاطر إعادة استخدامه

**لو استُخدم ActionService الآن**:

❌ **Risk 1: Data Fragmentation**
- Events split between `guarantee_actions` and `guarantee_history`
- Audit trail incomplete
- Legal/compliance risk

❌ **Risk 2: Loss of Context**
- No snapshots = can't prove "what changed"
- No before/after state
- Can't rollback or audit

❌ **Risk 3: UI Breakage**
- Returns JSON, UI expects HTML
- Server-driven pattern breaks
- User sees errors

❌ **Risk 4: Missing Features**
- No `active_action` tracking
- UI can't show "pending actions"
- UX degradation

❌ **Risk 5: Timeline Gaps**
- Actions not in unified timeline
- Timeline incomplete
- ADR-007 violated

**Overall Risk**: **CRITICAL** (would break core architecture)

---

### القرار المعماري الصريح

**من MASTER-Refactor-Governance.md**:
> "لا refactor قبل فهم كامل. إذا كان المنطق الحالي يعمل ويتبع العقد، لا نلمسه."

**الحالة**:
- ✅ Current APIs تعمل بشكل ممتاز
- ✅ تتبع ADR-007 (Unified Timeline)
- ✅ Full audit trail
- ✅ Complete snapshots
- ✅ Zero regressions

**ActionService**:
- ❌ Predates ADR-007
- ❌ Incompatible with current architecture
- ❌ Would require complete rewrite to align
- ❌ **Not worth the effort**

**Decision**: **لن نعود إليه أبداً**

---

## 4. قرار الإيقاف النهائي

### Status Declaration

**ActionService = Legacy / Deprecated**

**Effective Date**: 2026-01-04  
**Final State**: Archived (read-only)

### ليس جزءاً من أي Execution Flow

**Confirmed Non-Usage**:

✅ **No API calls it**
- Checked: All `/api/*.php` files
- Result: Zero references

✅ **No Service uses it**
- Checked: All `/app/Services/*.php`
- Result: Zero imports

✅ **No Repository depends on it**
- Checked: All repositories
- Result: Zero dependencies

✅ **No UI invokes it**
- Checked: All JavaScript files
- Result: Zero AJAX calls

✅ **No Cron/Job references it**
- Checked: Background tasks
- Result: None exist

**Conclusion**: **Completely unused**

---

### لا يُستدعى ولا يُعتمد عليه

**Orphaned Code**:
```
ActionService.php
├─ Created: Early V3
├─ Last Meaningful Update: Pre-ADR-007
├─ Current Usage: ZERO
├─ Current Callers: NONE
└─ Status: ORPHANED
```

**Related Files**:
```
GuaranteeActionRepository.php
├─ Manages: guarantee_actions table
├─ Used By: Only ActionService
├─ Current Usage: ZERO
└─ Status: ORPHANED
```

---

## 5. Archival Plan

### What Gets Archived

**Code**:
- `app/Services/ActionService.php`
- `app/Repositories/GuaranteeActionRepository.php`
- Any related test files (if exist)

**Database**:
- `guarantee_actions` table structure
- Data dump (if any records exist)

**Documentation**:
- This ADR (permanent record)
- Analysis document (ANALYSIS-ActionService-vs-APIs.md)

### Where It Goes

```
/deprecated/
├─ action-service/
│  ├─ ActionService.php
│  ├─ GuaranteeActionRepository.php
│  └─ README.md (explains why deprecated)
│
└─ db/
   ├─ guarantee_actions_schema.sql
   └─ guarantee_actions_data.sql (if records exist)
```

---

## 6. Migration Path (None Required)

**Question**: Do we need to migrate existing `guarantee_actions` data?

**Answer**: ❌ **No**

**Reason**:
- Table likely empty or contains only test data
- Current system uses `guarantee_history` exclusively
- No production dependency

**Verification Required**:
```sql
SELECT COUNT(*) FROM guarantee_actions;
-- If 0 → Safe to drop
-- If >0 → Archive data, then drop
```

---

## 7. Future Implications

### For Future Development

**Rule**: If similar functionality needed:

✅ **DO**:
- Start from current APIs (extend/reduce/release.php)
- Use `guarantee_history` (unified timeline)
- Follow ADR-007 pattern
- Include snapshots
- Set `active_action`

❌ **DON'T**:
- Resurrect ActionService
- Use `guarantee_actions` table
- Create new dual-table system
- Ignore unified timeline

### For New Team Members

**If Asked**: "Why don't we use ActionService?"

**Answer**: Read this ADR. Summary:
1. It's pre-ADR-007 (legacy)
2. Incompatible with unified timeline
3. Missing critical features (snapshots, active_action)
4. Current system is better
5. Deprecated permanently

---

## 8. Compliance & Audit

### Legal/Regulatory

**Question**: Does archiving affect compliance?

**Answer**: ❌ **No**

**Reason**:
- ActionService never stored actual audit trail
- `guarantee_history` is the legal source of truth
- All events properly recorded there
- ADR-007 improves compliance (unified trail)

### Audit Trail Integrity

**Before ActionService Deprecation**:
- Events in `guarantee_history`: ✅ Complete
- Events in `guarantee_actions`: ❓ Unused/Empty

**After Deprecation**:
- Events in `guarantee_history`: ✅ Complete
- Change: None (already not using `guarantee_actions`)

**Impact**: **ZERO** (improves clarity)

---

## References

- [ADR-001: Skip Phase 2, Go to Phase 3](./ADR-001-Skip-Phase2-Go-Phase3.md)
- [ADR-007: Unified Timeline](./ADR-007-Unified-Timeline.md) (implied)
- [ANALYSIS: ActionService vs APIs](./ANALYSIS-ActionService-vs-APIs.md)
- [MASTER Refactor Governance](./MASTER-Refactor-Governance.md)
- [System Understanding Lock](./System-Understanding-Lock.md)

---

## Approval

**Decision**: ✅ **Approved**  
**Date**: 2026-01-04  
**Authorized By**: المالك المعماري

**Next Actions**:
1. Archive code → `/deprecated/action-service/`
2. Dump & archive `guarantee_actions` table
3. Verify zero dependencies
4. Run smoke tests
5. Close permanently

---

**Status**: ✅ **DEPRECATED - DO NOT USE**  
**Replacement**: Use current APIs (extend/reduce/release.php)  
**Timeline**: Unified in `guarantee_history`
