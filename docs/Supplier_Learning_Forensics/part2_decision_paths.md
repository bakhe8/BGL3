# SUPPLIER LEARNING LOGIC MAP - PART 2: COMPLETE SUGGESTION DECISION INVENTORY

## 3. COMPLETE SUGGESTION DECISION INVENTORY

This section enumerates EVERY decision point that determines which suppliers are suggested, with what confidence, and in what order.

---

### 3.1 ENTRY POINT: LearningSuggestionService.getSuggestions()

**Primary orchestration service for Hybrid Learning System (ADR-009)**

**Input:** `rawName` (string)

**Decision Tree:**

```
getSuggestions(rawName)
├─[DECISION 1]─ getUserFeedback(rawName)
│  ├─ Query: learning_confirmations WHERE raw_supplier_name = rawName
│  ├─ Group by: supplier_id, action
│  ├─ Returns: confirmations map, rejections map
│  └─ NOTE: Uses EXACT raw_supplier_name, not normalized
│
├─[DECISION 2]─ Gather entity_anchor candidates
│  ├─ Call: ArabicLevelBSuggestions.find(rawName)
│  ├─ For each result:
│  │  ├─ Create candidate with base_score=85
│  │  ├─ source='entity_anchor'
│  │  └─ Store matched_anchor
│  └─ NOTE: Can be empty array (silent rejection if no anchors)
│
├─[DECISION 3]─ Gather learned candidates (from confirmations only)
│  ├─ For each supplier_id in confirmationsMap:
│  │  ├─ If NOT already in candidates:
│  │  │  ├─ Fetch supplier by ID
│  │  │  ├─ Create candidate with base_score=65
│  │  │  └─ source='learned'
│  │  └─ Else: skip (entity_anchor takes precedence)
│  └─ NOTE: Rejections-only do NOT create candidates
│
├─[DECISION 4]─ Gather historical candidates
│  ├─ Call: getHistoricalSelections(rawName)
│  ├─ For each supplier_id with frequency:
│  │  ├─ If NOT already in candidates:
│  │  │  ├─ Fetch supplier by ID
│  │  │  ├─ Create candidate with base_score=40
│  │  │  └─ source='historical'
│  │  └─ Else: skip
│  └─ NOTE: Uses JSON fragment matching, fragile
│
├─[DECISION 5]─ Uplift historical to learned
│  ├─ For each candidate where source='historical':
│  │  ├─ If confirmations count > 0:
│  │  │  └─ Change source to 'learned'
│  │  └─ Else: keep as historical
│  └─ Line 113-115, changes source label only
│
├─[DECISION 6]─ Calculate final confidence scores
│  ├─ For each candidate:
│  │  ├─ Call: ConfidenceCalculator.calculate(source, confirms, history, rejections)
│  │  ├─ Formula: base + confirmBoost + histBoost - (rejections * 33.4)
│  │  ├─ Returns: score 0-100
│  │  └─ Call: ConfidenceCalculator.getLevel(score)
│  │     ├─ score >= 85: Level B
│  │     ├─ score >= 65: Level C
│  │     ├─ score >= 40: Level D
│  │     └─ score < 40: NULL (filtered out)
│  └─ If level is NULL, candidate is DISCARDED (line 126)
│
├─[DECISION 7]─ Build result array
│  ├─ For candidates with valid level:
│  │  ├─ Include: id, official_name, confidence, level, source_type
│  │  ├─ Include counts: confirmation_count, historical_count, rejection_count
│  │  └─ Include: reason_ar (Arabic explanation)
│  └─ Discarded candidates leave NO TRACE in output
│
└─[DECISION 8]─ Sort and return
   ├─ Sort: by confidence DESC (line 145)
   └─ Return: all results (no limit)
```

**CRITICAL PATH BEHAVIORS:**

1. **Confirmation vs Rejection asymmetry:**
   - Confirmations CREATE candidates (line 70-83)
   - Rejections ONLY penalize existing candidates (line 111 in calculator)
   - Pure rejections (no confirms, no history) = no suggestion appears at all

2. **Source precedence:**
   - Entity anchor candidates created first
   - Learned candidates only added if supplier NOT already from anchor
   - Historical candidates only added if supplier NOT from anchor or learned
   - **Effect:** Same supplier appears once, under highest-priority source

3. **Scoring caps:**
   - No hardcoded score cap in LearningSuggestionService
   - ConfidenceCalculator can return >100 (no enforcement at line 32 min/max)
   - Display/UI may clip scores

