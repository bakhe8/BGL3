# LEARNING UNIFICATION CHARTER (DRAFT) - PART 1

## GOVERNANCE FRAMEWORK FOR SUPPLIER LEARNING CONSOLIDATION

**Document Status:** DRAFT - Requires Approval Before Implementation  
**Phase:** Exploration → Consolidation Transition  
**Authority:** System Architecture & Learning Governance  

---

## 0. CURRENT REALITY SNAPSHOT (NO OPINIONS)

### Evidence-Based Inventory of Parallel Subsystems

The BGL3 system currently operates **THREE DISTINCT supplier suggestion subsystems**, each with independent logic, data artifacts, and UI contracts.

---

### SUBSYSTEM A: Hybrid Learning System (ADR-009)

**Entry Points:**
- Service: `LearningSuggestionService` (app/Services/Suggestions/LearningSuggestionService.php)
- Method: `getSuggestions(string $rawName): array`
- Called by: UNKNOWN (endpoint not reviewed, inferred from architecture)

**Data Artifacts Used:**
1. `learning_confirmations` table
   - Stores: User confirm/reject actions
   - Queried by: Raw supplier name (EXACT match, not normalized)
   - Aggregation: COUNT by supplier_id and action
   
2. `guarantees` + `guarantee_decisions` tables
   - Stores: Historical supplier selections
   - Queried by: JSON fragment matching via LIKE
   - Aggregation: FREQUENCY count per supplier_id

3. Entity anchor extraction (delegated to Subsystem C)

**Confidence Scoring Logic:**
```
Base Score (by source):
  - entity_anchor: 85
  - learned: 65
  - historical: 40

Boosts:
  - Confirmations: +0 to +15 (1 confirm=+5, 2=+10, 3+=+15)
  - Historical: +0 to +20 (3-4 selections=+10, 5+=+20)

Penalties:
  - Rejections: -33.4 per rejection

Final = base + confirmBoost + histBoost - (rejections × 33.4)
Clamped: max(0, min(100, final))

Level Assignment:
  - ≥85: Level B
  - ≥65: Level C
  - ≥40: Level D
  - <40: NULL (not shown)
```

**Filtering/Blocking:**
- NONE within this subsystem
- Relies on entity anchor subsystem's silent rejection
- No usage_count filtering
- No block_count filtering

**Reason Expression to UI:**
```php
Parts concatenated with ' + ':
- If rejections: "تم رفضه {count} مرات"
- If matched_anchor: "كلمة مميزة: '{anchor}'"
- If confirmations: "تم تأكيده {count} مرات"
- Else if historical: "تم اختياره {count} مرات"
- If Level D: "بيانات تاريخية محدودة"
```

**Collisions with Other Subsystems:**
1. **With Subsystem B (SupplierCandidateService):**
   - Subsystem A uses raw name matching for confirmations
   - Subsystem B uses normalized name matching
   - Input "شركة النورس " vs "شركة النورس" create different learning histories in A, same in B
   - Result: Confidence divergence

2. **With Subsystem C (Entity Anchors):**
   - Subsystem A depends on C for entity_anchor candidates
   - If C returns empty (Golden Rule), A only shows learned/historical (lower confidence)
   - Same supplier may appear as Level B (via C) or Level C (via confirmations only)

---

### SUBSYSTEM B: Fuzzy Matching Candidate Service

**Entry Points:**
- Service: `SupplierCandidateService` (app/Services/SupplierCandidateService.php)
- Method: `supplierCandidates(string $rawSupplier): array`
- Called by: UNKNOWN (endpoint not reviewed, likely used in manual entry flows)

**Data Artifacts Used:**
1. `suppliers` table (all rows cached in memory)
2. `supplier_alternative_names` table (all rows for fuzzy, filtered for exact)
3. `supplier_override_names` table
4. `supplier_learning_cache` table (optional, mechanism unclear)

**Confidence Scoring Logic:**
```
Similarity Calculation:
  - Methods: exact, starts_with, contains, levenshtein, token_jaccard
  - Takes MAX of all methods
  
Weighting (by source):
  - WEIGHT_OFFICIAL (default ~0.90): exact/strong official matches
  - WEIGHT_FUZZY (default ~0.85): fuzzy official matches
  - WEIGHT_ALT_CONFIRMED (default ~0.95): exact alternative name matches
  - Learning cache: LEARNING_SCORE_CAP (default 0.90)

Final score = rawSimilarity × weight

Thresholds:
  - MATCH_AUTO_THRESHOLD (default 0.90): Auto-accept
  - MATCH_REVIEW_THRESHOLD (default 0.70): Minimum to display
  - MATCH_WEAK_THRESHOLD (default 0.80): Fuzzy cutoff
```

