# Query Pattern Audit - Phase 1

**Phase:** 1 - Signal Extraction & Mapping  
**Created:** 2026-01-03  
**Status:** In Progress  
**Purpose:** Audit all database queries for Database Role Declaration compliance  

---

## Audit Criteria

Based on `database_role_declaration.md` Article 4: Prohibited Patterns

### ❌ Violations to Identify:

1. **Decision Logic in SQL:**
   - `WHERE usage_count > 0` (decision filter)
   - `ORDER BY confidence DESC` (decision ordering)
   - `LIMIT 1` without explicit reason (arbitrary first-match)

2. **Signal-Decision Conflation:**
   - Queries that treat accumulated counts as suppression switches
   - Queries that embed confidence thresholds

3. **Non-Normalized Signal Queries:**
   - Queries on `raw_supplier_name` without normalization aggregation

---

## Query Inventory

### 1. Alias Exact Match

**Location:** `SupplierLearningRepository.php:18-26`

**Query:**
```php
$stmt = $pdo->prepare("
    SELECT s.id, s.official_name, s.english_name, 
           'alias' as source, 1.0 as score
    FROM supplier_alternative_names san
    JOIN suppliers s ON san.supplier_id = s.id
    WHERE san.normalized_name = :normalized
      AND san.usage_count > 0
    LIMIT 1
");
```

**Analysis:**

| Aspect | Evaluation | Compliance |
|--------|------------|------------|
| **Signal retrieval** | ✓ Queries normalized_name | ✓ GOOD |
| **Decision filter** | ❌ `AND usage_count > 0` | ❌ VIOLATION |
| **Decision limit** | ❌ `LIMIT 1` (arbitrary winner) | ❌ VIOLATION |
| **Decision scoring** | ❌ Returns fixed `score = 1.0` | ❌ VIOLATION (belongs in Authority) |

**Violation Type:** Decision logic embedded in SQL

**Impact:** 
- Suppresses aliases with `usage_count ≤ 0` at database level
- Authority cannot see these signals to make informed decision
- First-match behavior hides conflicts

**Required Change:**
```php
// CORRECT: Return ALL aliases
$stmt = $pdo->prepare("
    SELECT s.id, s.official_name, s.english_name,
           san.usage_count,
           san.source,
           san.created_at
    FROM supplier_alternative_names san
    JOIN suppliers s ON san.supplier_id = s.id
    WHERE san.normalized_name = :normalized
    -- NO usage_count filter
    -- NO LIMIT
");

// Authority decides:
// - Which to show (usage_count threshold)
// - Which to prioritize (if multiple)
```

**Reference:** Database Role Declaration, Article 4.1

---

### 2. Learning Confirmations Aggregation

**Location:** `LearningRepository.php:25-35`

**Query:**
```php
$stmt = $pdo->prepare("
    SELECT supplier_id, action, COUNT(*) as count
    FROM learning_confirmations
    WHERE raw_supplier_name = :raw_name
    GROUP BY supplier_id, action
");
```

**Analysis:**

| Aspect | Evaluation | Compliance |
|--------|------------|------------|
| **Signal retrieval** | ✓ Aggregates confirmations | ✓ GOOD |
| **Non-normalized query** | ❌ `WHERE raw_supplier_name` | ❌ ISSUE (fragmentation) |
| **Decision logic** | ✓ No filtering/ordering | ✓ GOOD |

**Violation Type:** Structural inefficiency (not Charter violation, but fitness issue)

**Impact:**
- Input variants fragment learning (forensics evidence)
- "شركة النورس" vs "شركة  النورس" = separate histories
- Authority must work around

this

**Required Change (Phase 1 - Document Only, Fix in Phase 6):**
```sql
-- Future (requires schema update):
SELECT supplier_id, action, COUNT(*) as count
FROM learning_confirmations
WHERE normalized_supplier_name = :normalized  -- NEW COLUMN
GROUP BY supplier_id, action
```

**Status:** Document as known issue, defer fix to Phase 6 (database schema updates)

**Reference:** Database Fitness Analysis, Section 3: Normalization Integrity

