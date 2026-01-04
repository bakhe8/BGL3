# DUPLICATION & CONTRADICTION REPORT

## 🔴 CRITICAL: Code Duplication

### DUPLICATION #1: Learning Repositories

**Evidence**: TWO repositories handle learning with overlapping responsibilities

#### LearningRepository.php (88 lines)
**Location**: `app/Repositories/LearningRepository.php`  
**Tables**: `learning_confirmations`, `guarantees`  
**Methods**:
- `getUserFeedback($rawName)` - Groups confirmations/rejections by supplier_id
- `getRejections($rawName)` - Gets rejected supplier IDs
- `getHistoricalSelections($rawName)` - **JSON LIKE query** on guarantees.raw_data
- `logDecision($data)` - Logs to `learning_confirmations`

**Query Pattern** (lines 48-63):
```php
$jsonFragment = '"supplier":"' . str_replace('"', '\"', $rawName) . '"';

$stmt = $this->db->prepare("
    SELECT d.supplier_id, COUNT(*) as frequency
    FROM guarantees g
    JOIN guarantee_decisions d ON g.id = d.guarantee_id
    WHERE g.raw_data LIKE ? 
    AND d.supplier_id IS NOT NULL
    GROUP BY d.supplier_id
");

$stmt->execute(['%' . $jsonFragment . '%']);
```

#### SupplierLearningRepository.php (216 lines)
**Location**: `app/Repositories/SupplierLearningRepository.php`  
**Tables**: `supplier_alternative_names`, `suppliers`, `supplier_decisions_log`  
**Methods**:
- `findSuggestions($normalized, $limit)` - Gets supplier suggestions
- `incrementUsage($supplierId, $rawName)` - Positive learning
- `decrementUsage($supplierId, $rawName)` - Negative learning (capped at -5)
- `learnAlias($supplierId, $rawName)` - Creates new alternative name
- `logDecision($data)` - Logs to `supplier_decisions_log`
- `findConflictingAliases($supplierId, $normalized)` - Finds conflicting names

**Logging Table** (lines 144-161):
```php
$stmt = $this->db->prepare("
    INSERT INTO supplier_decisions_log 
    (guarantee_id, raw_input, normalized_input, chosen_supplier_id, 
     chosen_supplier_name, decision_source, confidence_score, 
     was_top_suggestion, decided_at)
    VALUES (:gid, :raw, :norm, :sid, :sname, :src, :score, :top, :at)
");
```

**CONTRADICTION**:
- **TWO** different tables for logging decisions:
  - `learning_confirmations` (via LearningRepository)
  - `supplier_decisions_log` (via SupplierLearningRepository)
- **UNCLEAR** which is authoritative
- **BOTH** log supplier decisions with different schemas
- **NO SYNCHRONIZATION** between them

---

### DUPLICATION #2: JSON Query Pattern (Fragile)

**Repeated Pattern**: Searching JSON field with LIKE operator

**Occurrence 1**: LearningRepository.php:48-63
```php
WHERE g.raw_data LIKE '%"supplier":"<name>"%'
```

**Occurrence 2**: GuaranteeDecisionRepository.php:208-219
```php
$pattern = '%"supplier":"' . $normalizedInput . '"%';
$stmt->execute(['pattern' => $pattern]);
```

**Shared Risk**:
- ⚠️ **BRITTLE**: Breaks if JSON format changes
- ⚠️ **INJECTION**: String concatenation in JSON pattern (escaped, but risky)
- ⚠️ **PERFORMANCE**: No index on JSON field, full table scan
- ⚠️ **FALSE POSITIVES**: Could match partial strings in other fields

**Better Alternative**:
- Use JSON_EXTRACT (SQLite 3.38+) or dedicated column
- Current approach documented in `docs/implementation/query_pattern_audit.md` (line 206)

---

### DUPLICATION #3: Status Calculation Logic

**Evidence**: Status calculated in MULTIPLE places with potentially different logic

**Location 1**: index.php:179-180
```php
$statusToSave = \App\Services\StatusEvaluator::evaluate($supplierId, $bankId);
```

**Location 2**: save-and-next.php:180
```php
$statusToSave = \App\Services\StatusEvaluator::evaluate($supplierId, $bankId);
```

**Location 3**: index.php:243-324 (Status Reasons)
```php
$statusReasons = \App\Services\StatusEvaluator::getReasons(
    $mockRecord['supplier_id'] ?? null,
    $mockRecord['bank_id'] ?? null,
    []
);
```

**Location 4**: index.php:266-273 (Direct mapping)
```php
// Map decision status to display status
// Decision status: 'ready' or 'rejected'
// Display status: 'ready' (has decision) or 'pending' (no decision)
$mockRecord['status'] = 'ready'; // Any decision = ready for action
```