4. **Rejection penalty:**
   - Each rejection = -33.4 points
   - 3 rejections = -100 points, guarantees score < 0
   - Negative scores filtered by level check (level=NULL)

**DIVERGENCE EVIDENCE:**

- Raw name "شركة النورس" vs "شركة النورس " (trailing space) query DIFFERENT learning histories
- If user confirms via variant A, but later input uses variant B, NO LEARNING APPLIED
- System does not normalize before querying learning_confirmations

---

### 3.2 ENTRY POINT: SupplierCandidateService.supplierCandidates()

**Fuzzy matching service for general supplier candidate generation**

**Input:** `rawSupplier` (string)

**Decision Tree:**

```
supplierCandidates(rawSupplier)
├─[DECISION 1]─ Normalize input
│  ├─ Call: Normalizer.normalizeSupplierName(rawSupplier)
│  ├─ Uses: ArabicNormalizer.normalize()
│  └─ If empty, return empty candidates
│
├─[DECISION 2]─ Get blocked supplier IDs
│  ├─ Query: supplier_learning_cache WHERE normalized_input = normalized AND block_count > 0
│  ├─ Returns: array of supplier IDs to exclude
│  └─ Applied to ALL candidate sources (lines 155, 182, 219, 239)
│
├─[DECISION 3]─ Check learning cache
│  ├─ Query: supplier_learning_cache WHERE normalized_input = normalized AND effective_score > 0
│  ├─ If not empty:
│  │  ├─ For each cached entry:
│  │  │  ├─ If source = 'learning' or 'user_history':
│  │  │  │  ├─ Create candidate with score=LEARNING_SCORE_CAP (default 0.90)
│  │  │  │  ├─ source='learning'
│  │  │  │  └─ is_learning=true
│  │  │  └─ Else: skip other cache sources
│  │  └─ Cached candidates added FIRST
│  └─ UNKNOWN: When/how cache is populated
│
├─[DECISION 4]─ Match against overrides
│  ├─ For each entry in supplier_override_names:
│  │  ├─ Normalize override_name
│  │  ├─ Calculate similarity: scoreComponents(normalized, candidateNorm)
│  │  ├─ Take max score from: exact, starts, contains, levenshtein, tokens
│  │  ├─ If score < MATCH_REVIEW_THRESHOLD: skip
│  │  ├─ If supplier_id in blockedIds: skip
│  │  └─ Else: create candidate with source='override', weighted score
│  └─ Weighted by WEIGHT_OFFICIAL (default ~0.90)
│
├─[DECISION 5]─ Match against official suppliers
│  ├─ For each supplier in cached suppliers list:
│  │  ├─ Normalize official_name
│  │  ├─ Calculate similarity (same as overrides)
│  │  ├─ If score < MATCH_REVIEW_THRESHOLD AND score < MATCH_WEAK_THRESHOLD: skip
│  │  ├─ If supplier_id in blockedIds: skip
│  │  ├─ Determine match type:
│  │  │  ├─ score >= 1.0: 'exact', strength='strong'
│  │  │  ├─ score >= MATCH_AUTO_THRESHOLD: 'fuzzy_strong', strength='strong'
│  │  │  └─ Else: 'fuzzy_weak', strength='weak'
│  │  └─ Apply weight: WEIGHT_OFFICIAL or WEIGHT_FUZZY based on strength
│  └─ NOTE: All suppliers loaded into memory once per request
│
├─[DECISION 6]─ Match against alternative names (exact)
│  ├─ Query: supplier_alternative_names WHERE normalized_name = normalized
│  ├─ For each match:
│  │  ├─ If supplier_id in blockedIds: skip
│  │  └─ Create candidate with score=1.0*WEIGHT_ALT_CONFIRMED, source='alternative'
│  └─ Uses exact match only
│
├─[DECISION 7]─ Match against alternative names (fuzzy)
│  ├─ Fetch ALL alternative names
│  ├─ For each:
│  │  ├─ If supplier_id in blockedIds: skip
│  │  ├─ Normalize alternative name
│  │  ├─ Calculate similarity
│  │  ├─ If score >= MATCH_WEAK_THRESHOLD:
│  │  │  ├─ Weighted by WEIGHT_FUZZY
│  │  │  ├─ Determine match_type: fuzzy_strong vs fuzzy_weak
│  │  │  └─ Create candidate with source='fuzzy_alternative'
│  │  └─ Else: skip
│  └─ Can create MANY candidates if fuzzy threshold is low
│
├─[DECISION 8]─ Deduplicate by supplier_id
│  ├─ For each candidate, group by supplier_id
│  ├─ Keep ONLY highest score per supplier_id
│  └─ Lines 263-268, discards lower-scoring duplicates
│
├─[DECISION 9]─ Apply weak threshold filter
│  ├─ Remove candidates where score_raw < MATCH_WEAK_THRESHOLD
│  └─ Line 272
│
├─[DECISION 10]─ Sort and limit
│  ├─ Sort: by score DESC
│  ├─ Limit: CANDIDATES_LIMIT (default 20)
│  └─ Return top N
```

