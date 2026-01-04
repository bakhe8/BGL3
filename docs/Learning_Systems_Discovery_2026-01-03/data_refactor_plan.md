# Data Refactor Plan

## Document #1 - Learning Merge Phase

**التاريخ**: 2026-01-03  
**الأولوية**: 🔴 **أهم خطوة** (Critical Path)  
**الحالة**: Plan - Ready for Review  
**الهدف**: تحويل schema من fragile JSON queries إلى structured columns بدون فقدان بيانات

---

## 🎯 Executive Summary

**المشكلة الحالية**:
- 2 systems تستخدم JSON LIKE queries (fragile, slow, brittle)
- لا indexes على raw_supplier_name في learning_confirmations
- Intent duplication (System #1 ↔ #3)

**الحل المقترح**:
- إضافة normalized_supplier_name column to guarantees
- إضافة indexes لـ learning_confirmations
- الحفاظ على dual signals (NO intent merge)
- Migration script with validation

**الفوائد المتوقعة**:
- ✅ No more JSON LIKE queries
- ✅ Faster queries (indexed)
- ✅ Safer (type-safe columns)
- ✅ Same behavior (backward compatible)

---

## 📊 Current State Analysis

### Tables Affected by Learning Systems

| Table | Usage | Issue | Priority |
|-------|-------|-------|----------|
| `guarantees` | S7, S8 (historical) | JSON LIKE on raw_data | 🔴 High |
| `learning_confirmations` | S9, S10 (explicit) | No index on raw_supplier_name | 🟡 Medium |
| `supplier_alternative_names` | S1 (aliases) | ✅ Good (indexed) | ✅ OK |
| `guarantee_decisions` | S7, S8 (historical) | JOIN, no index | 🟡 Medium |
| `suppliers` | S2-S6 (fuzzy, anchors) | ✅ Indexed | ✅ OK |

### Current Queries (Problematic)

**Query #1**: Historical Selections (FRAGILE)
```sql
-- ❌ Current (BAD)
SELECT d.supplier_id, COUNT(*) as count
FROM guarantees g
JOIN guarantee_decisions d ON g.id = d.guarantee_id
WHERE g.raw_data LIKE '%"supplier":"' || ? || '"%'
  AND d.supplier_id IS NOT NULL
GROUP BY d.supplier_id

-- File: GuaranteeDecisionRepository.php:getHistoricalSelections()
-- Problem: Full table scan, JSON format dependency
```

**Query #2**: Learning Confirmations (UNINDEXED)
```sql
-- ⚠️ Current (SLOW)
SELECT supplier_id, action, COUNT(*) as count
FROM learning_confirmations
WHERE raw_supplier_name = ?
GROUP BY supplier_id, action

-- File: LearningRepository.php:getUserFeedback()
-- Problem: No index on raw_supplier_name (full scan when table grows)
```

---

## 🔧 Proposed Schema Changes

### Change #1: Add normalized_supplier_name to guarantees

**Purpose**: Replace JSON LIKE query with indexed column lookup

**Schema**:
```sql
ALTER TABLE guarantees 
ADD COLUMN normalized_supplier_name TEXT;

CREATE INDEX idx_guarantees_normalized_supplier 
ON guarantees(normalized_supplier_name);
```

**Population Strategy**:
```sql
-- Backfill existing rows
UPDATE guarantees
SET normalized_supplier_name = (
    SELECT json_extract(raw_data, '$.supplier')
    WHERE json_extract(raw_data, '$.supplier') IS NOT NULL
);

-- Or use PHP script for normalization:
-- foreach (guarantees as g) {
--     normalized = Normalizer::normalize(g.raw_data['supplier']);
--     UPDATE guarantees SET normalized_supplier_name = normalized WHERE id = g.id;
-- }
```

**New Query** (SAFE):
```sql
-- ✅ Proposed (GOOD)
SELECT d.supplier_id, COUNT(*) as count
FROM guarantees g
JOIN guarantee_decisions d ON g.id = d.guarantee_id
WHERE g.normalized_supplier_name = ?
  AND d.supplier_id IS NOT NULL
GROUP BY d.supplier_id

-- Fast: indexed lookup
-- Safe: no JSON dependency
```

**Backward Compatibility**:
- ✅ raw_data remains unchanged (Timeline snapshots preserved)
- ✅ Existing code continues to work
- ✅ New column is additive (no destructive changes)

---

### Change #2: Add index to learning_confirmations

**Purpose**: Speed up learning feedback queries

**Schema**:
```sql
CREATE INDEX idx_learning_confirmations_raw_supplier 
ON learning_confirmations(raw_supplier_name);

-- Optional: composite index for common query pattern
CREATE INDEX idx_learning_confirmations_supplier_action
ON learning_confirmations(raw_supplier_name, action);
```

**Expected Improvement**:
```
Before: O(n) full table scan
After:  O(log n) index lookup + O(k) where k = matching rows
```

**Risk**: ✅ None (additive change, no behavioral impact)

---

### Change #3: Add index to guarantee_decisions (optional)

**Purpose**: Speed up JOIN in historical query

**Schema**:
```sql
-- Already has: guarantee_id (for FK)
-- Add composite for historical query:
CREATE INDEX idx_guarantee_decisions_supplier
ON guarantee_decisions(supplier_id) 
WHERE supplier_id IS NOT NULL;
```

**Expected Improvement**:
- Faster GROUP BY supplier_id
- Better JOIN performance

---

### Change #4: Add normalized_supplier_name to learning_confirmations (future-proof)

**Purpose**: Align with guarantees approach, fix fragmentation

**Schema**:
```sql
ALTER TABLE learning_confirmations
ADD COLUMN normalized_supplier_name TEXT;

CREATE INDEX idx_learning_normalized
ON learning_confirmations(normalized_supplier_name, action);
```

**Migration**:
```php
// Backfill
foreach (learning_confirmations as lc) {
    normalized = Normalizer::normalize(lc.raw_supplier_name);
    UPDATE learning_confirmations 
    SET normalized_supplier_name = normalized 
    WHERE id = lc.id;
}
```

**Updated Query**:
```sql
-- Use normalized column instead of raw
SELECT supplier_id, action, COUNT(*) as count
FROM learning_confirmations
WHERE normalized_supplier_name = ?  -- normalized input
GROUP BY supplier_id, action
```

**Benefit**: Fixes fragmentation issue
```
Before: "شركة النورس" ≠ "شركة النورس " (space)
After:  Both normalize to same value → aggregated correctly
```

---

## 📋 Migration Plan

### Phase 1: Preparation

**Step 1.1**: Backup Database
```bash
cp storage/database/app.sqlite storage/database/app.sqlite.backup-2026-01-03
```

**Step 1.2**: Create Migration Script
```sql
-- File: database/migrations/2026_01_04_learning_merge_schema.sql

-- guarantees
ALTER TABLE guarantees ADD COLUMN normalized_supplier_name TEXT;
CREATE INDEX idx_guarantees_normalized_supplier ON guarantees(normalized_supplier_name);

-- learning_confirmations
ALTER TABLE learning_confirmations ADD COLUMN normalized_supplier_name TEXT;
CREATE INDEX idx_learning_confirmations_raw_supplier ON learning_confirmations(raw_supplier_name);
CREATE INDEX idx_learning_confirmations_normalized ON learning_confirmations(normalized_supplier_name, action);

-- guarantee_decisions
CREATE INDEX idx_guarantee_decisions_supplier ON guarantee_decisions(supplier_id) WHERE supplier_id IS NOT NULL;

-- Note: guarantee_id already indexed (FK)
```

**Step 1.3**: Create Data Population Script
```php
// File: scripts/populate_normalized_columns.php

require __DIR__ . '/../app/Support/Database.php';
require __DIR__ . '/../app/Utils/ArabicNormalizer.php';

$db = \App\Support\Database::connect();

// 1. Populate guarantees.normalized_supplier_name
echo "Populating guarantees...\n";
$stmt = $db->query("SELECT id, raw_data FROM guarantees");
$count = 0;

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $rawData = json_decode($row['raw_data'], true);
    $supplierName = $rawData['supplier'] ?? null;
    
    if ($supplierName) {
        $normalized = \App\Utils\ArabicNormalizer::normalize($supplierName);
        
        $update = $db->prepare("UPDATE guarantees SET normalized_supplier_name = ? WHERE id = ?");
        $update->execute([$normalized, $row['id']]);
        $count++;
    }
}
echo "✅ Populated $count guarantees\n";

// 2. Populate learning_confirmations.normalized_supplier_name
echo "Populating learning_confirmations...\n";
$stmt = $db->query("SELECT id, raw_supplier_name FROM learning_confirmations");
$count = 0;

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $normalized = \App\Utils\ArabicNormalizer::normalize($row['raw_supplier_name']);
    
    $update = $db->prepare("UPDATE learning_confirmations SET normalized_supplier_name = ? WHERE id = ?");
    $update->execute([$normalized, $row['id']]);
    $count++;
}
echo "✅ Populated $count learning_confirmations\n";

echo "✅ Migration complete\n";
```

---

### Phase 2: Execution

**Step 2.1**: Run Schema Migration
```bash
sqlite3 storage/database/app.sqlite < database/migrations/2026_01_04_learning_merge_schema.sql
```

**Step 2.2**: Populate Data
```bash
php scripts/populate_normalized_columns.php
```

**Step 2.3**: Verify Completion
```sql
-- Check guarantees
SELECT COUNT(*) as total, 
       COUNT(normalized_supplier_name) as populated
FROM guarantees;
-- Expected: total == populated

-- Check learning_confirmations
SELECT COUNT(*) as total,
       COUNT(normalized_supplier_name) as populated  
FROM learning_confirmations;
-- Expected: total == populated
```

---

### Phase 3: Code Updates

**Update #1**: GuaranteeDecisionRepository::getHistoricalSelections()

```php
// File: app/Repositories/GuaranteeDecisionRepository.php

// OLD (lines ~245-255):
public function getHistoricalSelections(string $normalizedInput): array
{
    $pattern = '%"supplier":"' . $normalizedInput . '"%';
    
    $stmt = $this->db->prepare("
        SELECT d.supplier_id, COUNT(*) as count
        FROM guarantees g
        JOIN guarantee_decisions d ON g.id = d.guarantee_id
        WHERE g.raw_data LIKE ? 
          AND d.supplier_id IS NOT NULL
        GROUP BY d.supplier_id
    ");
    $stmt->execute([$pattern]);
    // ...
}

// NEW (PROPOSED):
public function getHistoricalSelections(string $normalizedInput): array
{
    $stmt = $this->db->prepare("
        SELECT d.supplier_id, COUNT(*) as count
        FROM guarantees g
        JOIN guarantee_decisions d ON g.id = d.guarantee_id
        WHERE g.normalized_supplier_name = ? 
          AND d.supplier_id IS NOT NULL
        GROUP BY d.supplier_id
    ");
    $stmt->execute([$normalizedInput]);
    // ... (rest unchanged)
}
```

**Update #2**: LearningRepository::getUserFeedback()

```php
// File: app/Repositories/LearningRepository.php

// OLD (lines ~25-35):
public function getUserFeedback(string $normalizedInput): array
{
    $stmt = $this->db->prepare("
        SELECT supplier_id, action, COUNT(*) as count
        FROM learning_confirmations
        WHERE raw_supplier_name = ?
        GROUP BY supplier_id, action
    ");
    $stmt->execute([$normalizedInput]);
    // ...
}

// NEW (PROPOSED):
public function getUserFeedback(string $normalizedInput): array
{
    $stmt = $this->db->prepare("
        SELECT supplier_id, action, COUNT(*) as count
        FROM learning_confirmations
        WHERE normalized_supplier_name = ?
        GROUP BY supplier_id, action
    ");
    $stmt->execute([$normalizedInput]);
    // ... (rest unchanged)
}
```

**Update #3**: LearningRepository::logDecision()

```php
// File: app/Repositories/LearningRepository.php

// Add normalized_supplier_name to INSERT:
public function logDecision(array $data): void
{
    // Normalize the supplier name
    $normalized = \App\Utils\ArabicNormalizer::normalize($data['raw_supplier_name']);
    
    $stmt = $this->db->prepare("
        INSERT INTO learning_confirmations (
            raw_supplier_name, 
            normalized_supplier_name,  -- NEW
            supplier_id, 
            confidence, 
            matched_anchor, 
            anchor_type, 
            action, 
            decision_time_seconds, 
            guarantee_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $data['raw_supplier_name'],
        $normalized,  // NEW
        $data['supplier_id'],
        // ... rest unchanged
    ]);
}
```

**Update #4**: Auto-populate on guarantee import

```php
// File: app/Repositories/GuaranteeRepository.php

public function create(array $data): Guarantee
{
    // Extract supplier name from raw_data for normalization
    $rawData = $data['raw_data'];
    $supplierName = $rawData['supplier'] ?? null;
    $normalized = $supplierName ? \App\Utils\ArabicNormalizer::normalize($supplierName) : null;
    
    $stmt = $this->db->prepare("
        INSERT INTO guarantees (
            guarantee_number, 
            raw_data, 
            normalized_supplier_name,  -- NEW
            import_source, 
            imported_by
        ) VALUES (?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $data['guarantee_number'],
        json_encode($rawData),
        $normalized,  // NEW
        $data['import_source'] ?? null,
        $data['imported_by'] ?? null
    ]);
    
    // ... rest unchanged
}
```

---

## ✅ Backward Compatibility Guarantees

### Guarantee #1: Raw Data Preserved
**Promise**: `raw_data` column remains untouched  
**Proof**: All updates are additive (new columns), no updates to raw_data

### Guarantee #2: Timeline Snapshots Intact
**Promise**: Historical snapshots preserve original supplier names  
**Proof**: Timeline uses raw_data['supplier'] for snapshots, unchanged

### Guarantee #3: Same Query Results
**Promise**: New queries return same results as old queries  
**Proof**: normalized_supplier_name is derived from raw_data deterministically

**Test**:
```sql
-- Compare results:
-- Old query
SELECT ... WHERE raw_data LIKE '%"supplier":"شركة النورس"%';

-- New query  
SELECT ... WHERE normalized_supplier_name = 'شركة النورس';  -- normalized

-- Expected: SAME supplier_ids, SAME counts
```

### Guarantee #4: No Breaking Changes
**Promise**: Existing code continues to work during transition  
**Proof**: 
- Old columns remain (raw_supplier_name, raw_data)
- New columns are additive
- Code can be updated incrementally

---

## 🎯 Success Criteria

### Criterion #1: No JSON LIKE Queries Remain
**Test**:
```bash
grep -r "LIKE.*%\"" app/Repositories/
# Expected: 0 results
```

### Criterion #2: All Normalized Columns Populated
**Test**:
```sql
-- guarantees
SELECT COUNT(*) - COUNT(normalized_supplier_name) as missing
FROM guarantees;
-- Expected: 0

-- learning_confirmations
SELECT COUNT(*) - COUNT(normalized_supplier_name) as missing
FROM learning_confirmations;
-- Expected: 0
```

### Criterion #3: Indexes Created and Used
**Test**:
```sql
-- Check indexes exist
.indexes guarantees
-- Expected: idx_guarantees_normalized_supplier

.indexes learning_confirmations
-- Expected: idx_learning_confirmations_normalized

 -- Verify index usage
EXPLAIN QUERY PLAN
SELECT * FROM guarantees WHERE normalized_supplier_name = 'test';
-- Expected: SEARCH using index idx_guarantees_normalized_supplier
```

### Criterion #4: Query Performance Improved
**Benchmark**:
```php
// Before migration
$start = microtime(true);
$old = getHistoricalSelections_OLD('شركة النورس');  // JSON LIKE
$oldTime = microtime(true) - $start;

// After migration
$start = microtime(true);
$new = getHistoricalSelections_NEW('شركة النورس');  // Indexed column
$newTime = microtime(true) - $start;

echo "Old: {$oldTime}s, New: {$newTime}s\n";
// Expected: newTime < oldTime (at least 2-5x faster with scale)
```

---

## 🚨 Rollback Plan

### If Migration Fails

**Step 1**: Restore Backup
```bash
cp storage/database/app.sqlite.backup-2026-01-03 storage/database/app.sqlite
```

**Step 2**: Revert Code Changes
```bash
git revert <commit-hash>
```

**Step 3**: Verify System Works
```bash
php tests/LearningSystemsTest.php
```

### Rollback-Safe Design

- ✅ **New columns are optional** (nullable)
- ✅ **Old columns remain** (backward compatible)
- ✅ **Code can query old columns** if new are missing
- ✅ **Incremental rollout possible** (update code in stages)

---

## 📊 Risk Assessment

| Risk | Probability | Impact | Mitigation |
|------|-------------|--------|------------|
| Migration script fails mid-way | Low | High | Transaction wrapping, backup |
| Data corruption during backfill | Low | High | Dry-run first, validation checks |
| Normalization inconsistency | Medium | Medium | Use same Normalizer class throughout |
| Performance regression | Low | Medium | Benchmark before/after |
| Missing edge case in SQL | Low | Low | Comprehensive testing |

---

## ⏱️ Estimated Timeline

| Phase | Duration | Dependencies |
|-------|----------|--------------|
| Schema migration | 5 min | Backup complete |
| Data population | 10-30 min | Depends on row count |
| Code updates | 2 hours | Migration complete |
| Testing & validation | 1-2 hours | Code complete |
| **Total** | **~4 hours** | Sequential |

---

## ✅ Approval Checklist

Before executing this plan:

- [ ] ✅ Backup database verified
- [ ] ✅ Migration script reviewed
- [ ] ✅ Population script tested on copy
- [ ] ✅ Code changes prepared (draft PRs)
- [ ] ✅ Test suite ready
- [ ] ✅ Rollback plan understood
- [ ] ✅ Phase Contract requirements met:
  - [ ] No behavior change
  - [ ] All signals preserved
  - [ ] JSON LIKE queries removed
  - [ ] Performance improved

---

**Plan Status**: 🟢 **Ready for Execution**  
**Next Step**: Signal Preservation Checklist

*This refactor is the foundation of Learning Merge. All other changes depend on this.*

---

## 📎 Quick Commands Reference

```bash
# Backup
cp storage/database/app.sqlite storage/database/app.sqlite.backup-$(date +%Y%m%d)

# Run migration
sqlite3 storage/database/app.sqlite < database/migrations/2026_01_04_learning_merge_schema.sql

# Populate data
php scripts/populate_normalized_columns.php

# Verify
sqlite3 storage/database/app.sqlite "
  SELECT COUNT(*) as total, COUNT(normalized_supplier_name) as populated 
  FROM guarantees;
"

# Test query performance
php scripts/benchmark_queries.php

# Rollback if needed
cp storage/database/app.sqlite.backup-YYYYMMDD storage/database/app.sqlite
```

---

*End of Data Refactor Plan*