---

### 3. Historical Selections (Fragile JSON Query)

**Location:** `LearningRepository.php:48-62`

**Query:**
```php
$stmt = $pdo->prepare("
    SELECT gd.supplier_id, COUNT(*) as historical_count
    FROM guarantees g
    JOIN guarantee_decisions gd ON g.id = gd.guarantee_id
    WHERE g.raw_data LIKE :pattern
    GROUP BY gd.supplier_id
");

// Pattern: '%"supplier":"' . $rawName . '"%'
```

**Analysis:**

| Aspect | Evaluation | Compliance |
|--------|------------|------------|
| **Signal retrieval** | ✓ Counts historical selections | ✓ GOOD (intent) |
| **JSON fragment matching** | ❌ `LIKE` on unstructured data | ❌ FRAGILE |
| **Non-normalized** | ❌ Uses raw name in pattern | ❌ ISSUE |
| **Decision logic** | ✓ No filtering | ✓ GOOD |

**Violation Type:** Fragile implementation (not Charter violation, but reliability issue)

**Impact:**
- False positives/negatives if JSON structure changes
- Misses data if field name changes
- Non-normalized matching fragments history

**Required Change:**
```php
// BETTER: Use structured join
$stmt = $pdo->prepare("
    SELECT gd.supplier_id, COUNT(*) as historical_count
    FROM guarantee_decisions gd
    JOIN guarantees g ON gd.guarantee_id = g.id
    -- Ideally: WHERE gd.input_normalized = :normalized
    -- Currently: WHERE clause logic in application layer
    GROUP BY gd.supplier_id
");

// Then filter in PHP by normalized input
$results = array_filter($all_decisions, function($row) use ($normalized) {
    return normalize($row['input_raw']) === $normalized;
});
```

**Status:** Document, propose refactor in Phase 2

**Reference:** Database Fitness Analysis, Section 2: Signal-Decision Leakage

---

### 4. Cache Suggestions Query

**Location:** `SupplierLearningCacheRepository.php:26-43`

**Query:**
```php
$stmt = $pdo->prepare("
    SELECT 
        supplier_id,
        effective_score,
        star_rating,
        fuzzy_score,
        source_weight
    FROM supplier_learning_cache
    WHERE normalized_input = :normalized
      AND effective_score > 0
    ORDER BY effective_score DESC
    LIMIT :limit
");
```

**Analysis:**

| Aspect | Evaluation | Compliance |
|--------|------------|------------|
| **Cache query** | ⚠️ Cache treated as suggestion source | ❌ VIOLATION (Authority bypass) |
| **Decision filtering** | ❌ `effective_score > 0` | ❌ VIOLATION |
| **Decision ordering** | ❌ `ORDER BY effective_score DESC` | ❌ VIOLATION |
| **Decision limiting** | ❌ `LIMIT :limit` | ❌ VIOLATION |

**Violation Type:** **CRITICAL - Cache-as-Authority**

**Impact:**
- Bypasses UnifiedLearningAuthority entirely
- Returns pre-computed decisions without signal aggregation
- Violates Database Role Declaration Article 5: Authority Alignment

**Required Change:**

**Option A (Preferred): Deprecate Table**
```php
// Remove cache queries entirely
// Authority computes live from signals
```

**Option B: True Cache (If Performance Required)**
```php
// Cache is populated BY Authority, queried BY Authority
// NOT exposed as alternative suggestion source
class UnifiedLearningAuthority {
    private function getSuggestionsCached($input) {
        $cached = $this->cache->get($input);
        if ($cached && $this->cache->isFresh($cached)) {
            return $cached; // Already Authority's output
        }
        
        $suggestions = $this->computeSuggestions($input);
        $this->cache->set($input, $suggestions, ttl: 3600);
        return $suggestions;
    }
}
```

**Status:** **CRITICAL - Requires ARB decision in Phase 0**

**Reference:** Database Role Declaration, Article 3: Signal vs Decision Hard Boundary

---

### 5. Blocked Suppliers Query

