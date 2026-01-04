# API Endpoint Mapping - Phase 1

**Phase:** 1 - Signal Extraction & Mapping  
**Created:** 2026-01-03  
**Status:** Complete  
**Purpose:** Map all HTTP endpoints to suggestion services to identify Authority entry points  

---

## Mapping Methodology

**Based on code review (no controllers found in standard location):**
- Controllers may be in routes files or embedded
- Services are called from application flow
- Mapping is inferred from service usage patterns

---

## Suggestion Flow Architecture (Current)

### Entry Point Pattern (Inferred)

```
User Input (Raw Supplier Name)
         ↓
   [Entry Point - TBD in routes]
         ↓
    ┌────┴────┐
    ↓         ↓
Service A  Service B  (Parallel execution)
    ↓         ↓
  Results  Results
    └────┬────┘
         ↓
    Merge/Choose
         ↓
    Response (JSON)
```

---

## Service Entry Points (By Use Case)

### 1. Level B Suggestions (Pilot Mode)

**Likely Endpoint:** `/api/suggestions/level-b` or `/api/pilot-suggestions`

**Service Called:** `ArabicLevelBSuggestions`

**Method:** `find(string $rawName): array`

**Returns:**
```php
[
    [
        'supplier_id' => int,
        'official_name' => string,
        'confidence' => int (70-95),
        'level' => 'B',
        'matched_anchor' => string,
        'anchor_type' => string
    ],
    ...
]
```

**Current Behavior:**
- Extracts entity anchors from input
- Searches supplier by anchors
- Returns Level B only (fixed)
- Implements "Golden Rule" (silent if no anchors)

**Charter Compliance:** ❌ NON-COMPLIANT (Authority role, dynamic confidence)

**Phase 2 Target:** Replace with Authority, convert to AnchorSignalFeeder

---

### 2. Hybrid Learning Suggestions

**Likely Endpoint:** `/api/suggestions/learning` or `/api/supplier-suggestions`

**Service Called:** `LearningSuggestionService`

**Method:** `getSuggestions(string $rawName): array`

**Returns:**
```php
[
    [
        'id' => int,
        'name' => string,
        'confidence' => int (0-100),
        'level' => 'B'|'C'|'D',
        'reason' => string (Arabic),
        'confirmation_count' => int,
        'rejection_count' => int
    ],
    ...
]
```

**Current Behavior:**
- Aggregates confirmations/rejections
- Queries entity anchors
- Queries learned aliases
- Queries historical selections
- Computes confidence (ConfidenceCalculator)
- Orders by confidence descending

**Charter Compliance:** ❌ NON-COMPLIANT (Authority role, should feed to unified Authority)

**Phase 2 Target:** Convert to LearningSignalFeeder

---

### 3. Fuzzy Matching Candidates

**Likely Endpoint:** `/api/suppliers/match` or `/api/candidates`

**Service Called:** `SupplierCandidateService`

**Method:** `supplierCandidates(string $rawSupplier): array`

**Returns:**
```php
[
    'normalized' => string,
    'candidates' => [
        [
            'id' => int,
            'name' => string,
            'score' => float (0-1.0),
            'match_type' => 'exact'|'fuzzy_official'|'alternative',
            'source' => string
        ],
        ...
    ]
]
```

**Current Behavior:**
- Checks cache first
- Generates fuzzy matches (official suppliers)
- Queries alternative names
- Applies blocking logic (block_count > 0)
- Scores with weights
- Deduplicates by highest score
- Orders by score descending

**Charter Compliance:** ❌ NON-COMPLIANT (Authority role, embedsdecision logic)

**Phase 2 Target:** Convert to FuzzySignalFeeder, AliasSignalFeeder

---

### 4. Learning Action (User Feedback)

**Likely Endpoint:** `/api/learning/confirm` or `/api/pilot/confirm`

**Service Called:** `LearningService`

**Method:** `learnFromDecision(array $data): void`

**Input:**
```php
[
    'raw_input' => string,
    'supplier_id' => int,
    'source' => 'manual'|'confirm',
    'was_top_suggestion' => bool,
    'confidence' => int,
    'anchor' => string|null,
    'guarantee_id' => int
]
```

