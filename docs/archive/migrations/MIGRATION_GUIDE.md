# Migration Guide: Active Action State

**Date:** 2025-12-31  
**Feature Branch:** `feature/active-action-state`  
**Estimated Time:** 30 minutes

---

## Phase 0: Safety ⚠️

### 1. Create Feature Branch
```bash
git checkout -b feature/active-action-state
git push -u origin feature/active-action-state
```

### 2. Backup Database
```bash
# Export current database
mysqldump -u [user] -p bgl_db > backup_before_active_action_$(date +%Y%m%d_%H%M%S).sql

# Verify backup
ls -lh backup_*.sql
```

### 3. Create Rollback Point
```bash
# Tag current commit
git tag pre-active-action-migration
git push --tags
```

---

## Phase 1: Database Schema

### Run Migration
```bash
mysql -u [user] -p bgl_db < migrations/2025_12_31_add_active_action.sql
```

### Expected Output
```
Schema migration completed successfully
total_records: [number]
null_actions: [number]
```

### Verify
```sql
DESCRIBE guarantee_decisions;
-- Should show:
-- active_action VARCHAR(20) NULL
-- active_action_set_at TIMESTAMP NULL
```

---

## Phase 2: One-time Backfill

### Run Backfill Script
```bash
mysql -u [user] -p bgl_db < migrations/2025_12_31_backfill_active_action.sql
```

### Expected Output
```
Step 1: PENDING guarantees - affected_rows: [X]
Step 2: READY guarantees - total_ready: [Y], with_action: [Z]
Step 3: RELEASED guarantees - affected_rows: [W]
=== BACKFILL COMPLETE ===
```

### Manual Verification
```sql
-- Check distribution
SELECT status, active_action, COUNT(*) 
FROM guarantee_decisions 
GROUP BY status, active_action;

-- Expected:
-- pending,  NULL       -> All pending
-- approved, extension  -> Some
-- approved, reduction  -> Some
-- approved, release    -> Some
-- approved, NULL       -> Some (no action yet)
-- released, release    -> All released
```

---

## Phase 3: API Updates

**Files to Update:**
- `api/extend.php` (add `setActiveAction('extension')`)
- `api/reduce.php` (add `setActiveAction('reduction')`)
- `api/release.php` (add `setActiveAction('release')`)
- `app/Repositories/GuaranteeDecisionRepository.php` (add method)

**Code will be updated in next steps.**

---

## Phase 4: Frontend Current View

**Files to Update:**
- `partials/record-form.php` (add hidden inputs)
- `public/js/records.controller.js` (read from DB fields)

**Logic:**
```
IF status != READY
    → NO PREVIEW + "البيانات غير مؤكدة"

IF status == READY && active_action == NULL
    → NO PREVIEW + "لا يوجد إجراء فعّال"

IF status == READY && active_action exists
    → SHOW PREVIEW with action-specific content
```

---

## Phase 5: Historical View

**Logic:**
- Historical view uses **temporary** `viewAction` (no DB write)
- Current view uses **DB** `active_action`
- Clear separation

---

## Phase 6: Cleanup

- Remove Timeline dependency in current view
- Keep Timeline for history display only

---

## Rollback Plan

### If Migration Fails

#### Step 1: Restore Database
```bash
mysql -u [user] -p bgl_db < backup_before_active_action_YYYYMMDD_HHMMSS.sql
```

#### Step 2: Revert Code
```bash
git checkout main
git branch -D feature/active-action-state
```

#### Step 3: Verify
```bash
# Check database
mysql -u [user] -p bgl_db -e "DESCRIBE guarantee_decisions"
# Should NOT show active_action column
```

---

## Testing Checklist

After migration, verify:

- [ ] Schema: `active_action` column exists
- [ ] Backfill: All PENDING have `NULL`
- [ ] Backfill: READY with recent action have value
- [ ] Backfill: RELEASED have `release`
- [ ] APIs: Extend sets `active_action = 'extension'`
- [ ] APIs: Reduce sets `active_action = 'reduction'`
- [ ] APIs: Release sets `active_action = 'release'`
- [ ] Preview: Blocked on PENDING
- [ ] Preview: Works on READY + action
- [ ] Historical: Doesn't write to DB
- [ ] Timeline: Still displays correctly

---

## Success Criteria (Acceptance)

✅ **1. PENDING guarantees:**
- No preview shown
- Message: "البيانات غير مؤكدة وتحتاج مراجعة"

✅ **2. READY + no action:**
- No preview shown
- Message: "لا يوجد إجراء فعّال بعد"

✅ **3. READY + action:**
- Preview shows correct letter content
- Content matches action type

✅ **4. Historical view:**
- Selecting event changes preview
- Changes are temporary (view-only)
- No DB writes

✅ **5. Return to current:**
- Preview resets to DB `active_action`
- Historical state cleared

✅ **6. Timeline navigation:**
- Never writes to DB
- Only reads/displays

---

## Support

**Migration Issues:**
- Check `backup_*.sql` files exist
- Verify database user permissions
- Check error logs

**Code Issues:**
- See `docs/03-impact-analysis.md`
- Review `docs/04-adr-action-state.md`

---

**IMPORTANT:** Do NOT proceed to next phase if current phase fails.