**Filtering/Blocking:**
- **Block list:** Queries `supplier_learning_cache` for `block_count > 0`
- Blocked suppliers excluded from ALL sources (overrides, official, alternatives)
- **usage_count filter:** Alternative names queried without usage_count threshold (different from Subsystem A's SupplierLearningRepository behavior)

**Reason Expression to UI:**
- NONE observed in code
- Returns: supplier_id, name, score, source, match_type, strength
- No Arabic reason string
- No explanation of why suggested

**Collisions with Other Subsystems:**
1. **With Subsystem A:**
   - Different scoring semantics (fuzzy similarity vs base+boost)
   - Different thresholds (70% vs 85%/65%/40%)
   - Different source labels ('learning' vs 'fuzzy_alternative')
   - If both called, UI receives two different "confidences" for same supplier

2. **With Subsystem D (Direct Alias Lookup):**
   - Subsystem B performs its own alternative_names queries
   - May duplicate/conflict with standalone alias lookup logic

---

### SUBSYSTEM C: Entity Anchor-Based Suggestions (ADR-007)

**Entry Points:**
- Service: `ArabicLevelBSuggestions` (app/Services/Suggestions/ArabicLevelBSuggestions.php)
- Method: `find(string $normalized, int $limit = 5): array`
- Called by: LearningSuggestionService (Subsystem A), possibly others

**Data Artifacts Used:**
1. `suppliers` table (queried via LIKE for anchor matching)
2. Entity anchor extraction (in-memory algorithm, no persistence)
3. Activity word extraction (in-memory algorithm)

**Confidence Scoring Logic:**
```
Precondition: Anchors extracted, if empty → return []

Anchor Uniqueness Check:
  - Query: COUNT suppliers WHERE name LIKE '%anchor%'
  - If count ≤ 3: unique
  - Else: generic

Scoring Formula:
  - 2+ unique anchors: 95%
  - 1 unique anchor + activity word: 90%
  - 1 unique anchor: 85%
  - 2+ generic anchors: 80%
  - 1 generic anchor + activity word: 75%
  - 1 generic anchor: 70%
  - 0 anchors: 0% (filtered)

Fuzzy Anchor Matching:
  - Levenshtein distance ≥ 70% similarity
```

**Filtering/Blocking:**
- **Min confidence:** 70% hardcoded (const MIN_CONFIDENCE)
- **Limit:** 5 suggestions default
- **Silent rejection:** No anchors = empty result, logged to error_log only

**Reason Expression to UI:**
```php
"تطابق اسم تجاري مميز: '{anchor}'"
Always includes matched anchor
Level always 'B' (candidates below 70% already filtered)
```

**Collisions with Other Subsystems:**
1. **With Subsystem A:**
   - Subsystem A calls C via `$this->arabicService->find()`
   - C's results become base_score=85 candidates in A
   - Then A applies confirmations/rejections boosts/penalties
   - Result: Same supplier may score 85% via C alone, or 100% via C+confirmations
   - User sees changing confidence for same anchor-based match

2. **With Subsystem B:**
   - Both perform supplier name matching
   - C uses entity anchors (strict), B uses fuzzy similarity (lenient)
   - C may return empty, B may return matches
   - If both called, B's fuzzy matches have no anchor explanation
   - Inconsistent reasoning

3. **Dynamic Scoring:**
   - Anchor uniqueness recalculated live on every query
   - Adding suppliers changes uniqueness → changes scores WITHOUT CODE CHANGE
   - Non-deterministic behavior over time

---

### SUBSYSTEM D: Direct Alias Lookup (Legacy/Implied)

**Entry Points:**
- Service: `SupplierLearningRepository` (app/Repositories/SupplierLearningRepository.php)
- Method: `findSuggestions(string $normalizedName, int $limit = 5): array`
- Called by: UNKNOWN (LearningService uses it, unclear if exposed to endpoints directly)

**Data Artifacts Used:**
1. `supplier_alternative_names` table (WHERE normalized_name = ? AND usage_count > 0)

**Confidence Scoring Logic:**
```
If alias found: 100% confidence, source='alias', LIMIT 1
Else: Falls through to fuzzy search on suppliers table (scoring unclear)
```

**Filtering/Blocking:**
- **usage_count threshold:** > 0 (excludes penalized aliases)
- **First match wins:** LIMIT 1, no ordering → non-deterministic if duplicates exist

**Reason Expression to UI:**
- Returns: source='alias', score=100
- No Arabic explanation
- Assumes UI knows what 'alias' means

**Collisions with Other Subsystems:**
1. **With Subsystem B:**
   - Both query `supplier_alternative_names`
   - D returns first match with 100% score
   - B aggregates all matches, applies fuzzy scoring
   - If both called, different results for same input

2. **With Subsystem A:**
   - D uses normalized name, A's confirmations use raw name
   - Learning divergence (Section 5.2 of forensic analysis)

---

### SUBSYSTEM E: Learning Action Service (Write Path)

**Entry Points:**
- Service: `LearningService` (app/Services/LearningService.php)
- Methods: 
  - `learnFromDecision(int $guaranteeId, array $input): void`
  - `penalizeIgnoredSuggestion(int $ignoredSupplierId, string $rawName): void`

**Data Artifacts Mutated:**
1. `supplier_alternative_names`: via `learnAlias()`, `incrementUsage()`, `decrementUsage()`
2. `supplier_decisions_log`: via `logDecision()`

**Learning Logic:**
```
learnFromDecision():
  If source === 'manual':
    - learnAlias() → checks if normalized_name exists, inserts if not
    - incrementUsage() → usage_count++
  Always:
    - logDecision() → append to log

penalizeIgnoredSuggestion():
  - decrementUsage() → usage_count--, floor at -5
```

**Collisions with Other Subsystems:**
1. **First-learned bias:** If Subsystem D or B already learned alias, new selections ignored (Section 5.3)
2. **Normalization drift:** Old aliases with old normalization become orphaned (Section 5.1)
3. **No coordination:** Write path doesn't coordinate with read paths (A, B, C, D)

---

### COLLISION SUMMARY TABLE

| Scenario | Subsystems Involved | Manifestation | Root Cause |
|----------|---------------------|---------------|------------|
| Same input, different confidences | A, B | Supplier shows 85% in one flow, 90% in another | Different scoring formulas |
| Alias not found after normalization update | D, E | Learned alias orphaned, not matched | Write uses old normalization, read uses new |
| Confirmation fragmentation | A, E | Same supplier has separate learning histories per input variant | A queries raw name, normalization not applied before query |
| Block vs penalty mismatch | B, D | B blocks supplier (block_count), D filters by usage_count | Two independent suppression mechanisms |
| Empty suggestions despite confirmations | A, C | User confirmed 5 times, but no entity anchor → empty | C's Golden Rule overrides A's learned data |
| Fuzzy match conflicts with anchor match | B, C | B suggests supplier X (fuzzy 88%), C suggests Y (anchor 85%) | Independent candidate generation |

---

## 1. PROBLEM STATEMENT (CONSOLIDATION PHASE)

### Fragmentation Evidence

**Fragmentation Type 1: Learning History Splitting**

Observed behavior:
- User types "مؤسسة التعاون" → Subsystem A queries `learning_confirmations` with exact raw string
- User types "مؤسسة   التعاون" (extra space) → Subsystem A queries with different string
- Result: TWO separate confirmation histories for normalization-equivalent inputs

Code evidence:
```php
// LearningRepository.php line 30
WHERE raw_supplier_name = ?
// No normalization applied before query
```

Consequence:
- Confirmation count=1 for each variant
- True confirmation count=5 (if aggregated across variants)
- Confidence underestimated, suggestions suppressed incorrectly

**Fragmentation Type 2: Alias vs Confirmation Divergence**

Observed behavior:
- Subsystem E normalizes before storing alias
- Subsystem A does NOT normalize before querying confirmations
- Input variants converge in aliases, diverge in confirmations

Code evidence:
```php
// SupplierLearningRepository.php line 71
$norm = $this->normalize($rawName); // Alias storage uses normalization

// LearningRepository.php line 30  
WHERE raw_supplier_name = ?  // Confirmation query does NOT normalize
```

Consequence:
- Alias lookup succeeds (normalized convergence)
- Confirmation boost fails (raw divergence)
- Same supplier appears with inconsistent confidence boosts

**Fragmentation Type 3: Normalization Evolution Orphaning**

Observed behavior:
- System deployed with ArabicNormalizer Phase 1
- Aliases learned and stored with Phase 1 normalization
- System updated to Phase 2 (ة→ه transformation added)
- Old aliases no longer match new queries

Code evidence: Section 5.1 of forensic analysis, no migration mechanism observed

Consequence:
- Database accumulates dead aliases (usage_count frozen)
- New aliases created for same conceptual input
- Fragmented learning across normalization versions

---

### Collision Evidence

**Collision Type 1: Scoring Semantics Clash**

Observed behavior:
- Subsystem A: base + boosts - penalties (range 0-100+, clamped)
- Subsystem B: similarity × weight (range 0-1.0)
- Both called for same input, both return "confidence" or "score"

UI receives:
```json
From A: {"supplier_id": 10, "confidence": 85, "level": "B"}
From B: {"supplier_id": 10, "score": 0.92}
```

Consequence:
- User sees two different numbers (85 vs 0.92 = 92?)
- Unclear which is "true" confidence
- Different thresholds (A: ≥85 for B, B: ≥0.90 for auto-accept)
- Same supplier, different actionability

**Collision Type 2: First-Learned Lock-In**

Observed behavior:
- User A selects Supplier 5 for input "الوطنية" (T=0)
- Subsystem E learns alias: supplier_id=5, normalized="الوطنيه"
- User B selects Supplier 12 for input "الوطنية" (T=10)
- Subsystem E checks if "الوطنيه" exists → TRUE, returns silently

Code evidence:
```php
// SupplierLearningRepository.php lines 125-128
$stmt = $this->db->prepare("SELECT id FROM supplier_alternative_names WHERE normalized_name = ?");
if ($stmt->fetch()) {
    return; // Already exists, silent exit
}
```

Consequence:
- Supplier 5 "owns" the alias permanently
- Supplier 12 never learned for this input
- Subsystem D always suggests Supplier 5 (100% confidence)
- Incorrect confident suggestion if first learning was erroneous

**Collision Type 3: Block vs Penalty Dual Suppression**

Observed behavior:
- Subsystem B checks `block_count > 0` to exclude suppliers
- Subsystem D checks `usage_count > 0` to exclude aliases
- Independent mechanisms, can mismatch

Scenarios:
- Supplier blocked (block_count=3) but alias active (usage_count=5)
  - Subsystem D suggests (alias found)
  - Subsystem B filters (supplier blocked)
  - Collision: suggested or not?

- Supplier not blocked (block_count=0) but alias penalized (usage_count=-2)
  - Subsystem D skips (usage_count ≤ 0)
  - Subsystem B includes (not blocked)
  - Collision: suggested or not?

Consequence:
- Non-deterministic suppression depending on which subsystem runs
- User confusion ("why was it suggested yesterday but not today?")

---

### Non-Determinism Evidence

**Non-Determinism Type 1: Dynamic Anchor Uniqueness**

Observed behavior:
- Subsystem C calculates anchor uniqueness live on every query
- Query: `COUNT(*) FROM suppliers WHERE normalized_name LIKE '%anchor%'`
- If count changes (new suppliers added), uniqueness flips

Timeline:
```
T0: "النورس" matches 3 suppliers → unique → score 85%
T30: New supplier "مؤسسة النورس الجنوبية" added
T31: "النورس" matches 4 suppliers → generic → score 70%
```

Code evidence: ArabicLevelBSuggestions.php lines 225-237

Consequence:
- Same input, same code, DIFFERENT score
- Confidence drops without code change
- User sees inconsistency, loses trust

**Non-Determinism Type 2: First-Match Arbitrary Winner**

Observed behavior:
- Subsystem D queries `WHERE normalized_name = ? LIMIT 1`
- No ORDER BY clause
- If multiple rows exist (should not, but data quality issues), arbitrary row returned

Code evidence: SupplierLearningRepository.php line 26

Consequence:
- Unstable suggestions across identical requests
- Database query planner determines winner
- Debugging nightmare

---

### UI Incoherence Evidence

**UI Incoherence Type 1: Dual Reason Languages**

Observed outputs:
- Subsystem A: Arabic reason strings ("تم تأكيده 3 مرات + كلمة مميزة: 'النورس'")
- Subsystem C: Arabic reason strings ("تطابق اسم تجاري مميز: 'النورس'")
- Subsystem B: No reason, only source='fuzzy_official', match_type='fuzzy_strong'
- Subsystem D: No reason, only source='alias', score=100

If UI aggregates multiple subsystems:
- Some suggestions have explanations, others don't
- Explanations use different formats
- User cannot predict what information they'll receive

**UI Incoherence Type 2: Level Semantics Mismatch**

Subsystem A levels:
- Level B: ≥85% (high confidence)
- Level C: ≥65% (medium)
- Level D: ≥40% (low)

Subsystem B has no levels, only scores:
- ≥90%: auto-accept threshold
- ≥70%: review threshold

If UI tries to map B's scores to A's levels:
- 88% from B → Level B or waiting? (B requires ≥85, but B's auto threshold is ≥90)
- Inconsistent user experience

**UI Incoherence Type 3: Color/Badge Fragmentation** (INFERRED)

If different subsystems drive different UI paths:
- Entity anchor suggestions may show blue badge "تطابق مميز"
- Learned suggestions may show green badge "تم التعلم"
- Fuzzy suggestions may show yellow badge "تشابه عالي"
- Cache suggestions may show no badge at all

Result:
- User sees different visual presentations for conceptually similar confidence
- Cannot build mental model of system behavior