**CONTRADICTION**:
- Status both **STORED** in DB (`guarantee_decisions.status`)
- Status also **CALCULATED** on-the-fly (StatusEvaluator.evaluate)
- **WHICH IS SOURCE OF TRUTH?**
- Line 266 comment says "Any decision = ready" but line 180 calculates from supplier_id + bank_id

**Evidences of Confusion**:
- index.php:274: `$mockRecord['is_locked'] = (bool)$decision->isLocked;`
- `is_locked` determines if "released", but status field also exists
- Multiple state indicators: `status`, `is_locked`, `active_action`

---

### DUPLICATION #4: Snapshot Creation Logic

**Location 1**: TimelineRecorder::createSnapshot (lines 21-72)
```php
public static function createSnapshot($guaranteeId, $decisionData = null) {
    // Fetches from database if $decisionData not provided
    // Returns array with supplier_name, bank_name, etc.
}
```

**Location 2**: save-and-next.php:176-177
```php
$oldSnapshot = \App\Services\TimelineRecorder::createSnapshot($guaranteeId);
```

**Location 3**: extend.php: 53, reduce.php:57, release.php:53
```php
$oldSnapshot = \App\Services\TimelineRecorder::createSnapshot($guaranteeId);
```

**Not Duplication, But...**:
- ✅ Centralized in TimelineRecorder (good)
- ⚠️ Relies on global `$db` variable
- ⚠️ Mixing of passed data vs database fetch (line 24)

---

### DUPLICATION #5: Bank Matching Logic

**Evidence**: Bank matching duplicated in SmartProcessingService

**Location 1**: SmartProcessingService.php:120-180 (approx)
```php
// Normalize bank name
$normalized = BankNormalizer::normalize($rawBankName);

// Try exact match
$stmt = $db->prepare('SELECT id FROM banks WHERE arabic_name = ?');
$stmt->execute([$rawBankName]);
$bankId = $stmt->fetchColumn();

// Fallback: alternative names
if (!$bankId) {
    $stmt = $db->prepare("
        SELECT b.id FROM banks b 
        JOIN bank_alternative_names a ON b.id = a.bank_id 
        WHERE a.normalized_name = ? LIMIT 1
    ");
    $stmt->execute([$normalized]);
    $bankId = $stmt->fetchColumn();
}
```

**Location 2**: save-and-next.php:127-141 (fallback bank resolution)
```php
// Try exact match on official name
$stmt = $db->prepare('SELECT id FROM banks WHERE arabic_name = ?');
$stmt->execute([$rawBankName]);
$bankId = $stmt->fetchColumn();

// If not found, try normalized match
if (!$bankId) {
    require_once __DIR__ . '/../app/Support/BankNormalizer.php';
    $norm = \App\Support\BankNormalizer::normalize($rawBankName);
    $stmt = $db->prepare("
        SELECT b.id FROM banks b 
        JOIN bank_alternative_names a ON b.id = a.bank_id 
        WHERE a.normalized_name = ? LIMIT 1
    ");
    $stmt->execute([$norm]);
    $bankId = $stmt->fetchColumn();
}
```

**EXACT DUPLICATION**: Same logic, same SQL queries, different files

**Should be**: Centralized in BankRepository or dedicated service

---

###DUPLICATION #6: Change Detection Logic

**Evidence**: Comparing old vs new values to build change descriptions

**Location 1**: save-and-next.php:93-168
```php
// Determine old state
$lastDecStmt = $db->prepare('SELECT supplier_id FROM guarantee_decisions WHERE guarantee_id = ?');
// ... resolve old supplier name
// ... resolve old bank name

// Check Supplier Change
if (trim($oldSupplier) !== trim($newSupplier)) {
    $changes[] = "تغيير المورد من [$oldSupplier] إلى [$newSupplier]";
}
```

**Location 2**: TimelineRecorder::recordDecisionEvent (lines 244-283)
```php
// Check Supplier
if (isset($newData['supplier_id'])) {
    $old = $oldSnapshot['supplier_id'] ?? null;
    $new = $newData['supplier_id'];
    if ($old != $new) {
        $changes[] = [
            'field' => 'supplier_id',
            'old_value' => ['id' => $old, 'name' => $oldSnapshot['supplier_name'] ?? ''],
            'new_value' => ['id' => $new, 'name' => $newData['supplier_name'] ?? ''],
            'trigger' => $isAuto ? 'ai_match' : 'manual'
        ];
    }
}
```

