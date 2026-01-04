# LEARNING UNIFICATION CHARTER (DRAFT) - PART 2

## 2. SINGLE LEARNING AUTHORITY — REQUIRED

### Definition of Single Learning Authority

**Single Learning Authority** in this codebase means:

ONE designated service holds EXCLUSIVE permission to:
1. Generate the final list of supplier suggestions for any input
2. Assign confidence scores using unified semantics
3. Determine suggestion ordering
4. Decide which suppliers appear and which are suppressed
5. Provide reason/explanation text to UI

All other modules become **signal feeders** or **data providers** that:
- CANNOT directly return suggestions to endpoints
- CANNOT assign confidence scores independently
- CANNOT make suppression decisions independently
- MUST route all data through the authority

---

### Authority Declaration Table

| Decision Area | Current Authorities (Observed) | Conflicts Caused | Proposed Single Authority | Cannot Override |
|---------------|-------------------------------|------------------|--------------------------|-----------------|
| **Suggestion Generation** | A: LearningSuggestionService<br>B: SupplierCandidateService<br>D: SupplierLearningRepository | Different suppliers suggested for same input;<br>Empty from A vs populated from B | **UnifiedSuggestionService**<br>(NEW or refactored from A) | No other service may return suggestions to UI/API |
| **Confidence Scoring Semantics** | A: base+boost-penalty (0-100)<br>B: similarity×weight (0-1.0)<br>C: anchor formula (70-95)<br>D: alias=100 | Different numbers for same supplier;<br>UI cannot compare confidence | **Authority assigns unified 0-100 scale**<br>using consolidated formula | Feeder services provide RAW signals only,<br>NOT final confidence |
| **Rejection/Penalty Behavior** | E: decrementUsage (floor -5)<br>A: rejection penalty (-33.4 per)<br>B: block_count increment | Dual suppression mechanisms;<br>Penalty vs block mismatch | **Authority decides penalty application**<br>using unified negative learning rules | Feeders report rejection signals,<br>Authority calculates impact |
| **Blocking Behavior** | B: block_count > 0 filter<br>D: usage_count > 0 filter | Supplier blocked in B but suggested by D;<br>Inconsistent suppression | **Authority enforces single blocking mechanism**<br>(unified suppression status) | No bypassing suppression;<br>All feeders must respect block status |
| **Alias Learning Ownership** | E: learnAlias (first-lock)<br>A: no alias learning<br>B: no alias learning | First-learned bias;<br>Cannot update incorrect aliases | **Authority manages alias creation/updates**<br>with conflict detection and resolution | Feeders cannot write to alias table;<br>Authority writes only |
| **UI Presentation Contract** | A: Arabic reason strings<br>C: Arabic reason strings<br>B: source/match_type only<br>D: score=100 only | Inconsistent explanation formats;<br>Some suggestions unexplained | **Authority returns standardized SuggestionDTO**<br>with required fields | UI receives suggestions ONLY from Authority;<br>Never directly from feeders |
| **Level Assignment** | A: B/C/D levels (85/65/40)<br>B: no levels, thresholds only<br>C: always B (pre-filtered to 70) | Level meaning unclear across sources;<br>Same score different levels | **Authority assigns unified B/C/D levels**<br>with stable semantics | Feeders do not assign levels |
| **Normalization Application** | E: normalizes before alias storage<br>A: does NOT normalize before confirmation query<br>B: normalizes for matching<br>D: normalizes for alias lookup | Learning fragmentation;<br>Same input creates multiple histories | **Authority applies normalization consistently**<br>to ALL data reads and writes | Feeder queries must use Authority's normalizer;<br>No independent normalization |

---

### What Authority IS Allowed to Decide

The Single Learning Authority has EXCLUSIVE permission to:

1. **Accept or reject feeder signals**
   - May choose to ignore certain signals based on quality/context
   - May apply time-based decay to old signals
   - May require minimum signal strength before consideration

2. **Weight feeder signals**
   - Assign relative importance to different signal types
   - Adjust weights based on signal freshness, user feedback, or system state
   - Override individual signal scores to maintain stability

3. **Resolve signal conflicts**
   - When multiple feeders suggest different suppliers for same input
   - When same supplier receives conflicting signals (confirm + reject)
   - When alias and manual selection disagree

4. **Apply unified scoring formula**
   - Combine all signals into single confidence score
   - Clamp/normalize scores to 0-100 range
   - Assign B/C/D levels with consistent thresholds

