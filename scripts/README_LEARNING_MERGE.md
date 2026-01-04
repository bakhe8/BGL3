# Learning Merge: Execution Scripts

## تم إنشاء 8 Scripts تنفيذية

### 1. Migration & Population

**`database/migrations/2026_01_04_learning_merge_schema.sql`**
- Schema migration (ADD columns + indexes)
- 4 new columns, 4 new indexes
- Run with: `sqlite3 storage/database/app.sqlite < database/migrations/2026_01_04_learning_merge_schema.sql`

**`scripts/populate_normalized_columns.php`**
- Populate normalized_supplier_name columns
- Run with: `php scripts/populate_normalized_columns.php`

---

### 2. Baseline Capture Scripts (Run BEFORE migration)

**`scripts/capture_historical_baseline.php`**
- Captures historical query results
- Uses JSON LIKE (old method)
- Saves to: `baselines/historical_baseline.json`

**`scripts/create_e2e_baseline.php`**
- Captures full suggestion results
- 100 real supplier names
- Saves to: `baselines/e2e_baseline_100.json`

---

### 3. Verification Scripts (Run AFTER migration)

**`scripts/verify_normalization.php`**
- Verifies normalized values are correct
- Tests 100 random samples
- Exit code 0 = success, 1 = failure

**`scripts/compare_historical_queries.php`**
- Compares new query results with baseline
- CRITICAL: Must match 100%
- Exit code 0 = success, 1 = failure (ROLLBACK required)

**`scripts/compare_e2e_results.php`**
- Compares full suggestions with baseline
- Success: 99%+ match (allow 1-2 edge cases)
- Exit code 0 = success, 1 = failure (ROLLBACK required)

---

### 4. Master Execution Script

**`scripts/run_learning_merge.sh`** (Bash)
- Runs entire migration pipeline
- Includes all verifications
- Auto-rollback on any failure

**Steps**:
1. Pre-flight checks
2. Backup database
3. Capture baselines
4. Run migration
5. Populate columns
6. Verify normalization
7. Compare historical queries
8. (E2E runs after code updates)

---

## 📋 خطة التنفيذ

### Phase 1: Pre-Execution
```bash
# 1. Review scripts (manual)
# 2. Test on dev copy first (recommended)
# 3. Backup production database
cp storage/database/app.sqlite storage/database/app.sqlite.backup-manual
```

### Phase 2: Baseline Capture
```bash
php scripts/capture_historical_baseline.php
php scripts/create_e2e_baseline.php
```

### Phase 3: Migration (Auto or Manual)

**Option A: Automatic** (recommended)
```bash
bash scripts/run_learning_merge.sh
```

**Option B: Manual Step-by-Step**
```bash
# Step 1: Backup
cp storage/database/app.sqlite storage/database/app.sqlite.backup-$(date +%Y%m%d)

# Step 2: Migration
sqlite3 storage/database/app.sqlite < database/migrations/2026_01_04_learning_merge_schema.sql

# Step 3: Populate
php scripts/populate_normalized_columns.php

# Step 4: Verify
php scripts/verify_normalization.php
php scripts/compare_historical_queries.php

# If all pass: proceed to code updates
# If any fails: ROLLBACK
cp storage/database/app.sqlite.backup-YYYYMMDD storage/database/app.sqlite
```

### Phase 4: Code Updates
(See Data Refactor Plan for code changes)

### Phase 5: E2E Verification
```bash
php scripts/compare_e2e_results.php
```

---

## ✅ Success Criteria

All scripts must exit with code 0:
- [x] populate_normalized_columns.php
- [x] verify_normalization.php
- [x] compare_historical_queries.php
- [x] compare_e2e_results.php (after code updates)

---

## 🚨 Rollback Procedure

If any script fails:
```bash
# Immediate rollback
cp storage/database/app.sqlite.backup-YYYYMMDD storage/database/app.sqlite

# Verify rollback
sqlite3 storage/database/app.sqlite "PRAGMA integrity_check;"

# System should be back to pre-migration state
```

---

**Status**: ✅ **Scripts Ready**  
**Next**: Run on dev copy first, then production
