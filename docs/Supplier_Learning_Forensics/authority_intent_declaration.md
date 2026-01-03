# AUTHORITY INTENT DECLARATION

## LEARNING AUTHORITY GOVERNANCE & TECHNICAL CONTRACT

**Status:** Constitutional Framework  
**Type:** Behavioral Intent + Implementation Contract  
**Scope:** Learning Authority Service - Single Source of Supplier Suggestions  
**Authority:** Learning Unification Charter + Database Role Declaration  
**Binding:** All suggestion logic MUST comply with this declaration  

---

## PREAMBLE: WHAT IS THE AUTHORITY?

The **Learning Authority** is the SINGLE, UNIFIED service responsible for:

✓ Receiving user input (raw supplier name)  
✓ Aggregating signals from database (without decision bias)  
✓ Computing confidence scores (unified formula)  
✓ Ordering suggestions (by confidence)  
✓ Returning standardized output (SuggestionDTO)  

**The Authority is NOT:**
- A database query wrapper
- A cache lookup service
- One of many competing suggestion sources
- An auto-decision maker

**The Authority IS:**
- The ONLY path from user input to supplier suggestions
- The guardian of unified scoring semantics
- The enforcer of Database Role Declaration
- The implementer of Charter principles

---

## PART 1: AUTHORITY INTENT (PHILOSOPHICAL FOUNDATION)

### 1.1 Role of the System

**Declaration:**

> The system acts strictly as a **suggestion assistant**.  
> It never makes or implies a decision.

**What This Means:**

- Authority returns **options**, not **answers**
- Confidence is **ordering aid**, not **truth claim**
- High confidence ≠ "This is correct"
- High confidence = "Based on signals, this ranks higher"

**Forbidden Language (UI/Code):**

❌ "Auto-selected"  
❌ "Correct match"  
❌ "Verified supplier"  

**Acceptable Language:**

✓ "Suggested"  
✓ "High confidence"  
✓ "Recommended for review"  

---

### 1.2 Meaning of Confidence

**Declaration:**

> Confidence represents an **internal composite weighting**  
> used solely for **ordering suggestions**.  
> It is not a guarantee, truth, or commitment.

**Technical Implication:**

- Confidence = f(signals, weights, context)
- Confidence is RELATIVE (within result set)
- Confidence is ORDINAL (for sorting)
- Confidence is NOT ABSOLUTE (not probability)

**User Perception:**

- 85% confidence ≠ 85% probability of correctness
- 85% confidence = "This suggestion is stronger than others based on available signals"

**Authority Responsibility:**

- Compute confidence consistently
- Order by confidence descending
- Never claim confidence is certainty

---

### 1.3 Silence Rule

**Declaration:**

> The system may present **no suggestions** only when:
> - no valid signals exist, or
> - signals are irreconcilably conflicting.

**What Triggers Silence:**

**Valid Reason 1: No Signals**
```
Input: "شركة غير معروفة تماماً"
Signals found: 0
Authority returns: [] (empty)
Explanation: "لا توجد بيانات كافية لتقديم اقتراحات"
```

**Valid Reason 2: Irreconcilable Conflict**
```
Input: "الشركة الوطنية"
Signals:
  - 10 confirmations for supplier_id=5
  - 10 confirmations for supplier_id=12
  - Equal confidence, cannot order
Authority returns: BOTH suppliers with equal confidence
(Not silence, presents conflict to user)
```

**Invalid Reason (FORBIDDEN):**

❌ Single rejection (weak signal, not silence-worthy)  
❌ Low confidence (below threshold, still return if above minimum)  
❌ Cache miss (not a signal absence)  
❌ Normalization mismatch (schema failure, not signal absence)  

**Minimum Threshold:**

Authority MAY filter suggestions below confidence 40 (Level D threshold per Charter)  
BUT this is presentation filter, not "no signals"

---

### 1.4 Negative Learning (Rejection)

**Declaration:**

