# ActionService - Deprecated Code Archive

> **Status**: ❌ DEPRECATED  
> **Date Archived**: 2026-01-04  
> **Reason**: Architectural incompatibility with unified timeline (ADR-007)

---

## ⚠️ WARNING

**DO NOT USE THIS CODE**

This code is archived for historical reference only.

---

## Why This Code Exists Here

### Original Purpose
ActionService was created to handle guarantee actions (extend/reduce/release) using a dual-table system:
- `guarantee_actions` table for actions
- `guarantee_history` table for other events

### Why It Was Deprecated

**ADR-007 (Unified Timeline)** replaced the dual-table approach with a single source of truth:
- **Everything** goes to `guarantee_history`
- Includes full snapshots
- Includes `active_action` tracking
- Better audit trail

### Incompatibilities

ActionService **cannot** be integrated with current system because:
1. ❌ Uses `guarantee_actions` (deprecated table)
2. ❌ No snapshot logic
3. ❌ No `active_action` field
4. ❌ Returns JSON (APIs return HTML partials)
5. ❌ Predates ADR-007

---

## Current Replacement

**Instead of ActionService, use**:
- `api/extend.php`
- `api/reduce.php`
- `api/release.php`

**Pattern**:
```php
// 1. Snapshot
$snapshot = TimelineRecorder::createSnapshot($id);

// 2. Update
$repo->updateRawData($id, $newData);

// 3. Set Active Action
$decisionRepo->setActiveAction($id, 'extension');

// 4. Record
TimelineRecorder::recordExtensionEvent($id, $snapshot, $newValue);
```

---

## Files Archived

- `ActionService.php` - Main service class
- `GuaranteeActionRepository.php` - Repository for guarantee_actions table

---

## References

- [ADR-002: ActionService Deprecation](../../docs/architectural_analysis_2026_01_04/ADR-002-ActionService-Deprecation.md)
- [ANALYSIS: ActionService vs APIs](../../docs/architectural_analysis_2026_01_04/ANALYSIS-ActionService-vs-APIs.md)

---

**Last Updated**: 2026-01-04  
**Status**: Permanently Archived
