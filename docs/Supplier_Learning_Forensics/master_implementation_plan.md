# MASTER IMPLEMENTATION PLAN

## SUPPLIER LEARNING UNIFICATION - COMPLETE EXECUTION ROADMAP

**Plan Status:** Ready for Approval & Execution  
**Foundation:** 11 Governance Documents  
**Timeline:** 5-6 months (detailed per phase)  
**Risk Level:** Low (safe, non-breaking approach)  

---

## EXECUTIVE SUMMARY

### What We Have (11 Documents)

**Forensic Analysis (3 parts):**
1. Artifact Inventory & Uniqueness Behavior
2. Decision Paths & Branching Logic
3. Outcome Reconstruction & Risks

**Constitutional Framework (4 parts):**
4. Charter Preamble - Governing Principles
5. Charter Part 1 - Current Reality & Problems
6. Charter Part 2 - Authority & Scoring Rules
7. Charter Part 3 - UI Contract & Governance

**Implementation Guidance (4 documents):**
8. Implementation Roadmap - 7 Phase Methodology
9. Database Fitness Analysis
10. Database Role Declaration
11. Authority Intent Declaration

### What We Will Build

**ONE unified supplier learning system** replacing 5 parallel subsystems:
- Single Learning Authority (instead of LearningSuggestionService, SupplierCandidateService, ArabicLevelBSuggestions)
- Unified confidence scoring (0-100 scale, B/C/D levels)
- Signal feeders (not independent systems)
- Clean SuggestionDTO (standardized UI contract)

---

## PHASE-BY-PHASE EXECUTION PLAN

### PHASE 0: FREEZE & GOVERNANCE LOCK

**Duration:** 1 week  
**Type:** Administrative & Governance  

#### Objectives

1. **Establish Charter Authority**
   - Formal approval of all 11 documents
   - Communication to all stakeholders
   - Establish Architecture Review Board (ARB)

2. **Stop The Expansion**
   - Freeze all new suggestion features
   - No new confidence formulas
   - No new UI variants

#### Actions

**Action 0.1: Document Approval Meeting**

**Participants:** Product Owner, Tech Lead, Senior Developers  
**Agenda:**
- Review Charter Preamble (governing principles)
- Review Database Role Declaration
- Review Authority Intent Declaration
- Approve or request amendments

**Deliverable:** Signed approval document

---

**Action 0.2: Team Communication**

**Format:** All-hands meeting + documentation share  
**Content:**
- Why unification is necessary (show fragmentation evidence from Forensic Analysis)
- What changes (development process, not immediate code)
- What stays frozen (new features on hold during consolidation)

**Reference Documents:**
- `charter_part1_reality_and_problems.md` (Section 1: Problem Statement)
- `implementation_roadmap.md` (Phase 0 section)

**Deliverable:** Team acknowledgment, no objections raised

---

**Action 0.3: Establish Pull Request Policy**

**Create:** `.github/PULL_REQUEST_TEMPLATE.md` (or equivalent)

**Template includes:**
```markdown
## Learning System Changes Checklist

- [ ] Does this PR create new suggestion service? ❌ REJECT
- [ ] Does this PR calculate confidence outside Authority? ❌ REJECT
- [ ] Does this PR modify SuggestionDTO schema? ⚠️ Requires Charter amendment
- [ ] Does this PR add signal feeder? ✓ Submit integration plan
- [ ] Does this PR align with Database Role Declaration? ✓ Specify role
```

**Reference Documents:**
- `charter_part3_ui_and_governance.md` (Section 7: Consolidation Guarantee)
- `database_role_declaration.md` (Article 8: Enforcement)

**Deliverable:** PR template active, first reviews use checklist

---

#### Success Criteria

✓ All 11 documents approved  
✓ ARB established (3+ members)  
✓ Team briefed, no active resistance  
✓ PR policy enforced (1+ PRs reviewed with checklist)  
✓ Zero new suggestion services created  

#### Risk Mitigation

**Risk:** Team resistance to freeze  
**Mitigation:** Emphasize temporary (5-6 months), show fragmentation costs

**Risk:** Unclear authority  
**Mitigation:** Designate specific ARB members, escalation path defined

---

### PHASE 1: SIGNAL EXTRACTION & MAPPING

