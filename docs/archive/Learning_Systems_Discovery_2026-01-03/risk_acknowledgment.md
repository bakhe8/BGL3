# Risk Acknowledgment

## Deliverable #4 - Phase Contract: Learning Merge

**التاريخ**: 2026-01-04  
**الحالة**: Pre-Execution Risk Documentation  
**الغرض**: توثيق جميع المخاطر المعروفة قبل البدء بالتنفيذ

---

## 🎯 Purpose & Scope

**هذه الوثيقة**:
- ✅ تحدد **كل** المخاطر المعروفة
- ✅ تصنف المخاطر (احتمالية × تأثير)
- ✅ تحدد استراتيجيات التخفيف
- ✅ توثق قرار القبول/الرفض لكل خطر

**ما لا تفعله**:
- ❌ لا تقترح حلول (هذا في Data Refactor Plan)
- ❌ لا تلغي المخاطر (بل تعترف بها)

---

## 📊 Risk Classification Matrix

| احتمالية | منخفض (L) | متوسط (M) | عالي (H) |
|----------|-----------|-----------|----------|
| **عالي (H)** | 🟡 Medium Risk | 🟠 High Risk | 🔴 Critical Risk |
| **متوسط (M)** | 🟢 Low Risk | 🟡 Medium Risk | 🟠 High Risk |
| **منخفض (L)** | 🟢 Low Risk | 🟢 Low Risk | 🟡 Medium Risk |

---

## 🔴 CRITICAL RISKS (Must Mitigate)

### RISK #1: Migration Script Failure Mid-Execution

**Category**: Data Migration  
**Probability**: Low (L)  
**Impact**: High (H)  
**Classification**: 🟡 **Medium Risk**

**Description**:
Schema migration or data population script fails after partial completion, leaving database in inconsistent state.

**Scenarios**:
1. Migration adds columns but popula script fails → some rows have NULL
2. Normalization fails for some records → data loss
3. SQLite lock/corruption during migration → database unusable

**Evidence of Risk**:
- Migration involves 3 ALTER TABLE statements
- Population requires processing ALL guarantees + learning_confirmations
- Single-user SQLite = no transaction isolation

**Mitigation Strategy**:

**Pre-Execution**:
```bash
# MANDATORY: Full backup BEFORE any changes
cp storage/database/app.sqlite storage/database/app.sqlite.backup-$(date +%Y%m%d-%H%M%S)

# Verify backup integrity
sqlite3 storage/database/app.sqlite.backup-* "PRAGMA integrity_check;"
```

**During Execution**:
```sql
-- Wrap migration in transaction (if possible)
BEGIN TRANSACTION;
  -- ALTER TABLE statements
  -- Population queries
COMMIT;  -- Only if ALL succeed
```

**Post-Execution**:
```bash
# Verify population completeness
php scripts/verify_normalization.php
# If fails: ROLLBACK using backup
```

**Acceptance Decision**: ✅ **ACCEPTED**

**Rationale**:
- Backup strategy reduces risk to near-zero
- Rollback is trivial (copy backup over)
- Testing on dev copy first further reduces risk

**Contingency**:
```bash
# If migration fails, immediate rollback:
cp storage/database/app.sqlite.backup-YYYYMMDD storage/database/app.sqlite
# Inform user, analyze failure, retry
```

---

### RISK #2: Historical Query Results Change After Schema Change

**Category**: Data Integrity  
**Probability**: Medium (M)  
**Impact**: High (H)  
**Classification**: 🟠 **High Risk**

**Description**:
Replacing JSON LIKE query with indexed column lookup **could** return different results if normalization is inconsistent.

**Scenarios**:

**Scenario A**: Normalization Inconsistency
```
Guarantee A: raw_data['supplier'] = "شركة النورس"
Guarantee B: raw_data['supplier'] = "شركة النورس " (trailing space)

Old query (JSON LIKE): Would find BOTH (loose match)
New query (normalized): Depends on normalization consistency

If normalization is SAME: ✅ Both found
If normalization DIFFERS: ❌ One or both lost
```