**Difference**:
- save-and-next builds **string descriptions** for display
- TimelineRecorder builds **structured arrays** for storage
- Same concept, different formats

**Could be Unified**: Single change detection service returning both formats

---

## ⚔️ LOGICAL CONTRADICTIONS

### CONTRADICTION #1: Status vs. is_locked vs. active_action

**Three State Indicators**:

| Field | Table | Purpose | Values |
|-------|-------|---------|--------|
| status | guarantee_decisions | Current processing state | 'pending', 'ready', 'released' |
| is_locked | guarantee_decisions | Immutability flag | 0, 1 |
| active_action | guarantee_decisions | UI preview pointer | null, 'extension', 'reduction', 'release' |

**Questions**:
1. If `status='released'`, is `is_locked` always 1? **YES** (release.php:68)
2. If `is_locked=1`, is `status` always 'released'? **UNCLEAR**
3. Can `active_action='release'` with `is_locked=0`? **YES** (set before lock)
4. What's the difference between `status='released'` and `is_locked=1`? **REDUNDANT?**

**Evidence of Confusion**:
- release.php:68: Sets `is_locked=1` with `locked_reason='released'`
- But `status` field also exists in same table
- index.php:273: Checks `is_locked` for released state
- index.php:266: Comments say status based on decision existence

**ROOT CAUSE**: Evolution of schema without cleanup. Likely:
- Phase 1: status field
- Phase 2: is_locked added for stricter control
- Phase 3: active_action added for UI
- Now all three coexist with unclear boundaries

---

### CONTRADICTION #2: Bank Auto-Match Finality

**Statement 1** (save-and-next.php:22):
```php
// Bank is no longer sent - it's set once during import/matching
```

**Implication**: Bank never changes after auto-match

**But**:
- save-and-next.php:156: Comment says "Check Bank Change"
- save-and-next.php:166-168: Logs bank change message
- Code assumes bank COULD change

**Evidence**:
- Line 112: "Resolve Old Bank Name" logic exists
- Line 114-142: Fallback bank resolution if not in decision
- Why have this if "never changes"?

**Actual Behavior**:
- Bank matched during SmartProcessingService
- Bank_id stored in guarantee_decisions
- Bank name stored in raw_data
- save-and-next does NOT send/update bank
- But code still checks for changes (dead code?)

**CONCLUSION**: Comments claim finality, but code suggests otherwise. Likely legacy code not removed.

---

### CONTRADICTION #3: Supplier Learning - Confirm vs. Reject

**Document** (LEARNING_ANALYSIS.md:96-118):
```markdown
## الكود المطلوب إضافته

// بعد تسجيل confirm للمورد المختار
if ($suggestions && count($suggestions) > 0) {
    $topSuggestion = $suggestions[0];
    
    // إذا المورد المختار مختلف عن الاقتراح الأول
    if ($topSuggestion['id'] != $supplierId) {
        // سجل رفض للاقتراح الأول
        $learningRepo->logDecision([
            'guarantee_id' => $guaranteeId,
            'raw_supplier_name' => $currentGuarantee->rawData['supplier'],
            'supplier_id' => $topSuggestion['id'],
            'action' => 'reject',
            ...
        ]);
    }
}
```

**Actual Code** (save-and-next.php:283-303):
```php
// ✅ Step 2: Log REJECTION for ignored top suggestion (implicit learning)
// Get current suggestions to identify what user ignored
$authority = \App\Services\Learning\AuthorityFactory::create();
$suggestions = $authority->getSuggestions($rawSupplierName);

if (!empty($suggestions)) {
    $topSuggestion = $suggestions[0];
    
    // If user chose DIFFERENT supplier than top suggestion → implicit rejection
    if ($topSuggestion->supplier_id != $supplierId) {
        $learningRepo->logDecision([
            'guarantee_id' => $guaranteeId,
            'raw_supplier_name' => $rawSupplierName,
            'supplier_id' => $topSuggestion->supplier_id,
            'action' => 'reject',
            'confidence' => $topSuggestion->confidence,
            'matched_anchor' => $topSuggestion->official_name,
            'decision_time_seconds' => 0
        ]);
    }
}
```

**CONTRADICTION**:
- Document says code is "مطلوب إضافته" (needs to be added)
- Code ALREADY EXISTS and is ACTIVE (lines 283-303)
- Comment at line 283 says "✅ Step 2" (already implemented)
- Document dated: Unclear (no timestamp)
- Code: Clearly present

**CONCLUSION**: Documentation is outdated. Feature already implemented but docs not updated.

---

### CONTRADICTION #4: Decision Source of Truth

**Question**: Where is decision data stored?

