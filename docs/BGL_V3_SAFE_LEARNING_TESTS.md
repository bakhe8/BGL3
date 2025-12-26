# BGL V3 - Safe Learning Integration Tests

## Test Suite for Safe Learning Implementation

**Purpose:** Verify that learned aliases no longer trigger auto-approval and usage_count is controlled

---

## Test 1: Manual Decision with Learned Alias

### Setup:
```sql
-- Create a learned alias manually
INSERT INTO supplier_alternative_names 
(supplier_id, alternative_name, normalized_name, source, usage_count, created_at)
VALUES 
(10, 'ABC Corp', 'abc corp', 'learning', 1, datetime('now'));
```

### Test Steps:
1. Import guarantee with supplier name: "ABC Corp"
2. Run Smart Processing: `SmartProcessingService::processNewGuarantees()`
3. Check guarantee_decisions table for this guarantee

### Expected Result:
```
✅ No auto-decision created (guarantee remains in pending state)
✅ Error log contains: "[SAFE_LEARNING] Auto-approval blocked for guarantee #X"
✅ Guarantee visible in manual review queue
```

### Verification Query:
```sql
SELECT gd.status, gd.decision_source, g.id
FROM guarantees g
LEFT JOIN guarantee_decisions gd ON g.id = gd.guarantee_id
WHERE g.raw_data LIKE '%ABC Corp%'
ORDER BY g.id DESC
LIMIT 1;

-- Expected: status = NULL OR 'pending', decision_source = NULL
```

---

## Test 2: Official Supplier Still Auto-Approves

### Setup:
```sql
-- Ensure official supplier exists
SELECT id, official_name FROM suppliers WHERE id = 5;
-- Assume supplier #5 = "XYZ Industries"
```

### Test Steps:
1. Import guarantee with exact official name: "XYZ Industries"
2. Run Smart Processing
3. Check guarantee_decisions table

### Expected Result:
```
✅ Auto-decision created with source='auto'
✅ Status = 'approved'
✅ No error log entry about blocking
```

### Verification Query:
```sql
SELECT gd.status, gd.decision_source, gd.supplier_id
FROM guarantees g
JOIN guarantee_decisions gd ON g.id = gd.guarantee_id
WHERE g.raw_data LIKE '%XYZ Industries%'
ORDER BY g.id DESC
LIMIT 1;

-- Expected: status = 'approved', decision_source = 'auto', supplier_id = 5
```

---

## Test 3: Usage Count Only Increments for Manual

### Setup:
```sql
-- Create test alias
INSERT INTO supplier_alternative_names 
(supplier_id, alternative_name, normalized_name, source, usage_count, created_at)
VALUES 
(15, 'Test Company Ltd', 'test company ltd', 'learning', 1, datetime('now'));

-- Record alias_id
SELECT id FROM supplier_alternative_names WHERE alternative_name = 'Test Company Ltd';
-- Assume alias_id = 100
```

### Test Steps:
1. Manually save guarantee with supplier_id=15, raw_name="Test Company Ltd"
   - Via save-and-next.php endpoint
2. Check usage_count

### Expected Result:
```
✅ usage_count incremented to 2
✅ Error log contains: "[SAFE_LEARNING] Incremented usage_count for supplier_id=15"
```

### Verification Query:
```sql
SELECT usage_count, last_used_at
FROM supplier_alternative_names
WHERE id = 100;

-- Expected: usage_count = 2, last_used_at = recent timestamp
```

---

## Test 4: Session Load Blocks Learning (Fatigue Protection)

### Setup:
```sql
-- Simulate 20 decisions in last 30 minutes
INSERT INTO supplier_decisions_log 
(guarantee_id, raw_input, chosen_supplier_id, chosen_supplier_name, source, score, decided_at)
SELECT 
    1000 + rowid as guarantee_id,
    'Test Supplier ' || rowid as raw_input,
    1 as chosen_supplier_id,
    'Test' as chosen_supplier_name,
    'manual' as source,
    100 as score,
    datetime('now', '-' || (rowid * 1) || ' minutes') as decided_at
FROM (SELECT rowid FROM sqlite_master LIMIT 20);

-- Verify count
SELECT COUNT(*) FROM supplier_decisions_log 
WHERE decided_at >= datetime('now', '-30 minutes');
-- Should return >= 20
```

### Test Steps:
1. Attempt to save manual decision for new guarantee
2. With new supplier name (should trigger learning)
3. Check if alias was created