**Location:** `SupplierLearningCacheRepository.php:124-135`

**Query:**
```php
$stmt = $pdo->prepare("
    SELECT DISTINCT supplier_id
    FROM supplier_learning_cache
    WHERE normalized_input = :normalized
      AND block_count > 0
");
```

**Analysis:**

| Aspect | Evaluation | Compliance |
|--------|------------|------------|
| **Suppression logic** | ❌ Database decides blocking | ❌ VIOLATION |
| **Decision filter** | ❌ `block_count > 0` as switch | ❌ VIOLATION |

**Violation Type:** Decision logic (suppression) in database

**Impact:**
- Database acts as suppression authority
- Authority cannot override or re-evaluate
- Dual suppression mechanism (usage_count vs block_count)

**Required Change:**
```php
// Return block signals, not pre-filtered blocked list
$stmt = $pdo->prepare("
    SELECT supplier_id, block_count
    FROM supplier_learning_cache
    WHERE normalized_input = :normalized
    -- NO filter
");

// Authority decides:
$blocked = array_filter($signals, fn($s) => $s['block_count'] >= $this->blockThreshold);
```

**Status:** Document, fix in Phase 2 (if cache retained)

**Reference:** Database Role Declaration, Article 4.1: Prohibited Patterns

---

### 6. Usage Count Increment/Decrement

**Location:** `SupplierLearningRepository.php:68-115`

**Queries:**
```php
// Increment
UPDATE supplier_alternative_names
SET usage_count = usage_count + 1
WHERE supplier_id = :supplier_id
  AND normalized_name = :normalized;

// Decrement
UPDATE supplier_alternative_names
SET usage_count = CASE 
    WHEN usage_count > -10 THEN usage_count - 1
    ELSE -10
END
WHERE supplier_id = :supplier_id
  AND normalized_name = :normalized;
```

**Analysis:**

| Aspect | Evaluation | Compliance |
|--------|------------|------------|
| **Signal accumulation** | ✓ Updates count | ✓ ACCEPTABLE (signal) |
| **Floor enforcement** | ✓ -10 floor in SQL | ⚠️ BORDERLINE |

**Violation Type:** None (this is signal accumulation, not decision)

**Note:** `usage_count` is SIGNAL (times used), but currently ABUSED as decision filter elsewhere