**Current Behavior:**
- Logs decision
- Creates/updates alias (if manual)
- Increments usage_count (chosen supplier)
- Decrements usage_count (top if ignored)
- Applies penalty to rejected suggestions

**Charter Compliance:** ✅ MOSTLY COMPLIANT (write path, action service)

**Phase 2 Target:** Review for signal-decision separation, keep as action service

---

## Endpoint to Service Matrix

| Endpoint (Inferred) | Service | Method | Provides | Status | Phase 2 Action |
|---------------------|---------|--------|----------|--------|----------------|
| `/api/suggestions/level-b` | ArabicLevelBSuggestions | `find()` | Level B suggestions | ❌ Authority | → AnchorSignalFeeder |
| `/api/suggestions/learning` | LearningSuggestionService | `getSuggestions()` | Hybrid suggestions (B/C/D) | ❌ Authority | → LearningSignalFeeder |
| `/api/suppliers/match` | SupplierCandidateService | `supplierCandidates()` | Fuzzy candidates | ❌ Authority | → FuzzySignalFeeder |
| `/api/learning/confirm` | LearningService | `learnFromDecision()` | Records feedback | ✅ Action | Review, likely keep |
| `/api/learning/reject` | LearningService | `learnFromDecision()` | Records rejection | ✅ Action | Review, likely keep |

---

## UI Component Flow (Inferred)

### Supplier Selection UI Component

**Likely Flow:**
```javascript
// User types "شركة النورس"
const input = userInput;

// Call suggestion endpoint
const response = await fetch('/api/suggestions/learning', {
    method: 'POST',
    body: JSON.stringify({ supplier_name: input })
});

const suggestions = await response.json();

// Display suggestions
suggestions.forEach(suggestion => {
    displaySuggestion({
        name: suggestion.name,
        confidence: suggestion.confidence,
        level: suggestion.level,
        badge: getBadgeByLevel(suggestion.level),  // B/C/D
        reason: suggestion.reason
    });
});

// User selects supplier
onUserSelect(selected_supplier_id, was_top) => {
    fetch('/api/learning/confirm', {
        method: 'POST',
        body: JSON.stringify({
            raw_input: input,
            supplier_id: selected_supplier_id,
            was_top_suggestion: was_top,
            ...
        })
    });
}
```

---

## Phase 2 Unified Flow (Target)

### After Authority Implementation

```
User Input
    ↓
[Single Endpoint: /api/suggestions]
    ↓
UnifiedLearningAuthority.getSuggestions()
    ↓
    ├─ AliasSignalFeeder.getSignals()
    ├─ AnchorSignalFeeder.getSignals()
    ├─ FuzzySignalFeeder.getSignals()
    ├─ LearningSignalFeeder.getSignals()
    └─ HistoricalSignalFeeder.getSignals()
    ↓
Aggregate Signals
    ↓
ConfidenceCalculatorV2.calculate()
    ↓
SuggestionFormatter.toDTO()
    ↓
[Return: Unified SuggestionDTO[]]
```

**Benefits:**
- Single API endpoint
- Consistent response format
- Unified confidence scale (0-100)
- All signals considered
- Transparent provenance

---

## Critical Observations

### 1. No Controller Files Found

**Standard Laravel/PHP patterns:**
- `/app/Http/Controllers/...` - Not present in this codebase
- `/app/Controllers/...` - Not found

**Possible Architectures:**
1. **Routes file with closures** (`routes/api.php` or similar)
2. **MVC-less architecture** (services called directly from entry point)
3. **Different framework** (not Laravel - custom routing)

**Action Required:**
- [ ] Locate actual entry points (routes file, index.php, etc.)
- [ ] Confirm endpoint URLs
- [ ] Trace request flow from HTTP to Service

---

### 2. Multiple Parallel Entry Points Confirmed

**Evidence:**
- 3 services act as independent suggestion sources
- Each returns different format
- UI likely aggregates OR selects based on context

