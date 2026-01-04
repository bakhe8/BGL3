# Signal Preservation Checklist

## Document #2 - Learning Merge Phase

**التاريخ**: 2026-01-03  
**الحالة**: Binding Checklist  
**الهدف**: ضمان عدم فقدان أي signal  أو سلوك خلال الدمج

---

## 🎯 Purpose

**هذه الوثيقة**:
- ✅ Checklist ملزمة لكل signal type
- ✅ تحدد ما يجب الحفاظ عليه **بالضبط**
- ✅ تعطي معايير قبول واضحة
- ❌ **لا تقترح** كيفية التنفيذ (هذا في Data Refactor Plan)

---

## 📋 Signal Preservation Matrix

### SIGNAL S1: `alias_exact`

#### Pre-Merge State
**Location**: `supplier_alternative_names` table  
**Query**: `WHERE normalized_name = ?`  
**Strength**: Always `1.0`  
**Base Score**: `100`

#### Must Preserve

- [x] ✅ Exact match behavior (normalized_name lookup)
- [x] ✅ Always returns strength=1.0
- [x] ✅ Metadata includes alias_source ('learning' | 'manual' | 'import')
- [x] ✅ No usage_count filtering (Query Pattern Audit #9 compliant)
- [x] ✅ Conflict detection still works (Trust Gate integration)

#### Success Criteria

**Test Case #1**: Alias Match
```php
// Input: "شركة النورس"
// Pre-merge: alias exists for supplier_id=5
$before = getSignals('شركة النورس');
// Expected: SignalDTO(supplier_id=5, type='alias_exact', strength=1.0)

// Post-merge:
$after = getSignals('شركة النورس');
// Expected: SAME result

assert($before == $after);
```

**Test Case #2**: Conflict Detection
```php
// Setup: Alias "شركة النورس" exists for TWO suppliers (5, 7)
// Supplier 5's alias source='learning', Supplier 7's source='manual'

$trust = evaluateTrust(supplierId=5, source='learning', 'شركة النورس');
// Expected: isTrusted=false (conflict detected)

// Post-merge: SAME behavior
```

#### Post-Merge Acceptance

- [ ] Same signal emitted for same input
- [ ] Same strength calculation
- [ ] Same metadata structure
- [ ] Conflict detection works
- [ ] No performance regression

---

### SIGNAL S2: `entity_anchor_unique`

#### Pre-Merge State
**Location**: Computed (AnchorSignalFeeder)  
**Source**: `suppliers.official_name` LIKE '%anchor%'  
**Strength**: `1.0` (freq=1) or `0.9` (freq=2)  
**Base Score**: `90`

#### Must Preserve

- [x] ✅ Anchor extraction logic (ArabicEntityExtractor)
- [x] ✅ Frequency calculation (countSuppliersWithAnchor)
- [x] ✅ Strength formula: freq=1→1.0, freq=2→0.9
- [x] ✅ Signal type tier: freq≤2 = 'unique'
- [x] ✅ Metadata includes matched_anchor, anchor_frequency

#### Success Criteria

**Test Case #1**: Unique Anchor
```php
// Input: "شركة النورس الذهبي"
// Anchor extracted: "النورس الذهبي" (unique, freq=1)

$before = getSignals('شركة النورس الذهبي');
// Expected: SignalDTO(type='entity_anchor_unique', strength=1.0)

$after = getSignals('شركة النورس الذهبي');
assert($before == $after);
```

**Test Case #2**: Anchor Frequency Tier
```php
// Setup: "النورس" appears in 2 suppliers
$signals = getSignals('شركة النورس');
$signal = findSignalByType($signals, 'entity_anchor_unique');

// Expected:
assert($signal->raw_strength == 0.9);  // freq=2
assert($signal->metadata['anchor_frequency'] == 2);
```

#### Post-Merge Acceptance

- [ ] Same anchors extracted  
- [ ] Same frequency calculation
- [ ] Same tier classification (unique vs generic)
- [ ] Same strength values
- [ ] No performance regression (LIKE queries optimized?)

---

### SIGNAL S3: `entity_anchor_generic`

#### Pre-Merge State
**Strength**: `0.7` (freq 3-5) or `0.5` (freq >5)  
**Base Score**: `75`

#### Must Preserve

- [x] ✅ freq≥3 = 'generic' tier
- [x] ✅ Strength formula: freq 3-5→0.7, freq>5→0.5
- [x] ✅ Same as S2 logic, different tier

#### Success Criteria

**Test Case**: Generic Anchor
```php
// "التجارة" appears in 50 suppliers (generic)
$signals = getSignals('شركة التجارة');
$signal = findSignalByType($signals, 'entity_anchor_generic');

// Expected:
assert($signal->raw_strength == 0.5);  // freq>5
assert($signal->signal_type == 'entity_anchor_generic');
```

#### Post-Merge Acceptance

- [ ] Same tier threshold (freq>=3)
- [ ] Same strength formula
- [ ] No behavioral change

---

### SIGNAL S4-S6: Fuzzy Matching (strong/medium/weak)

#### Pre-Merge State
**Tiers**: S4 (≥0.85), S5 (0.70-0.84), S6 (0.55-0.69)  
**Base Scores**: 85, 70, 55  
**Strength Modifier**: `(similarity - 0.9) × 50`

#### Must Preserve

- [x] ✅ Levenshtein distance calculation
- [x] ✅ Similarity formula: `1 - (distance / max_length)`
- [x] ✅ Tier thresholds: 0.85, 0.70, 0.55
- [x] ✅ Strength modifier applied in confidence calc
- [x] ✅ All suppliers scanned (no pre-filtering)

#### Success Criteria

**Test Case #1**: Strong Fuzzy
```php
// Input: "شركة النورس" vs "شركة النورس للتجارة"
// Similarity ≈ 0.87

$signals = getSignals('شركة النورس');
$fuzzy = findSignalByType($signals, 'fuzzy_official_strong');

// Expected:
assert($fuzzy->raw_strength >= 0.85);
assert($fuzzy->signal_type == 'fuzzy_official_strong');
```

**Test Case #2**: Strength Modifier
```php
// Similarity = 0.92
$confidence = calculateConfidence([fuzzy_signal], 0, 0);
// Expected: base=85 + modifier=(0.92-0.9)×50 = 85+1 = 86

assert($confidence == 86);
```

#### Post-Merge Acceptance

- [ ] Same similarity calculation
- [ ] Same tier classification
- [ ] Same strength modifier
- [ ] No performance change (optimization optional but not required)

---

### SIGNAL S7-S8: Historical Selections (frequent/occasional)

#### Pre-Merge State
**Source**: `guarantees.raw_data` + `guarantee_decisions`  
**Query**: JSON LIKE (⚠️ **TO BE REPLACED**)  
**Tiers**: S7 (count≥5), S8 (count 1-4)  
**Strength**: Logarithmic scale

#### Must Preserve

- [x] ✅ Selection counting logic
- [x] ✅ Tier thresholds: count≥5 = frequent
- [x] ✅ Logarithmic strength: `0.3 + (0.5 × log(count+1) / log(20))`
- [x] ✅ Same supplier_ids returned for same input
- [x] ✅ Same counts

#### Success Criteria

**Test Case #1**: Historical Count
```php
// Setup: "شركة النورس" selected 7 times for supplier_id=5

// Pre-merge (JSON LIKE):
$before = getHistoricalSelections('شركة النورس');
// Expected: [{supplier_id: 5, count: 7}]

// Post-merge (indexed column):
$after = getHistoricalSelections('شركة النورس');

assert($before == $after);  // SAME result
```

**Test Case #2**: Strength Calculation
```php
// count=7
$strength = calculateHistoricalStrength(7);
// Expected: 0.3 + (0.5 × log(8) / log(20)) ≈ 0.65

assert(abs($strength - 0.65) < 0.01);
```

**⚠️ CRITICAL**: Query change (JSON LIKE → indexed column) must NOT change results

#### Post-Merge Acceptance

- [ ] Same supplier_ids returned
- [ ] Same counts for each supplier
- [ ] Same strength calculation
- [ ] Same tier classification
- [ ] **Query uses indexed column** (not JSON LIKE)

---

### SIGNAL S9: `learning_confirmation`

#### Pre-Merge State
**Source**: `learning_confirmations` WHERE `action='confirm'`  
**Query**: GROUP BY supplier_id  
**Strength**: `min(1.0, count / 10)`  
**Effect**: Confirmation boost (+5/+10/+15)

#### Must Preserve

- [x] ✅ Aggregation by supplier_id
- [x] ✅ Strength formula: count/10 (capped at 1.0)
- [x] ✅ Boost tiers: count≤2→+5, count≤5→+10, count>5→+15
- [x] ✅ Applied as additive boost (not multiplicative)

#### Success Criteria

**Test Case #1**: Confirmation Count
```php
// Setup: 3 confirmations for "شركة النورس" → supplier_id=5

$feedback = getUserFeedback('شركة النورس');
$confirm = findByAction($feedback, 'confirm', 5);

// Expected:
assert($confirm['count'] == 3);
assert($confirm['supplier_id'] == 5);
```

**Test Case #2**: Boost Calculation
```php
// 3 confirmations
$boost = calculateConfirmationBoost(3);
// Expected: +10 (tier: count≤5)

assert($boost == 10);
```

**⚠️ CRITICAL**: Query may change (raw_supplier_name → normalized_supplier_name) but counts MUST match

#### Post-Merge Acceptance

- [ ] Same counts aggregated
- [ ] Same boost calculation
- [ ] Same tiers
- [ ] **Query uses normalized column** (fixes fragmentation)

---

### SIGNAL S10: `learning_rejection`

#### Pre-Merge State
**Source**: `learning_confirmations` WHERE `action='reject'`  
**Strength**: `min(1.0, count / 5)`  
**Effect**: Rejection penalty (`× 0.75^count`)

#### Must Preserve

- [x] ✅ **Implicit rejection** still logged (save-and-next.php:290-298)
- [x] ✅ Same penalty formula: multiplicative 25% per rejection
- [x] ✅ Faster accumulation than confirmation (count/5 vs count/10)
- [x] ✅ Applied to base confidence before clamping

#### Success Criteria

**Test Case #1**: Implicit Rejection Logged
```php
// User chooses supplier B when top suggestion was A

// Pre-merge:
logImplicitRejection(topSuggestion=A, chosen=B);
// Expected: INSERT learning_confirmations (supplier_id=A, action='reject')

// Post-merge: SAME behavior
```

**Test Case #2**: Rejection Penalty
```php
// 2 rejections
$baseConfidence = 80;
$finalConfidence = applyRejectionPenalty(2, $baseConfidence);
// Expected: 80 × (0.75^2) = 80 × 0.5625 = 45

assert($finalConfidence == 45);
```

**⚠️ Phase Contract Decision**: Implicit rejection has **SAME** penalty as explicit (no differential)

#### Post-Merge Acceptance

- [ ] Implicit rejection still works
- [ ] Same penalty formula
- [ ] Same effect on confidence
- [ ] No behavioral change

---

## 🔒 Global Preservation Requirements

### Requirement #1: Primary Signal Selection
**Rule**: Confidence is based on ONE primary signal (highest BASE_SCORE)

**Test**:
```php
// Signals: alias_exact (100), fuzzy_strong (85), historical (60)
$primary = identifyPrimarySignal($signals);

// Expected: alias_exact (100 > 85 > 60)
assert($primary->signal_type == 'alias_exact');
```

**Post-merge**: SAME logic

---

### Requirement #2: Confirmation Boost is Additive
**Rule**: Fixed amounts (+5/+10/+15), not scaled

**Test**:
```php
// Base=85, confirmations=3 → boost=+10
$confidence = calculate($signals, confirmCount=3, rejectCount=0);
// Expected: 85 + 10 = 95

assert($confidence == 95);
```

**Post-merge**: SAME calculation

---

### Requirement #3: Rejection Penalty is Multiplicative
**Rule**: Each rejection × 0.75

**Test**:
```php
// Base=90, rejections=1
$confidence = calculate($signals, 0, rejectCount=1);
// Expected: 90 × 0.75 = 67.5 → 67

assert($confidence == 67);
```

**Post-merge**: SAME formula

---

### Requirement #4: Fuzzy Strength Modifier
**Rule**: Only fuzzy signals get strength adjustment

**Test**:
```php
// alias_exact: strength=1.0, no modifier
$mod1 = calculateStrengthModifier(alias_signal);
assert($mod1 == 0);

// fuzzy_strong: strength=0.92
$mod2 = calculateStrengthModifier(fuzzy_signal);
assert($mod2 == 1);  // (0.92-0.9)×50 = 1
```

**Post-merge**: SAME logic

---

### Requirement #5: Threshold Consistency
**Rule**: Same display thresholds

**Values**:
- Auto-accept: `90%`
- Review threshold: `70%`
- Display floor: `40%`

**Post-merge**: ✅ **NO CHANGE** to thresholds

---

## 🎯 Aggregate Acceptance Criteria

### Master Test: Same Input → Same Output

**Test Suite**:
```php
// 100 real supplier names from production
$testInputs = loadProductionSamples(100);

foreach ($testInputs as $input) {
    $before = getSuggestions_PreMerge($input);
    $after = getSuggestions_PostMerge($input);
    
    // Assert SAME suggestions
    assert(count($before) == count($after));
    
    // Assert SAME order
    for ($i=0; $i<count($before); $i++) {
        assert($before[$i]->supplier_id == $after[$i]->supplier_id);
        assert($before[$i]->confidence == $after[$i]->confidence);
    }
}
```

**Acceptance**: ✅ **100% match** on all test cases

---

## 📋 Checklist for Each Signal Type

Before marking signal as "preserved":

- [ ] ✅ Test case written
- [ ] ✅ Test case PASSED on pre-merge code
- [ ] ✅ Test case PASSED on post-merge code
- [ ] ✅ Results are IDENTICAL (not just similar)
- [ ] ✅ No threshold changes
- [ ] ✅ No formula changes
- [ ] ✅ Performance acceptable (no regression > 2x)

---

## ✅ Final Acceptance Gate

**ALL of the following MUST be true**:

- [ ] All 10 signal types preserved (S1-S10)
- [ ] Master test (100 samples) passes 100%
- [ ] No behavioral changes detected
- [ ] JSON LIKE queries replaced
- [ ] Performance improved (or at least not worse)
- [ ] Backward Compatibility Map written
- [ ] Phase Contract requirements met

**Only then**: Learning Merge is considered **successful**.

---

**Checklist Version**: 1.0  
**Status**: 🔒 **Binding**  
**Next**: Verification & Comparison Plan

*Any signal not passing its preservation test = merge FAILURE.*

---

## 📎 Quick Reference

```
S1:  alias_exact              → MUST: exact match, strength=1.0, conflict detection
S2:  entity_anchor_unique      → MUST: freq≤2, strength 0.9-1.0
S3:  entity_anchor_generic     → MUST: freq≥3, strength 0.5-0.7
S4:  fuzzy_official_strong     → MUST: sim≥0.85, modifier applied
S5:  fuzzy_official_medium     → MUST: sim 0.70-0.84
S6:  fuzzy_official_weak       → MUST: sim 0.55-0.69
S7:  historical_frequent       → MUST: count≥5, same counts after query change
S8:  historical_occasional     → MUST: count 1-4
S9:  learning_confirmation     → MUST: count/10, boost +5/+10/+15
S10: learning_rejection        → MUST: implicit still works, ×0.75 penalty
```

*End of Signal Preservation Checklist*