**Duration:** 2-3 weeks  
**Type:** Documentation & Analysis (no code changes)  

#### Objectives

1. **Map Current Codebase to Roles**
   - Classify every service/repository as Signal/Decision/Entity/Hybrid
   - Document current query patterns
   - Identify all suggestion entry points

2. **Create Signal Inventory**
   - List all signal types with current storage
   - Document current weighting (implicit and explicit)
   - Map signal flow: DB → Service → UI

#### Actions

**Action 1.1: Service Classification Matrix**

**Create:** `docs/implementation/service_classification.md`

**Format:**
| Service/Class | Current Role | Target Role | Status | Migration Plan |
|---------------|--------------|-------------|--------|----------------|
| LearningSuggestionService | AUTHORITY | FEEDER (confirmations) | REFACTOR | Extract signal aggregation |
| SupplierCandidateService | AUTHORITY | FEEDER (fuzzy matching) | REFACTOR | Remove scoring logic |
| ArabicLevelBSuggestions | AUTHORITY | FEEDER (entity anchors) | REFACTOR | Remove confidence calc |
| SupplierLearningRepository | SIGNAL+DECISION | SIGNAL | REQUIRES FIX | Separate usage_count logic |
| LearningRepository | SIGNAL | SIGNAL | COMPLIANT | No change |
| ... | ... | ... | ... | ... |

**Reference Documents:**
- `supplier_learning_forensics_part1.md` (Section 1: Artifact Inventory)
- `supplier_learning_forensics_part2.md` (Section 5: Decision Inventory)
- `database_role_declaration.md` (Article 6: Role Declaration Table)

**Deliverable:** Complete classification spreadsheet

---

**Action 1.2: Query Pattern Audit**

**Create:** `docs/implementation/query_patterns.md`

**For each query in current services:**
- Query location (file:line)
- SQL/query logic
- Purpose (signal retrieval vs decision filtering)
- Compliance status (passes Database Role Declaration?)
- Required changes (if any)

**Example Entry:**
```markdown
### Query: Alias Exact Match

**Location:** `SupplierLearningRepository.php:26`

**Current SQL:**
```sql
SELECT * FROM supplier_alternative_names 
WHERE normalized_name = ? AND usage_count > 0 
LIMIT 1
```

**Analysis:**
- **Signal retrieval:** ✓ (normalized_name match)
- **Decision filter:** ❌ (usage_count > 0 is decision logic)
- **Decision limit:** ❌ (LIMIT 1 is arbitrary winner)

**Violation:** Embeds decision logic in SQL (Database Role Declaration, Article 4.1)

**Required Change:**
- Remove `AND usage_count > 0`
- Remove `LIMIT 1`
- Authority applies threshold and ordering
```

**Reference Documents:**
- `database_role_declaration.md` (Article 4: Prohibited Patterns)
- `supplier_learning_forensics_part2.md` (Flow diagrams)

**Deliverable:** Audit report with 20+ queries analyzed

---

**Action 1.3: API Endpoint Mapping**

**Create:** `docs/implementation/endpoint_mapping.md`

**Map:**
- Which HTTP endpoints call which suggestion services
- Current response format per endpoint
- UI components consuming each endpoint

**Purpose:** Identify all refactoring points, no endpoint bypasses Authority

**Deliverable:** Endpoint → Service → UI flow diagram

---

#### Success Criteria

✓ Service classification 100% complete  
✓ Query audit identifies all decision-in-SQL violations  
✓ Endpoint map shows all suggestion flows  
✓ Team understands current fragmentation (review meeting held)  

#### Outputs (Artifacts)

- `service_classification.md`
- `query_patterns.md`
- `endpoint_mapping.md`

---

### PHASE 2: AUTHORITY SHADOW BUILD

**Duration:** 3-4 weeks  
**Type:** Development (parallel to production, zero impact)  

#### Objectives

1. **Create UnifiedLearningAuthority Service**
   - Implements Authority Intent Declaration
   - Calls existing services as feeders
   - Returns SuggestionDTO

2. **Create Signal Feeder Interfaces**
   - Extract signal retrieval from current services
   - Standardize feeder output format

3. **Implement Unified Scoring**
   - ConfidenceCalculatorv2 (Charter formula)
   - Provenance tracking

#### Actions