5. **Enforce suppression rules**
   - Decide which suppliers to block/filter
   - Apply penalty floors and recovery logic
   - Maintain consistent suppression across all inputs

6. **Normalize all inputs consistently**
   - Apply current normalization algorithm to ALL queries and writes
   - Migrate old data when normalization changes (or declare deprecation policy)
   - Ensure feeder queries use Authority's normalizer

7. **Generate explanations**
   - Build Arabic reason strings from signal provenance
   - Decide what information to expose to UI
   - Maintain consistent explanation format

8. **Order results**
   - Sort by confidence, then by tiebreaker rules
   - Apply business rules (e.g., prefer recently used)
   - Limit result count

---

### What Authority IS NOT Allowed to Decide

The Single Learning Authority CANNOT:

1. **Change feeder signal definitions**
   - Cannot redefine what "confirmation" means (set by user action)
   - Cannot redefine what "exact match" means (set by normalization)
   - Cannot redefine entity anchor extraction rules (set by ADR-007)

2. **Bypass data integrity constraints**
   - Cannot ignore database constraints
   - Cannot write invalid data to bypass conflict detection
   - Cannot delete learning data without audit trail

3. **Override explicit user actions**
   - If user manually selects Supplier X, Authority cannot substitute Supplier Y
   - Cannot suppress a manually selected supplier in current session
   - Cannot ignore user's explicit rejection decision

4. **Change UI contract without coordination**
   - SuggestionDTO schema is a contract with frontend
   - Changes require frontend migration
   - Cannot remove required fields

---

### What Other Modules Are Forbidden from Deciding Directly

**Feeder Services/Modules** (A, B, C, D without refactoring, or new extracted feeders) are FORBIDDEN to:

1. **Return suggestions directly to endpoints/UI**
   - All suggestion requests MUST route through Authority
   - Feeders return signals/candidates to Authority only
   - No direct API exposure for feeders

2. **Assign confidence scores using their own semantics**
   - Feeders provide RAW similarity/match strength
   - Authority translates to unified confidence
   - No "score" field in feeder output, only "signal_strength" or "raw_similarity"

3. **Make suppression decisions independently**
   - Feeders cannot decide to hide a supplier
   - Must pass ALL matches to Authority
   - Authority applies suppression consistently

4. **Write learning data independently**
   - No direct alias creation
   - No direct usage_count modification
   - Must send learning signals to Authority, which decides how to persist

5. **Apply normalization independently**
   - All normalization routed through Authority's normalizer
   - Feeders use Authority-provided normalized input
   - No divergent normalization logic

---

## 3. LEARNING SIGNALS — FEEDERS, NOT SYSTEMS

### Signal Classification Framework

The following table enumerates ALL learning signals currently used, reclassified as INPUTS to unified authority (not independent systems).

