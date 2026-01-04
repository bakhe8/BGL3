# Service Classification Matrix - Phase 1

**Phase:** 1 - Signal Extraction & Mapping  
**Created:** 2026-01-03  
**Status:** In Progress  
**Purpose:** Classify all supplier learning/suggestion services by role to plan migration  

---

## Classification Taxonomy

| Role | Definition | Target State |
|------|------------|--------------|
| **AUTHORITY** | Computes final suggestions, scores, orders results | ONE service only: UnifiedLearningAuthority |
| **FEEDER** | Provides signals (observations, raw data) to Authority | Multiple allowed |
| **HYBRID** | Mixes signal retrieval + decision logic | MUST REFACTOR to separate |
| **REPOSITORY** | Database access layer, no business logic | Keep, may need cleanup |
| **UTILITY** | Supporting functions (normalization, formatting) | Keep |
| **DEPRECATED** | Unused or to be removed | Remove in Phase 6 |

---

## Services Classification (Backend)

###

 Core Suggestion Services

| Service/Class | Location | Current Role | Target Role | Status | Migration Plan |
|---------------|----------|--------------|-------------|--------|----------------|
| **LearningSuggestionService** | `app/Services/Suggestions/LearningSuggestionService.php` | **AUTHORITY** | FEEDER (learning signals) | 🔴 REQUIRES MAJOR REFACTOR | Extract signal feeding, remove scoring/ordering → LearningSignalFeeder |
| **SupplierCandidateService** | `app/Services/SupplierCandidateService.php` | **AUTHORITY** (fuzzy matching) | FEEDER (fuzzy signals) | 🔴 REQUIRES MAJOR REFACTOR | Extract similarity calculation, remove scoring/filtering → FuzzySignalFeeder |
| **ArabicLevelBSuggestions** | `app/Services/Suggestions/ArabicLevelBSuggestions.php` | **AUTHORITY** (entity anchor) | FEEDER (anchor signals) | 🔴 REQUIRES MAJOR REFACTOR | Extract anchor matching, remove confidence calc → AnchorSignalFeeder |
| **LearningService** | `app/Services/LearningService.php` | HYBRID (write + read) | Split: FEEDER + ACTION | 🟡 PARTIAL REFACTOR | Keep write path (record decisions), extract confirmation aggregation → part of LearningSignalFeeder |
| **ConfidenceCalculator** | `app/Services/Suggestions/ConfidenceCalculator.php` | UTILITY (scoring) | DEPRECATED | 🟡 REPLACE | Superseded by ConfidenceCalculatorV2 in Authority |

**Legend:**
- 🔴 Major refactor required
- 🟡 Minor changes needed
- 🟢 Compliant, no change

---

### Repository Layer

| Repository/Class | Location | Current Role | Target Role | Status | Migration Plan |
|------------------|----------|--------------|-------------|--------|----------------|
| **SupplierLearningRepository** | `app/Repositories/SupplierLearningRepository.php` | **HYBRID** (signal + decision logic) | REPOSITORY (signal access) | 🔴 CLEAN UP | Remove decision logic (`usage_count > 0` filters, LIMIT 1), expose raw signal retrieval methods |
| **LearningRepository** | `app/Repositories/LearningRepository.php` | REPOSITORY (signal) | REPOSITORY (signal) | 🟢 COMPLIANT | Minimal changes: add normalized query support for confirmations |
| **SupplierLearningCacheRepository** | `app/Repositories/SupplierLearningCacheRepository.php` | REPOSITORY (cache) | DEPRECATED or CACHE | 🔴 EVALUATE | Deprecate if cache-as-authority, OR refactor to true cache (Authority-populated) |
| **SupplierAlternativeNameRepository** | `app/Repositories/SupplierAlternativeNameRepository.php` | REPOSITORY (alias) | REPOSITORY (alias) | 🟡 MINOR CLEANUP | Remove first-match LIMIT 1, return all aliases for Authority to decide |
| **SupplierRepository** | `app/Repositories/SupplierRepository.php` | REPOSITORY (entity) | REPOSITORY (entity) | 🟢 COMPLIANT | No changes expected |

---

### Utilities & Support

| Utility/Class | Location | Current Role | Target Role | Status | Notes |
|---------------|----------|--------------|-------------|--------|-------|
| **ArabicNormalizer** | `app/Utils/ArabicNormalizer.php` | UTILITY (normalization) | UTILITY | 🟢 COMPLIANT | Used by Authority, no change |
| **Normalizer** | `app/Support/Normalizer.php` | UTILITY (wrapper) | UTILITY | 🟢 COMPLIANT | Wrapper for ArabicNormalizer, keep |

---

## Detailed Analysis by Service

### 1. LearningSuggestionService

**File:** `app/Services/Suggestions/LearningSuggestionService.php`

**Current Responsibilities:**
1. Aggregates user feedback (confirmations/rejections) ← **SIGNAL**
2. Queries entity anchors ← **SIGNAL**
3. Queries learned confirmations ← **SIGNAL**
4. Queries historical selections ← **SIGNAL**
5. ❌ Computes confidence via ConfidenceCalculator ← **DECISION**
6. ❌ Orders results by confidence ← **DECISION**
7. ❌ Formats as suggestions ← **DECISION**

**Violation:** Steps 5-7 are Authority responsibilities