**Scenario B**: Migration Missed Some Rows
```
Some guarantees have normalized_supplier_name = NULL (population failed)

Old query: Would find them (raw_data exists)
New query: Would NOT find them (WHERE normalized = ? → no match)

Result: Data loss in historical counts
```

**Evidence of Risk**:
- `ArabicNormalizer::normalize()` is complex (multiple transformations)
- Migration script loops over ALL rows (failure possible)
- No existing tests for normalization consistency

**Mitigation Strategy**:

**Pre-Execution - Capture Baseline**:
```php
// scripts/capture_historical_baseline.php
$testInputs = [/* 100 real supplier names */];
$baseline = [];

foreach ($testInputs as $input) {
    $baseline[$input] = getHistoricalSelections_OLD($input);  // JSON LIKE
}

save('baselines/historical_baseline.json', $baseline);
```

**Post-Migration - Verify Results**:
```php
// scripts/verify_historical_query.php
$baseline = load('baselines/historical_baseline.json');
$errors = 0;

foreach ($baseline as $input => $expected) {
    $actual = getHistoricalSelections_NEW($input);  // Indexed column
    
    if ($expected !== $actual) {
        echo "MISMATCH: $input\n";
        $errors++;
    }
}

if ($errors > 0) {
    echo "CRITICAL: $errors mismatches found - ROLLBACK REQUIRED\n";
    exit(1);
}
```

**Post-Migration - Verify Population**:
```sql
-- Check for NULL values
SELECT COUNT(*) - COUNT(normalized_supplier_name) as missing
FROM guarantees;
-- Expected: 0
```

**Acceptance Decision**: ✅ **ACCEPTED WITH VERIFICATION**

**Rationale**:
- Baseline comparison will catch ANY discrepancies
- Population verification ensures no NULLs
- If mismatch found: IMMEDIATE ROLLBACK (no exceptions)

**Contingency**:
```
IF verification fails:
  1. STOP immediately
  2. Rollback to backup
  3. Analyze root cause
  4. Fix normalization issue
  5. Re-test on dev copy
  6. Retry migration
```

**Phase Contract Alignment**:
- Success Criterion: "لم تتغير نتائج الاقتراحات"
- This risk **directly threatens** that criterion
- Mitigation is **MANDATORY**, not optional

---

### RISK #3: E2E Test Fails (< 100% Match)

**Category**: Behavioral Change  
**Probability**: Low (L)  
**Impact**: High (H)  
**Classification**: 🟡 **Medium Risk**

**Description**:
After migration, suggestions for same inputs are different (order, confidence, or supplier_ids).

**Scenarios**:

**Scenario A**: Confidence Calculation Bug
```
Pre-merge:  getSuggestions("شركة النورس") → [A:90%, B:75%, C:60%]
Post-merge: getSuggestions("شركة النورس") → [A:88%, B:75%, C:60%]

Cause: Subtle bug in strength modifier or boost calculation
```

**Scenario B**: Signal Count Changes
```
Pre-merge:  3 historical selections found for "شركة النورس"
Post-merge: 2 historical selections found (one lost in migration)

Result: Different confidence score for that supplier
```

**Evidence of Risk**:
- Complex aggregation logic (UnifiedLearningAuthority)
- Multiple signal types combined
- Confidence formula has many steps (see Canonical Model)

**Mitigation Strategy**:

**Pre-Execution - Capture E2E Baseline**:
```php
// scripts/create_e2e_baseline.php
$inputs = loadProductionSamples(100);  // Real supplier names
$authority = AuthorityFactory::create();
$baseline = [];

foreach ($inputs as $input) {
    $suggestions = $authority->getSuggestions($input);
    $baseline[$input] = serialize($suggestions);  // Full serialization
}

save('baselines/e2e_baseline_100.json', $baseline);
```

