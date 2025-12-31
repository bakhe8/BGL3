# Manual Testing Report - Active Action State

**Tester:** _________________  
**Date:** 2025-12-31  
**Environment:** Development (localhost:8000)  
**Branch:** feature/active-action-state

---

## Testing Instructions

### Setup
1. ✅ Migration completed successfully
2. ✅ Database has 4 guarantees:
   - 3 with `active_action = NULL`
   - 1 with `active_action = 'reduction'`

### How to Test

#### Test 1: Open Application
**URL:** http://localhost:8000

**Expected:**
- Page loads successfully
- Guarantee records displayed
- No JavaScript errors

**Result:** ☐ Pass ☐ Fail  
**Notes:** _______________________

---

#### Test 2: READY + Action (Reduction)
**Target:** Guarantee with `active_action = 'reduction'`

**Steps:**
1. Navigate to the guarantee that has reduction action
2. Check preview section
3. Look for event context badge

**Expected:**
- ✅ Preview visible
- ✅ Subject: "طلب تخفيض الضمان البنكي..."
- ✅ Intro: "طلب تخفيض الضمان البنكي الموضح أعلاه"
- ✅ Badge shows: "سياق الحدث: تخفيض 📉"

**Actual:**
- Preview visible: ☐ Yes ☐ No
- Subject correct: ☐ Yes ☐ No
- Intro correct: ☐ Yes ☐ No
- Badge visible: ☐ Yes ☐ No

**Result:** ☐ Pass ☐ Fail  
**Screenshot:** _______________________

---

#### Test 3: READY + NULL Action
**Target:** Guarantees with `active_action = NULL`

**Steps:**
1. Navigate to a guarantee with NULL action
2. Check preview section

**Expected:**
- ❌ No preview shown
- OR
- ⚠️ Message: "لا يوجد إجراء فعّال بعد"

**Actual:**
- Preview hidden: ☐ Yes ☐ No
- Message shown: ☐ Yes ☐ No
- Message text: _______________________

**Result:** ☐ Pass ☐ Fail  
**Notes:** _______________________

---

#### Test 4: Browser Console Check
**Steps:**
1. Open DevTools (F12)
2. Go to Console tab
3. Refresh page

**Expected:**
- ✅ No errors
- ✅ No warnings (or only minor warnings)

**Actual Errors:**
```
_______________________
_______________________
```

**Result:** ☐ Pass ☐ Fail

---

#### Test 5: Hidden Inputs Check
**Steps:**
1. Open DevTools → Elements tab
2. Search for `<input id="activeAction"`
3. Search for `<input id="decisionStatus"`

**Expected:**
- ✅ `#activeAction` exists
- ✅ `#decisionStatus` exists
- ✅ Values populated correctly

**Actual:**
- activeAction found: ☐ Yes ☐ No
- Value: _______________________
- decisionStatus found: ☐ Yes ☐ No
- Value: _______________________

**Result:** ☐ Pass ☐ Fail

---

#### Test 6: Timeline Navigation (Critical)
**Steps:**
1. Open a guarantee with timeline events
2. Click on any timeline event
3. Observe preview changes
4. Click "العودة للوضع الحالي"

**Expected:**
- ✅ Historical banner appears
- ✅ Preview changes (if event has subtype)
- ✅ Clicking "return" resets preview
- ✅ Badge updates correctly
- ✅ **NO DATABASE WRITES** (verify in code or DB)

**Actual:**
- Banner appears: ☐ Yes ☐ No
- Preview changes: ☐ Yes ☐ No
- Return works: ☐ Yes ☐ No
- Badge updates: ☐ Yes ☐ No

**Result:** ☐ Pass ☐ Fail  
**Notes:** _______________________

---

#### Test 7: Action Buttons (Extend/Reduce/Release)
**Steps:**
1. Click "تمديد" on a READY guarantee
2. Complete the action
3. Check database

**Expected:**
- ✅ Action succeeds
- ✅ `active_action` updated in DB
- ✅ Preview updates immediately
- ✅ Badge shows new action

**Verify in DB:**
```sql
SELECT id, guarantee_id, active_action 
FROM guarantee_decisions 
WHERE guarantee_id = [ID];
```

**Result:**
- Action executed: ☐ Yes ☐ No
- DB updated: ☐ Yes ☐ No
- active_action value: _______________________

**Result:** ☐ Pass ☐ Fail

---

## Quick Verification Queries

### Check Current State
```sql
SELECT 
    id,
    guarantee_id,
    status,
    active_action,
    active_action_set_at
FROM guarantee_decisions;
```

### After Testing Actions
```sql
SELECT 
    status,
    active_action,
    COUNT(*) as count
FROM guarantee_decisions
GROUP BY status, active_action;
```

---

## Common Issues & Solutions

### Issue: Preview not showing for reduction
**Possible Causes:**
- JavaScript not loading
- Hidden inputs not populated
- Status gate blocking

**Debug:**
```javascript
// In browser console
console.log(document.getElementById('activeAction').value);
console.log(document.getElementById('decisionStatus').value);
```

### Issue: Timeline navigation writes to DB
**Check:**
```sql
-- Run before clicking timeline
SELECT active_action FROM guarantee_decisions WHERE id = 1;

-- Click timeline event

-- Run again (should be unchanged)
SELECT active_action FROM guarantee_decisions WHERE id = 1;
```

---

## Summary

**Tests Passed:** ___ / 7  
**Tests Failed:** ___  

**Critical Issues:**
1. _______________________
2. _______________________

**Minor Issues:**
1. _______________________
2. _______________________

**Overall Status:** ☐ PASS ☐ FAIL ☐ NEEDS FIXES

---

## Decision

☐ **Approve for merge to main**  
☐ **Approve with minor fixes**  
☐ **Reject - needs rework**

**Reason:**
_________________________________
_________________________________

---

**Tested by:** _________________  
**Date:** _________________  
**Signature:** _________________
