# THE ALIAS LEARNING DEATH SPIRAL

**Classification:** CRITICAL RISK  
**Severity:** HIGH  
**Impact Radius:** Cross-guarantee contamination  
**Detection Difficulty:** VERY HIGH  
**Reversibility:** LOW (requires manual database intervention)

---

## INITIAL CONDITION

**Trigger Event:**
User is reviewing guarantee #42 with raw supplier name: `"ABC Corporation Ltd"`

**Actual Truth:**
- This supplier should map to `supplier_id = 10` ("ABC Corporation Limited" - official name)

**User Error:**
- User is tired, distracted, or misreads name
- Selects `supplier_id = 25` ("XYZ Industries") from dropdown
- Clicks "Save & Next"

**System State Before:**
- No alias exists for "abc corporation ltd"
- Supplier #10 and #25 are both legitimate, unrelated companies

---

## DECISION SEQUENCE

### Decision 1: Manual Save (`save-and-next.php` line 243-255)

```php
REPLACE INTO guarantee_decisions 
VALUES (guarantee_id=42, supplier_id=25, status='approved', decision_source='manual', ...)
```

**Outcome:** Wrong link created: Guarantee #42 → XYZ Industries

---

### Decision 2: Learning Activation (`save-and-next.php` line 282-288)

```php
$learningService->learnFromDecision($guaranteeId, [
    'supplier_id' => 25,              // WRONG supplier
    'raw_supplier_name' => 'ABC Corporation Ltd',  // CORRECT name
    'source' => 'manual',
    'confidence' => 100               // System trusts user 100%
]);
```

**Trigger:** `source === 'manual'` → Learning enabled

---

### Decision 3: Alias Creation (`LearningService.php` line 42-43)

```php
if ($source === 'manual') {
    $this->learningRepo->learnAlias($supplierId, $rawName);
}
```

**Execution Path:**
1. Normalize: `"ABC Corporation Ltd"` → `"abc corporation ltd"`
2. Check existence: `SELECT FROM supplier_alternative_names WHERE normalized_name = "abc corporation ltd"` → **NOT FOUND**
3. **CREATE ALIAS:**
```sql
INSERT INTO supplier_alternative_names 
VALUES (
    supplier_id = 25,                           -- XYZ Industries (WRONG)
    alternative_name = 'ABC Corporation Ltd',   
    normalized_name = 'abc corporation ltd',
    source = 'learning',
    usage_count = 1
)
```

**Critical Side Effect:** Permanent mapping created: `"abc corporation ltd"` → Supplier #25 (XYZ Industries)

---

### Decision 4: Usage Logging (`LearningService.php` line 47)

```php
$this->learningRepo->incrementUsage($supplierId, $rawName);
```

**Execution:** 
```sql
UPDATE supplier_alternative_names 
SET usage_count = usage_count + 1 
WHERE supplier_id = 25 AND normalized_name = 'abc corporation ltd'
```

**Bug Alert:** This UPDATE runs IMMEDIATELY after INSERT, trying to match the just-created row, but may execute before the INSERT commits (race condition). However, the row IS created regardless.

---

## LEARNING/SIDE EFFECTS

### Immediate Effects (Guarantee #42):
- ✅ Guarantee linked to wrong supplier
- ✅ Alias stored in database
- ✅ Decision logged with 100% confidence
- ✅ Timeline event recorded as "manual decision"

### Database State After:
```
supplier_alternative_names:
id  | supplier_id | alternative_name        | normalized_name        | source    | usage_count
----|-------------|-------------------------|------------------------|-----------|------------
147 | 25          | ABC Corporation Ltd     | abc corporation ltd    | learning  | 1
```