**Answer 1**: `guarantee_decisions` table (primary)
**Answer 2**: `raw_data` field in `guarantees` table (historical)

**Evidence of Confusion**:
- index.php:236: `$mockRecord['supplier_name'] = $raw['supplier'] ?? '';`
- index.php:280-290: Overrides with supplier from decision if exists
- Line 108: Fallback to raw if decision missing

**Flow**:
1. Import: supplier_name in raw_data
2. Auto-match: supplier_id in decision, raw_data unchanged
3. Display: Prefers decision.supplier_id resolved name, falls back to raw_data

**ACTUAL SOURCE OF TRUTH**: Depends on context:
- For display: `decision` wins
- For history: `raw_data` preserved
- For matching: `raw_data` is input

**NOT A BUG**, but could be clearer with explicit "current" vs "original" naming.

---

### CONTRADICTION #5: Event Subtype Usage

**TimelineRecorder.php**:
- Stores `event_subtype` in guarantee_history table
- Used for filtering and display labels

**Event Recording Calls**:
- `recordExtensionEvent()`: subtype='extension' (line 176)
- `recordReductionEvent()`: subtype='reduction' (line 209)
- `recordReleaseEvent()`: subtype='release' (line 237)
- `recordDecisionEvent()`: subtype = 'ai_match' or 'manual_edit' (line 282)
- `recordImportEvent()`: subtype = $source (excel/manual/smart_paste) (line 432)

**Display Logic** (TimelineRecorder.php:526-610):
1. Checks bank-only change (lines 536-550) → "تطابق تلقائي" (overrides subtype)
2. Uses event_subtype mapping (lines 553-567)
3. Falls back to event_type (lines 585-609)

**CONTRADICTION**:
- Line 548: "If ONLY bank changed → always automatic (overrides subtype)"
- But subtype already set correctly in recording
- Why override?

**Possible Reason**: Bank matching added later, event_subtype not always present in old events.

**CONCLUSION**: Defensive programming for backward compatibility, but creates confusion about subtype authority.

---

## 🔄 FRONTEND vs BACKEND DUPLICATION

### DUPLICATION #7: Validation Logic

**Backend Validation** (save-and-next.php:81-90):
```php
if (!$guaranteeId || !$supplierId) {
    $missing = [];
    if (!$guaranteeId) $missing[] = 'Guarantee ID';
    if (!$supplierId) $missing[] = "Supplier";
    
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing fields: ' . implode(', ', $missing)]);
    exit;
}
```

**Frontend Validation** (assumed in index.php JavaScript):
- Likely validates supplier selection before submission
- Not visible in examined code (inline JS too large to fully analyze)

**RISK**: If frontend validation differs from backend, inconsistent behavior.

**RECOMMENDATION**: Backend validation is authoritative (correct), but frontend should match for UX.

---

### DUPLICATION #8: Status Display Logic

**Backend** (index.php:319-324):
```php
$statusReasons = \App\Services\StatusEvaluator::getReasons(
    $mockRecord['supplier_id'] ?? null,
    $mockRecord['bank_id'] ?? null,
    []
);
$mockRecord['status_reasons'] = $statusReasons;
```

**Frontend**: Receives `status_reasons` and displays badges/tooltips

**Question**: Does frontend ever recalculate status, or fully trusts backend?

**Answer**: Not visible in examined code (JS embedded in index.php:1000+lines)

**ASSUMPTION**: Likely frontend trusts backend (good if true).

---

## 🎭 OVERLAPPING RESPONSIBILITIES

### OVERLAP #1: Learning Repositories

**LearningRepository**:
- Logs to `learning_confirmations`
- Queries historical guarantees
- Used by: save-and-next.php

**SupplierLearningRepository**:
- Logs to `supplier_decisions_log`
- Manages `supplier_alternative_names`
- Used by: SmartProcessingService (assumed)

**Overlap**:
- Both deal with supplier learning
- Both log decisions
- Different tables, same concept

**Separation Rationale** (guessed):
- LearningRepository: User-driven learning (manual decisions)
- SupplierLearningRepository: System-driven learning (auto-match feedback + alias management)

**PROBLEM**: Names don't reflect this distinction. Easy to confuse.

---

### OVERLAP #2: TimelineRecorder vs GuaranteeHistoryRepository

**TimelineRecorder** (631 lines):
- Creates snapshots
- Records events
- Formats labels and icons
- Generates letter HTML

**GuaranteeHistoryRepository** (assumed exists, not examined):
- Data access for `guarantee_history` table?

**Question**: Does GuaranteeHistoryRepository exist, or does TimelineRecorder do everything?

