# SUPPLIER LEARNING LOGIC MAP - PART 3: OUTCOME RECONSTRUCTION & DIVERGENCE

## 4. OUTCOME-DRIVEN PATH RECONSTRUCTION

This section starts from OBSERVED or PLAUSIBLE outcomes and traces backward to identify HOW the system's logic could produce them.

---

### 4.1 OUTCOME: Same Supplier Suggested Multiple Times in One Response

**Observable manifestation:** User sees supplier "شركة النورس للتجارة" appearing twice in suggestion list with different confidence scores

**Path Reconstruction:**

**Plausible Path A: Multi-Source Initial Generation**
1. LearningSuggestionService.getSuggestions() called
2. Entity anchor extraction finds "النورس" (anchor)
3. ArabicLevelBSuggestions.find() returns supplier_id=42 with confidence=85 (base entity_anchor score)
4. User previously confirmed this supplier 2 times → confirmationsMap[42]=2
5. Line 70-83: candidate already exists from Step 3, SKIP adding learned candidate
6. Line 108: confidence calculated for candidate[42]: 85 (base entity_anchor) + 10 (2 confirms) = 95
7. **Result:** Single entry, confidence 95, appears once

**Plausible Path B: Race Condition in Deduplication (if concurrent access)**
1. Two threads/requests simultaneously generate candidates
2. Both create candidates for supplier_id=42 from different sources
3. Deduplication happens per-request, not globally
4. **Result:** If responses merged at UI layer, appears twice
5. **Likelihood:** LOW (single-threaded PHP execution per request)

**Plausible Path C: Cache + Live Mismatch**
1. If LearningSuggestionService used caching (not observed in code but architecturally possible)
2. Cache returns supplier_id=42, confidence=90
3. Live calculation also generates supplier_id=42, confidence=85
4. If caching layer doesn't deduplicate before return, both included
5. **Result:** Duplicate entries
6. **Evidence:** No caching observed in current LearningSuggestionService implementation

**ACTUAL CURRENT LOGIC VERDICT:**
- Current code PREVENTS this outcome via deduplication (lines 100-101, 263-268)
- If this outcome occurs, it indicates:
  - External aggregation layer merging multiple suggestion sources
  - UI displaying cached + fresh suggestions without client-side dedup
  - OR: System behavior DIFFERS from reviewed code

---

### 4.2 OUTCOME: Supplier Not Suggested Despite Exact Name Match

**Observable manifestation:** User types "مؤسسة عبدالله" which exactly matches supplier in database, but NO suggestion appears

**Path Reconstruction:**

**Plausible Path A: Normalization Divergence**
1. Database contains: `alternative_name="مؤسسة عبدالله"` with `normalized_name="موسسه عبداللة"`
2. User input: "مؤسسة عبدالله"
3. Current normalization: ArabicNormalizer.normalize() produces "موسسه عبدالله"
4. Query: `SELECT * FROM supplier_alternative_names WHERE normalized_name = "موسسه عبدالله"`
5. No match because stored normalized value differs (ة→ه applied differently, or extra character)
6. Alternative source search also misses due to normalization drift
7. **Result:** Zero suggestions despite exact visual match

**Evidence:** ArabicNormalizer changes (line 56: ة → ه) may not have been applied retroactively to database

**Plausible Path B: Entity Anchor Requirement (LearningSuggestionService)**
1. User input: "مؤسسة عبدالله"
2. Entity anchor extraction identifies: [] (empty, "مؤسسة" is stop word, "عبدالله" may not be in entity list)
3. ArabicLevelBSuggestions.find() returns [] due to Golden Rule (line 60-63)
4. User has never confirmed this supplier → confirmationsMap empty
5. No historical selections → historicalMap empty
6. **Result:** Zero candidates generated, empty response

**Evidence:** Golden Rule strictly enforced, no anchor = no suggestion from that service