**Migration Plan:**
```
Extract:
- getUserFeedback() → LearningSignalFeeder.getConfirmationSignals()
- getRejections() → LearningSignalFeeder.getRejectionSignals()
- getHistoricalSelections() → HistoricalSignalFeeder.getSignals()

Deprecate:
- Confidence computation (Authority does this)
- Result ordering (Authority does this)
- Formatting (Authority does this)

Timeline: Phase 2 (week 1-2)
Owner: [TBD]
```

---

### 2. SupplierCandidateService

**File:** `app/Services/SupplierCandidateService.php`

**Current Responsibilities:**
1. Queries cache ← **CACHE LOOKUP** (problematic)
2. Generates fuzzy match candidates ← **SIGNAL**
3. Queries official suppliers ← **SIGNAL**
4. Queries alternative names ← **SIGNAL**
5. ❌ Applies blocking logic ← **DECISION**
6. ❌ Scores candidates (similarity × weight) ← **DECISION**
7. ❌ Deduplicates (highest score wins) ← **DECISION**
8. ❌ Orders by score ← **DECISION**

**Violation:** Steps 1, 5-8 are Authority responsibilities

**Migration Plan:**
```
Extract:
- Fuzzy matching logic → FuzzySignalFeeder.getSignals()
  - Return: supplier_id, similarity_score (0-1.0), match_type
  - NO final score, NO filtering

Keep as separate feeders:
- Official supplier exact match → OfficialExactFeeder
- Official supplier fuzzy → OfficialFuzzyFeeder
- Alternative name exact → AliasExactFeeder
- Alternative name fuzzy → AliasFuzzyFeeder

Deprecate:
- Cache lookup (Authority decides cache strategy)
- Blocking (Authority handles suppression)
- Scoring/dedup/ordering (Authority)

Timeline: Phase 2 (week 2-3)
Owner: [TBD]
```

---

### 3. ArabicLevelBSuggestions

**File:** `app/Services/Suggestions/ArabicLevelBSuggestions.php`

**Current Responsibilities:**
1. Extracts entity anchors from input ← **SIGNAL PROCESSING**
2. Queries suppliers matching anchors ← **SIGNAL**
3. ❌ Implements "Golden Rule" (no anchors = silent return) ← **DECISION**
4. ❌ Computes dynamic confidence (anchor uniqueness) ← **DECISION**
5. ❌ Returns Level B suggestions ← **DECISION** (Authority assigns levels)

**Violation:** Steps 3-5 are Authority responsibilities

**Migration Plan:**
```
Extract:
- Anchor extraction → Keep as utility (AnchorExtractor)
- Anchor matching → AnchorSignalFeeder.getSignals()
  - Return: supplier_id, matched_anchor, anchor_frequency
  - NO confidence, NO level assignment

Authority handles:
- Silence decision (if no signals from ANY feeder, not just anchors)
- Confidence calculation (anchor uniqueness as factor)
- Level assignment (B/C/D based on final confidence)

Timeline: Phase 2 (week 1-2)
Owner: [TBD]
```

---

### 4. LearningService

**File:** `app/Services/LearningService.php`

**Current Responsibilities:**
1. Records user manual selections ← **ACTION** (keep)
2. Learns aliases (creates/updates) ← **ACTION** (keep)
3. Increments usage_count ← **ACTION** (keep, but review logic)
4. Decrements usage_count (penalties) ← **ACTION** (keep)
5. Logs decisions ← **AUDIT** (keep)

**Current Status:** Mostly compliant (write path)

**Issue:** No signalreading (that's in LearningRepository), but needs review:
- `usage_count++` logic: Is this signal or decision?
- Per Database Role Declaration: This is SIGNAL accumulation (acceptable)

**Migration Plan:**
```
Keep as-is for Phase 2-4 (write path)

Phase 6 review:
- Ensure no decision logic in write operations
- Consider renaming to LearningActionService (clarity)

Timeline: Phase 6 (optional cleanup)
Owner: [TBD]
```

---

## Summary Statistics

**Total Services Analyzed:** 9

**By Status:**
- 🔴 Major Refactor Required: 4 (LearningSuggestionService, SupplierCandidateService, ArabicLevelBSuggestions, SupplierLearningRepository)
- 🟡 Minor Changes: 3 (LearningService, SupplierLearningCacheRepository, SupplierAlternativeNameRepository)
- 🟢 Compliant: 2 (LearningRepository, ArabicNormalizer)

**By Target Role:**
- To become FEEDER: 3 (LearningSuggestionService, SupplierCandidateService, ArabicLevelBSuggestions)
- To be DEPRECATED: 2 (ConfidenceCalculator, SupplierLearningCacheRepository - TBD)
- REPOSITORY (keep): 5
- UTILITY (keep): 2

---

## Next Steps

### Immediate (This Week):
- [ ] Review this matrix with team (validation session - 2 hours)
- [ ] Identify service owners for refactoring
- [ ] Estimate effort per service (story points)

### Phase 2 Prep (Next Week):
- [ ] Design SignalDTO interface
- [ ] Design Feeder interface contracts
- [ ] Create UnifiedLearningAuthority skeleton

### Questions for ARB:
1. SupplierLearningCacheRepository: Deprecate completely or refactor to true cache?
2. Should we split SupplierCandidateService into 4 separate feeders or keep as one with multiple signal types?
3. Priority order for refactoring (parallel vs sequential)?

---

**Status:** ✅ Draft Complete - Pending Team Review

**Reviewers:**
- [ ] Tech Lead
- [ ] Senior Backend Dev
- [ ] ARB (when formed)

**Last Updated:** 2026-01-03