**Action 2.1: Create Authority Service Skeleton**

**File:** `app/Services/UnifiedLearningAuthority.php`

**Structure:**
```php
class UnifiedLearningAuthority
{
    public function __construct(
        private Normalizer $normalizer,
        private AliasSignalFeeder $aliasFeeder,
        private AnchorSignalFeeder $anchorFeeder,
        private FuzzySignalFeeder $fuzzyFeeder,
        private LearningSignalFeeder $learningFeeder,
        private HistoricalSignalFeeder $historicalFeeder,
        private ConfidenceCalculatorV2 $scorer,
        private SuggestionFormatter $formatter
    ) {}

    public function getSuggestions(string $rawInput): array
    {
        // Step 1: Normalize (Authority Intent 2.2, Step 1)
        $normalized = $this->normalizer->normalize($rawInput);
        
        // Step 2-7: Implement per Authority Intent Declaration
        // ...
        
        return $suggestions; // Array of SuggestionDTO
    }
}
```

**Reference Documents:**
- `authority_intent_declaration.md` (Section 2.2: Decision Formation Process)
- `charter_part2_authority_and_scoring.md` (Section 2: Single Learning Authority)

**Deliverable:** Authority service with unit tests (not integrated yet)

---

**Action 2.2: Create Signal Feeder Interfaces**

**Create:** `app/Contracts/SignalFeederInterface.php`

```php
interface SignalFeederInterface
{
    /**
     * Retrieve signals for normalized input
     * 
     * @return array<SignalDTO>
     */
    public function getSignals(string $normalizedInput): array;
}

class SignalDTO
{
    public function __construct(
        public int $supplier_id,
        public string $signal_type,  // 'alias_exact', 'entity_anchor', etc.
        public float $raw_strength,   // 0.0-1.0, NOT final confidence
        public array $metadata = []
    ) {}
}
```

**Implement:**
- `AliasSignalFeeder` (extracts from SupplierLearningRepository)
- `AnchorSignalFeeder` (extracts from ArabicLevelBSuggestions)
- `FuzzySignalFeeder` (extracts from SupplierCandidateService)
- `LearningSignalFeeder` (aggregates confirmations/rejections)
- `HistoricalSignalFeeder` (counts historical selections)

**Reference Documents:**
- `charter_part2_authority_and_scoring.md` (Section 3: Learning Signals - Feeders)
- `database_role_declaration.md` (Article 6.1: Signal Table Requirements)

**Deliverable:** 5 feeder classes, all return SignalDTO[], tested independently

---

**Action 2.3: Implement Unified Confidence Calculator**

**File:** `app/Services/ConfidenceCalculatorV2.php`

**Formula (from Charter):**
```php
public function calculate(array $signals): int
{
    // Identify primary signal (highest base weight)
    $primary = $this->identifyPrimary($signals);
    
    // Base score by signal type
    $base = match($primary['signal_type']) {
        'alias_exact' => 100,
        'entity_anchor_unique' => 90,
        'entity_anchor_generic' => 75,
        'fuzzy_official_strong' => 85,
        'historical' => 45,
        default => 40
    };
    
    // Aggregate learning signals
    $confirmations = $this->countSignals($signals, 'confirmation');
    $rejections = $this->countSignals($signals, 'rejection');
    
    // Apply boosts/penalties (Charter formula)
    $confirmBoost = min($confirmations * 5, 15);
    $rejectPenalty = $rejections * 10;
    
    // Final confidence
    $confidence = $base + $confirmBoost - $rejectPenalty;
    
    // Clamp 0-100
    return max(0, min(100, $confidence));
}
```

**Reference Documents:**
- `charter_part2_authority_and_scoring.md` (Section 4: Unified Scoring Semantics)
- `authority_intent_declaration.md` (Section 2.4: Confidence Computation)

**Deliverable:** Calculator with 20+ unit tests covering edge cases

---

**Action 2.4: Create SuggestionDTO Formatter**

**File:** `app/Services/SuggestionFormatter.php`

**Transforms:** Internal candidate → SuggestionDTO (Charter schema)

**Includes:**
- Level assignment (B/C/D based on confidence)
- Arabic reason generation (unified format)
- Metadata population