**From Examined Code**: TimelineRecorder writes directly to DB (line 318-334):
```php
$stmt = $db->prepare("
    INSERT INTO guarantee_history 
    (guarantee_id, event_type, event_subtype, snapshot_data, event_details, letter_snapshot, created_at, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");
```

**No Repository** seen for guarantee_history. TimelineRecorder mixes service and data access.

**CONCLUSION**: TimelineRecorder violates separation of concerns (acts as both service and repository).

---

## 📊 DUPLICATION SUMMARY TABLE

| Type | Locations | Risk Level | Impact |
|------|-----------|------------|--------|
| Learning Repositories | Learning Repo, SupplierLearning Repo | HIGH | Confusion, dual logging, potential desynch |
| JSON Query Pattern | LearningRepo:51, DecisionRepo:218 | HIGH | Brittle, no reuse, performance |
| Bank Matching Logic | SmartProcessing:120, save-and-next:127 | MEDIUM | Maintenance burden, inconsistency risk |
| Status Calculation | index.php:180, save-and-next:180, multiple | MEDIUM | Confusion about source of truth |
| Change Detection | save-and-next:93, TimelineRecorder:244 | LOW | Different formats, but intentional |
| Validation | Frontend (unknown), Backend | LOW | Standard pattern, but should match |

## 🎯 CONTRADICTION SUMMARY TABLE

| Contradiction | Evidence | Severity |
|---------------|----------|----------|
| Status vs is_locked vs active_action | 3 overlapping state fields | HIGH |
| Bank finality claim vs code | Comments say "never changes", code checks for changes | MEDIUM |
| Learning docs vs implementation | Docs say "to be added", code exists | LOW (docs issue) |
| Event subtype override | Subtype set correctly but overridden in display logic | LOW |
| Decision source of truth | raw_data vs guarantee_decisions | MEDIUM |

---

## 🔍 HIDDEN ASSUMPTIONS EXPOSED

1. **Database Always Available**: No retry logic, crashes if DB fails after initial connect
2. **JSON Format Never Changes**: LIKE queries assume specific JSON formatting
3. **Supplier IDs Stable**: ID/name mismatch safeguard (save-and-next:34-46) suggests IDs can change?
4. **Global $db Exists**: TimelineRecorder uses global without null check
5. **Snapshots Are Atomic**: No transaction wrapping snapshot→update→record flow
6. **Frontend Sends Correct Data**: Backend validation exists but may lag frontend changes
7. **Single User Context**: No concurrency control, last-write-wins

---

##🧠 ARCHITECTURAL INSIGHTS

### Pattern Found: "Grow-and-Patch" Evolution

**Evidence**:
- status, then is_locked, then active_action (3 state fields)
- LearningRepository, then SupplierLearningRepository (2 learning systems)
- learning_confirmations, then supplier_decisions_log (2 logging tables)
- event_type, then event_subtype (refinement without removing old)

**Characteristic**: New features added alongside old ones without removing predecessors.

**Result**: Increasing complexity, unclear boundaries, potential contradictions.

---

### Pattern Found: "Frontend-Last" Architecture

**Evidence**:
- Most logic in backend (index.php, API endpoints)
- JavaScript controllers are thin (6 files, ~200 lines each)
- Server-driven partials (HTML returned from API)
- Limited client-side state

**Benefit**: Easier to reason about state (server = truth)
**Drawback**: More page weight, less responsive UI

---

## ✅ WHAT'S WORKING WELL

Despite duplications and contradictions, some patterns are clean:

1. **Repository Pattern**: Most repositories are clean, focused (except TimelineRecorder)
2. **Value Objects**: Models (Guarantee, GuaranteeDecision) are pure data
3. **Centralized Normalization**: Normalizer, BankNormalizer (single source)
4. **Snapshot Pattern**:
 Strong discipline in extend/reduce/release (snapshot→update→record)
5. **DTO Usage**: UnifiedLearningAuthority uses SignalDTO, SuggestionDTO (clean interfaces)

---

## 🚨 WHAT NEEDS ATTENTION

1. **Consolidate Learning Systems**: Merge or clearly separate LearningRepository vs SupplierLearningRepository
2. **Remove Dead Code**: Bank change detection in save-and-next (if truly unused)
3. **Clarify State Fields**: Document status vs is_locked vs active_action lifecycle
4. **Extract Utilities**: Bank matching logic → BankMatchingService
5. **Fix TimelineRecorder**: Split into TimelineService + GuaranteeHistoryRepository
6. **Update Docs**: LEARNING_ANALYSIS.md is outdated
7. **Index JSON Fields**: If continuing LIKE queries (or migrate to JSON_EXTRACT)