**CRITICAL PATH BEHAVIORS:**

1. **Blocking is pervasive:**
   - Blocked suppliers filtered at EVERY source (overrides, official, alternatives exact, alternatives fuzzy)
   - No bypass logic
   - Once blocked, supplier never appears regardless of match quality

2. **Cache bypass:**
   - If cache has entries, cached candidates included
   - But cache presence does NOT skip other sources
   - Cached and live candidates both generated, then deduplicated

3. **Score weighting opacity:**
   - Different sources have different weights (WEIGHT_OFFICIAL, WEIGHT_FUZZY, WEIGHT_ALT_CONFIRMED)
   - Settings can be customized per deployment
   - Same raw similarity gets different final scores based on source
   - **Effect:** Lower-quality official match can outscore higher-quality alternative match

4. **In-memory fuzzy matching:**
   - All suppliers and all alternatives loaded into memory
   - N×M comparisons (input vs allSuppliers, input vs allAlternatives)
   - Performance degrades with scale

**DIVERGENCE EVIDENCE:**

- Candidate from official suppliers (source='fuzzy_official') and candidate from alternative names (source='alternative') for SAME supplier_id creates TWO entries initially
- Deduplication keeps highest score, but DISCARDS source context
- Result shows single supplier but user cannot tell which source "won"

---

### 3.3 ENTRY POINT: ArabicLevelBSuggestions.find()

**Entity anchor-based suggestion engine (ADR-007)**

**Input:** `normalized` (string, already normalized)

**Decision Tree:**

```
find(normalized)
├─[DECISION 1]─ Extract entity anchors
│  ├─ Tokenize: explode(' ', normalized)
│  ├─ Call: ArabicEntityAnchorExtractor.extract(normalized)
│  ├─ Returns: array of distinctive entity words
│  └─ GOLDEN RULE: If empty, return [] immediately (line 60-63)
│
├─[DECISION 2]─ Extract activity words
│  ├─ Call: ArabicEntityAnchorExtractor.extractActivityWords(tokens)
│  ├─ Returns: array of business activity terms
│  └─ Used for scoring boost only
│
├─[DECISION 3]─ Search by each anchor
│  ├─ For each anchor:
│  │  └─ Call: searchByAnchor(anchor)
│  │     ├─[SUB-DECISION 3a]─ Exact match
│  │     │  ├─ Query: suppliers WHERE normalized_name LIKE '%anchor%'
│  │     │  ├─ LIMIT 20
│  │     │  └─ If >= 5 matches, skip fuzzy (line 140)
│  │     │
│  │     └─[SUB-DECISION 3b]─ Fuzzy match (if <5 exact)
│  │        ├─ Query: suppliers WHERE LENGTH(normalized_name) >= (anchorLen - 3) LIMIT 500
│  │        ├─ For each candidate:
│  │        │  ├─ Tokenize candidate name
│  │        │  ├─ For each word in candidate:
│  │        │  │  ├─ If word length < 4: skip
│  │        │  │  ├─ Calculate: mb_levenshtein(anchor, word)
│  │        │  │  ├─ Similarity = 1 - (distance / maxLength)
│  │        │  │  ├─ If similarity >= 0.70: MATCH (line 198)
│  │        │  │  └─ Break (one word match enough)
│  │        │  └─ Avoid duplicates with exact matches
│  │        └─ Return: exact + fuzzy matches
│  │
│  └─ Accumulate all matches across all anchors
│
├─[DECISION 4]─ Score each supplier match
│  ├─ For each supplier from search results:
│  │  └─ Call: scoreMatch(supplierName, anchors, activityWords, matchedAnchor)
│  │     ├─ Count unique anchors matched in supplier name
│  │     ├─ Count generic anchors matched in supplier name
│  │     ├─ Count activity words matched in supplier name
│  │     ├─ Check uniqueness: isUniqueAnchor() for each
│  │     │  └─ Query: COUNT suppliers WHERE name LIKE '%anchor%'
│  │     │     ├─ If count <= 3: unique
│  │     │     └─ Else: generic
│  │     │
│  │     └─ Apply scoring formula:
│  │        ├─ uniqueAnchors >= 2: 95%
│  │        ├─ uniqueAnchors == 1 AND activityWords >= 1: 90%
│  │        ├─ uniqueAnchors == 1: 85%
│  │        ├─ genericAnchors >= 2: 80%
│  │        ├─ genericAnchors == 1 AND activityWords >= 1: 75%
│  │        ├─ genericAnchors == 1: 70%
│  │        └─ Else: 0 (reject)
│  │
│  └─ If score < MIN_CONFIDENCE (70): discard candidate
│
├─[DECISION 5]─ Deduplicate by supplier_id
│  ├─ If same supplier matched by multiple anchors
│  ├─ Keep: highest confidence score only
│  └─ Lines 320-332
│
├─[DECISION 6]─ Sort and limit
│  ├─ Sort: confidence DESC
│  ├─ Limit: 5 (default parameter)
│  └─ Return top 5
```