| Signal Name | Current Source | Current Storage | Current Weighting | Current Failure Modes | Unification Treatment |
|-------------|----------------|-----------------|-------------------|-----------------------|----------------------|
| **Manual Selection** | LearningService.learnFromDecision() | supplier_decisions_log, alias via learnAlias | Implicit (creates alias w/ usage_count=1) | First-lock bias; normalization drift | **FEEDER SIGNAL**<br>Strength: HIGH<br>Authority decides alias creation with conflict detection |
| **User Confirmation** | User clicks confirm on suggestion | learning_confirmations (raw name) | +5/+10/+15 boost | Fragmented by input variant; not normalized before query | **FEEDER SIGNAL**<br>Strength: HIGH<br>Authority aggregates across normalized input variants |
| **User Rejection** | User clicks reject on suggestion | learning_confirmations (raw name) | -33.4 penalty | Fragmented by input variant; asymmetric recovery | **FEEDER SIGNAL**<br>Strength: NEGATIVE<br>Authority applies unified penalty with recovery rules |
| **Historical Selection** | guarantees + guarantee_decisions | JSON in raw_data field | +10/+20 boost | Fragile JSON fragment matching; no structured query | **FEEDER SIGNAL**<br>Strength: MEDIUM<br>Authority queries structured data (if migrated) or applies conservative weighting |
| **Alias Exact Match** | supplier_alternative_names (normalized_name =) | supplier_alternative_names | 100 score (Subsystem D)<br>1.0×WEIGHT_ALT_CONFIRMED (Subsystem B) | First-match arbitrary winner; dual query paths | **FEEDER SIGNAL**<br>Strength: VERY HIGH<br>Authority uses single query path, deterministic ordering |
| **Entity Anchor Match** | ArabicLevelBSuggestions.find() | Computed in-memory (suppliers LIKE '%anchor%') | 70-95 based on uniqueness | Silent rejection if no anchors; dynamic uniqueness scoring | **FEEDER SIGNAL**<br>Strength: HIGH (if unique anchor)<br>Authority caches uniqueness or applies stable scoring |
| **Fuzzy Official Match** | SupplierCandidateService (similarity calc) | suppliers table (in-memory cache) | similarity × WEIGHT_FUZZY | N×M performance; no quality threshold enforcement | **FEEDER SIGNAL**<br>Strength: VARIABLE (based on similarity)<br>Authority applies unified threshold |
| **Fuzzy Alternative Match** | SupplierCandidateService (all alternatives) | supplier_alternative_names (all rows) | similarity × WEIGHT_FUZZY | N×M performance; lower quality than exact | **FEEDER SIGNAL**<br>Strength: MEDIUM<br>Authority prefers exact over fuzzy |
| **Override Match** | SupplierCandidateService (override table) | supplier_override_names | similarity × WEIGHT_OFFICIAL | Rare usage; unclear purpose | **FEEDER SIGNAL**<br>Strength: HIGH (admin override intent)<br>Authority respects but logs for audit |
| **Learning Cache** | supplier_learning_cache | supplier_learning_cache (effective_score) | LEARNING_SCORE_CAP (0.90) | Population mechanism unclear; cache-live divergence | **FEEDER SIGNAL**<br>(IF RETAINED)<br>Strength: Cache of Authority's own past decisions<br>OR DEPRECATED if redundant |
| **Block Count** | supplier_learning_cache (block_count > 0) | supplier_learning_cache | Binary suppression | Dual mechanism with usage_count penalty | **SUPPRESSION SIGNAL**<br>Authority consolidates into unified block status |
| **Usage Count** | supplier_alternative_names (usage_count) | supplier_alternative_names | Incremented/decremented | Penalty floor -5; no recovery path; filter at >0 threshold | **WEIGHT SIGNAL**<br>Authority interprets as alias confidence weight, not binary filter |

---

### Signal Feeder Responsibilities

Each feeder MUST:

1. **Provide raw signal strength, not final confidence**
   - Return: `[{supplier_id, signal_type, raw_strength, metadata}]`
   - Example: `{supplier_id: 10, signal_type: 'entity_anchor', raw_strength: 0.85, matched_anchor: 'النورس'}`
   - NOT: `{supplier_id: 10, confidence: 85, level: 'B'}`

2. **Return ALL matches above internal quality threshold, no suppression**
   - Feeder cannot hide suppliers
   - Authority applies suppression
   - Example: Fuzzy matcher returns all suppliers with similarity ≥ 0.60, Authority filters to ≥0.70

3. **Use Authority-provided normalized input**
   - Authority normalizes once
   - Passes normalized string to all feeders
   - Ensures consistent matching

4. **Include provenance metadata**
   - What matched (anchor, alias, fuzzy method)
   - Where data came from (table, cache, calculation)
   - When data was created/updated (if relevant)
   - Why this signal was generated (context)

5. **NOT query learning_confirmations or supplier_decisions_log directly**
   - Only Authority aggregates confirmations/rejections
   - Feeders focus on matching, not learning accumulation

---

### Signal Aggregation Rules (Authority's Responsibility)

Authority receives signals from multiple feeders for same supplier_id:

```
Input: "شركة النورس"
Normalized: "شركه النورس"

Signals received:
1. {supplier_id: 10, signal_type: 'alias_exact', raw_strength: 1.0, source: 'supplier_alternative_names'}
2. {supplier_id: 10, signal_type: 'entity_anchor', raw_strength: 0.85, matched_anchor: 'النورس', uniqueness: 'unique'}
3. {supplier_id: 10, signal_type: 'fuzzy_official', raw_strength: 0.92, similarity_method: 'levenshtein'}
4. {supplier_id: 10, signal_type: 'confirmation', count: 3, from: 'learning_confirmations'}
5. {supplier_id: 10, signal_type: 'rejection', count: 1, from: 'learning_confirmations'}
```

