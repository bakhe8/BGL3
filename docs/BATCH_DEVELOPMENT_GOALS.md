# Batch System Implementation - Development Goals

**Branch**: `feature/batch-system`
**Start Date**: 2026-01-06
**Estimated Time**: 5.5 hours

---

## 🎯 Project Objectives

### Primary Goal
Implement a complete batch management system for guarantees that enables:
- Group operations on multiple guarantees
- Organized workflow management
- Batch printing capabilities
- Clear batch lifecycle (open → work → close)

### Key Principles
1. **Minimal Schema**: Only 3 user-defined fields in `batch_metadata`
2. **DRY**: Derive all other data from existing `guarantees` table
3. **No Timeline Interference**: Batches are organizational only
4. **import_source = Batch ID**: Single source of truth

---

## 📋 Approved Features

### Database
- [x] `batch_metadata` table (4 columns: id, import_source, batch_name, batch_notes, status)
- [x] **Removed**: `archived` status (not needed in phase 1)
- [x] Indexes on import_source and status

### Backend Services
- [x] ImportService updates:
  - Filename in import_source for Excel imports
  - Daily batches for manual entry (`manual_YYYYMMDD`)
  - Duplicate detection with timeline events
- [x] BatchService with 4 operations:
  - `extendBatch()` - group extend (all-or-nothing)
  - `closeBatch()` - mark as completed
  - `updateMetadata()` - edit name/notes (allowed even when closed)
  - `reopenBatch()` - reopen closed batch

### API Endpoints
- [x] `/api/batches.php` with 4 actions:
  - `extend`
  - `close`
  - `update_metadata` (new)
  - `reopen` (new)

### UI Pages
- [x] `views/batches.php` - List all batches (active + completed)
- [x] `views/batch-detail.php` - **Main batch page** with:
  - Batch info and metadata editing
  - Guarantees table with multi-select
  - Batch operations (extend/release)
  - Print preview button
  - Close/Reopen button
- [x] `views/batch-print-preview.php` - Preview ready/not-ready
- [x] Navigation link in header

---

## 🔒 Approved Decisions

### Decision #1: batch-detail.php is Essential
- **Status**: Required
- **Reason**: Main interface for batch work

### Decision #2: Metadata Editing Always Allowed
- **Status**: Allowed even on closed batches
- **Reason**: Metadata is descriptive only

### Decision #3: Print After Closure
- **Status**: Allowed (read-only/reprint)
- **Reason**: Useful for review/archiving

### Decision #4: Reuse Individual Logic
- **Status**: Required
- **Reason**: No new business logic for batch operations

### Decision #5: No archived Status
- **Status**: Removed from phase 1
- **Reason**: Not needed, adds complexity

### Decision #6: Orphaned Batches
- **Status**: Deferred
- **Reason**: Rare edge case

### Decision #7: Batch Reopening
- **Status**: Allowed with user confirmation
- **Reason**: Handle user errors gracefully

### Decision #8-10: Technical Checks
- GuaranteeRepository::findByNumber - check if exists
- SmartPasteService - check if exists
- batch-print.php - verify IDs support

### Decision #11: batch_metadata Creation
- **Status**: Manual only (not automatic)
- **Reason**: Keep DB clean, create only when needed

### Decision #12-13: Implicit Batches
- **Status**: Fully supported
- **Reason**: Batches work without metadata (active by default)

### Decision #14: Navigation
- **Status**: Link in header/main menu
- **Label**: "الدفعات"

---

## 📊 Implementation Phases

### Phase 1: Database (30 min)
- Create `batch_metadata` table
- Add indexes
- Verify

### Phase 2: ImportService (60 min)
- Filename in import_source
- Daily manual batches
- Duplicate handling
- Technical checks

### Phase 3: Batch Operations (90 min)
- BatchService class
- 4 API endpoints
- Batch operations logic

### Phase 4: UI (120 min)
- batches.php (list)
- batch-detail.php (main page)
- batch-print-preview.php
- Navigation link

### Phase 5: Testing (40 min)
- Technical verifications
- 7 test scenarios
- Documentation

---

## ✅ Success Criteria

1. User can see all batches (active and closed)
2. User can open a batch and see all guarantees
3. User can perform group operations (extend/release) - all-or-nothing
4. User can print with preview (ready/not-ready filter)
5. User can close a batch (disables group operations)
6. User can reopen a closed batch
7. User can edit batch name/notes anytime
8. Duplicate imports are logged in timeline
9. No data duplication (DRY principle maintained)
10. Timeline remains unaffected

---

## 🚫 Explicitly NOT Included

- ❌ batch_items junction table
- ❌ Session-based batch context
- ❌ Multi-batch membership for same guarantee
- ❌ archived status
- ❌ Automatic batch_metadata creation
- ❌ Orphaned batch cleanup (deferred)

---

## 📚 Reference Documents

1. [batch_concept.md](file:///C:/Users/Bakheet/Documents/Projects/BGL3/docs/batch_concept.md) - Final approved concept
2. [batch_technical_appendix.md](file:///C:/Users/Bakheet/Documents/Projects/BGL3/docs/batch_technical_appendix.md) - Technical details
3. [implementation_plan.md](file:///C:/Users/Bakheet/.gemini/antigravity/brain/389418eb-f8b9-4d57-bed3-f7060e28065d/implementation_plan.md) - Detailed implementation
4. [gaps_and_decisions_needed.md](file:///C:/Users/Bakheet/.gemini/antigravity/brain/389418eb-f8b9-4d57-bed3-f7060e28065d/gaps_and_decisions_needed.md) - Resolved gaps

---

**Last Updated**: 2026-01-06 00:37:00
**Status**: Ready to implement
**Backup Created**: Yes
**Git Branch**: feature/batch-system