**CRITICAL PATH BEHAVIORS:**

1. **Silent rejection (Golden Rule):**
   - No anchors extracted = zero suggestions returned
   - No error, no warning, no log (except error_log, line 347)
   - If input is pure activity words or stop words, EMPTY result

2. **Uniqueness is dynamic:**
   - Each anchor's uniqueness checked via live query
   - Same anchor can be unique today, generic tomorrow (if more suppliers added)
   - Scoring CHANGES over time without any code change

3. **Fuzzy threshold fixed:**
   - 70% similarity hardcoded (line 198)
   - Lower than SupplierCandidateService's MATCH_WEAK_THRESHOLD (typically 80%)
   - **Effect:** More lenient matching but confined to entity anchors only

4. **Activity word boost:**
   - Presence of activity words upgrades confidence tier
   - "مؤسسة عبدالله للتجارة" (establishment + name + activity):
     - If "عبدالله" is unique: 90% (unique + activity)
     - If "عبدالله" is generic: 75% (generic + activity)
   - Same supplier, different confidence based on query content

**DIVERGENCE EVIDENCE:**

- If user inputs "عبدالله" only (no activity word, no second anchor):
  - If "عبدالله" unique: 85%
  - If "عبدالله" generic: 70%
- If user inputs "عبدالله للتجارة" (adds activity word):
  - If unique: 90% (upgraded)
  - If generic: 75% (upgraded)
- SAME supplier, DIFFERENT input variations = DIFFERENT confidence levels
- No memory of past inputs, each scored independently

---

### 3.4 CONFIDENCE CALCULATION (ConfidenceCalculator)

**Formula Breakdown:**

```
score = base + confirmBoost + histBoost - (rejections * 33.4)
Clamped to: max(0, min(100, score))
```

**Base Values (by source):**
- entity_anchor: 85
- learned: 65
- historical: 40

**Confirmation Boost:**
- 0 confirms: +0
- 1 confirm: +5
- 2 confirms: +10
- 3+ confirms: +15

**Historical Boost:**
- 0-2 selections: +0
- 3-4 selections: +10
- 5+ selections: +20

**Rejection Penalty:**
- Per rejection: -33.4
- 3 rejections: -100 (guaranteed elimination)

**Level Assignment:**
- >= 85: Level B (high confidence)
- >= 65: Level C (medium)
- >= 40: Level D (low)
- < 40: NULL (hidden)

**OBSERVED SCORE RANGES:**

| Source | Min | Max | Explanation |
|--------|-----|-----|-------------|
| entity_anchor | 85 | 115 | Base 85 + max boost 30 (3 confirms + 5 historical) - rejections|
| learned | 65 | 95 | Base 65 + max boost 30 |
| historical | 40 | 70 | Base 40 + max boost 30 |

**Clamping behavior:**
- min(100, score) should prevent >100
- BUT: Line 32 uses max first, then min
- If score=115: max(0, min(100, 115)) = max(0, 100) = 100
- **Clamping works correctly**

**HOWEVER:**
- No such clamping in LearningSuggestionService before returning (line 129-141)
- Score passed as-is to result array
- UI/display layer may show >100 scores

**DIVERGENCE EVIDENCE:**

- Entity anchor candidate with 3 confirms + 5 historical = 85 + 15 + 20 = 120 raw score
- After clamp: 100
- Displayed as: Level B, 100% confidence
- But rejection penalty can bring it below B threshold:
  - 120 - (2 rejections * 33.4) = 120 - 66.8 = 53.2 → Level C