Authority's aggregation:
1. **Deduplicate by supplier_id** (already same supplier)
2. **Select primary match signal** (alias_exact wins due to highest priority)
3. **Apply learning boosts** (+15 for 3 confirmations)
4. **Apply learning penalties** (-33.4 for 1 rejection)
5. **Compute final confidence:**
   ```
   Base = 100 (alias exact match)
   Boost = +15 (confirmations)
   Penalty = -33.4 (rejection)
   Final = 100 + 15 - 33.4 = 81.6 → 82 (rounded)
   Level = C (≥65, <85)
   ```
6. **Build reason:**
   "تطابق دقيق (اسم بديل) + تم تأكيده 3 مرات + تم رفضه مرة واحدة"

Result: Single suggestion with unified confidence, provenance-aware explanation

---

## 4. UNIFIED SCORING & WEIGHT SEMANTICS

### Unified Semantics Contract

This contract defines HOW the Authority combines signals into confidence scores.

---

#### 4.1 Positive Learning Semantics

**Definition:** Positive learning is the accumulation of evidence that an input→supplier mapping is correct.

**Creation:**
- First manual selection creates alias entry with `usage_count=1`
- First confirmation creates learning_confirmations entry with `action='confirm'`
- Authority recognizes BOTH as independent positive signals

**Reinforcement:**
- Subsequent manual selections: `usage_count++` (via Authority, not direct increment)
- Subsequent confirmations: additional rows in learning_confirmations
- Each reinforcement increases weight in confidence calculation

**Decay (IF IMPLEMENTED IN FUTURE):**
- Time-based: Signals older than N months have reduced weight
- NOT IMPLEMENTED CURRENTLY
- If added, Authority applies decay formula, feeders unaware

**Caps:**
- usage_count: NO CAP OBSERVED (unbounded growth risk)
- Confirmation boost: +15 max (at 3+ confirmations)
- Alias match contribution: 100 base (no accumulation beyond match quality)

**Proposed Charter Rule:**
> Positive learning signals accumulate without cap UNLESS Authority detects signal inflation risk. Authority MAY apply soft caps to prevent runaway confidence. Current caps: confirmation_boost ≤ +15, historical_boost ≤ +20.

---

#### 4.2 Negative Learning Semantics

**Definition:** Negative learning is the accumulation of evidence that an input→supplier mapping is incorrect.

**Current Mechanisms:**
1. **Rejection penalty** (Subsystem A): -33.4 per rejection
2. **Usage count decrement** (Subsystem E): usage_count--, floor at -5

**Asymmetry Observed:**
- 3 rejections = -100 points → guaranteed suppression (confidence <0)
- 3 rejections = usage_count drops to -2 (if started at 1)
- Recovery requires 3+ manual selections to bring usage_count back to 1

**Proposed Charter Rule:**
> Negative learning applies symmetrically to positive learning. Penalty magnitude equals boost magnitude. Recovery path MUST exist: enough positive signals can overcome negative signals. Floor serves as warning threshold, not permanent ban.

**Unified Penalty:**
- 1 rejection = -10 points (equivalent to 2 confirmations lost)
- Usage count reflects total signal balance: positive increments - negative decrements
- Floor at -10 (not -5), requires sustained rejection to reach
- Automatic recovery: If supplier manually selected AFTER reaching floor, penalty reduced by half

**Deletion (NOT SUPPRESSION):**
- If usage_count reaches -10 AND no positive signals in last 6 months → candidate for archival (not deletion, data retained for audit)
- Authority flags for review, does NOT auto-delete

---

#### 4.3 Weight Assignment by Signal Type

**Weight Hierarchy (Proposed Unified Scale):**

| Signal Type | Base Weight | Rationale | Adjustments |
|-------------|-------------|-----------|-------------|
| **Alias Exact Match** | 100 | Learned explicit mapping | -10 per rejection (min 70) |
| **Entity Anchor (Unique)** | 90 | High-confidence anchor matching | +5 if confirmed, -10 per rejection |
| **Entity Anchor (Generic)** | 75 | Generic anchor, less distinctive | +5 if confirmed, -10 per rejection |
| **Fuzzy Official (Strong)** | 85 | High similarity to official name | No learning boost (data-driven only) |
| **Fuzzy Official (Weak)** | 70 | Moderate similarity | No learning boost |
| **Fuzzy Alternative (Strong)** | 80 | High similarity to known alias | +5 if confirmed |
| **Fuzzy Alternative (Weak)** | 65 | Moderate similarity to alias | +5 if confirmed |
| **Admin Override** | 95 | Manual admin mapping | Cannot be penalized (admin authority) |
| **Historical Selection Only** | 45 | Weak signal, old data | +10 if 3+ selections, +20 if 5+ |
| **Learning Cache** (if retained) | Use cached score | Pre-computed by Authority | Treat as past Authority decision |

