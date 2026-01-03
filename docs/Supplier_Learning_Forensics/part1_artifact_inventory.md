# SUPPLIER LEARNING LOGIC MAP - PART 1: ARTIFACT INVENTORY

## CONTEXT

This is a forensic analysis of the ACTUAL supplier learning and suggestion behavior in the BGL3 system. This document exposes what the system DOES, not what it should do. All contradictions, duplications, and divergences are documented as EVIDENCE of logic, not errors.

---

## 1. LEARNING & SUGGESTION ARTIFACT INVENTORY

### 1.1 PRIMARY LEARNING ARTIFACTS

#### ARTIFACT: `supplier_alternative_names` Table

**Purpose (Inferred from Code):**
- Stores learned aliases that map raw supplier input variations to canonical supplier IDs
- Acts as exact-match lookup cache for normalization bypassing

**Creation Events:**

1. **Via SupplierLearningRepository.learnAlias() (Line 120-137)**
   - **Trigger:** User makes manual selection (`source === 'manual'`)
   - **Precondition:** Normalized name does not already exist in table
   - **Data written:**
     - `supplier_id`: The chosen supplier
     - `alternative_name`: Raw input exactly as entered
     - `normalized_name`: Result of ArabicNormalizer.normalize()
     - `source`: Hardcoded to `'learning'`
     - `usage_count`: Initialized to `1`
   - **Uniqueness constraint:** Only checks if normalized_name exists (line 125-128), NOT if supplier_id+normalized_name pair exists
   - **Silent behavior:** If normalized name exists, function RETURNS WITHOUT ERROR OR LOG, regardless of whether it maps to a DIFFERENT supplier_id

**Reuse Events:**

1. **Via SupplierLearningRepository.findSuggestions() (Line 18-62)**
   - **Query:** Exact match on `normalized_name` with `usage_count > 0`
   - **Returns:** FIRST match only (LIMIT 1, line 26)
   - **Score:** Hardcoded 100% confidence
   - **Effect:** If alias found, SHORT-CIRCUITS all other matching logic
   - **No consideration of:** Which supplier_id it maps to, or if multiple aliases exist for same normalized_name

2. **Via SupplierAlternativeNameRepository.findByNormalized() (Line 27-50)**
   - **Query:** Exact match on `normalized_name`
   - **Returns:** FIRST match only (LIMIT 1, line 32)
   - **No ordering specified:** Database returns arbitrary row if duplicates exist

3. **Via SupplierAlternativeNameRepository.findAllByNormalized() (Line 57-72)**
   - **Query:** Exact match on `normalized_name`
   - **Returns:** ALL matches (no limit)
   - **Used by:** SupplierCandidateService line 218
   - **Effect:** CAN expose duplicate mappings, creates multiple candidates

4. **Via SupplierCandidateService.supplierCandidates() fuzzy alternative search (Line 238-260)**
   - **Query:** ALL alternative names, fuzzy matched in-memory
   - **Effect:** Each alternative becomes separate candidate

**Modification Events:**

1. **Via SupplierLearningRepository.incrementUsage() (Line 68-89)**
   - **Trigger:** User makes manual selection AND alias exists
   - **Mutation:** `usage_count = usage_count + 1`
   - **Query:** `WHERE supplier_id = ? AND normalized_name = ?`
   - **Silent behavior:** If no rows match, no error, no creation
   - **Log:** Only logs IF rowCount > 0 (lines 80-86)
   - **Effect:** Over time, usage_count grows unbounded (no cap observed in code)

2. **Via SupplierLearningRepository.decrementUsage() (Line 95-115)**
   - **Trigger:** Penalty applied (negative learning)
   - **Mutation:** `usage_count = CASE WHEN usage_count > -5 THEN usage_count - 1 ELSE -5 END`
   - **Hard floor:** -5 (line 103)
   - **Query:** `WHERE supplier_id = ? AND normalized_name = ?`
   - **Effect:** Can suppress alias from appearing in findSuggestions (which filters `usage_count > 0`)

**Duplication Behavior:**

- **System treats as DUPLICATE:** Two entries with same `normalized_name` (prevented by existence check in learnAlias, line 125)
- **System treats as UNIQUE:** Two entries with same `normalized_name` but created at different times (first one wins)
- **System treats as UNIQUE:** Two entries with same `alternative_name` but different normalization results (edge case: normalization algorithm change)
- **System treats as UNIQUE:** Same supplier_id + normalized_name pair with different `source` values
- **UNKNOWN:** Whether database has UNIQUE constraint on `normalized_name` alone or on (`supplier_id`, `normalized_name`) pair