**Plausible Path C: Blocked Supplier**
1. User input normalized: "موسسه عبدالله"
2. supplier_learning_cache contains: `normalized_input="موسسه عبدالله"`, `supplier_id=15`, `block_count=3`
3. SupplierCandidateService.supplierCandidates() calls getBlockedSupplierIds()
4. Returns: [15]
5. All candidate generation paths (lines 155, 182, 219, 239) skip supplier_id=15
6. **Result:** Perfect match exists but filtered out

**Plausible Path D: usage_count <= 0**
1. Supplier alternative name learned initially: usage_count=1
2. User rejected it multiple times
3. Each rejection: decrementUsage() lowers usage_count
4. Current value: usage_count=-3
5. SupplierLearningRepository.findSuggestions() query: `WHERE usage_count > 0` (line 25)
6. **Result:** Alias exists but filtered out by usage threshold

**Plausible Path E: Score Below Threshold**
1. Supplier matches via fuzzy logic
2. Similarity score: 68%
3. MATCH_REVIEW_THRESHOLD setting: 70%
4. Line 178-179 in SupplierCandidateService: skip if score < threshold
5. **Result:** Candidate generated but immediately discarded

**OBSERVATION:**
- MULTIPLE independent paths can cause zero suggestions
- System gives NO INDICATION which path was taken
- User cannot differentiate between "not found" vs "found but blocked" vs "found but penalized"

---

### 4.3 OUTCOME: Different Confidence for Same Supplier on Successive Identical Inputs

**Observable manifestation:** User types "شركة النورس" → sees supplier_id=5 confidence=85%. Five minutes later, types "شركة النورس" again → sees supplier_id=5 confidence=90%

**Path Reconstruction:**

**Plausible Path A: Learning Accumulation**
1. First request (T=0):
   - No prior confirmations
   - Entity anchor match: base=85
   - Confidence=85
2. User confirms suggestion
3. LearningService.learnFromDecision() logs confirmation
4. learning_confirmations table: new row with action='confirm'
5. Second request (T=5min):
   - getUserFeedback() returns: confirmationsMap[5]=1
   - Entity anchor match: base=85
   - Confirmation boost: +5
   - Confidence=90
6. **Result:** Score increase due to intermediate user action

**Evidence:** Working as designed, confirmations accumulate

**Plausible Path B: Cache Refresh**
1. First request reads stale supplier_learning_cache
2. Cache entry has effective_score=85
3. Background process updates cache between requests
4. Cache entry updated to effective_score=90 (recalculated with new data)
5. Second request reads fresh cache
6. **Result:** Confidence change due to cache update timing

**Likelihood:** MEDIUM if cache auto-refresh exists (not observed in code)

**Plausible Path C: Anchor Uniqueness Change**
1. First request:
   - Anchor "النورس" matches 3 suppliers → unique (threshold=3)
   - isUniqueAnchor() returns true
   - Score: 85 (one unique anchor, no activity word)
2. Between requests: New supplier "مؤسسة النورس الثانية" added to database
3. Second request:
   - Anchor "النورس" now matches 4 suppliers → generic
   - isUniqueAnchor() returns false
   - Score: 70 (one generic anchor, no activity word)
4. **Result:** Confidence DECREASE due to database growth

**Evidence:** Uniqueness is dynamic, checked live (lines 225-237)

**Plausible Path D: Normalization Algorithm Change**
1. First request: ArabicNormalizer in Phase 1 mode
2. System updated, ArabicNormalizer now in Phase 2 mode
3. Second request: Different normalized output
4. Matches different alias entry or no alias at all
5. Falls back to different suggestion source with different base score
6. **Result:** Confidence change due to normalization evolution

**Likelihood:** HIGH during system development/deployment