**Reference Documents:**
- `charter_part3_ui_and_governance.md` (Section 6: UI Unification Contract)
- `authority_intent_declaration.md` (Section 2.3: Output Schema)

**Deliverable:** Formatter returns valid SuggestionDTO, validated against schema

---

#### Success Criteria

✓ Authority service compiles, all dependencies injected  
✓ 5 signal feeders implemented, unit tested  
✓ ConfidenceCalculatorV2 matches Charter formula exactly  
✓ SuggestionFormatter outputs valid DTOs  
✓ **Production UNAFFECTED** (Authority not called yet)  

#### Test Strategy

**Unit Tests:**
- Each feeder: Mock DB, verify SignalDTO output
- Calculator: Test all signal combinations, verify formula
- Formatter: Test DTO schema compliance

**Integration Tests (Authority Service):**
- Mock all feeders, verify Authority aggregation logic
- Test confidence ordering
- Test filtering (min threshold 40)

---

### PHASE 3: DUAL RUN (VALIDATION)

**Duration:** 2-4 weeks  
**Type:** Monitoring & Comparison (production traffic, zero user impact)  

#### Objectives

1. **Run Authority in Shadow Mode**
   - Every suggestion request → both legacy AND Authority execute
   - Log both outputs for comparison
   - Users see ONLY legacy results

2. **Compare Results**
   - Coverage: Does Authority find all suppliers legacy finds?
   - Confidence: Are scores reasonable?
   - Performance: Is Authority fast enough?

3. **Identify Gaps**
   - Missing signals
   - Formula bugs
   - Edge cases

#### Actions

**Action 3.1: Add Shadow Execution to Endpoints**

**Modify:** All endpoints currently returning suggestions

**Pattern:**
```php
public function getSupplierSuggestions(Request $request)
{
    $rawName = $request->input('supplier_name');
    
    // LEGACY (shown to user)
    $legacyResults = $this->learningSuggestionService->getSuggestions($rawName);
    
    // AUTHORITY (shadow, logged only)
    try {
        $authorityResults = $this->unifiedAuthority->getSuggestions($rawName);
        
        // Log for comparison
        $this->comparisonLogger->log([
            'input' => $rawName,
            'legacy' => $legacyResults,
            'authority' => $authorityResults,
            'timestamp' => now(),
            'request_id' => request()->id()
        ]);
    } catch (\Exception $e) {
        // Authority errors silent, logged
        report($e);
    }
    
    // Return legacy (user experience unchanged)
    return response()->json($legacyResults);
}
```

**Reference Documents:**
- `implementation_roadmap.md` (Phase 3: Dual Run)

**Deliverable:** Shadow execution active on all suggestion endpoints

---

**Action 3.2: Create Comparison Dashboard**

**Tool:** SQL queries + spreadsheet OR simple admin panel

**Metrics to Track:**
1. **Coverage Comparison:**
   - Queries where Authority returns suppliers Legacy doesn't
   - Queries where Legacy returns suppliers Authority doesn't
   - Overlap percentage

2. **Confidence Divergence:**
   - Same supplier, different confidence scores
   - Histogram of divergence magnitude

3. **Performance:**
   - Authority execution time vs Legacy
   - Percentiles (p50, p95, p99)

4. **Error Rate:**
   - Authority exceptions/errors
   - Root causes

**Deliverable:** Weekly comparison reports (4 reports over 4 weeks)

---

**Action 3.3: Gap Analysis & Fixes**

**Process:**
1. Review comparison reports
2. Identify patterns (e.g., "Authority misses aliases from source X")
3. Debug root cause
4. Fix Authority or feeder logic
5. Re-run comparison
6. Iterate

**Common Gaps (Expected):**
- Normalization inconsistencies
- Missing signal feeder
- Threshold too aggressive
- Formula bug

**Reference Documents:**
- `database_fitness_analysis.md` (Section 7: Unknown Zones - may uncover issues)

**Deliverable:** Gap analysis document + fixes applied

---

#### Success Criteria

✓ Dual run active for 2+ weeks  
✓ Coverage gap < 5% (Authority finds 95%+ of Legacy suppliers)  
✓ Confidence divergence understood and justified  
✓ Performance acceptable (Authority < 200ms p95)  
✓ Error rate < 1%  
✓ Team confident Authority is production-ready  

#### Rollback Plan