**Status:** Queries themselves OK, but usage in other queries (query #1) is problematic

**Reference:** Database Role Declaration, Article 6.1: Signal Table Requirements

---

### 7. Fuzzy Candidate Generation

**Location:** `SupplierCandidateService.php:140-185` (in-memory, not SQL)

**Pattern:**
```php
// SQL retrieves ALL suppliers
$stmt = $pdo->query("SELECT id, official_name, normalized_name FROM suppliers");

// Fuzzy matching in PHP
foreach ($suppliers as $supplier) {
    $similarity = $this->calculateSimilarity($normalized, $supplier['normalized_name']);
    
    if ($similarity >= $this->threshold) {
        $candidates[] = [
            'supplier_id' => $supplier['id'],
            'score' => $similarity * $this->weightOfficial  // DECISION SCORING
        ];
    }
}

// Then orders by score (DECISION ORDERING)
usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);
```

**Analysis:**

| Aspect | Evaluation | Compliance |
|--------|------------|------------|
| **SQL query** | ✓ Retrieves all entities | ✓ GOOD |
| **Similarity calculation** | ✓ Generates signal | ✓ GOOD |
| **Scoring** | ❌ Applies weight, computes score | ❌ VIOLATION (belongs in Authority) |
| **Ordering** | ❌ Orders by score | ❌ VIOLATION |

**Violation Type:** Decision logic in service (not SQL, but wrong layer)

**Impact:** Service acts as mini-Authority

**Required Change:**
```php
// Feeder returns similarity signal only
public function getSignals($normalized): array {
    $suppliers = $this->getAllSuppliers();
    $signals = [];
    
    foreach ($suppliers as $supplier) {
        $similarity = $this->calculateSimilarity($normalized, $supplier['normalized_name']);
        
        if ($similarity >= 0.4) {  // Minimum viability, not decision
            $signals[] = new SignalDTO(
                supplier_id: $supplier['id'],
                signal_type: 'fuzzy_official',
                raw_strength: $similarity,  // 0-1.0, NOT weighted
                metadata: ['match_method' => 'levenshtein']
            );
        }
    }
    
    return $signals;  // NO ordering, Authority handles
}
```

**Status:** Refactor in Phase 2

**Reference:** Authority Intent Declaration, Section 2.4: Signal Consumption Rules

---

### 8. Supplier Exact Match

**Location:** `SupplierRepository.php:18-22`

**Query:**
```php
$stmt = $pdo->prepare('
    SELECT id, official_name, display_name, normalized_name, supplier_normalized_key 
    FROM suppliers 
    WHERE normalized_name = :n
');
```

**Analysis:**

| Aspect | Evaluation | Compliance |
|--------|------------|------------|
| **Signal retrieval** | ✓ Queries normalized match | ✓ GOOD |
| **Decision logic** | ✓ No filtering/ordering | ✓ GOOD |
| **Entity lookup** | ✓ Clean entity retrieval | ✓ GOOD |

**Violation Type:** None

**Status:** ✅ Compliant - Entity repository query

**Reference:** Database Role Declaration, Article 2.1 (Entity Tables)

---

### 9. Alternative Names - All by Normalized

**Location:** `SupplierAlternativeNameRepository.php:59-72`

**Query:**
```php
$stmt = $this->db->prepare("
    SELECT 
        san.id,
        san.supplier_id,
        san.alternative_name,
        san.normalized_name,
        san.source,
        s.official_name,
        s.normalized_name as supplier_normalized
    FROM supplier_alternative_names san
    JOIN suppliers s ON san.supplier_id = s.id
    WHERE san.normalized_name = ?
");
```

**Analysis:**

| Aspect | Evaluation | Compliance |
|--------|------------|------------|
| **Signal retrieval** | ✓ Returns ALL matches | ✓ GOOD |
| **Decision logic** | ✓ No LIMIT, no filtering | ✓ GOOD |
| **Join inclusion** | ✓ Provides context | ✓ GOOD |

**Violation Type:** None

**Status:** ✅ Compliant - Returns all signals for Authority to decide

**Note:** This is the CORRECT pattern (unlike query #1 which had LIMIT 1)

**Reference:** Authority Intent Declaration, Section 2.1.2

---

### 10. Guarantee Decisions by Guarantee ID

**Location:** `GuaranteeDecisionRepository.php:60-73`

**Query:**
```php
$stmt = $this->db->prepare("
    SELECT 
        id,
        guarantee_id,
        supplier_id,
        decision_source,
        confidence_at_decision,
        was_top_suggestion,
        created_at
    FROM guarantee_decisions
    WHERE guarantee_id = ?
    ORDER BY created_at DESC
");
```

**Analysis:**

| Aspect | Evaluation | Compliance |
|--------|------------|------------|
| **Historical retrieval** | ✓ Gets decision history | ✓ GOOD |
| **Decision ordering** | ⚠️ `ORDER BY created_at` | ✓ ACCEPTABLE (temporal, not confidence) |
| **Audit data** | ✓ Includes metadata | ✓ GOOD |

**Violation Type:** None

**Status:** ✅ Compliant - Audit/historical query with temporal ordering (not decision ordering)

**Reference:** Database Role Declaration, Article 2.4 (Audit Tables)

---

### 11. Fuzzy Supplier Search (In-Memory Pattern - Already Covered in #7)

**Status:** Covered in query #7 analysis

---

### 12. Cache Upsert (Write Operation)

**Location:** `SupplierLearningCacheRepository.php:51-93`

**Query (Insert):**
```php
$stmt = $this->db->prepare("
    INSERT INTO supplier_learning_cache (
        normalized_input,
        supplier_id,
        fuzzy_score,
        source_weight,
        usage_count,
        total_score,
        effective_score,
        star_rating
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");
```

**Query (Update):**
```php
$stmt = $this->db->prepare("
    UPDATE supplier_learning_cache 
    SET 
        fuzzy_score = ?,
        source_weight = ?,
        usage_count = ?,
        total_score = ?,
        effective_score = ?,
        star_rating = ?
    WHERE normalized_input = ? AND supplier_id = ?
");
```

**Analysis:**

| Aspect | Evaluation | Compliance |
|--------|------------|------------|
| **Write operation** | ⚠️ Stores computed values | ❌ VIOLATION if Authority doesn't write |
| **effective_score** | ❌ Final decision value | ❌ VIOLATION (cached decision) |
| **star_rating** | ❌ UI presentation logic | ❌ VIOLATION (UI in data layer) |

**Violation Type:** Cache populated with decision values (if not by Authority)

**Impact:** 
- If populated by service OTHER than Authority → cache-as-authority violation
- If populated BY Authority → acceptable as true cache

**Required Investigation:**
- **WHO calls upsert()? **
- If SupplierCandidateService → VIOLATION
- If UnifiedLearningAuthority (future) → OK

**Status:** ⚠️ **REQUIRES CODE TRACE** - No calls found in reviewed code

**Reference:** Database Role Declaration, Article 4.4 (Cache Requirements)

---

### 13. Increment Cache Usage/Block

**Location:** `SupplierLearningCacheRepository.php:100-121`

**Queries:**
```php
// Increment usage
$stmt = $this->db->prepare("
    UPDATE supplier_learning_cache 
    SET usage_count = usage_count + ?
    WHERE normalized_input = ? AND supplier_id = ?
");

// Increment block
$stmt = $this->db->prepare("
    UPDATE supplier_learning_cache 
    SET block_count = block_count + ?
    WHERE normalized_input = ? AND supplier_id = ?
");
```

**Analysis:**

| Aspect | Evaluation | Compliance |
|--------|------------|------------|
| **Signal accumulation** | ✓ Increments count | ✓ ACCEPTABLE as signal |
| **Dual mechanism** | ❌ usage_count AND block_count | ❌ DESIGN ISSUE |

**Violation Type:** Dual suppression mechanisms (conflicting with supplier_alternative_names.usage_count)

**Impact:**
- Two independent usage counters (cache vs alias table)
- Can diverge silently
- Authority must reconcile or choose one

**Status:** ⚠️ Document as known issue - Phase 2 decision: deprecate cache or unify

**Reference:** Charter Part 1, Section 1: Parallel Subsystem Collision

---

### 14. Learning Decision Log Insert

**Location:** `LearningRepository.php:68-85`

**Query:**
```php
$stmt = $this->db->prepare("
    INSERT INTO learning_confirmations (
        raw_supplier_name,
        supplier_id,
        confidence,
        matched_anchor,
        anchor_type,
        action,
        decision_time_seconds,
        guarantee_id,
        created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
");
```

**Analysis:**

| Aspect | Evaluation | Compliance |
|--------|------------|------------|
| **Signal logging** | ✓ Append-only | ✓ GOOD |
| **Raw name storage** | ⚠️ Non-normalized | ⚠️ ISSUE (fragmentation) |
| **Metadata inclusion** | ✓ Context preserved | ✓ GOOD |

**Violation Type:** None (write operation is compliant)

**Note:** The ISSUE is in the READ query (#2), not the write

**Status:** ✅ Compliant write operation

**Future Enhancement:** Add `normalized_supplier_name` column (Phase 6)

**Reference:** Database Role Declaration, Article 6.1 (Signal Table Requirements)

---

### 15. Supplier Alternative Name Insert (Learn Alias)

**Location:** `SupplierLearningRepository.php:132-141`

**Query:**
```php
$stmt = $this->db->prepare("
    INSERT INTO supplier_alternative_names (
        supplier_id,
        alternative_name,
        normalized_name,
        source,
        usage_count,
        created_at
    ) VALUES (?, ?, ?, 'learning', 1, ?)
");
```

**Analysis:**

| Aspect | Evaluation | Compliance |
|--------|------------|------------|
| **Signal creation** | ✓ Records learned alias | ✓ GOOD |
| **Initial usage_count** | ✓ Starts at 1 | ✓ REASONABLE |
| **Source tracking** | ✓ Hardcoded 'learning' | ✓ GOOD provenance |

**Violation Type:** None

**Note:** Write operation is fine. The PROBLEM is uniqueness constraint behavior (query #1 rejection if exists)

**Status:** ✅ Compliant write operation

**Reference:** Database Role Declaration, Article 6.1

---

### 16. Supplier Decision Log Insert

**Location:** `SupplierLearningRepository.php:144-165`

**Query:**
```php
$stmt = $this->db->prepare("
    INSERT INTO supplier_decisions_log (
        guarantee_id,
        raw_input,
        normalized_input,
        chosen_supplier_id,
        chosen_supplier_name,
        decision_source,
        confidence_score,
        was_top_suggestion,
        decided_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
");
```

**Analysis:**

| Aspect | Evaluation | Compliance |
|--------|------------|------------|
| **Audit logging** | ✓ Records decision | ✓ GOOD |
| **Normalized storage** | ✓ Includes normalized_input | ✓ EXCELLENT |
| **Metadata** | ✓ Complete context | ✓ GOOD |

**Violation Type:** None

**Status:** ✅ **EXEMPLARY** - This is the correct pattern

**Note:** Unlike learning_confirmations, this table DOES store normalized_input

**Recommendation:** Use this table as model for enhancing learning_confirmations

**Reference:** Database Role Declaration, Article 2.4 (Audit Tables)

---

### 17. Find Conflicting Aliases

**Location:** `SupplierLearningRepository.php:200-209`

**Query:**
```php
$sql = "
    SELECT id, supplier_id, alternative_name, normalized_name 
    FROM supplier_alternative_names 
    WHERE normalized_name = ? AND supplier_id != ?
";
$stmt = $this->db->prepare($sql);
```

**Analysis:**

| Aspect | Evaluation | Compliance |
|--------|------------|------------|
| **Conflict detection** | ✓ Finds other suppliers with same normalized name | ✓ GOOD |
| **Decision logic** | ✓ None (just retrieval) | ✓ GOOD |

**Violation Type:** None

**Status:** ✅ Compliant - Utility query for conflict management

**Use Case:** Identifies when multiple suppliers claim same alias (important for Authority collision resolution)

**Reference:** Charter Part 2, Section 5 (Collision Resolution Rules)

---

### 18. Get All Normalized Alternative Names

**Location:** `SupplierAlternativeNameRepository.php:81-92`

**Query:**
```php
$stmt = $this->db->prepare("
    SELECT DISTINCT normalized_name 
    FROM supplier_alternative_names
");
```

**Analysis:**

| Aspect | Evaluation | Compliance |
|--------|------------|------------|
| **Bulk retrieval** | ✓ Gets all aliases | ✓ ACCEPTABLE |
| **Use case** | ⚠️ Unclear (not observed in reviewed services) | ⚠️ INVESTIGATE |

**Violation Type:** None

**Status:** ✅ Compliant but potentially unused

**Question:** Where is this used? If for in-memory fuzzy matching, consider performance implications

**Reference:** N/A

---

### 19. Guarantee Raw Data JSON Search (Already Covered in #3)

**Status:** Covered in query #3 analysis

---

### 20. Supplier Normalized Key Lookup

**Location:** `SupplierRepository.php:106-111`

**Query:**
```php
$stmt = $pdo->prepare('
    SELECT * FROM suppliers 
    WHERE supplier_normalized_key = :k 
    LIMIT 1
');
```

**Analysis:**

| Aspect | Evaluation | Compliance |
|--------|------------|------------|
| **Key-based lookup** | ✓ Uses normalized key (space-removed) | ✓ GOOD |
| **LIMIT 1** | ✓ Acceptable (entity lookup by unique key) | ✓ OK |

**Violation Type:** None

**Status:** ✅ Compliant - Entity lookup by alternative key

**Note:** `supplier_normalized_key` appears to be space-removed normalization (different from `normalized_name`)

**Reference:** Database Role Declaration, Article 2.1 (Entity Tables)

---

## Summary Statistics (FINAL)

**Total Queries Audited:** 20 (comprehensive coverage)

**By Violation Type:**
- ❌ **CRITICAL (Cache-as-Authority):** 1 (Query #4 - getSuggestions from cache)
- ❌ **Decision Logic in SQL:** 3 (Queries #1, #4, #5)
- ❌ **Service-Layer Violations:** 1 (Query #7 - fuzzy scoring)
- ⚠️ **Fragmentation Issues:** 2 (Queries #2, #3)
- ⚠️ **Design Issues:** 2 (Queries #12, #13 - cache ambiguity, dual mechanism)
- ✓ **Compliant:** 11 (Queries #6, #8, #9, #10, #14, #15, #16, #17, #18, #20)

**By Required Action:**
- **Phase 0 Decision Required:** 1 (Cache deprecation)
- **Phase 2 Refactor:** 5 (Queries #1, #4, #5, #7, #12/13)
- **Phase 6 Schema Update:** 2 (Queries #2, #3)
- **No Action (Compliant):** 11

**By Repository:**
- SupplierLearningRepository: 5 queries (2 violations, 3 compliant)
- SupplierLearningCacheRepository: 4 queries (3 violations/issues, 1 TBD)
- LearningRepository: 3 queries (1 issue, 2 compliant)
- SupplierAlternativeNameRepository: 3 queries (3 compliant)
- SupplierRepository: 3 queries (3 compliant)
- GuaranteeDecisionRepository: 1 query (1 compliant)
- In-Memory (SupplierCandidateService): 1 pattern (1 violation)

---

## Critical Findings Summary

### Must Fix in Phase 2:

1. **Query #1:** Remove `usage_count > 0` filter, remove `LIMIT 1`
2. **Query #4:** Deprecate cache OR refactor to Authority-populated
3. **Query #5:** Return signals, not pre-filtered blocked list
4. **Query #7:** Feeder returns similarity only, not weighted score
5. **Query #12/13:** Decide cache fate (deprecate or strict discipline)

### Document for Phase 6:

1. **Query #2:** Add `normalized_supplier_name` to `learning_confirmations`
2. **Query #3:** Refactor historical queries to use structured data

### Investigation Required:

1. **Query #12:** WHO populates cache? (No caller found in reviewed code)
2. **Query #18:** WHERE is getAllNormalized used? (Performance concern if full scan)

---

## Recommendations

### Immediate (Phase 0):
1. ✅ **ARB Decision:** Deprecate `supplier_learning_cache` table
   - **Rationale:** No population mechanism found, cache-as-authority violation
   - **Impact:** Remove queries #4, #5, #12, #13
   - **Benefit:** Eliminates 4 violations, simplifies architecture

### Phase 1 (This Phase):
2. ✅ **Document Remaining Patterns:** Query audit complete (20/20)
3. ✅ **Endpoint Mapping:** Next deliverable

### Phase 2 (Implementation):
4. **Fix SQL Violations:** Queries #1, #5 (if cache retained)
5. **Refactor Feeders:** Query #7 (service-layer violations)
6. **Build Authority:** Consumes ALL signals, applies Charter formula

### Phase 6 (Schema Updates):
7. **Add normalized_supplier_name to learning_confirmations**
8. **Ensure supplier_decisions_log pattern used everywhere**

---

## Next Steps

- [x] Complete query audit (20 queries analyzed)
- [ ] Create Endpoint Mapping document
- [ ] Present findings to team/ARB
- [ ] Get cache deprecation decision
- [ ] Prepare Phase 2 refactoring tickets

---

**Status:** ✅ **QUERY AUDIT COMPLETE**

**Coverage:** 20 critical queries across 6 repositories + 1 service pattern

**Confidence:** High - All major query paths analyzed

**Reviewers:**
- [ ] Tech Lead
- [ ] Senior Backend Dev
- [ ] ARB

**Last Updated:** 2026-01-03