**OBSERVATION:**
- Confidence is NOT DETERMINISTIC for same input
- External factors (database state, other users' actions, system updates) affect scoring
- No version tracking or stability guarantees

---

### 4.4 OUTCOME: Suggestion Order Reversal

**Observable manifestation:** 
- Request 1: Suggestions are [supplier A (85%), supplier B (75%)]
- Request 2 (identical input): Suggestions are [supplier B (90%), supplier A (80%)]

**Path Reconstruction:**

**Plausible Path A: Parallel Confirmation by Different Users**
1. User 1 types "شركة التعاون" →  A=85, B=75 (sorted)
2. User 2 confirms supplier B for similar input "التعاون"
3. learning_confirmations records confirmation for B
4. User 1 types "شركة التعاون" again
5. getUserFeedback() includes User 2's confirmation
6. B confidence boosted: 75 + 15 = 90 (assuming 3 confirms from User 2)
7. A remains 85 (if no new confirmations)
8. **Result:** Order flipped due to other users' learning

**Evidence:** Learning is GLOBAL, not per-user

**Plausible Path B: Historical Selections Accumulation**
1. Between requests, guarantees processed
2. getHistoricalSelections() query finds new selections for supplier B
3. Historical boost applied: B gains +10 or +20
4. A historical boost unchanged
5. **Result:** B overtakes A

**Plausible Path C: Source Priority Shift**
1. First request:
   - A from entity_anchor (base=85), no confirms
   - B from historical (base=40), 3 confirms → 40+15=55... Wait, this doesn't reach 75
2. Let me recalculate:
   - B from entity_anchor (base=85), 0 confirms: shouldn't be 75
   - OR B from learned (base=65), 1 confirm: 65+5=70
3. Actually for B=75 initially:
   - Could be entity_anchor (85) with 1 rejection: 85-33.4=51.6 (doesn't match)
   - Could be learned (65) with 1 confirm + 1 historical: 65+5+10=80 (doesn't match)
4. **Hypothesis INVALID:** Need actual data to reconstruct accurately

**OBSERVATION:**
- Without concrete data, multiple formulas can produce same score
- Reverse engineering specific scores requires trial-and-error
- System does not expose formula breakdown in results

**Plausible Path D: Cache Inconsistency**
1. First request: Cache miss, live calculation
2. Second request: Cache hit, returns different pre-computed scores
3. Cache populated between requests by async job with different logic
4. **Result:** Order flip due to cache vs live divergence

**Likelihood:** MEDIUM if caching exists, LOW in current observed implementation

---

### 4.5 OUTCOME: Repeated Identical Suggestions

**Observable manifestation:** User sees 5 suggestions, 3 of them are the exact same supplier with same confidence

**Path Reconstruction:**

**Plausible Path A: Client-Side Display Bug (NOT system logic issue)**
- System returns deduplicated list
- Client UI renders same item multiple times due to indexing bug
- **Verdict:** EXTERNAL to supplier learning logic

**Plausible Path B: Deduplication Failure Due to Dirty Data**
1. Database contains:
   - supplier_alternative_names: supplier_id=10, normalized_name="الشركه الوطنيه"
   - supplier_alternative_names: supplier_id=10, normalized_name="الشركة الوطنية" (different due to normalization bug)
2. User input normalizes to "الشركه الوطنيه"
3. First query matches first entry
4. Second query matches second entry (if normalization re-applied differently)
5. Deduplication by supplier_id should merge... BUT:
6. If candidates come from different code paths with timing gaps, dedup might miss them
7. **Result:** Same supplier_id appears multiple times

**Likelihood:** LOW in current code (dedup is robust, lines 263-268)

**Plausible Path C: Deduplication Only Within Service, Not Across Services**
1. UI calls LearningSuggestionService.getSuggestions() → returns [supplier_id=10, confidence=85]
2. UI also calls SupplierCandidateService.supplierCandidates() → returns [supplier_id=10, confidence=90]
3. UI merges both lists without deduplication
4. **Result:** Duplicate supplier from different APIs

**Likelihood:** HIGH if UI/frontend aggregates multiple backend sources

**ACTUAL CURRENT LOGIC VERDICT:**
- Single-service calls are deduplicated
- Cross-service aggregation is EXTERNAL CONCERN, not handled by learning logic

---

## 5. LEARNING DIVERGENCE & DRIFT ANALYSIS

### 5.1 NORMALIZATION DRIFT

**Mechanism:**
- Supplier name normalized when first learned
- normalized_name stored in database
- Future queries apply CURRENT normalization algorithm
- If algorithm changes, stored values become stale

**Divergence Timeline:**

```
T0: System deployed, ArabicNormalizer in Phase 1
  - User learns "مؤسسة عبدالله"
  - Stored: normalized_name="موسسة عبدالله" (ة is NOT normalized in Phase 1)
  - usage_count=1

T30: System updated, ArabicNormalizer upgraded to Phase 2
  - Phase 2 now normalizes: ة → ه
  - Old data unchanged

T60: User inputs "مؤسسة عبدالله"
  - Current normalization: "موسسه عبدالله" (ة → ه)
  - Query: WHERE normalized_name = "موسسه عبدالله"
  - Match: NONE (stored value is "موسسة عبدالله")
  - Result: Alias not found, learning LOST

T90: User manually selects supplier again
  - learnAlias() normalizes input with Phase 2: "موسسه عبدالله"
  - Check existence: "موسسه عبدالله" not found (old entry is "موسسة عبدالله")
  - Inserts NEW row: normalized_name="موسسه عبدالله", usage_count=1
  - Result: TWO aliases for same input, one orphaned (old), one active (new)
```

**Database State After T90:**
| id | supplier_id | normalized_name | usage_count | created_at |
|----|-------------|-----------------|-------------|------------|
| 123 | 5 | موسسة عبدالله | 1 | T0 |
| 456 | 5 | موسسه عبدالله | 1 | T90 |

**Consequences:**
- Old alias (id=123) never matches again, usage_count frozen at 1
- New alias (id=456) starts fresh, loses historical weight
- Fragmented learning for same conceptual supplier name
- No auto-migration or reconciliation logic observed

**Compounding Over Time:**
- Each normalization change fragments learning further
- N normalization versions → up to N aliases per supplier
- Database bloat with dead alias entries
- Suggestion quality degrades as historical data scatters

---

### 5.2 RAW NAME VARIANCE FRAGMENTATION

**Mechanism:**
- learning_confirmations uses EXACT raw_supplier_name (line 30)
- Does NOT normalize before querying
- Minor input variations create separate learning histories

**Divergence Example:**

```
User A types: "شركة النورس"
  - learning_confirmations: raw_supplier_name="شركة النورس", action='confirm'

User B types: "شركة النورس " (trailing space)
  - learning_confirmations: raw_supplier_name="شركة النورس ", action='confirm'

User C types: "شركة  النورس" (double space)
  - learning_confirmations: raw_supplier_name="شركة  النورس", action='confirm'
```

**Query Behavior:**

```
getUserFeedback("شركة النورس")
  → Returns: confirmationsMap[supplier_id]=1 (only User A's confirmation)

getUserFeedback("شركة النورس ")
  → Returns: confirmationsMap[supplier_id]=1 (only User B's confirmation)

getUserFeedback("شركة  النورس")
  → Returns: confirmationsMap[supplier_id]=1 (only User C's confirmation)
```

**Result:**
- Same supplier, same normalization, THREE separate learning pools
- Each pool accumulates confirmations/rejections independently
- Confidence score varies based on which exact variant user types
- **FRAGMENTED LEARNING** due to whitespace sensitivity

**Mitigation Observed:**
- SupplierLearningRepository DOES normalize before storing aliases (line 71, 98)
- But LearningRepository (learning_confirmations) does NOT (evidenced by query line 30)

**Consequence:**
- Alias learning converges (normalized storage)
- Confirmation learning diverges (raw storage)
- TWO PARALLEL LEARNING SYSTEMS with different fragmentation characteristics

---

### 5.3 FIRST-LEARNED BIAS

**Mechanism:**
- learnAlias() checks if normalized_name exists (line 125)
- If exists, silently returns (line 127-128)
- No check for supplier_id match

**Divergence Scenario:**

```
T0: User selects Supplier A (id=5) for input "الوطنية"
  - learnAlias(5, "الوطنية")
  - Inserts: supplier_id=5, normalized_name="الوطنيه"

T10: User selects Supplier B (id=12) for input "الوطنية"
  - learnAlias(12, "الوطنية")
  - Checks: normalized_name="الوطنيه" exists → TRUE
  - Returns silently
  - NO insertion, NO update, NO warning

T20: Any user inputs "الوطنية"
  - findSuggestions() queries: WHERE normalized_name="الوطنيه"
  - Returns: supplier_id=5 (first learned)
  - Supplier B (id=12) NEVER suggested via alias
```

**Database State:**
| id | supplier_id | normalized_name | usage_count |
|----|-------------|-----------------|-------------|
| 100 | 5 | الوطنيه | 5 |

**Result:**
- Supplier A "owns" the alias permanently
- Supplier B selections don't reinforce alias learning
- **FIRST-LEARNED BIAS:** Earlier selections dominate future suggestions
- No conflict resolution, no voting, no override mechanism

**When This Happens:**
- Ambiguous supplier names (e.g., "الوطنية" could be multiple companies)
- User errors (selected wrong supplier initially)
- Data quality issues (duplicate suppliers in database)

**Compounding:**
- usage_count for Supplier A grows (incrementUsage succeeds)
- Supplier B never gets usage_count for this alias
- Confidence gap widens over time
- Self-reinforcing incorrect learning if first selection was wrong

---

### 5.4 PARALLEL SUGGESTION PATHS: CONFLICT WITHOUT AWARENESS

**System has THREE independent suggestion sources:**
1. LearningSuggestionService (Hybrid Learning, ADR-009)
2. SupplierCandidateService (Fuzzy Matching)
3. Direct alias lookups (MatchingService or other legacy)

**Each source can return DIFFERENT suppliers for SAME input:**

```
Input: "مؤسسة التقدم"

LearningSuggestionService:
  - getUserFeedback() finds 3 confirmations for supplier_id=20
  - Returns: [supplier_id=20, confidence=80, source='learned']

SupplierCandidateService:
  - Fuzzy match finds supplier_id=35 with 92% similarity
  - Returns: [supplier_id=35, score=0.92, source='fuzzy_official']

MatchingService (hypothetical):
  - Exact alias match finds supplier_id=50
  - Returns: [supplier_id=50, certainty=100]
```

**If UI aggregates all three:**
- User sees THREE different supplier suggestions
- All claim varying levels of confidence
- No indication of WHY they differ
- User must choose between conflicting system recommendations

**Observed Deduplication:**
- WITHIN each service: deduplication by supplier_id
- ACROSS services: NO automatic deduplication (responsibility of caller)

**Result:**
- Different code paths accumulate different learning artifacts
- Same supplier appears with different scores from different sources
- System lacks UNIFIED VIEW of learned knowledge

---

### 5.5 PENALTY ACCUMULATION WITHOUT RECOVERY

**Mechanism:**
- decrementUsage() lowers usage_count (line 95-115)
- Floor: -5 (line 103)
- NO automatic recovery mechanism

**Divergence Timeline:**

```
T0: Supplier learned, usage_count=1
T10: User rejects suggestion 3 times
  - decrementUsage() called 3 times
  - usage_count: 1 → 0 → -1 → -2

T20: User inputs same name
  - findSuggestions() query: WHERE usage_count > 0
  - No match (usage_count=-2)
  - Supplier NOT suggested

T30: User tries to manually select supplier via dropdown
  - Supplier appears in full list (not filtered by usage_count)
  - User selects it
  - incrementUsage() called
  - usage_count: -2 → -1
  - STILL below threshold, STILL not suggested

T40: User selects again
  - usage_count: -1 → 0
  - STILL not suggested (threshold is > 0, not >= 0)

T50: User selects third time
  - usage_count: 0 → 1
  - NOW suggested again
```

**Result:**
- Supplier effectively "banned" from suggestions until 3+ manual re-selections
- Penalty persists longer than boost (3 rejects need 4 confirms to recover)
- Asymmetric learning: easier to penalize than to recover

**Intended vs Actual:**
- **Intended:** Negative learning prevents bad suggestions
- **Actual:** Can create sticky incorrect bans if rejections were erroneous
- **Missing:** Decay mechanism, time-based forgetting, manual override

---

## 6. FUTURE BEHAVIOR RISK (WITHOUT FIXING)

### 6.1 EXPONENTIAL DUPLICATION RISK

**Current Logic Allows:**
- Normalization changes create new alias entries (Section 5.1)
- No cleanup of orphaned aliases
- Each deployment with normalization update → +N new aliases
- Database table grows: O(users × suppliers × normalization_versions)

**Plausible Future State (12 months):**
```
supplier_alternative_names table:
- 50,000 active aliases
- 200,000 orphaned aliases (never match due to old normalization)
- Query performance degrades
- Disk space waste
```

**Enabled by:**
- No uniqueness constraint on raw alternative_name
- No garbage collection of unused aliases
- No migration scripts for normalization updates

---

### 6.2 SUGGESTION QUALITY DEGRADATION

**Current Logic Allows:**
- Fragmented confirmation learning (Section 5.2)
- Average confirmation count per variant: 1-2
- True confirmation count if aggregated: 50+
- But query only sees fragment, returns low confidence

**Plausible Future Manifestation:**
- Supplier with 100 total confirmations across all input variants
- Any single input variant query returns confidence=65 (base learned + 5 for 1 confirm)
- Should be high confidence, appears as medium
- **Degraded trust in suggestions**

**Enabled by:**
- Raw name exactness in learning_confirmations queries
- No fuzzy aggregation of similar inputs

---

### 6.3 FALSE CONFIDENCE FROM NOISE

**Current Logic Allows:**
- Repeated user corrections for WRONG initial learning (Section 5.3)
- First-learned alias never updates to correct supplier
- usage_count grows for incorrect alias
- High confidence for incorrect suggestion

**Plausible Future State:**
- "شركة التعاون" incorrectly learned to map to supplier_id=99 (wrong)
- Correct supplier is id=100
- Users keep manually selecting id=100
- BUT usage_count for id=99 alias keeps growing (incrementUsage may still be called if logic differs elsewhere)
- Suggestion: id=99 with 95% confidence (incorrect but highly confident)

**Enabled by:**
- No conflict detection between alias and manual selections
- No feedback loop to correct learned aliases
- usage_count increments without validation

---

### 6.4 INCONSISTENT USER EXPERIENCE

**Current Logic Allows:**
- Dynamic uniqueness scoring (Section 4.3 Path C)
- Anchor becomes generic as database grows
- What was 85% last month is 70% today
- No user notification of confidence drop

**Plausible Future Manifestation:**
- User trained to trust Level B suggestions (>=85%)
- Over time, same inputs drop to Level C (65-84%)
- User notices inconsistency, loses trust
- Support tickets: "Why did confidence drop for same supplier?"

**Enabled by:**
- Live uniqueness calculation without caching
- No confidence stability guarantees
- Interface doesn't explain score changes

---

### 6.5 CACHE-LIVE DIVERGENCE CRISIS

**Current Logic Allows:**
- supplier_learning_cache exists but population mechanism unclear
- Cache and live can return different results
- No cache invalidation triggers observed

**Plausible Future State:**
- Cache populated by nightly batch job
- Live data updated by user actions throughout day
- Morning users see cached suggestions (stale)
- Afternoon users see live suggestions (fresh)
- Same input, different results based on time of day
- "Works on my machine" debugging nightmare

**Enabled by:**
- Two parallel data sources (cache + tables)
- No clear cache refresh policy
- No consistency guarantees

---

## 7. INTENDED vs ACTUAL LEARNING (EXPOSURE ONLY)

### 7.1 ALIAS LEARNING

**APPEARS to Intend:**
- Learn raw input variations as aliases to canonical suppliers
- Reuse learned aliases for instant future matching

**ACTUALLY Allows:**
- First learned alias locks supplier association forever
- Subsequent different supplier selections for same input are IGNORED
- Creates incorrect confident suggestions if first learning was wrong

**Contradiction:**
- Manual selection (user authority) vs stored alias (system authority)
- System chooses alias over manual when conflict arises implicitly

---

### 7.2 CONFIRMATION LEARNING

**APPEARS to Intend:**
- Accumulate user feedback to boost confidence
- Learn from both current user and historical trends

**ACTUALLY Allows:**
- Fragmented learning across input variants
- Same supplier has multiple disconnected learning histories
- Confidence varies unpredictably based on exact input spacing/diacritics

**Contradiction:**
- Normalization applied to matching BUT NOT to learning queries
- Two-tier learning: normalized aliases, non-normalized confirmations

---

### 7.3 NEGATIVE LEARNING

**APPEARS to Intend:**
- Penalize bad suggestions to prevent recurring errors
- Gradual suppression via usage_count decrement

**ACTUALLY Allows:**
- Permanent banning without recovery path
- Negative usage_count persists indefinitely (no decay)
- Harder to recover than to penalize (asymmetric)

**Ambiguity:**
- Is -5 floor meant to be recoverable or permanent?
- No documentation observed on recovery expectations

---

### 7.4 CACHING

**APPEARS to Intend:**
- Pre-compute suggestions for performance
- Fast retrieval of frequently used mappings

**ACTUALLY Allows:**
- Stale data if cache not refreshed
- Parallel learning paths (cache vs live)
- Undefined consistency guarantees

**UNKNOWN:**
- When cache is populated
- How cache is invalidated
- Whether cache overrides live data or supplements it

---

### 7.5 DEDUPLICATION

**APPEARS to Intend:**
- Each supplier appears once per suggestion list
- Best score wins in case of multiple sources

**ACTUALLY Allows:**
- Works within single service call
- Does NOT work across multiple API calls (if UI aggregates)
- Source information lost after deduplication (user can't see why score changed)

**Contradiction:**
- Deduplication discards valuable provenance data
- User sees final score but not score composition

---

## 8. INSUFFICIENT INFORMATION - UNKNOWN REGIONS

### 8.1 UNKNOWN: Cache Population Mechanism

**Artifact:** supplier_learning_cache table

**Missing Information:**
- When is cache populated?
- What triggers upsert() calls?
- How are fuzzy_score, source_weight, effective_score calculated?
- Is there a background job? Manual admin action? Automatic on first query?

**Required to Reduce Ambiguity:**
- Cron job definitions or background worker code
- Admin panel code showing manual cache refresh buttons
- Auto-population triggers in suggestion services (not found in reviewed code)

---

### 8.2 UNKNOWN: Historical Selections JSON Parsing Reliability

**Code:** LearningRepository.getHistoricalSelections() line 51

**Fragile Logic:**
```php
$jsonFragment = '"supplier":"' . str_replace('"', '\\"', $rawName) . '"';
$sql = "... WHERE raw_data LIKE '%{$jsonFragment}%' ..."
```

**Questions:**
- What if JSON structure changes?
- What if supplier name contains special characters that break pattern?
- How often do false positives/negatives occur?
- Is raw_data actually JSON or some other format?

**Required:**
- Sample raw_data content
- JSON schema definition
- Error rate metrics

---

### 8.3 UNKNOWN: Multiple Services Coordination

**Observation:**
- LearningSuggestionService and SupplierCandidateService exist separately
- Do they run in parallel? Sequential? Conditional?
- Does UI call one or both?
- How are results merged?

**Required:**
- API endpoint code showing which service(s) are called
- Frontend code showing suggestion display logic
- Request/response logs

---

### 8.4 UNKNOWN: Database Constraints

**Speculation from Code:**
- supplier_alternative_names likely has UNIQUE on normalized_name (inferred from line 125-128 check)
- OR has UNIQUE on (supplier_id, normalized_name) pair (lesstikely, would need different logic)
- supplier_learning_cache likely has UNIQUE on (normalized_input, supplier_id) (inferred from upsert logic)

**Required:**
- Actual CREATE TABLE statements with constraints
- Database schema file

**Artifacts That Would Reduce Ambiguity:**
```
c:\Users\Bakheet\Documents\Projects\BGL3\storage\database\migrations\*.sql
c:\Users\Bakheet\Documents\Projects\BGL3\schema.sql
```

---

END OF SUPPLIER LEARNING LOGIC MAP