**Conflict Resolution:** If multiple signals for same supplier, use HIGHEST base weight, then apply ALL learning adjustments

---

#### 4.4 Unified Confidence Formula

```
For each supplier candidate:

1. Identify PRIMARY signal (highest base weight)
   primary_weight = max(signal.base_weight for signal in signals_for_supplier)

2. Aggregate learning signals:
   total_confirmations = SUM(confirmations across all normalized variants of input)
   total_rejections = SUM(rejections across all normalized variants of input)
   usage_score = usage_count (from alias if applicable, else 0)

3. Calculate boosts/penalties:
   confirmation_boost = min(total_confirmations * 5, 15)  # Cap at +15
   historical_boost = (see historical selection count logic)
   rejection_penalty = total_rejections * 10  # Symmetric with confirmation
   usage_penalty = max(usage_score, 0) * 2 if usage_score < 0 else 0

4. Compute final confidence:
   confidence = primary_weight + confirmation_boost + historical_boost 
                - rejection_penalty - usage_penalty
   
5. Clamp:
   confidence = max(0, min(100, confidence))

6. Assign level:
   if confidence >= 85: level = 'B'
   elif confidence >= 65: level = 'C'
   elif confidence >= 40: level = 'D'
   else: level = NULL (not shown)
```

**Normalization Aggregation Rule:**
- Authority queries learning_confirmations with normalized input
- Aggregates across ALL raw_supplier_name values that normalize to same value
- Solves fragmentation problem (Section 5.2)

**Example:**
```
Signals for supplier_id=10:
- alias_exact: base_weight=100
- confirmations: 5 (aggregated across "شركة النورس", "شركة  النورس", "شركة النورس ")
- rejections: 1
- usage_count: 3

Calculation:
confidence = 100 + min(5*5, 15) + (3*2) - (1*10)
           = 100 + 15 + 6 - 10
           = 111 → clamped to 100
level = 'B'
```

---

#### 4.5 Stability Preservation

**Problem:** Dynamic scoring (anchor uniqueness, database growth) causes same input to yield different confidence over time.

**Proposed Charter Rule:**
> Confidence scores SHOULD be stable for same input within reasonable time window (same day). Authority MAY cache confidence calculations for repeated queries within session/hour. Structural changes (many new suppliers added) trigger cache invalidation and re-scoring notification.

**Implementation Guidance (NOT IMPLEMENTATION):**
- Cache: (normalized_input, supplier_id) → (confidence, computed_at)
- TTL: 1 hour or until learning signal received
- Invalidation: New supplier added → clear cache for affected anchors

---

## 5. COLLISION RESOLUTION RULES (NO SURPRISES)

### Legal-Clause Precision Rules

---

#### Rule 5.1: Multiple Suppliers for Same Normalized Input

**Situation:** Authority receives signals for different supplier_ids matching same normalized input.

**Example:**
```
Input: "الوطنية" (normalized)
Signals:
- Supplier 5: alias_exact, usage_count=10
- Supplier 12: fuzzy_official, similarity=0.88

```

**Authority MUST:**
1. Return BOTH as separate suggestions (do not auto-pick winner)
2. Order by confidence DESC
3. If confidence difference < 5 points: flag as ambiguous in metadata
4. Include provenance in reason: "اسم مشترك بين موردين" (shared name warning)

**Authority MAY NOT:**
- Suppress lower-confidence supplier if both above Level D threshold
- Merge into single suggestion
- Auto-select based on usage without user confirmation

**Exception:** If one supplier has admin override signal, admin override wins absolutely (95 base weight)

---

#### Rule 5.2: Alias Learning Conflicts with Manual Selection

**Situation:** Existing alias maps input→Supplier A, user manually selects Supplier B.

**Example:**
```
Existing: normalized="الشركه" → supplier_id=5, usage_count=10
User action: Selects supplier_id=12 for input "الشركة"
```