### Expected Result:
```
✅ Decision saved successfully
✅ NO new alias created
✅ Error log contains: "[SAFE_LEARNING] Learning blocked - session load too high (20 decisions in 30min)"
```

### Verification Query:
```sql
-- Check if any aliases created in last minute
SELECT COUNT(*) 
FROM supplier_alternative_names
WHERE created_at >= datetime('now', '-1 minute');

-- Expected: 0 (no new aliases despite manual decision)
```

---

## Test 5: Circular Learning Prevention

### Test Steps:
1. Call `learnFromDecision()` with:
```php
$input = [
    'supplier_id' => 20,
    'raw_supplier_name' => 'Another Corp',
    'source' => 'manual',
    'suggested_by_alias' => true  // KEY FLAG
];
```

2. Check if alias created

### Expected Result:
```
✅ NO alias created
✅ Error log contains: "[SAFE_LEARNING] Learning blocked - decision based on learned alias (circular)"
```

---

## Test 6: Official Name Conflict Detection

### Setup:
```sql
-- Create two suppliers with similar names
INSERT INTO suppliers (official_name, normalized_name) 
VALUES ('Global Trading Co', 'global trading co');
-- Assume supplier_id = 30

INSERT INTO suppliers (official_name, normalized_name) 
VALUES ('Regional Trading Co', 'regional trading co');
-- Assume supplier_id = 31
```

### Test Steps:
1. User selects supplier_id=30 ("Global Trading Co")
2. But raw name from Excel is "Regional Trading Co"
3. Attempt to learn alias

### Expected Result:
```
✅ NO alias created (conflict detected)
✅ Error log contains: "[SAFE_LEARNING] Learning blocked - raw name 'Regional Trading Co' conflicts with official supplier name"
```

---

## Test 7: Learned Alias Score is 0.90

### Verification:
```php
// In SupplierCandidateService::supplierCandidates()
$candidates = $service->supplierCandidates('abc corp');

// Find learning candidate
$learningCandidate = array_filter($candidates, function($c) {
    return $c['source'] === 'learning';
});

// Assert score
assert($learningCandidate[0]['score'] === 0.90);
```

### Expected Result:
```
✅ Score = 0.90 (not 1.0)
✅ is_learning flag = true
```

---

## Regression Test: Full Workflow

### Scenario: End-to-end guarantee processing

1. **Import:** Upload Excel with 10 guarantees
2. **Smart Processing:** Run auto-matching
3. **Manual Review:** Process 5 guarantees manually
4. **Extend:** Extend 2 guarantees
5. **Release:** Release 1 guarantee

### Verification Points:
- ✅ No learned aliases auto-approved
- ✅ Official suppliers auto-approved correctly
- ✅ Usage counts only incremented for manual decisions
- ✅ Session load tracked accurately
- ✅ All timeline events logged
- ✅ No duplicate aliases created

---

## Running Tests

### Method 1: Manual Testing via UI
1. Use browser to navigate through workflows
2. Check database after each action
3. Verify error logs

### Method 2: PHP Script
```php
// tests/safe_learning_test.php
require_once __DIR__ . '/../bootstrap.php';

$service = new SmartProcessingService(...);
$result = $service->processNewGuarantees();

// Assert no auto-approvals from learning
assert($result['auto_matched'] === 0 || checkNoLearningSource());

echo "✅ All tests passed\n";
```

### Method 3: SQL Verification
```bash
# After each test, run verification queries
sqlite3 database/bgl.db < docs/BGL_V3_AUDIT_QUERIES.sql
```

---

## Success Criteria

| Test | Status |
|------|--------|
| Manual decision with learned alias | ⏳ Pending |
| Official supplier auto-approve | ⏳ Pending |
| Usage count control | ⏳ Pending |
| Session load blocking | ⏳ Pending |
| Circular learning prevention | ⏳ Pending |
| Conflict detection | ⏳ Pending |
| Score = 0.90 verification | ⏳ Pending |
| Regression test | ⏳ Pending |

**All tests must pass before deployment.**

---

## Test Data Cleanup

```sql
-- After testing, clean up test data
DELETE FROM supplier_alternative_names WHERE alternative_name LIKE 'Test%';
DELETE FROM supplier_decisions_log WHERE guarantee_id >= 1000;
DELETE FROM suppliers WHERE official_name LIKE 'Test%';
```