**Divergence Risks:**

- If normalized_name conflicts between suppliers, ONLY THE FIRST learned alias persists
- Subsequent attempts to learn DIFFERENT supplier for same normalized_name are SILENTLY IGNORED
- No conflict detection, no warning, no merge logic

---

#### ARTIFACT: `learning_confirmations` Table

**Purpose (Inferred from Code):**
- Logs user explicit confirmation/rejection actions during Pilot Mode (suggestion review)
- Primary data source for Hybrid Learning System (ADR-009)

**Creation Events:**

1. **Via LearningRepository.logDecision() (Line 66-85)**
   - **Trigger:** User confirms or rejects a Level B suggestion
   - **Data written:**
     - `raw_supplier_name`: Exact input
     - `supplier_id`: The suggestion id
     - `confidence`: Score of the suggestion
     - `matched_anchor`: Entity anchor if applicable
     - `anchor_type`: 'learned' or other
     - `action`: 'confirm' or 'reject'
     - `decision_time_seconds`: Speed of decision
     - `guarantee_id`: Related guarantee
     - `created_at`: Timestamp
   - **No uniqueness constraints observed**
   - **Effect:** EVERY confirmation/rejection creates NEW ROW (accumulation)

**Reuse Events:**

1. **Via LearningRepository.getUserFeedback() (Line 25-35)**
   - **Query:** Aggregates by `raw_supplier_name` (NOT normalized)
   - **Returns:** COUNT of confirms/rejects per supplier_id
   - **Grouping:** `supplier_id, action`
   - **Used by:** LearningSuggestionService.getSuggestions() line 36

2. **Via LearningRepository.getRejections() (Line 37-46)**
   - **Query:** DISTINCT supplier_ids rejected for a raw_supplier_name
   - **Returns:** List of supplier_ids to penalize
   - **Used by:** Legacy/alternate suggestion logic (not in current LearningSuggestionService)

**Modification Events:**
- NONE. Table is append-only.

**Duplication Behavior:**

- **Every user action creates separate row:** Same user + same raw_name + same supplier + same action = multiple rows
- **Counted as confirmations:** Multiple identical confirms increase boost
- **Counted as rejections:** Multiple identical rejects increase penalty
- **No temporal weighting:** Old confirmations = recent confirmations
- **No session deduplication:** Rapid clicks = multiple learning events

**Divergence Risks:**

- Confirmation count can grow unbounded
- No mechanism to "forget" or decay old learning
- If raw_supplier_name changes slightly (e.g., extra space, diacritic), creates SEPARATE learning history
- Query uses EXACT raw_supplier_name match, not normalized match

---

#### ARTIFACT: `supplier_learning_cache` Table

**Purpose (Inferred from Code):**
- Precomputed suggestion cache with scoring metadata
- Used by SupplierCandidateService for fast retrieval

**Creation Events:**

1. **Via SupplierLearningCacheRepository.upsert() (Line 48-93)**
   - **Trigger:** UNKNOWN (no calls observed in reviewed code)
   - **Checks:** If (`normalized_input`, `supplier_id`) pair exists
   - **Insert vs Update:** Separate code paths
   - **Data written:**
     - `normalized_input`
     - `supplier_id`
     - `fuzzy_score`
     - `source_weight`
     - `usage_count`
     - `block_count`
     - Computed fields (total_score, effective_score, star_rating) - mechanism unclear

**Reuse Events:**

1. **Via SupplierLearningCacheRepository.getSuggestions() (Line 26-43)**
   - **Query:** `WHERE normalized_input = ? AND effective_score > 0`
   - **Returns:** Up to limit, ordered by effective_score DESC
   - **Used by:** SupplierCandidateService.supplierCandidates() line 121

2. **Via SupplierLearningCacheRepository.getBlockedSupplierIds() (Line 124-135)**
   - **Query:** `WHERE normalized_input = ? AND block_count > 0`
   - **Returns:** List of supplier_ids to exclude
   - **Used by:** SupplierCandidateService.supplierCandidates() lines 120, 155, 182, 219, 239

**Modification Events:**

1. **Via SupplierLearningCacheRepository.incrementUsage() (Line 98-106)**
   - **Mutation:** `usage_count = usage_count + increment`
   - **Trigger:** UNKNOWN (no calls observed)

2. **Via SupplierLearningCacheRepository.incrementBlock() (Line 111-119)**
   - **Mutation:** `block_count = block_count + increment`
   - **Trigger:** UNKNOWN (no calls observed)

**Duplication Behavior:**