**Authority MUST:**
1. Detect conflict: normalized input matches existing alias with different supplier_id
2. Log conflict to audit table
3. Create OPTIONS:
   - Option A: Update alias to supplier_id=12, archive old alias (mark as superseded)
   - Option B: Create SECOND alias for same normalized input (shared alias)
   - Option C: Decrease old alias usage_count, create new alias with usage_count=1

**Current Behavior (INVALID):** Silently ignores new selection

**Proposed Charter Decision:** **Option B (Shared Alias)**
- Allow multiple suppliers for same normalized input
- Both appear in suggestions
- User's manual selection adds positive signal to supplier_id=12
- Next query shows BOTH suppliers, ordered by total confidence

**Authority MAY NOT:**
- Silently ignore user's manual selection
- Auto-override without conflict detection

---

#### Rule 5.3: Cache vs Live Matching Disagree

**Situation:** `supplier_learning_cache` returns supplier X, live matching returns supplier Y.

**Example:**
```
Cache: normalized="التقدم" → supplier_id=20, effective_score=90
Live: alias lookup → supplier_id=35, usage_count=5 (alias created after cache populated)
```

**Authority MUST:**
1. Trust LIVE data over cache
2. Include cache result only if no live match found OR cache adds unique suppliers
3. De-duplicate by supplier_id
4. Log cache-live divergence for monitoring

**Authority MAY NOT:**
- Trust cache over live data
- Return both as separate suggestions for same supplier_id

**Future Consideration:** If cache divergence >10% of queries, deprecate cache system

---

#### Rule 5.4: Same Supplier, Multiple Sources, Different Scores

**Situation:** Same supplier_id has signals from multiple feeders with different raw strengths.

**Example:**
```
Supplier 10:
- Signal 1: alias_exact, raw_strength=1.0
- Signal 2: fuzzy_official, raw_strength=0.92
- Signal 3: entity_anchor, raw_strength=0.85
```

**Authority MUST:**
1. Select HIGHEST weight signal as primary source
2. Include secondary signals in metadata/reason
3. Compute confidence using primary weight + learning boosts
4. Build reason explaining multi-source match:
   "تطابق دقيق (اسم بديل) + تطابق مع الاسم الرسمي (92%)"

**Authority MAY NOT:**
- Average the scores
- Create multiple suggestions for same supplier
- Expose internal scoring details (raw_strength values) to UI

---

#### Rule 5.5: Normalization Version Changes

**Situation:** Normalization algorithm updated, old aliases incompatible with new normalized queries.

**Example:**
```
Old normalization: "مؤسسة" → "موسسة" (ة retained)
New normalization: "مؤسسة" → "موسسه" (ة→ه applied)
Database: aliases with "موسسة" exist
Query: input="مؤسسة" → normalizes to "موسسه" with new algorithm
Match: FAILS
```

**Authority MUST:**
1. When normalization changes deployed, log as BREAKING CHANGE
2. Run migration to re-normalize ALL supplier_alternative_names.normalized_name
3. Maintain audit trail: (old_normalized, new_normalized, migration_timestamp)
4. Until migration complete, try BOTH old and new normalization in queries (compatibility mode)

**Authority MAY NOT:**
- Deploy normalization changes without data migration plan
- Silently orphan old aliases

**Compatibility Mode (Temporary):**
```
Query with NEW normalization: "موسسه"
If no match:
  Query with OLD normalization: "موسسة"
  If match found: LOG for migration, return result
```

Remove compatibility mode after migration confirmed complete.

---

#### Rule 5.6: Block Count vs Usage Count Mismatch

**Situation:** Supplier has block_count>0 (blocked) but alias has usage_count>0 (active).

**Example:**
```
supplier_learning_cache: supplier_id=15, block_count=3
supplier_alternative_names: supplier_id=15, normalized="...", usage_count=5
```

**Authority MUST:**
1. Respect block_count as ABSOLUTE suppression
2. Do NOT suggest supplier_id=15 regardless of usage_count
3. Log the mismatch for review (data inconsistency)

**Unified Rule:**
```
IF block_count > 0 THEN
  suppress supplier (do not show in suggestions)
ELSE IF usage_count <= 0 THEN
  suppress alias (do not use this alias for matching)
ELSE
  include in suggestions
END IF
```

**Authority SHALL consolidate block_count and usage_count:**
- Negative usage_count interpreted as alias-level suppression
- block_count interpreted as supplier-level suppression (all aliases)
- Supplier-level suppression overrides alias-level activity