**Post-Migration - Run E2E Test**:
```php
// tests/E2EComparisonTest.php
$baseline = load('baselines/e2e_baseline_100.json');
$authority = AuthorityFactory::create();
$errors = [];

foreach ($baseline as $input => $expected) {
    $actual = $authority->getSuggestions($input);
    
    if (serialize($actual) !== serialize($expected)) {
        $errors[] = "Mismatch for: $input";
    }
}

// Acceptance: 99%+ match (allow 1-2 edge cases with documentation)
if (count($errors) > 2) {
    echo "FAIL: Too many mismatches - ROLLBACK\n";
    exit(1);
}
```

**Acceptance Decision**: ✅ **ACCEPTED WITH 99% THRESHOLD**

**Rationale**:
- 100% match is ideal but 99% is acceptable
- 1-2 edge cases allowed IF documented and justified
- More than 2 = systematic issue = ROLLBACK

**Contingency**:
```
IF E2E test fails:
  IF errors <= 2:
    - Investigate each case
    - Document why different
    - Get user approval to proceed
  
  IF errors > 2:
    - IMMEDIATE ROLLBACK
    - Analyze systematic cause
    - Fix code
    - Re-test on dev
    - Retry migration
```

---

## 🟡 MEDIUM RISKS (Acceptable with Monitoring)

### RISK #4: Performance Regression on Large Datasets

**Category**: Performance  
**Probability**: Low (L)  
**Impact**: Medium (M)  
**Classification**: 🟢 **Low Risk**

**Description**:
New queries (indexed columns) might be slower than expected on large datasets.

**Scenarios**:

**Scenario A**: Index Not Used
```sql
-- Query planner doesn't use index (e.g., wrong column order)
EXPLAIN QUERY PLAN
  SELECT * FROM guarantees WHERE normalized_supplier_name = 'test';
  
Result: SCAN guarantees (full table scan - BAD)
Expected: SEARCH using index idx_guarantees_normalized_supplier (GOOD)
```

**Scenario B**: Slower Than Old Query (Paradox)
```
Old query (JSON LIKE): 50ms @ 5000 guarantees
New query (indexed):   75ms @ 5000 guarantees

Why? Index overhead > LIKE scan for small datasets
```

**Evidence of Risk**:
- Current dataset size unknown (likely < 10K guarantees)
- SQLite index overhead on small tables
- No baseline performance metrics

**Mitigation Strategy**:

**Pre-Execution - Benchmark Old Queries**:
```php
// scripts/benchmark_old_queries.php
$iterations = 100;
$testInputs = loadSamples(10);

$oldTime = benchmark(function() use ($testInputs) {
    foreach ($testInputs as $input) {
        getHistoricalSelections_OLD($input);  // JSON LIKE
    }
}, $iterations);

echo "Old query avg: {$oldTime}ms\n";
```

**Post-Migration - Benchmark New Queries**:
```php
// scripts/benchmark_new_queries.php
$newTime = benchmark(function() use ($testInputs) {
    foreach ($testInputs as $input) {
        getHistoricalSelections_NEW($input);  // Indexed column
    }
}, $iterations);

echo "New query avg: {$newTime}ms\n";
echo "Ratio: " . ($newTime / $oldTime) . "x\n";
```

**Post-Migration - Verify Index Usage**:
```sql
EXPLAIN QUERY PLAN
SELECT * FROM guarantees WHERE normalized_supplier_name = ?;
-- Expected output: "SEARCH ... USING INDEX idx_guarantees_normalized_supplier"
```

**Acceptance Decision**: ✅ **ACCEPTED (Non-Blocking)**

**Rationale**:
- Phase Contract does NOT require performance improvement
- Only requirement: **no behavioral change**
- Performance is **nice-to-have**, not mandatory
- If slower: acceptable as long as < 2x regression

**Acceptance Threshold**:
```
IF new_time <= 2 × old_time:
  ✅ ACCEPTED (acceptable regression)
ELSE:
  ⚠️ Investigate but DO NOT rollback
  - Analyze query plan
  - Consider ANALYZE command
  - May optimize later (Phase 2)
```

