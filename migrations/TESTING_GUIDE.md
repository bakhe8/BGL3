# Manual Testing Guide: Active Action State

**Feature:** Explicit Active Action State  
**Date:** 2025-12-31  
**Tester:** ___________  
**Environment:** Development / Staging

---

## Pre-Testing Setup

### ☑️ Prerequisites
- [ ] Feature branch created: `feature/active-action-state`
- [ ] Database backup exists: `backup_before_active_action_*.sql`
- [ ] Migrations run successfully (Phase 1-2)
- [ ] Code deployed to test environment
- [ ] Browser cache cleared

---

## Test Session 1: Database Migration

### Test 1.1: Schema Verification
```sql
DESCRIBE guarantee_decisions;
```

**Expected:** Should show:
- `active_action` VARCHAR(20) NULL
- `active_action_set_at` TIMESTAMP NULL

**Result:** ☐ Pass ☐ Fail  
**Notes:** _______________________

---

###Test 1.2: Backfill Verification
```sql
SELECT status, active_action, COUNT(*) 
FROM guarantee_decisions 
GROUP BY status, active_action;
```

**Expected:**
- `pending, NULL` → All pending guarantees
- `approved, extension` → Some approved
- `approved, reduction` → Some approved
- `approved, release` → Some approved
- `approved, NULL` → Some approved (no action yet)
- `released, release` → All released

**Result:** ☐ Pass ☐ Fail  
**Actual Distribution:**
```
status    | active_action | count
----------|---------------|------
pending   | NULL          | ___
approved  | NULL          | ___
approved  | extension     | ___
approved  | reduction     | ___
approved  | release       | ___
released  | release       | ___
```

---

## Test Session 2: API Actions

### Test 2.1: Extension API

**Steps:**
1. Select a READY guarantee (has supplier + bank)
2. Click "تمديد" button
3. Confirm action

**Verify in Database:**
```sql
SELECT active_action, active_action_set_at 
FROM guarantee_decisions 
WHERE guarantee_id = [ID];
```

**Expected:**
- `active_action = 'extension'`
- `active_action_set_at` = current timestamp

**Result:** ☐ Pass ☐ Fail  
**Notes:** _______________________

---

### Test 2.2: Reduction API

**Steps:**
1. Select a READY guarantee
2. Click "تخفيض" button
3. Enter new amount
4. Confirm

**Verify:**
```sql
SELECT active_action, active_action_set_at 
FROM guarantee_decisions 
WHERE guarantee_id = [ID];
```

**Expected:**
- `active_action = 'reduction'`
- `active_action_set_at` = current timestamp

**Result:** ☐ Pass ☐ Fail  
**Notes:** _______________________

---

### Test 2.3: Release API

**Steps:**
1. Select a READY guarantee
2. Click "إفراج" button
3. Confirm

**Verify:**
```sql
SELECT active_action, is_locked, status 
FROM guarantee_decisions 
WHERE guarantee_id = [ID];
```

**Expected:**
- `active_action = 'release'`
- `is_locked = 1`
- `status = 'released'`

**Result:** ☐ Pass ☐ Fail  
**Notes:** _______________________

---

## Test Session 3: Frontend Current View

### Test 3.1: PENDING Guarantee (No Preview)

**Steps:**
1. Navigate to a PENDING guarantee (imported, not confirmed yet)
2. Check preview section

**Expected:**
- ❌ No preview/letter visible
- ✅ Message shown: "البيانات غير مؤكدة وتحتاج مراجعة"

**Result:** ☐ Pass ☐ Fail  
**Actual Message:** _______________________

---

### Test 3.2: READY + No Action (No Preview)

**Steps:**
1. Navigate to a READY guarantee with `active_action = NULL`
2. Check preview section

**Expected:**
- ❌ No preview/letter visible
- ✅ Message shown: "لا يوجد إجراء فعّال بعد"

**Result:** ☐ Pass ☐ Fail  
**Actual Message:** _______________________

---

### Test 3.3: READY + Extension (Preview Shows)

**Steps:**
1. Navigate to a READY guarantee with `active_action = 'extension'`
2. Check preview section

**Expected:**
- ✅ Preview visible
- ✅ Subject line: "طلب تمديد الضمان البنكي رقم..."
- ✅ Intro phrase: "طلب تمديد الضمان البنكي الموضح أعلاه"
- ✅ Event context badge shows: "سياق الحدث: تمديد 🔄"

**Result:** ☐ Pass ☐ Fail  
**Actual Intro Phrase:** _______________________

---

### Test 3.4: READY + Reduction (Preview Shows)

**Steps:**
1. Navigate to a READY guarantee with `active_action = 'reduction'`
2. Check preview section

**Expected:**
- ✅ Preview visible
- ✅ Subject line: "طلب تخفيض الضمان البنكي رقم..."
- ✅ Intro phrase: "طلب تخفيض الضمان البنكي الموضح أعلاه"
- ✅ Event context badge shows: "سياق الحدث: تخفيض 📉"

**Result:** ☐ Pass ☐ Fail  
**Notes:** _______________________

---

### Test 3.5: READY + Release (Preview Shows)

**Steps:**
1. Navigate to a READY guarantee with `active_action = 'release'`
2. Check preview section

**Expected:**
- ✅ Preview visible
- ✅ Subject line: "طلب الإفراج عن الضمان البنكي رقم..."
- ✅ Intro phrase: "طلب الإفراج عن الضمان البنكي الموضح أعلاه"
- ✅ Event context badge shows: "سياق الحدث: إفراج 📤"

**Result:** ☐ Pass ☐ Fail  
**Notes:** _______________________

