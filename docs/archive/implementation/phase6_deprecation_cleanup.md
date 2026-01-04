# Phase 6: Legacy Deprecation & Cleanup - Complete Guide

**Phase:** 6 - Legacy Deprecation  
**Duration:** 2-3 months  
**Status:** 🟢 Ready to Plan  
**Prerequisites:** Phase 5 complete (UI fully on SuggestionDTO)  
**Goal:** Remove ALL Legacy code, fix database issues, finalize unification  

---

## 🎯 Objectives

1. ✅ Deprecate Legacy suggestion services
2. ✅ Remove Legacy tables/columns (if unused)
3. ✅ Fix database schema issues (from Phase 1 audit)
4. ✅ Remove ProductionRouter (Authority is THE system)
5. ✅ Final Charter compliance verification
6. ✅ Celebrate complete unification! 🎉

---

## 📊 Current State (Post-Phase 5)

**Active Systems:**
- ✅ UnifiedLearningAuthority (primary)
- ✅ ProductionRouter (routing 100% to Authority)
- ❌ Legacy services (unused, kept for fallback)

**Database:**
- ✅ `suppliers` table (Entity - used)
- ✅ `supplier_alternative_names` (Signal - used)
- ✅ `learning_confirmations` (Signal - used, needs normalization fix)
- ❌ `supplier_learning_cache` (Cache - deprecated in Phase 1)
- ⚠️ `supplier_decisions_log` (Audit - used, needs review)

**Code:**
- ❌ LearningSuggestionService (deprecated)
- ❌ SupplierCandidateService (deprecated)
- ❌ ArabicLevelBSuggestions (deprecated)
- ❌ ConfidenceCalculator (old version, deprecated)

---

## 🗑️ Deprecation Strategy

### Week 1-2: Mark as Deprecated

**Update all Legacy services:**

```php
<?php

namespace App\Services\Suggestions;

/**
 * @deprecated Since Phase 6 (2026-01-03)
 * Use UnifiedLearningAuthority instead
 * 
 * This service will be REMOVED in 3 months (2026-04-03)
 * 
 * Migration Guide: docs/implementation/phase6_deprecation.md
 */
class LearningSuggestionService
{
    public function getSuggestions(string $rawInput): array
    {
        trigger_error(
            'LearningSuggestionService is deprecated. Use UnifiedLearningAuthority.',
            E_USER_DEPRECATED
        );

        // Original code remains (for now)
        // ...
    }
}
```

**Add to all deprecated services:**
- LearningSuggestionService
- SupplierCandidateService
- ArabicLevelBSuggestions
- ConfidenceCalculator
- LearningService (review first - may keep write path)

---

### Week 3-4: Remove ProductionRouter

**Why:** Authority is 100%, no routing needed

**Before:**
```php
class SupplierController
{
    private ProductionRouter $router;

    public function getSuggestions(Request $request)
    {
        $input = $request->input('supplier_name');
        $suggestions = $this->router->getSuggestions($input);
        return response()->json($suggestions);
    }
}
```

**After:**
```php
class SupplierController
{
    private UnifiedLearningAuthority $authority;

    public function __construct()
    {
        $this->authority = AuthorityFactory::create();
    }

    public function getSuggestions(Request $request)
    {
        $input = $request->input('supplier_name');
        
        // Direct call to Authority (no router)
        $suggestions = $this->authority->getSuggestions($input);
        
        // Convert DTOs to arrays
        $data = array_map(fn($dto) => $dto->toArray(), $suggestions);
        
        return response()->json($data);
    }
}
```

**Benefits:**
- ✅ Simpler code (one less layer)
- ✅ Faster (no routing check)
- ✅ Clearer ownership (Authority is THE system)

---

### Week 5-8: Database Schema Fixes

**Issue 1: learning_confirmations - Raw Name Fragmentation**