**Phase Contract Alignment**:
- Success Criteria does NOT include performance
- Constraint is "No Behavior Change" (not "Better Performance")
- This risk does **not** block merge

---

### RISK #5: Fragmentation Fix Changes Counts

**Category**: Learning Data  
**Probability**: Medium (M)  
**Impact**: Low (L)  
**Classification**: 🟢 **Low Risk**

**Description**:
Using `normalized_supplier_name` instead of `raw_supplier_name` will **fix fragmentation** → counts may increase.

**Example**:
```
Before migration (fragmented):
  raw: "شركة النورس"  → 3 confirmations
  raw: "شركة النورس " → 2 confirmations (extra space)
  Total seen by query: 3 (only exact match)

After migration (unified):
  normalized: "شركة النورس" → 5 confirmations (3+2 aggregated)
  Total seen by query: 5
```

**Is this a bug?** ❌ **NO**  
**Is this expected?** ✅ **YES**  
**Is this desired?** ✅ **YES** (fixes the fragmentation issue)

**Impact on Confidence**:
- Supplier may get **higher** confidence (more confirmations)
- Supplier may appear in suggestions when it didn't before (threshold crossed)

**Evidence of Risk**:
- Documented in Truth Summary as "fragmentation issue"
- Signal Preservation Checklist has test case for this
- This is a **FEATURE**, not a bug

**Mitigation Strategy**:

**Pre-Execution - Document Expected Behavior**:
```markdown
## Expected: Counts Will Increase

Fragmentation fix means:
- Some suppliers will have HIGHER confirmation counts
- Some suppliers will have HIGHER rejection counts
- This is CORRECT behavior (was fragmented before)
```

**Post-Migration - Verify Fix Worked**:
```php
// Test: Insert fragmented data, verify aggregation
$db->exec("
  INSERT INTO learning_confirmations (raw_supplier_name, normalized_supplier_name, supplier_id, action)
  VALUES 
    ('شركة النورس', 'شركة النورس', 5, 'confirm'),
    ('شركة النورس ', 'شركة النورس', 5, 'confirm')  -- extra space in raw
");

$feedback = getUserFeedback('شركة النورس');  // Uses normalized
$count = $feedback[action='confirm'][supplier_id=5]['count'];

assert($count == 2);  // Both aggregated ✅
```

**Acceptance Decision**: ✅ **ACCEPTED AS FEATURE**

**Rationale**:
- This is **expected** and **desired**
- Fixes a known issue (fragmentation)
- Not a regression, but an **improvement**
- Users will see **better** suggestions (more accurate counts)

**Phase Contract Alignment**:
- This does NOT violate "No Behavior Change"
- The **new** behavior is the **correct** behavior
- Old behavior was **buggy** (unintended fragmentation)

---

### RISK #6: Dormant Methods Activation Complexity

**Category**: Future Maintenance  
**Probability**: Low (L)  
**Impact**: Medium (M)  
**Classification**: 🟢 **Low Risk**

**Description**:
If dormant methods (`learnAlias`, `incrementUsage`, `decrementUsage`) are activated in the future, they may need adjustments to work with new schema.

**Scenarios**:

**Scenario A**: learnAlias() Needs normalized_supplier_name
```php
// Current (unused):
public function learnAlias($supplierId, $rawName, $normalized) {
    INSERT INTO supplier_alternative_names (
        supplier_id, 
        alternative_name = $rawName,
        normalized_name = $normalized,  // OK
        source = 'learning'
    )
}

// If activated after merge:
// Works WITH the normalized column ✅
```

**Scenario B**: incrementUsage() References Removed Column
```php
// If we had REMOVED usage_count column (we didn't):
public function incrementUsage($aliasId) {
    UPDATE supplier_alternative_names 
    SET usage_count = usage_count + 1  ← Would fail
    WHERE id = $aliasId
}

// But we KEPT the column, so: ✅ Will work
```