> A rejection is a **weak negative signal**:
> - applies only to the rejected suggestion,
> - activates only after repetition,
> - never results in permanent suppression.

**Technical Rules:**

**Single Rejection:**
- Penalty: -10 points (Charter formula)
- Effect: Decreases confidence, may drop below threshold
- NOT: Immediate suppression
- NOT: Permanent ban

**Repeated Rejections (3+):**
- Cumulative penalty: -30 points
- Effect: Likely below display threshold
- Still: Supplier can recover via positive signals
- Still: Not permanently deleted

**Activation Threshold:**
```
1 rejection:  Minor penalty, suggestion still shown
2 rejections: Moderate penalty, may drop to Level D
3 rejections: Strong penalty, likely hidden (confidence < 40)
```

**Recovery Path:**

- Manual selection: +usage_count, potentially restores confidence
- New confirmations: +boost, can overcome rejections
- Time decay (if implemented): Old rejections lose weight

**Permanent Suppression (FORBIDDEN):**

❌ No "blacklist" mechanism  
❌ No "never show again" flag  
❌ No irreversible penalty floor  

**Floor Rule:**

- usage_count floor: -10 (not -∞)
- Reaching floor ≠ deletion
- Reaching floor = "very weak signal, needs recovery"

---

### 1.5 Correction vs Penalty

**Declaration:**

> Choosing a non-top suggestion results in:
> - positive reinforcement of the chosen option,
> - mild negative adjustment of the skipped top option.

**Scenario:**

```
Authority suggests:
1. Supplier A (90% confidence)
2. Supplier B (85% confidence)
3. Supplier C (75% confidence)

User selects: Supplier B
```

**Authority Response:**

**Positive Reinforcement (Supplier B):**
- Learn alias (if manual entry)
- Increment usage_count
- Log as confirmation (if applicable)
- Effect: Next query, Supplier B likely rises

**Mild Negative Adjustment (Supplier A):**
- Log as "top suggestion ignored" (audit)
- Decrement usage_count by 1 (mild)
- Effect: Supplier A confidence slightly lower next time

**No Penalty (Supplier C):**
- User didn't reject C, just chose B
- C maintains current signals

**Forbidden:**

❌ Harsh penalty for top suggestion (-10 or more)  
❌ Treating "ignored" as "rejected"  
❌ Penalizing ALL unchosen suggestions  

**Asymmetry Principle:**

- Positive reinforcement: Strong (usage_count++, confirmation logged)
- Negative adjustment: Mild (usage_count--, floor -10)
- Bias toward learning what works, not over-penalizing

---

### 1.6 Stability Preference

**Declaration:**

> The system prioritizes **behavioral stability and predictability**  
> over rapid adaptation.

**What This Means:**

**Stable:**
- Same input → same suggestions (within session/day)
- Confidence doesn't flip randomly
- Top suggestion doesn't change without new signal

**Predictable:**
- User can build mental model of system behavior
- Suggestions don't disappear unexpectedly
- Confidence increases/decreases have clear causes

**vs Rapid Adaptation:**
- NOT: Every click instantly reshapes all suggestions
- NOT: Real-time re-ranking on every keystroke
- NOT: Aggressive forgetting of old signals

**Technical Implementation:**

- Cache suggestions for short duration (1 hour)
- Require N signals before significant confidence change (N=3 for boosts)
- Smooth transitions (gradual decay, not instant drops)

**Exception:**

NEW explicit signal (confirmation, manual selection) → immediate update acceptable

---

### 1.7 Decision Ownership

**Declaration:**

> All final decisions belong to the user.  
> The system learns from outcomes but does not assume responsibility for past choices.

**Implication for Authority:**

**Authority Does NOT:**
- Override user manual selection
- Auto-select on user's behalf (even at 100% confidence)
- Assume user's past choice was "correct"

**Authority DOES:**
- Learn from user's choice as signal
- Increase confidence for chosen supplier (positive signal)
- Decrease confidence for alternatives if repeatedly ignored

**Past Choices:**