**Problem:** (From Phase 1 Query Audit #2)
- Table stores `raw_supplier_name` (non-normalized)
- Learning fragmented across input variants
- "شركة النورس" vs "شركة  النورس" = separate histories

**Fix: Add normalized column**

```sql
-- Migration: Add normalized_supplier_name column
ALTER TABLE learning_confirmations 
ADD COLUMN normalized_supplier_name TEXT;

-- Index for performance
CREATE INDEX idx_learning_confirmations_normalized 
ON learning_confirmations(normalized_supplier_name);
```

**Backfill existing data:**
```php
<?php

/**
 * Backfill normalized_supplier_name for existing records
 * 
 * Run once after migration
 */

use App\Support\Normalizer;
use App\Support\Database;

$normalizer = new Normalizer();
$pdo = Database::connection();

// Get all unique raw names
$stmt = $pdo->query('SELECT DISTINCT raw_supplier_name FROM learning_confirmations');
$rawNames = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo "Backfilling " . count($rawNames) . " unique names...\n";

foreach ($rawNames as $rawName) {
    $normalized = $normalizer->normalizeSupplierName($rawName);
    
    $update = $pdo->prepare('
        UPDATE learning_confirmations 
        SET normalized_supplier_name = :normalized 
        WHERE raw_supplier_name = :raw
    ');
    
    $update->execute([
        'normalized' => $normalized,
        'raw' => $rawName
    ]);
}

echo "✅ Backfill complete\n";
```

**Update LearningRepository:**
```php
// Before (Phase 1)
$stmt = $this->db->prepare("
    SELECT supplier_id, action, COUNT(*) as count
    FROM learning_confirmations
    WHERE raw_supplier_name = :raw_name
    GROUP BY supplier_id, action
");

// After (Phase 6)
$stmt = $this->db->prepare("
    SELECT supplier_id, action, COUNT(*) as count
    FROM learning_confirmations
    WHERE normalized_supplier_name = :normalized
    GROUP BY supplier_id, action
");
```

**Impact:**
- ✅ Learning no longer fragmented
- ✅ Accurate confirmation counts
- ✅ Better signal quality for Authority

---

**Issue 2: supplier_learning_cache - Deprecated Table**

**Problem:** (From Phase 1 Query Audit #4)
- Table acts as cache-as-authority (violation)
- No population mechanism found
- Dual usage counters (cache vs alias table)

**Fix: Drop table**

```sql
-- Migration: Remove deprecated cache table
DROP TABLE IF EXISTS supplier_learning_cache;
```

**Remove related code:**
- [ ] `SupplierLearningCacheRepository.php` - DELETE
- [ ] Any queries to cache table - REMOVE
- [ ] Cache-related tests - REMOVE

**Verification:**
```bash
# Search for remaining references
grep -r "supplier_learning_cache" .
grep -r "SupplierLearningCacheRepository" .

# Should return: 0 results
```

---

**Issue 3: guarantees.raw_data - JSON Fragility**

**Problem:** (From Phase 1 Query Audit #3)
- Historical query uses LIKE on JSON blob
- Fragile, may break if JSON structure changes

**Fix: Add structured columns (optional, if historical queries critical)**

```sql
-- Migration: Add structured columns to guarantees
ALTER TABLE guarantees
ADD COLUMN supplier_input_raw TEXT,
ADD COLUMN supplier_input_normalized TEXT;

-- Index
CREATE INDEX idx_guarantees_supplier_normalized
ON guarantees(supplier_input_normalized);
```

**Backfill (if needed):**
```php
// Extract from raw_data JSON and populate new columns
// Similar pattern to learning_confirmations backfill
```

**Update GuaranteeDecisionRepository:**
```php
// Before (fragile)
$stmt = $this->db->prepare("
    SELECT gd.supplier_id, COUNT(*) as count
    FROM guarantees g
    JOIN guarantee_decisions gd ON g.id = gd.guarantee_id
    WHERE g.raw_data LIKE :pattern
    GROUP BY gd.supplier_id
");

// After (structured)
$stmt = $this->db->prepare("
    SELECT gd.supplier_id, COUNT(*) as count
    FROM guarantees g
    JOIN guarantee_decisions gd ON g.id = gd.guarantee_id
    WHERE g.supplier_input_normalized = :normalized
    GROUP BY gd.supplier_id
");
```

---

### Week 9-10: Remove Legacy Services

**After 3-month deprecation period:**

**Delete Files:**
```bash
# Suggestion services
rm app/Services/Suggestions/LearningSuggestionService.php
rm app/Services/Suggestions/ArabicLevelBSuggestions.php
rm app/Services/SupplierCandidateService.php

# Old calculator
rm app/Services/Suggestions/ConfidenceCalculator.php

# Old repositories (if deprecated)
rm app/Repositories/SupplierLearningCacheRepository.php

# Tests for deprecated services
rm tests/Services/LearningSuggestionServiceTest.php
rm tests/Services/SupplierCandidateServiceTest.php
```

**Update Imports:**
- Search & replace all imports
- Update to use UnifiedLearningAuthority
- Remove deprecated use statements

---

### Week 11-12: Final Compliance Verification

**Charter Compliance Audit:**

| Requirement | Status | Evidence |
|-------------|--------|----------|
| Single Learning Authority | ✅ | UnifiedLearningAuthority only |
| Unified Confidence (0-100) | ✅ | ConfidenceCalculatorV2 |
| No decision logic in SQL | ✅ | Query audit shows clean queries |
| SuggestionDTO everywhere | ✅ | Phase 5 complete |
| No cache-as-authority | ✅ | supplier_learning_cache dropped |
| Normalized learning | ✅ | normalized_supplier_name added |
| No fragmented UI | ✅ | Phase 5 consolidation |

**Database Role Declaration Audit:**

| Table | Role | Compliance | Notes |
|-------|------|------------|-------|
| suppliers | Entity | ✅ | Clean |
| supplier_alternative_names | Signal | ✅ | usage_count is signal (not filter) |
| learning_confirmations | Signal | ✅ | Now normalized |
| supplier_decisions_log | Audit | ✅ | Append-only, good schema |
| guarantees | Entity | ✅ | Now structured |
| supplier_learning_cache | Cache | ✅ REMOVED | Deprecated |

**Overall Compliance: 100%** 🎉

---

## 📊 Metrics: Before vs After

### Code Metrics

| Metric | Before (Phase 0) | After (Phase 6) | Delta |
|--------|------------------|-----------------|-------|
| Suggestion Services | 5 | 1 | -80% |
| Confidence Scales | 3 | 1 | -67% |
| Lines of Code (suggestion logic) | ~2,500 | ~1,500 | -40% |
| UI Adapter Functions | ~15 | 0 | -100% |
| Database Tables (learning) | 4 | 3 | -25% |

### Quality Metrics

| Metric | Before | After | Delta |
|--------|--------|-------|-------|
| Learning Fragmentation | ~30% | <5% | -83% |
| Confidence Consistency | 3 formats | 1 format | +100% |
| UI Components (suggestion) | 5 variants | 1 variant | -80% |
| Charter Compliance | 40% | 100% | +150% |

### Performance Metrics (Expected)

| Metric | Before | After | Delta |
|--------|--------|-------|-------|
| Avg Suggestion Time | 75ms | 60ms | -20% |
| Cache Complexity | High | None | -100% |
| Debug Time (bugs) | 2-3 days | 1 day | -50% |
| New Feature Time | 2 weeks | 1 week | -50% |

---

## 🎉 Celebration & Documentation

### Week 13: Project Completion

**1. Final Report**

Create comprehensive completion report:
- All 7 phases complete
- Before/After comparisons
- Lessons learned
- Future roadmap

**2. Team Celebration**

- All-hands presentation
- Demo of unified system
- Acknowledge contributors
- Pizza party! 🍕

**3. Knowledge Transfer**

**Update Documentation:**
- [ ] Authority Developer Guide
- [ ] Signal Feeder Creation Guide
- [ ] Confidence Formula Reference
- [ ] Database Schema Documentation

**Training Sessions:**
- [ ] New team members onboarding
- [ ] Authority maintenance training
- [ ] Troubleshooting guide

---

## ✅ Phase 6 Completion Checklist

### Database
- [ ] `normalized_supplier_name` column added to learning_confirmations
- [ ] Existing data backfilled
- [ ] `supplier_learning_cache` table dropped
- [ ] Historical queries updated to use structured columns
- [ ] All indexes created

### Code
- [ ] Legacy services marked deprecated (3-month notice)
- [ ] ProductionRouter removed
- [ ] DirectAuthority calls in all controllers
- [ ] Legacy services deleted (after 3 months)
- [ ] Deprecated code cleaned up

### Compliance
- [ ] Charter compliance: 100%
- [ ] Database Role Declaration: 100%
- [ ] No violations in codebase
- [ ] All audits pass

### Documentation
- [ ] Final project report published
- [ ] Developer guides updated
- [ ] Schema documentation complete
- [ ] Maintenance runbooks created

### Validation
- [ ] All tests pass
- [ ] Production stable for 30 days
- [ ] Zero regressions
- [ ] Team trained

**When ALL checked:** 🎉 **PROJECT COMPLETE!** 🎉

---

## 🚀 Phase 7: Continuous Governance (Ongoing)

**After Phase 6, enter maintenance mode:**

**Monthly:**
- [ ] Review Charter compliance
- [ ] Audit new PRs for violations
- [ ] Update confidence formula if needed
- [ ] Monitor Authority performance

**Quarterly:**
- [ ] ARB meeting
- [ ] Review lessons learned
- [ ] Plan improvements
- [ ] Celebrate successes

**Yearly:**
- [ ] Comprehensive system review
- [ ] Charter amendments (if needed)
- [ ] Performance optimization
- [ ] Team retrospective

---

**Status:** 🟢 Ready to Execute  
**Prerequisites:** Phase 5 complete  
**Duration:** 2-3 months  
**Outcome:** Complete unification, Charter-compliant system  

**Last Updated:** 2026-01-03  
**Final Phase:** Phase 6 of 7 (Phase 7 = ongoing governance)