### System Knowledge Mutation:
- The system now "knows": `"ABC Corporation Ltd"` = XYZ Industries (supplier #25)
- This "knowledge" has:
  - **Authority:** `score = 1.0` (perfect match)
  - **Priority:** Checked FIRST in candidate generation (`SupplierCandidateService` line 214-232)
  - **Permanence:** No expiry, no review flag, no confidence decay
  - **Invisibility:** No UI to list/review learned aliases

---

## LONG-TERM CASCADE (THE DEATH SPIRAL)

### Future Guarantee #78 (1 week later)

**Import:** New Excel file contains supplier `"ABC Corporation Ltd"` (same name, different guarantee)

**Auto-Processing Flow:**

#### Step 1: Smart Processing (`SmartProcessingService.processNewGuarantees()`)
- Guarantee #78 has no decision yet
- Calls `LearningService.getSuggestions("ABC Corporation Ltd")`

#### Step 2: Suggestion Lookup (`SupplierLearningRepository.findSuggestions()` line 21-32)
```sql
SELECT s.id, s.official_name, 'alias' as source, 100 as score
FROM supplier_alternative_names a
JOIN suppliers s ON a.supplier_id = s.id
WHERE a.normalized_name = 'abc corporation ltd'
LIMIT 1
```

**Result:** 
```json
[
  {
    "id": 25,
    "official_name": "XYZ Industries",
    "source": "alias",
    "score": 100
  }
]
```

#### Step 3: Auto-Match Decision (`SmartProcessingService` line 90)
```php
if ($top['score'] >= 90) {  // 100 >= 90 ✓
    $supplierId = $top['id'];  // 25
    $supplierConfidence = $top['score'];  // 100
}
```

#### Step 4: Conflict Check (`ConflictDetector.detect()`)
- Conflicts: `[]` (only ONE candidate with score 100%)
- No ambiguity detected

#### Step 5: Auto-Approval (`SmartProcessingService` line 136-139)
```php
if ($supplierId && $bankId && empty($conflicts)) {
    $this->createAutoDecision($guaranteeId, $supplierId, $bankId);
    $this->logAutoMatchEvents(...);
    $stats['auto_matched']++;
}
```

**Outcome:** 
- ✅ Guarantee #78 **AUTOMATICALLY** linked to supplier #25 (XYZ Industries)
- ✅ User **NEVER SEES** this guarantee (auto-matched)
- ✅ Timeline logged as "System AI" decision
- ✅ No human review

### Reinforcement Loop (Guarantee #134, #201, #287...)

**For EVERY future guarantee with "ABC Corporation Ltd":**

1. Auto-match to supplier #25 (score 100%)
2. No human review (auto-approved)
3. Usage count incremented (line 47): `usage_count = 2, 3, 4, ...N`
4. More "evidence" that mapping is correct
5. **User perception:** "System is learning well!" (but it's learning the ERROR)

---

## PROPAGATION VECTORS

### Vector 1: Name Variations
Any similar name also matches:
- `"ABC Corporation Limited"`
- `"ABC Corp Ltd"`
- `"ABC Corporation"`
- `"A.B.C. Corporation Ltd."`

All normalize to similar strings → fuzzy match → high score → auto-link to #25

### Vector 2: Typos Multiply Error
User imports: `"ABC Corporration Ltd"` (typo: double 'r')
- Fuzzy match score to existing alias: ~92%
- Auto-accept threshold: 90%
- **NEW ALIAS CREATED** linking typo to #25
- Now TWO wrong aliases exist

### Vector 3: Cross-Contamination
- Financial reports pull data by supplier_id
- All "ABC Corporation Ltd" guarantees attributed to "XYZ Industries"
- Management decisions based on wrong aggregations
- Contract renewals sent to wrong company

---

## LONG-TERM IMPACT

### Quantitative Damage

**Assumption:** 
- "ABC Corporation Ltd" appears in 5 guarantees per month
- Error remains undetected for 6 months

**Result:**
- **30 guarantees** mislinked to wrong supplier
- **Cumulative alias usage_count:** 31 (original + 30 auto-matches)
- **User sees:** 1 out of 31 (3% visibility)
- **System confidence:** 100% (unchanged by volume)

### Qualitative Damage

#### 1. **Data Integrity Collapse**
- Supplier #10 ("ABC Corporation Limited") appears to have NO activity
- Supplier #25 ("XYZ Industries") appears to supply ABC's products
- Historical trends corrupted
- Cross-referencing with external systems fails

#### 2. **Cascading Business Errors**
- Purchase orders sent to wrong supplier
- Performance evaluations attribute ABC's work to XYZ
- Contract disputes ("We never supplied that item!")
- Audit failures (mismatch with invoices)

#### 3. **Irreversibility**
- No UI to view learned aliases
- No "undo learning" function
- Correction requires:
  - Direct database DELETE on alias
  - Manual correction of 30+ decisions
  - Re-import or manual update of raw_data
  - No tool to find all affected guarantees

#### 4. **Detection Difficulty**
- Auto-matched guarantees invisible to user
- No alert on "unusual" alias usage
- Discovery happens only when:
  - User manually searches for "ABC Corporation Limited" supplier
  - Sees zero guarantees (actually linked under "XYZ Industries")
  - Investigates why

#### 5. **Trust Erosion**
Once discovered:
- User questions ALL auto-matches
- Disables Smart Processing (if possible)
- OR audits thousands of decisions manually
- System ROI collapses

---

## WHY THIS IS THE MOST DANGEROUS LOOP

### 1. **Single Point of Failure**
One user error at ONE moment creates permanent corruption

### 2. **Exponential Propagation**
1 wrong decision → 30+ automatic wrong decisions

### 3. **Silent Operation**
User receives NO warning:
- No "Are you sure?" prompt
- No "This seems unusual" alert
- No review queue for learned aliases

### 4. **Absolute Authority**
System treats learned alias as **more authoritative** than fuzzy matches:
- Score = 1.0 (perfect)
- Checked FIRST (before official suppliers)
- Overrides even official_name matches if normalized differs

### 5. **No Natural Correction**
Unlike other errors (e.g., wrong expiry date gets caught during extension), this error:
- Never surfaces in normal workflow
- Doesn't cause exceptions
- Passes all validations
- Accumulates "evidence" of correctness (usage_count)

### 6. **Cross-Guarantee Contamination**
Other loops affect single guarantee; this affects:
- All past guarantees (via search/reports)
- All future guarantees (via auto-match)
- All analytics (via aggregations)

### 7. **Violation of Least Privilege**
A single manual save operation (low-privilege action) triggers:
- Knowledge base modification (high-privilege action)
- Automatic future decisions (system-level action)
- No separation of concerns

---

## THE PERFECT STORM CONDITIONS

This loop is maximally dangerous when:

1. **User fatigue:** End of shift, processing 100+ guarantees
2. **Name similarity:** "ABC Corp" vs "ABC Corporation" vs "XYZ ABC Division"
3. **No validation:** System accepts ANY selection as truth
4. **High auto-match threshold:** 90% means most aliases auto-match
5. **No undo:** User can't "unreview" or flag uncertain decisions

**Result:** System optimized to propagate errors, not correct them.

---

## LOOP VISUALIZATION

```
┌─────────────────────────────────────────────────────────────────┐
│  SINGLE USER ERROR (t=0)                                        │
│  "ABC Corporation Ltd" → supplier_id=25 (WRONG)                 │
└────────────────┬────────────────────────────────────────────────┘
                 │
                 ▼
         ┌───────────────┐
         │ Learn Alias   │
         │ score = 1.0   │
         │ source=manual │
         └───────┬───────┘
                 │
                 ▼
    ┌────────────────────────┐
    │ PERMANENT STORAGE      │
    │ supplier_alternative_  │
    │ names (no expiry)      │
    └────────┬───────────────┘
             │
             │ Future Imports (t+1 week, t+2 weeks...)
             ▼
    ┌─────────────────────┐
    │ Smart Processing    │
    │ Finds alias         │
    │ score=100%          │
    └────────┬────────────┘
             │
             ▼
    ┌─────────────────────┐
    │ Auto-Match          │
    │ (no human review)   │
    └────────┬────────────┘
             │
             ▼
    ┌─────────────────────┐
    │ Increment           │
    │ usage_count++       │
    │ (reinforcement)     │
    └────────┬────────────┘
             │
             └──────┐
                    │
     ┌──────────────▼──────────────┐
     │  ERROR AMPLIFICATION        │
     │  30 guarantees mislinked    │
     │  Analytics corrupted        │
     │  Business decisions wrong   │
     └────────────┬────────────────┘
                  │
                  ▼
          ┌───────────────┐
          │  DETECTION    │
          │  (months later│
          │   if at all)  │
          └───────┬───────┘
                  │
                  ▼
          ┌───────────────┐
          │ Manual Fix    │
          │ Required:     │
          │ - DB edit     │
          │ - 30+ records │
          │ - Reports     │
          └───────────────┘
```

---

## LOOP SUMMARY

| Phase | Description |
|-------|-------------|
| **Initial** | User makes 1 wrong selection |
| **Learning** | System creates permanent alias (score=1.0) |
| **Propagation** | All future similar names auto-match to wrong supplier |
| **Reinforcement** | usage_count increases, appears more "correct" |
| **Detection** | Near impossible via normal workflow |
| **Correction** | Requires database surgery + manual audits |
| **Blast Radius** | 30+ guarantees per 6 months, all business processes affected |

---

## CRITICAL OBSERVATION

**This is not a bug. This is the system working AS DESIGNED.**

The danger is not in broken code—it's in the **assumption that user manual selections are always correct**, combined with **permanent learning** and **no review mechanism**.

The system architecture creates a positive feedback loop where errors become more entrenched over time, making them harder to detect and more expensive to correct.

---

**Document Status:** AS-IS Analysis (No Fixes Proposed)  
**Date:** 2025-12-26  
**Source:** Production Forensic Analysis