If critical gap discovered:
- Keep dual run active
- Fix Authority
- Extend Phase 3 duration
- Do NOT proceed to Phase 4 until criteria met

---

### PHASE 4: SILENT SWITCH

**Duration:** 1 week + 2 weeks observation  
**Type:** Production cutover (backend only, UI unchanged)  

#### Objectives

1. **Switch Endpoints to Authority**
   - API returns Authority results to UI
   - Legacy services still exist (fallback)

2. **UI Sees No Change**
   - Authority returns SuggestionDTO compatible with current UI expectations
   - No visual changes, same fields consumed

3. **Monitor Stability**
   - Error rates
   - User behavior (acceptance rates)
   - Support tickets

#### Actions

**Action 4.1: Flip the Switch**

**Modify:** Endpoints to return Authority results

```php
public function getSupplierSuggestions(Request $request)
{
    $rawName = $request->input('supplier_name');
    
    // NEW: Authority is primary
    try {
        $results = $this->unifiedAuthority->getSuggestions($rawName);
    } catch (\Exception $e) {
        // Fallback to legacy on error
        report($e);
        $results = $this->learningSuggestionService->getSuggestions($rawName);
    }
    
    return response()->json($results);
}
```

**Phased Rollout:**
- Day 1: 10% of traffic → Authority
- Day 2: 25%
- Day 3: 50%
- Day 5: 100%

**Reference Documents:**
- `implementation_roadmap.md` (Phase 4: Silent Switch)

**Deliverable:** 100% traffic on Authority, fallback active

---

**Action 4.2: Monitor User Behavior**

**Metrics:**
- Suggestion acceptance rate (before vs after)
- Manual selection rate
- Average decision time
- Support ticket volume