- Historical decisions are SIGNALS, not GROUND TRUTH
- User may have selected wrong supplier in the past
- Authority learns pattern, not correctness

**Example:**

```
User selected Supplier X 100 times for input "ABC"
Later, user discovers Supplier X was wrong, should be Supplier Y

Authority response:
- Does NOT claim Supplier X is "correct" (no responsibility)
- DOES suggest Supplier X (strong signal pattern)
- ALLOWS user to select Supplier Y
- LEARNS new pattern (Supplier Y now accumulates signals)
```

---

## PART 2: TECHNICAL CONTRACT

### 2.1 Signal Consumption Rules

**Authority MUST:**

#### Rule 2.1.1: Normalize Input ONCE

```php
$normalized = $this->normalizer->normalize($rawInput);
// Use $normalized for ALL queries
// Do NOT normalize again differently
```

**Effect:** Consistent signal aggregation

---

#### Rule 2.1.2: Query ALL Relevant Signals

**Retrieve:**
1. Alias matches (exact on normalized name)
2. Entity anchor matches (if anchors extracted)
3. Fuzzy official matches (similarity ≥ threshold)
4. User confirmations (aggregated by normalized input)
5. User rejections (aggregated by normalized input)
6. Historical selections (frequency count)

**Do NOT:**
- Query only cache
- Query only one signal type
- Apply decision filters in SQL (e.g., `WHERE usage_count > 0`)

---

#### Rule 2.1.3: Aggregate Confirmations Across Variants

**Problem:**
```sql
-- WRONG
SELECT COUNT(*) FROM learning_confirmations 
WHERE raw_supplier_name = 'شركة النورس';
-- Misses: 'شركة  النورس', 'شركة النورس '
```

**Solution:**

```php
// Authority-side aggregation
$allRawVariants = $this->findRawVariants($normalized);
$totalConfirmations = 0;
foreach ($allRawVariants as $variant) {
    $totalConfirmations += $this->countConfirmations($variant);
}
```

**OR (Preferred after schema update):**

```sql
-- Requires normalized_supplier_name column
SELECT COUNT(*) FROM learning_confirmations 
WHERE normalized_supplier_name = ?;
```

---

#### Rule 2.1.4: Treat Signals as Raw Data

**Authority receives signals in RAW form:**

```php
// Alias signal
[
    'supplier_id' => 10,
    'signal_type' => 'alias_exact',
    'raw_strength' => 1.0,  // NOT confidence
    'metadata' => ['source' => 'learning']
]

// Confirmation signal
[
    'supplier_id' => 10,
    'signal_type' => 'confirmation',
    'count' => 3,
    'metadata' => ['action' => 'confirm']
]
```

**Authority computes confidence:**

```php
$confidence = $this->computeConfidence([
    'alias_match' => 1.0,
    'confirmations' => 3,
    'rejections' => 0,
    'historical' => 0
]);
// Returns: 100 + 15 - 0 = 115 → clamped to 100
```

**Forbidden:**

```php
// FORBIDDEN: Query returns final confidence
$confidence = $db->query("SELECT effective_score FROM cache WHERE...");
return $confidence; // Bypasses Authority logic
```

---

### 2.2 Decision Formation Process

**Authority MUST follow this sequence:**

#### Step 1: Normalize Input
```php
$normalized = $this->normalizer->normalize($rawInput);
```

#### Step 2: Gather Signals
```php
$signals = [
    'aliases' => $this->aliasFeeder->getSignals($normalized),
    'anchors' => $this->anchorFeeder->getSignals($normalized),
    'fuzzy' => $this->fuzzyFeeder->getSignals($normalized),
    'confirmations' => $this->learningFeeder->getConfirmations($normalized),
    'rejections' => $this->learningFeeder->getRejections($normalized),
    'historical' => $this->historicalFeeder->getSelections($normalized)
];
```

#### Step 3: Aggregate by Supplier
```php
$candidatesBySupplier = [];
foreach ($signals as $signalType => $signalList) {
    foreach ($signalList as $signal) {
        $candidatesBySupplier[$signal['supplier_id']][] = $signal;
    }
}
```