- **UNKNOWN:** Whether (`normalized_input`, `supplier_id`) has UNIQUE constraint
- **Implied unique:** upsert logic checks for existence before insert
- **Multiple entries possible if:** Constraint doesn't exist and upsert logic fails

**Divergence Risks:**

- **COMPLETE UNKNOWN:** Population mechanism not found in reviewed code
- Cache may be stale, out-of-sync, or unpopulated
- If cache is empty, SupplierCandidateService proceeds with live matching (line 123 check)
- Cache and live data may diverge silently

---

### 1.2 SECONDARY ARTIFACTS (Historical Data)

#### ARTIFACT: Guarantee Decisions (guarantees + guarantee_decisions tables)

**Purpose:** Historical record of past supplier selections

**Reuse:**

1. **Via LearningRepository.getHistoricalSelections() (Line 48-64)**
   - **Query:** JSON-like search via `raw_data LIKE '%"supplier":"<rawName>"%'`
   - **Fragile matching:** String fragment match, not structured JSON query
   - **Returns:** Frequency count per supplier_id
   - **Used by:** LearningSuggestionService.getSuggestions() line 86

**Duplication:** Each guarantee creates independent historical data point

**Divergence:** If supplier name in raw_data varies slightly, historical data fragments

---

## 2. DUPLICATE vs UNIQUE BEHAVIOR MAP

### 2.1 NORMALIZATION-DRIVEN UNIQUENESS

**Function:** ArabicNormalizer.normalize()

**Transformations Applied:**
1. Unicode whitespace → single space (U+00A0, U+202F, U+2009, U+2007)
2. Multiple spaces → single space
3. Arabic character variants:
   - ى → ي
   - ة → ه
   - أ, إ, آ → ا
   - ؤ → و
   - ئ → ي
4. Remove diacritics (U+064B to U+065F)
5. Remove punctuation: ()[]{}،,؛;.!?-_
6. Lowercase (mb_strtolower)
7. Trim

**What System Treats as Duplicate:**
- "شركة النورس" vs "شركة  النورس" (extra space) → SAME
- "مؤسسة التعاون" vs "مؤسسه التعاون" (ة vs ه) → SAME
- "عَبدالرَّحمن" vs "عبدالرحمن" (with/without diacritics) → SAME

**What System Treats as Unique:**
- "النورس" vs "النوارس" → DIFFERENT (pluralization not handled)
- "عبدالرحمن" vs "عبد الرحمن" (connected vs separated) → DIFFERENT (space inside word)
- "ABC Company" vs "abc company" → SAME (lowercase applied)

**CRITICAL DIVERGENCE POINT:**

If normalization algorithm changes or is applied inconsistently:
- OLD normalized values in database don't match NEW normalized values from input
- Creates PARALLEL learning for what humans perceive as same supplier
- No migration or reconciliation observed

---

### 2.2 DATABASE-DRIVEN UNIQUENESS

#### Pattern: First-Match Wins

**Behavior:** `findSuggestions()` returns LIMIT 1 (line 26)
- Multiple aliases for same normalized_name → arbitrary winner
- No deterministic ordering → unstable suggestions across identical inputs
- **ALLOWS:** Two suppliers both claim same alias, first inserted wins forever

#### Pattern: Existence-Only Check

**Behavior:** `learnAlias()` checks if normalized_name exists, not (supplier_id, normalized_name)
- If Supplier A already learned "الشركة الوطنية"
- User manually selects Supplier B for input "الشركة الوطنية"
- learnAlias() finds existing entry, RETURNS silently (line 127-128)
- Supplier B selection is NOT learned
- Future inputs get Supplier A (wrong learning)

**This is CONFLICT-BY-DESIGN, not a bug from system's perspective**

---

### 2.3 SCORING-DRIVEN UNIQUENESS

Multiple candidates for same supplier_id from different sources are treated as SEPARATE during candidate generation, then DEDUPLICATED by keeping highest score.

**Example execution path:**

Input: "شركة الفجر"

Candidates generated:
1. From `supplier_alternative_names` exact: supplier_id=5, score=1.0*0.95=0.95, source='alternative'
2. From official suppliers fuzzy: supplier_id=5, score=0.88*0.85=0.748, source='fuzzy_official'
3. From learning cache: supplier_id=5, score=0.90, source='learning'

Deduplication (SupplierCandidateService line 263-268):
- Keeps: candidate #3 (learning, score=0.90)
- Discards: #1 and #2

**Human perception:** Duplicate entries
**System behavior:** Separate candidates, best score wins
**Effect:** Same supplier appears once but scoring history is opaque