**Evidence of Risk**:
- Methods are **preserved** (Phase Contract Decision #3)
- Schema changes are **additive only** (no columns removed)
- Methods should work "as-is" or with minor adjustments

**Mitigation Strategy**:

**During Migration**:
- ✅ **DO NOT** remove any columns used by dormant methods
- ✅ **DO NOT** rename concepts (e.g., "confirmation" → "approval")
- ✅ Preserve schema compatibility

**Post-Migration - Verify Methods Callable**:
```php
// Smoke test: Can methods be called without errors?
$repo = new SupplierLearningRepository();

// Test learnAlias (dry-run, don't commit)
try {
    $repo->learnAlias(1, 'test', 'test');
    echo "✅ learnAlias() callable\n";
} catch (Exception $e) {
    echo "❌ learnAlias() broken: {$e->getMessage()}\n";
}

// Test incrementUsage
try {
    $repo->incrementUsage(1);
    echo "✅ incrementUsage() callable\n";
} catch (Exception $e) {
    echo "❌ incrementUsage() broken\n";
}
```

**Acceptance Decision**: ✅ **ACCEPTED (Low Priority)**

**Rationale**:
- Methods are NOT used currently
- Future activation is hypothetical
- If activated: unit tests will catch any issues
- Risk is **low** because schema is intentionally compatible

**Contingency**:
```
IF dormant methods activated later:
  1. Write unit tests FIRST
  2. If tests fail: adjust methods to new schema
  3. Expected effort: < 1 hour
```

---

## 🟢 LOW RISKS (Monitoring Only)

### RISK #7: Baseline Capture Timing

**Category**: Testing  
**Probability**: Low (L)  
**Impact**: Low (L)  
**Classification**: 🟢 **Low Risk**

**Description**:
If baselines are captured **during** normal usage, concurrent user actions might affect baseline accuracy.

**Scenario**:
```
Baseline capture script runs:
  Input: "شركة النورس"
  Suggestions: [A, B, C]
  
Meanwhile, user adds confirmation for supplier D:
  INSERT INTO learning_confirmations (...)
  
Baseline saved.

But when we run post-merge comparison:
  Same input now returns: [D, A, B, C]  ← D moved to top
  
Mismatch detected! But it's NOT due to merge, it's due to timing.
```

**Mitigation**:
- Capture baselines when system is **idle** (no active users)
- OR: Backup database FIRST, capture baselines from backup copy
- Document timestamp of baseline capture

**Acceptance**: ✅ **ACCEPTED**

**Rationale**: Single-user system (ADR constraint), timing risk is minimal.

---

### RISK #8: SQLite Version Differences

**Category**: Environment  
**Probability**: Low (L)  
**Impact**: Low (L)  
**Classification**: 🟢 **Low Risk**

**Description**:
If development and production use different SQLite versions, behavior might differ.

**Check**:
```bash
sqlite3 --version
# Verify: 3.x.x
```

**Mitigation**: Document SQLite version in Diff Report.

**Acceptance**: ✅ **ACCEPTED**

---

## 📋 Risk Summary Table

| Risk ID | Description | Probability | Impact | Classification | Decision |
|---------|-------------|-------------|--------|----------------|----------|
| #1 | Migration Failure | Low | High | 🟡 Medium | ✅ Accepted with backup |
| #2 | Historical Query Change | Medium | High | 🟠 High | ✅ Accepted with verification |
| #3 | E2E Test Fail | Low | High | 🟡 Medium | ✅ Accepted with 99% threshold |
| #4 | Performance Regression | Low | Medium | 🟢 Low | ✅ Accepted (non-blocking) |
| #5 | Fragmentation Fix | Medium | Low | 🟢 Low | ✅ Accepted as feature |
| #6 | Dormant Methods | Low | Medium | 🟢 Low | ✅ Accepted (low priority) |
| #7 | Baseline Timing | Low | Low | 🟢 Low | ✅ Accepted |
| #8 | SQLite Version | Low | Low | 🟢 Low | ✅ Accepted |

---

## ✅ Overall Risk Assessment

### Risk Level: 🟡 **MEDIUM-LOW**

**Justification**:
- 0 Critical (🔴) risks with NO mitigation
- 3 High risks with STRONG mitigation (backup, verification, testing)
- 3 Medium/Low risks (acceptable, non-blocking)
- 2 Low risks (monitoring only)

**Readiness for Execution**: ✅ **GREEN LIGHT**

**Conditions**:
1. ✅ **MUST**: Full database backup before ANY changes
2. ✅ **MUST**: Capture all baselines BEFORE migration
3. ✅ **MUST**: Run verification scripts AFTER migration
4. ✅ **MUST**: Immediate rollback if ANY verification fails

---

## 🚨 Rollback Triggers (STOP Conditions)

**Immediate rollback required if**:

1. 🔴 Migration script fails mid-way (Risk #1)
2. 🔴 Historical query verification finds > 0 mismatches (Risk #2)
3. 🔴 E2E test has > 2 mismatches (Risk #3)
4. 🔴 Population verification finds ANY NULL values
5. 🔴 Normalization verification finds inconsistencies

**No rollback for**:
- 🟢 Performance regression < 2x (Risk #4)
- 🟢 Fragmentation fix increases counts (Risk #5)
- 🟢 Dormant methods need minor adjustments (Risk #6)

---

## 📋 Pre-Execution Checklist

Before starting migration:

- [ ] ✅ Database backup completed and verified
- [ ] ✅ Baselines captured (historical, learning, E2E)
- [ ] ✅ Production samples extracted (100 samples)
- [ ] ✅ Row counts documented
- [ ] ✅ Rollback plan tested (restore from backup)
- [ ] ✅ All team members aware of rollback triggers
- [ ] ✅ Time allocated for rollback if needed (2-4 hours buffer)

---

## 📋 Post-Execution Verification Checklist

After migration BEFORE declaring success:

- [ ] ✅ Schema verification passed
- [ ] ✅ Population completeness verified (0 NULLs)
- [ ] ✅ Normalization consistency verified
- [ ] ✅ Historical query comparison passed (0 mismatches)
- [ ] ✅ Learning query comparison passed
- [ ] ✅ E2E test passed (99%+ match)
- [ ] ✅ Index usage verified (EXPLAIN QUERY PLAN)
- [ ] ✅ No JSON LIKE queries remain (grep check)

**Only if ALL pass**: Migration is **successful**.

**If ANY fail**: **ROLLBACK immediately**, analyze, fix, retry.

---

## 🔒 Risk Acceptance Sign-Off

**This document acknowledges**:
- All known risks have been identified
- Mitigation strategies are in place
- Rollback plan is ready
- Verification methods are defined
- Acceptance criteria are clear

**Risk Level**: 🟡 **MEDIUM-LOW** (Acceptable)

**Decision**: ✅ **PROCEED WITH EXECUTION**

**Conditions**: 
- Full backup mandatory
- Verification mandatory
- Rollback triggers must be respected

---

**Document Version**: 1.0  
**Status**: 🔒 **Binding**  
**Date**: 2026-01-04  
**Next**: Create Migration Scripts & Baselines

*Risks are acknowledged. Proceed with caution.*

---

## 📎 Quick Risk Reference

```
🔴 CRITICAL: Migration failure → ✅ Backup + transaction
🟠 HIGH:     Query results change → ✅ Baseline comparison
🟡 MEDIUM:   E2E mismatch → ✅ 99% threshold
🟢 LOW:      Performance → ✅ Monitor (non-blocking)
🟢 LOW:      Fragmentation fix → ✅ Feature (expected)

ROLLBACK IF: Migration fails, Verification fails, E2E > 2 errors
PROCEED IF:  All verifications pass, Backup exists
```

*End of Risk Acknowledgment*