---

## Test Session 4: Historical View (View-Only)

### Test 4.1: Navigate to Historical Extension Event

**Steps:**
1. Open a guarantee timeline
2. Click on an "extension" event in timeline
3. Observe preview changes

**Expected:**
- ✅ Preview updates to show extension content
- ✅ Historical banner appears
- ✅ "العودة للوضع الحالي" button visible

**Verify in DevTools Console:**
```
eventSubtype should be set temporarily (not written to DB)
```

**Result:** ☐ Pass ☐ Fail  
**Notes:** _______________________

---

### Test 4.2: Return to Current State

**Steps:**
1. While in historical view, click "العودة للوضع الحالي"
2. Observe preview changes

**Expected:**
- ✅ Historical banner disappears
- ✅ Preview resets to current `active_action` from DB
- ✅ Event context badge shows current action (if any)

**Verify:**
No database writes occurred during timeline navigation.

**Result:** ☐ Pass ☐ Fail  
**Notes:** _______________________

---

### Test 4.3: Timeline Navigation (No DB Writes)

**Steps:**
1. Click multiple timeline events
2. Check database `active_action` value
3. Return to current

**Expected:**
- Database `active_action` **UNCHANGED** during all timeline clicks
- Changes only on actual actions (extend/reduce/release buttons)

**Verify:**
```sql
-- Before timeline navigation
SELECT active_action FROM guarantee_decisions WHERE guarantee_id = [ID];

-- After clicking multiple events
SELECT active_action FROM guarantee_decisions WHERE guarantee_id = [ID];

-- Should be IDENTICAL
```

**Result:** ☐ Pass ☐ Fail  
**Notes:** _______________________

---

## Test Session 5: Edge Cases

### Test 5.1: Import New Guarantee

**Steps:**
1. Import a new guarantee from Excel
2. Check its `active_action`

**Expected:**
- `active_action = NULL`
- Status = `pending`

**Result:** ☐ Pass ☐ Fail  
**Notes:** _______________________

---

### Test 5.2: Auto-Match

**Steps:**
1. Let system auto-match a guarantee
2. Check `active_action`

**Expected:**
- `active_action` remains `NULL`
- Status may become `ready` if both supplier + bank matched

**Result:** ☐ Pass ☐ Fail  
**Notes:** _______________________

---

### Test 5.3: Manual Confirmation (PENDING → READY)

**Steps:**
1. Take a PENDING guarantee
2. Manually select supplier + bank
3. Save
4. Check `active_action`

**Expected:**
- Status changes to `ready`
- `active_action` remains `NULL` (no action yet)
- Preview NOT shown (no action)

**Result:** ☐ Pass ☐ Fail  
**Notes:** _______________________

---

### Test 5.4: Action on PENDING (Should Fail)

**Steps:**
1. Try to extend a PENDING guarantee
2. Check error message

**Expected:**
- ❌ Action blocked
- Error: "لا يمكن تمديد ضمان غير مكتمل..."

**Result:** ☐ Pass ☐ Fail  
**Actual Error:** _______________________

---

## Test Session 6: Browser Testing

### Test 6.1: Multi-Browser Compatibility

**Test in:**
- [ ] Chrome/Edge
- [ ] Firefox
- [ ] Safari (if available)

**Expected:**
- All functionality works identically
- Preview renders correctly
- Hidden inputs accessible

**Results:**
- Chrome/Edge: ☐ Pass ☐ Fail
- Firefox: ☐ Pass ☐ Fail
- Safari: ☐ Pass ☐ Fail

---

### Test 6.2: Console Errors

**Steps:**
1. Open DevTools Console
2. Perform all actions (extend/reduce/release)
3. Navigate timeline

**Expected:**
- ✅ No JavaScript errors
- ✅ No warnings

**Result:** ☐ Pass ☐ Fail  
**Errors Found:** _______________________

---

## Test Session 7: Performance

### Test 7.1: Page Load Time

**Steps:**
1. Measure page load time before migration
2. Measure page load time after migration
3. Compare

**Expected:**
- No significant performance degradation
- Difference < 100ms

**Results:**
- Before: _____ ms
- After: _____ ms
- Difference: _____ ms

**Result:** ☐ Pass ☐ Fail

---

###Test 7.2: Database Query Performance

**Steps:**
```sql
EXPLAIN SELECT * FROM guarantee_decisions WHERE active_action = 'extension';
```

**Expected:**
- Index used (`idx_active_action`)
- Reasonable execution time

**Result:** ☐ Pass ☐ Fail  
**Execution Time:** _____ ms

---

## Final Sign-Off

### Summary

**Total Tests:** 24  
**Passed:** _____  
**Failed:** _____  
**Skipped:** _____

### Critical Issues Found
1. _______________________
2. _______________________
3. _______________________

### Minor Issues Found
1. _______________________
2. _______________________

### Recommendations
☐ **Approve for Production**  
☐ **Approve with Minor Fixes**  
☐ **Reject - Major Issues**

### Notes
_________________________________
_________________________________
_________________________________

---

**Tested By:** ___________________  
**Date:** ___________________  
**Signature:** ___________________

---

## Rollback Procedure (If Needed)

If critical issues found:

1. Stop using feature
2. Restore database:
   ```bash
   mysql -u [user] -p bgl_db < backup_before_active_action_*.sql
   ```
3. Revert code:
   ```bash
   git checkout main
   ```
4. Notify team
5. Document issues in GitHub/Jira

---

**Next Steps:**
- If all pass → Merge to main
- If issues → Create bug tickets
- Update documentation based on findings