#### Step 4: Compute Confidence per Supplier
```php
foreach ($candidatesBySupplier as $supplierId => $supplierSignals) {
    $confidence = $this->scorer->computeConfidence($supplierSignals);
    $candidates[] = [
        'supplier_id' => $supplierId,
        'confidence' => $confidence,
        'signals' => $supplierSignals  // Provenance
    ];
}
```

#### Step 5: Filter by Minimum Threshold
```php
$candidates = array_filter($candidates, fn($c) => $c['confidence'] >= 40);
```

#### Step 6: Order by Confidence
```php
usort($candidates, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
```

#### Step 7: Format as SuggestionDTO
```php
return array_map(fn($c) => $this->formatter->toDTO($c), $candidates);
```

**No step may be skipped.**  
**No alternative paths allowed.**

---

### 2.3 Output Schema (SuggestionDTO)

**Authority MUST return suggestions in this format ONLY:**

```typescript
interface SuggestionDTO {
  // Identity
  supplier_id: number;
  official_name: string;
  english_name: string | null;
  
  // Confidence & Level
  confidence: number;  // 0-100 integer
  level: 'B' | 'C' | 'D';  // Never null
  
  // Explanation (Arabic)
  reason_ar: string;  // Required, never empty
  
  // Learning Context
  confirmation_count: number;
  rejection_count: number;
  usage_count: number;
  
  // Metadata (optional, for debugging)
  primary_source?: 'alias_exact' | 'entity_anchor' | 'fuzzy_official' | 'historical';
  signal_count?: number;
  is_ambiguous?: boolean;
  requires_confirmation?: boolean;
}
```

**Validation:**

```php
function validateSuggestionDTO($dto): void {
    assert($dto['confidence'] >= 0 && $dto['confidence'] <= 100);
    assert(in_array($dto['level'], ['B', 'C', 'D']));
    assert(!empty($dto['reason_ar']));
    assert($dto['confidence'] >= 85 ? $dto['level'] === 'B' : true);
    assert($dto['confidence'] >= 65 && $dto['confidence'] < 85 ? $dto['level'] === 'C' : true);
    assert($dto['confidence'] >= 40 && $dto['confidence'] < 65 ? $dto['level'] === 'D' : true);
}
```

---

### 2.4 Storage Rules

**Authority MAY store:**

#### Decision Log (Audit)
```sql
INSERT INTO supplier_authority_decisions (
    input_raw,
    input_normalized,
    suggestions_returned,  -- JSON array of SuggestionDTOs
    decision_timestamp,
    authority_version
) VALUES (?, ?, ?, NOW(), 'v2.1');
```

**Purpose:** Audit trail, debugging, A/B testing  
**NOT for:** Active learning, suggestion generation  

#### Learning Signal Creation

When user makes selection:
```php
// Authority delegates to LearningService
$this->learningService->recordDecision([
    'raw_input' => $rawInput,
    'normalized_input' => $normalized,
    'chosen_supplier_id' => $chosenId,
    'was_top_suggestion' => ($chosenId === $top SuggestionId),
    'all_suggestions' => $suggestions
]);
```

**LearningService responsibilities:**
- Update alias usage (increment chosen, decrement top if ignored)
- Log confirmation (if applicable)
- Update signal tables

**Authority does NOT:**
- Directly write to signal tables
- Update usage_count itself
- Manage cache population

---

### 2.5 Prohibited Operations

**Authority MUST NOT:**

#### ❌ Query Cache as Alternative Source

```php
// FORBIDDEN
$cached = $this->cache->getSuggestions($input);
if (!empty($cached)) {
    return $cached;  // Bypasses Authority logic
}
```

**Correct:**
```php
// Authority computes, then caches
$suggestions = $this->computeSuggestions($input);
$this->cache->store($input, $suggestions, ttl: 3600);
return $suggestions;
```

---

#### ❌ Embed Decision Logic in SQL