**Expectation:** NO CHANGE (users don't notice)

**Deliverable:** 2-week monitoring report

---

**Action 4.3: Hotfix Process**

If critical issue discovered:
```php
// Emergency rollback flag
if (config('learning.use_legacy_fallback')) {
    return $this->learningSuggestionService->getSuggestions($rawName);
}
```

**Triggers:**
- Error rate > 5%
- Acceptance rate drops > 10%
- Support tickets spike

**Deliverable:** Hotfix plan documented, tested

---

#### Success Criteria

✓ Authority serving 100% of production traffic  
✓ Error rate < 2%  
✓ Acceptance rate stable (within ±5% of baseline)  
✓ Support tickets stable  
✓ No critical bugs requiring rollback  
✓ Team confident in Authority stability  

---

### PHASE 5: UI CONSOLIDATION

**Duration:** 1-2 weeks  
**Type:** Frontend changes (visual consistency)  

#### Objectives

1. **Apply Unified UI Contract**
   - Standardize suggestion display
   - Remove source-based styling
   - Unify colors, badges, explanations

2. **Simplify User Experience**
   - One visual language
   - Predictable presentation

#### Actions

**Action 5.1: UI Component Refactor**

**Update:** Supplier suggestion display components

**Before (Fragmented):**
```javascript
if (suggestion.source === 'entity_anchor') {
    badgeColor = 'blue';
    label = 'تطابق مميز';
} else if (suggestion.source === 'fuzzy') {
    badgeColor = 'yellow';
    label = 'تشابه عالي';
}
```

**After (Unified):**
```javascript
// Single source of truth: suggestion.level
if (suggestion.level === 'B') {
    badgeColor = 'green';
    label = 'ثقة عالية';
} else if (suggestion.level === 'C') {
    badgeColor = 'yellow';
    label = 'ثقة متوسطة';
} else if (suggestion.level === 'D') {
    badgeColor = 'orange';
    label = 'ثقة منخفضة';
}

// Display reason_ar directly (already formatted by Authority)
explanation = suggestion.reason_ar;
```

**Reference Documents:**
- `charter_part3_ui_and_governance.md` (Section 6: UI Unification Contract)

**Deliverable:** UI components updated, design review approved

---

**Action 5.2: Remove Source-Based Logic**

**Audit:** All frontend code for `suggestion.source` usage

**Pattern to Remove:**
```javascript
// FORBIDDEN: Different handling by source
if (suggestion.primary_source === 'alias_exact') {
    // Special handling
}
```

**Only Allowed:**
```javascript
// OK: Debug/audit display (not user-facing)
console.log('Debug: primary_source =', suggestion.primary_source);
```

**Deliverable:** Frontend audit clean, no source-based UX

---

#### Success Criteria

✓ UI refresh deployed  
✓ All suggestions use unified visual treatment  
✓ User feedback positive (or neutral, no complaints)  
✓ Design consistency verified  

---

### PHASE 6: DEPRECATION & CLEANUP

**Duration:** 2-3 months (grace period + removal)  

#### Objectives

1. **Mark Legacy Services as Deprecated**
2. **Remove or Refactor to Feeders**
3. **Clean Up Database** (if needed)

#### Actions

**Action 6.1: Mark Deprecated**

**Add to legacy services:**
```php
/**
 * @deprecated Use UnifiedLearningAuthority instead
 * Scheduled for removal: 2026-Q2
 */
class LearningSuggestionService
{
    // Code remains but annotated
}
```

**Deliverable:** All legacy services annotated

---

**Action 6.2: Refactor to Feeders**

**Transform:**
- LearningSuggestionService → LearningSignalFeeder (if not already done)
- SupplierCandidateService → FuzzySignalFeeder
- ArabicLevelBSuggestions → AnchorSignalFeeder

**Remove:**
- Independent scoring logic
- Suggestion formatting
- Direct endpoint calls

**Deliverable:** Legacy services reduced to feeder functions only

---

**Action 6.3: Database Schema Updates** (Optional, if needed)

**Based on:** Database Role Declaration compliance

**Potential Changes:**
- Add `normalized_supplier_name` to `learning_confirmations`
- Add index for efficient Authority queries
- Deprecate `supplier_learning_cache` (if not used)

**Reference Documents:**
- `database_role_declaration.md` (Section 5.2: Future Compliance Path)

**Deliverable:** Schema migration plan (if needed), tested

---

**Action 6.4: Remove Deprecated Code**

**After grace period (3 months):**
- Delete deprecated service classes
- Remove unused endpoints
- Archive old tests

**Deliverable:** Codebase cleaned, reduced complexity

---

#### Success Criteria

✓ No new code calls deprecated services  
✓ Deprecated services converted to feeders  
✓ Database compliant with Role Declaration  
✓ Code removal executed safely  

---

### PHASE 7: CONTINUOUS GOVERNANCE

**Duration:** Ongoing (indefinitely)  
**Type:** Maintenance & Enforcement  

#### Objectives

1. **Prevent Fragmentation Return**
2. **Monitor Compliance**
3. **Evolve Charter as Needed**

#### Actions

**Action 7.1: Monthly Governance Review**

**Agenda:**
- Review PRs from past month
- Check for Charter violations
- Discuss proposed new signal types
- Update metrics dashboard

**Reference Documents:**
- All Charter documents (living governance)

**Deliverable:** Monthly meeting notes, compliance report

---

**Action 7.2: Automated Compliance Tests**

**Tests:**
```php
// Only one suggestion provider
test('only_authority_provides_suggestions');

// All suggestions are valid DTOs
test('authority_returns_valid_dtos');

// No decision logic in SQL
test('no_sql_decision_filters');
```

**Run:** On every PR, CI/CD pipeline

**Deliverable:** Test suite protecting Charter compliance

---

**Action 7.3: Charter Amendment Process**

**When needed:**
- New signal type discovered
- Formula needs adjustment
- UI contract evolves

**Process:**
1. Propose amendment with justification
2. ARB review
3. Update Charter documents
4. Communicate to team
5. Update code/tests

**Deliverable:** Living Charter, versioned and maintained

---

## SUCCESS METRICS (OVERALL)

### Technical Metrics

| Metric | Before | Target | How to Measure |
|--------|--------|--------|----------------|
| Suggestion Sources | 5 parallel | 1 unified | Code audit: count services tagged 'suggestion-provider' |
| Confidence Scales | 3 different | 1 (0-100) | API response analysis |
| UI Presentation Variants | 3+ | 1 | Frontend component audit |
| Learning Fragmentation Rate | ~30% | <5% | DB query: confirmations aggregated vs raw count |
| Cache-Live Divergence | Unknown | 0% (or deprecated) | Comparison queries |
| Code Complexity (LOC) | Baseline | -20% | SLOC in suggestion services |

### User Experience Metrics

| Metric | Target |
|--------|--------|
| Suggestion Acceptance Rate | Within 5% of baseline |
| Decision Time | Within 10% of baseline |
| Support Tickets (confusion) | No increase |
| User Satisfaction | Neutral or positive |

### Governance Metrics

| Metric | Target |
|--------|--------|
| Charter Violations | 0 per month |
| PRs Rejected for Non-Compliance | Available metric, not zero (shows enforcement) |
| ARB Meeting Frequency | 1 per month minimum |

---

## RISK REGISTER

| Risk | Probability | Impact | Mitigation | Owner |
|------|-------------|--------|------------|-------|
| Team resistance to freeze | Medium | High | Clear communication of temporary nature, show fragmentation costs | Tech Lead |
| Authority slower than legacy | Low | High | Performance testing in Phase 2, optimization before Phase 4 | Backend Team |
| Gap in signal coverage | Medium | High | Dual run (Phase 3) identifies gaps before cutover | QA + Backend |
| UI changes confuse users | Low | Medium | A/B test UI updates, collect feedback | Frontend + Product |
| Database migration breaks prod | Low | Critical | Test migrations on staging,  rolling deployment | DevOps |
| Charter becomes stale | Medium | Medium | Quarterly review process, living document culture | ARB |

---

## DEPENDENCIES & PREREQUISITES

**Before Starting Phase 0:**
- [ ] All 11 documents reviewed and understood by team
- [ ] ARB members identified and committed
- [ ] Product Owner approves consolidation as priority
- [ ] No critical active development on suggestion features

**Before Starting Each Phase:**
- [ ] Previous phase success criteria MET
- [ ] Team retrospective conducted
- [ ] Risks re-assessed
- [ ] Go/No-Go decision made

---

## DELIVERABLES CHECKLIST

### Documentation (Already Complete ✓)
- [x] Forensic Analysis (3 parts)
- [x] Charter (4 parts)
- [x] Implementation Roadmap
- [x] Database Fitness Analysis
- [x] Database Role Declaration
- [x] Authority Intent Declaration

### Phase Deliverables (To Be Created)
- [ ] Service Classification Matrix (Phase 1)
- [ ] Query Pattern Audit (Phase 1)
- [ ] Endpoint Mapping (Phase 1)
- [ ] UnifiedLearningAuthority Service (Phase 2)
- [ ] 5 Signal Feeders (Phase 2)
- [ ] ConfidenceCalculatorV2 (Phase 2)
- [ ] Comparison Reports (Phase 3)
- [ ] Gap Analysis (Phase 3)
- [ ] Production Cutover Plan (Phase 4)
- [ ] UI Component Updates (Phase 5)
- [ ] Deprecation Notices (Phase 6)
- [ ] Continuous Governance Process (Phase 7)

---

## TIMELINE SUMMARY

```
                  MONTH 1    MONTH 2    MONTH 3    MONTH 4    MONTH 5    MONTH 6
Phase 0 (Freeze)      ████
Phase 1 (Extract)     ██████████
Phase 2 (Build)              ████████████████
Phase 3 (Dual Run)                     ████████████████
Phase 4 (Switch)                                ██████████
Phase 5 (UI)                                          ████████
Phase 6 (Deprecate)                                       █████████████████████
Phase 7 (Govern)      ════════════════════════════════════════════════════════→
                      (Starts immediately, continues indefinitely)
```

**Critical Path:** Phase 0 → 1 → 2 → 3 → 4  
**Parallelizable:** Phase 5 can overlap with late Phase 4  
**Long-tail:** Phase 6 grace period extends timeline but low effort  

---

## APPROVAL & SIGN-OFF

**This plan requires approval from:**

- [ ] Product Owner (business justification, timeline)
- [ ] Tech Lead (technical feasibility, resource allocation)
- [ ] ARB (Charter compliance, governance structure)
- [ ] Frontend Lead (UI changes, Phase 5)
- [ ] DevOps (deployment strategy, Phase 4)

**Approval Date:** _______________  
**Approved By:** _______________  

**Document Version:** 1.0  
**Last Updated:** 2026-01-03  
**Document Owner:** Architecture Review Board  

---

**END OF MASTER IMPLEMENTATION PLAN**