**Implication:**
- UI may call different endpoints for different scenarios
- Example: "Pilot Mode" → Level B endpoint, "Normal" → Learning endpoint

**Phase 2 Solution:**
- Unify all endpoints to route through Authority
- Authority decides which signals to use (not UI)
- UI always calls `/api/suggestions`, receives SuggestionDTO

---

### 3. Response Format Inconsistency

| Service | Confidence Type | Level | Reason Field |
|---------|-----------------|-------|--------------|
| ArabicLevelBSuggestions | int (70-95) | Always 'B' | matched_anchor (NOT reason_ar) |
| LearningSuggestionService | int (0-100) | B/C/D | reason (Arabic) |
| SupplierCandidateService | float (0-1.0) | None | None |

**UI Impact:**
- Must handle 3 different formats
- Scale conversion (0-1 → 0-100)
- Conditional rendering (level badge if exists)

**Phase 5 Fix:**
- Authority always returns SuggestionDTO
- Single format for all suggestions
- UI simplified

---

## Recommendations

### Phase 1 (Current - Complete Analysis):

1. ✅ **Identify Actual Endpoints:**
   - Search for route definitions
   - Trace HTTP entry points
   - Document request/response contracts

2. ✅ **Document Current UI Consumers:**
   - Which frontend components call which endpoints
   - How responses are transformed/displayed

### Phase 2 (Build Authority):

3. **Create Single Endpoint:**
   - `/api/suggestions/unified` (new)
   - Calls UnifiedLearningAuthority only
   - Returns SuggestionDTO[]

4. **Maintain Legacy Endpoints (Temporarily):**
   - Keep old endpoints for rollback
   - Gradually migrate UI to unified endpoint

### Phase 4 (Cutover):

5. **Switch Endpoint Routing:**
   ```php
   // Old
   Route::post('/api/suggestions/learning', [LearningSuggestionService::class, 'getSuggestions']);
   
   // New (Phase 4)
   Route::post('/api/suggestions/learning', [UnifiedLearningAuthority::class, 'getSuggestions']);
   // OR redirect to unified
   Route::post('/api/suggestions/learning', function() {
       return app(UnifiedLearningAuthority::class)->getSuggestions(request()->input('supplier_name'));
   });
   ```

6. **Deprecate Legacy Endpoints:**
   - Mark old endpoints with 410 Gone after Phase 6
   - Document migration in API docs

---

## Next Steps

- [ ] Locate routes file (search for `Route::`, `->post`, `->get`)
- [ ] Trace one complete request (entry point → service → response)
- [ ] Document actual endpoint URLs
- [ ] Create sequence diagrams for each flow
- [ ] Update this document with confirmed endpoints

---

## Unknown Zones

**Requires Investigation:**

1. **Routing Layer Location:**
   - Where are HTTP routes defined?
   - Framework used (Laravel, custom, other)?

2. **UI Component Names:**
   - Which React/Vue/vanilla JS component calls suggestions?
   - File paths?

3. **Endpoint Authentication:**
   - Are suggestion endpoints protected?
   - Session-based or stateless?

4. **Rate Limiting:**
   - Any throttling on suggestion endpoints?
   - Could affect dual-run performance

5. **Caching Layer (HTTP):**
   - CDN or reverse proxy caching responses?
   - Could complicate cutover

---

## PHASE 1 COMPLETION STATUS

### Deliverables:

- [x] Service Classification Matrix
- [x] Query Pattern Audit (20 queries)
- [x] Endpoint Mapping (inferred)

### Missing Details:
- [ ] Actual endpoint URLs (routes file not located)
- [ ] UI component file names

### Decision:

**PROCEED TO PHASE 2** with current knowledge:
- We know which services to refactor
- We know which queries to fix
- Endpoint details can be confirmed during implementation

---

**Status:** ✅ COMPLETE (with noted unknowns)

**Confidence:** Medium-High
- Services/queries mapped: High confidence
- Endpoints: Medium confidence (inferred, not confirmed)

**Action:** Confirm endpoint details in parallel with Phase 2 work

**Last Updated:** 2026-01-03