```php
// FORBIDDEN
$results = $db->query("
    SELECT * FROM supplier_alternative_names 
    WHERE normalized_name = ? 
    AND usage_count > 0  -- Decision filter
    ORDER BY usage_count DESC  -- Decision ordering
    LIMIT 1  -- Decision limiting
");
```

**Correct:**
```php
// Retrieve ALL signals
$signals = $db->query("
    SELECT * FROM supplier_alternative_names 
    WHERE normalized_name = ?
");

// Authority applies logic
$filtered = array_filter($signals, fn($s) => $this->meetsThreshold($s));
$ordered = $this->orderByConfidence($filtered);
$limited = array_slice($ordered, 0, 5);
```

---

#### ❌ Return Non-Standard Format

```php
// FORBIDDEN
return [
    'id' => 10,
    'name' => 'شركة النورس',
    'score' => 0.92,  // Not 0-100 scale
    'type' => 'fuzzy'  // Implementation detail
];
```

**Correct:**
```php
return [
    'supplier_id' => 10,
    'official_name' => 'شركة النورس',
    'confidence' => 92,  // 0-100 integer
    'level' => 'B',
    'reason_ar' => 'تطابق دقيق + تم تأكيده 3 مرات'
];
```

---

#### ❌ Make Auto-Selection Decision

```php
// FORBIDDEN
if ($topSuggestion['confidence'] >= 95) {
    $this->autoSelectSupplier($topSuggestion['supplier_id']);
    return ['auto_selected' => true];
}
```

**Even at 100% confidence, Authority only suggests.**

---

## PART 3: GOVERNANCE

### 3.1 Authority Supremacy

**This declaration establishes:**

> **Learning Authority is the SINGLE, AUTHORITATIVE source**  
> **for all supplier suggestions.**

**No service, controller, or UI component may:**
- Generate suggestions independently
- Apply different confidence formulas
- Bypass Authority via cache lookup
- Merge results from multiple sources

**All suggestion requests MUST route through Authority.**

---

### 3.2 Compliance Verification

**Automated Tests:**

```php
// Test: Authority is only source
function test_no_alternative_suggestion_sources() {
    $services = app()->tagged('suggestion-provider');
    $this->assertCount(1, $services);
    $this->assertInstanceOf(LearningAuthority::class, $services[0]);
}

// Test: Output format compliance
function test_authority_returns_valid_dto() {
    $authority = app(LearningAuthority::class);
    $results = $authority->getSuggestions('test input');
    
    foreach ($results as $suggestion) {
        $this->validateSuggestionDTO($suggestion);
    }
}
```

**Manual Review (Quarterly):**
- Audit confidence formula stability
- Review signal aggregation patterns
- Verify no SQL decision logic
- Check cache usage patterns

---

### 3.3 Amendment Process

**This declaration may be amended ONLY via:**

1. Charter amendment (if philosophical principles change)
2. Architecture Review Board approval (if technical contract evolves)
3. Version increment with changelog

**Individual teams/developers CANNOT:**
- Add alternative suggestion paths
- Create confidence formula variants
- Extend SuggestionDTO without approval

---

## FINAL DECLARATION

**The Learning Authority exists to:**

✓ Serve users with helpful, ordered suggestions  
✓ Learn from user decisions without claiming authority  
✓ Maintain stability and predictability  
✓ Respect that users make final decisions  

**The Learning Authority refuses to:**

❌ Auto-select on user's behalf  
❌ Claim certainty about suggestions  
❌ Permanently suppress options  
❌ Bypass unified governance  

---

**This is the bridge between:**
- Charter principles (what we believe)
- Database roles (what we store)
- Implementation code (what we build)

**Authority Intent Declaration is now BINDING.**

---

**END OF AUTHORITY INTENT DECLARATION**

**Version:** 1.0  
**Effective Date:** Upon Charter Approval  
**Supersedes:** All parallel suggestion services  
**Dependencies:** Learning Unification Charter, Database Role Declaration
