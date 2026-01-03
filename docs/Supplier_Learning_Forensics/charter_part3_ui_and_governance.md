# LEARNING UNIFICATION CHARTER (DRAFT) - PART 3

## 6. UI UNIFICATION CONTRACT (ONE VOICE)

### Problem Restatement

Current UI receives suggestions from multiple sources with inconsistent formats:
- Source A: confidence (0-100), level ('B'/'C'/'D'), Arabic reason
- Source B: score (0-1.0), no level, source label only  
- Source C: confidence (70-95), level ('B'), Arabic reason with anchor
- Source D: score=100, source='alias', no reason

Result: UI cannot present consistent experience, must handle multiple formats, creates visual/behavioral incoherence.

---

### Canonical Suggestion DTO Schema

The Authority SHALL return suggestions to UI in ONE standardized format:

```typescript
interface SuggestionDTO {
  // Identity
  supplier_id: number;           // Required
  official_name: string;         // Required, supplier's canonical name
  english_name: string | null;  // Optional, for display if available
  
  // Confidence & Level
  confidence: number;            // Required, 0-100 integer
  level: 'B' | 'C' | 'D';       // Required, never NULL (filtered before return)
  
  // Explanation
  reason_ar: string;             // Required, Arabic explanation
  reason_details: ReasonDetail[]; // Optional, structured breakdown
  
  // Provenance (for debugging/audit, not always shown to user)
  primary_source: SourceType;    // Required
  signal_count: number;          // How many signals contributed
  
  // Metadata
  is_ambiguous: boolean;         // True if multiple suppliers with close confidence
  requires_confirmation: boolean; // True if Level C or D
  
  // Learning context
  confirmation_count: number;    // Total confirmations (across all variants)
  rejection_count: number;       // Total rejections
  usage_count: number;           // Alias usage count (if applicable)
}

enum SourceType {
  ALIAS_EXACT = 'alias_exact',
  ENTITY_ANCHOR = 'entity_anchor',
  FUZZY_OFFICIAL = 'fuzzy_official',
  FUZZY_ALTERNATIVE = 'fuzzy_alternative',
  ADMIN_OVERRIDE = 'admin_override',
  HISTORICAL = 'historical'
}

interface ReasonDetail {
  signal_type: SourceType;
  contribution: string;  // Arabic fragment, e.g., "تطابق دقيق"
  metadata: object;      // Flexible, e.g., {matched_anchor: "النورس"}
}
```

**Rules:**
1. ALL fields marked "Required" MUST be present in every suggestion
2. `confidence` MUST be integer 0-100, never decimal, never >100
3. `level` MUST match confidence: B if ≥85, C if ≥65, D if ≥40
4. `reason_ar` MUST NOT be empty, minimum one fragment
5. `supplier_id` MUST be valid foreign key to suppliers table

---

### Canonical Reason Model

#### Format Rules

**Structure:**
```
reason_ar = primary_match + learning_signals + warnings

Examples:
- "تطابق دقيق (اسم بديل) + تم تأكيده 3 مرات"
- "تطابق مع كلمة مميزة: 'النورس' + تم اختياره 5 مرات"
- "تشابه عالي مع الاسم الرسمي (92%) + بيانات محدودة"
```

**Components:**

1. **Primary Match** (ALWAYS FIRST):
   - Alias exact: "تطابق دقيق (اسم بديل)"
   - Entity anchor (unique): "تطابق مع كلمة مميزة: '{anchor}'"
   - Entity anchor (generic): "تطابق مع كلمة: '{anchor}'"
   - Fuzzy official: "تشابه مع الاسم الرسمي ({similarity}%)"
   - Fuzzy alternative: "تشابه مع اسم بديل ({similarity}%)"
   - Admin override: "تطابق يدوي (إدارة النظام)"
   - Historical: "تم اختياره سابقاً"

2. **Learning Signals** (IF PRESENT):
   - Confirmations: "+ تم تأكيده {count} مرات" (plural) or "مرة واحدة" (singular)
   - Historical selections: "+ تم اختياره {count} مرات"
   - High usage: "+ استخدام متكرر" (if usage_count > 10)

3. **Warnings** (IF APPLICABLE):
   - Rejections: "+ تم رفضه {count} مرات"
   - Ambiguous: "+ اسم مشترك بين موردين"
   - Limited data: "+ بيانات محدودة" (if Level D)
   - Low confidence: "+ يتطلب مراجعة" (if Level C or D)

**Concatenation:** Use " + " between components (space + plus + space)

**Length Limit:** Max 120 characters (Arabic), truncate learning signals if exceeds, always preserve primary match

---

### Canonical Level Semantics

| Level | Confidence Range | Meaning | UI Presentation | User Action |
|-------|------------------|---------|-----------------|-------------|
| **B** | 85-100 | High confidence, very likely correct | Green badge "ثقة عالية"<br>Bold text | Can accept quickly,<br>minimal review needed |
| **C** | 65-84 | Medium confidence, probably correct | Yellow badge "ثقة متوسطة"<br>Normal text | Should review before accepting,<br>confirmation recommended |
| **D** | 40-64 | Low confidence, possible match | Orange badge "ثقة منخفضة"<br>Lighter text | Requires careful review,<br>alternative options recommended |

**Stability Guarantee:**
- Level thresholds SHALL NOT change without major version update
- UI can rely on level semantics for styling/behavior decisions
- Changes require frontend migration plan

**Filtering Rule:**
- Authority SHALL NOT return suggestions with confidence <40
- UI SHALL assume all received suggestions are actionable (even if D requires more review)
- Empty result array means: no matches found, not "matches filtered"

---

### UI Contract Guarantees

The Authority guarantees to UI:

1. **One Format**
   - All suggestions conform to SuggestionDTO schema
   - No need to handle multiple formats or type-check

2. **One Language**
   - `reason_ar` always in Arabic
   - No English reasons intermixed
   - `official_name` may be English (supplier data), but reason is Arabic

3. **One Confidence Scale**
   - Always 0-100 integer
   - Never float, never >100, never negative
   - Directly comparable across all suggestions

4. **One Level Mapping**
   - B/C/D levels consistent with confidence ranges
   - UI can trust level without recalculating

5. **Always Deduped by supplier_id**
   - Each supplier appears AT MOST once
   - UI does not need to deduplicate

6. **Sorted by Confidence DESC**
   - Top suggestion is highest confidence
   - UI can display in order received

7. **Stable Within Session**
   - Same input within session returns same results (unless new learning signal)
   - UI can cache for current record

The UI SHALL NOT:
1. Recalculate confidence or levels
2. Merge suggestions from multiple API calls (Authority provides unified result)
3. Apply its own filtering/blocking logic
4. Change reason text or styling based on source

---

## 7. STOP THE EXPANSION — CONSOLIDATION GUARANTEE

### Prohibition Policy (During Consolidation Phase)

**FORBIDDEN until unification complete:**

1. **Creating new suggestion services/subsystems**
   - No new classes that generate supplier suggestions independently
   - No new confidence scoring logic outside Authority
   - No new endpoints that bypass Authority

2. **Adding new confidence scales or level definitions**
   - No percentages alongside 0-100 scale
   - No letter grades (A/B/C/D) different from current semantics
   - No color schemes that differ from canonical UI contract

3. **Introducing new learning tables or caches**
   - No new tables for "smarter matching" unless approved as feeder signal
   - No new cache layers that could diverge from Authority

4. **Creating new UI variants for suggestions**
   - No alternate suggestion displays for different flows
   - No special badges/icons outside canonical contract

5. **Implementing "quick fixes" to subsystems**
   - No patches to Subsystems A/B/C/D that increase divergence
   - Bug fixes allowed only if they align with unification

**REQUIRED before adding new features:**
1. Demonstrate new feature as **signal feeder only**, not independent system
2. Document how Authority will consume signal
3. Define signal weight and collision rules
4. Update SuggestionDTO schema if new metadata fields needed (with version increment)

---

### Signal Feeder Integration Process

**If new signal type needed (e.g., "supplier reputation score"), MUST:**

1. **Define Signal Schema:**
   ```
   {
     signal_type: 'reputation_score',
     raw_strength: 0.0-1.0,
     metadata: {
       score_source: 'review_system',
       review_count: number,
       computed_at: timestamp
     }
   }
   ```

2. **Propose Weight:**
   - Where does this signal rank in weight hierarchy?
   - Does it replace existing signal or add to it?

3. **Define Collision Behavior:**
   - What if reputation score conflicts with alias match?
   - Does it boost confidence or act as tiebreaker only?

4. **Implement as Feeder:**
   - Create `ReputationSignalFeeder` class
   - Returns signal array, does NOT return suggestions
   - Authority calls feeder, aggregates with other signals

5. **Update Authority Logic:**
   - Add reputation signal to weight table
   - Add to confidence formula (if contributes to score)
   - Update reason model to include reputation fragment (if shown to user)

6. **Update UI Contract:**
   - If metadata exposed to UI, update SuggestionDTO
   - If new reason fragment, update reason model documentation

**NEVER:**
- Create `ReputationSuggestionService` that returns its own suggestion list
- Bypass Authority by calling feeder from endpoint directly

---

### Deprecation of Subsystems (Post-Unification)

After Authority implemented and feeder refactoring complete:

**Phase 1: Dual-Run (Verification)**
- Authority runs alongside legacy subsystems
- Log both outputs, compare results
- Monitor for regressions or missing signals
- Duration: 1 month production use

**Phase 2: Authority Primary**
- Switch endpoints to Authority
- Legacy subsystems callable for comparison only
- Alert if divergence detected
- Duration: 1 month

**Phase 3: Deprecation**
- Remove or archive legacy subsystem code
- Keep feeder logic only
- Update documentation to reflect Authority as sole source

**Services to Deprecate:**
- `LearningSuggestionService` → refactored into Authority or archived
- `SupplierCandidateService` → refactored into feeder modules or archived
- `ArabicLevelBSuggestions` → becomes `EntityAnchorFeeder` (name change, interface change)
- `SupplierLearningRepository.findSuggestions()` → becomes feeder method or removed

**Services to Retain (as feeders or data access only):**
- `LearningService` (writes learning data)
- Repositories (data access layer)
- Normalizer (shared utility)

---

### Governance Enforcement

**Code Review Checklist:**
- [ ] Does this PR create a new suggestion service? → REJECT
- [ ] Does this PR add confidence calculation logic outside Authority? → REJECT
- [ ] Does this PR introduce new suggestion format? → REJECT
- [ ] Does this PR modify UI contract without schema update? → REJECT
- [ ] Does this PR add signal feeder with integration plan? → REVIEW
- [ ] Does this PR fix bug in existing system aligning with unification? → APPROVE

**Architecture Review Board:**
- Any changes to Authority, feeders, or UI contract require approval
- Monthly review of consolidation progress
- Track fragmentation risk metrics:
  - Number of active suggestion code paths
  - UI format variants in production
  - Cache-live divergence rate

---

## 8. RISKS IF WE DO NOT UNIFY (FROM CURRENT LOGIC)

### Risk Catalog (Evidence-Based, No Fixes)

---

#### Risk 8.1: Exponential Alias Duplication

**Current Logic Enables:**
- Normalization changes orphan old aliases (Part 1, Section 5.1)
- No cleanup mechanism for orphaned entries
- Each normalization evolution creates +N new aliases per supplier
- No cap on duplicate creation

**Plausible Future State (12-24 months):**
```
supplier_alternative_names table:
- 500,000 total rows
- 100,000 active aliases (matched by current normalization)
- 400,000 orphaned aliases (never match, never cleaned)
- Query performance: Degraded (full table scans on LIKE queries)
- Database size: 10x larger than necessary
- Maintenance cost: Manual cleanup required
```

**Business Impact:**
- Slower suggestion response time
- Increased infrastructure cost
- Developer confusion (why so many aliases?)
- Data inconsistency hard to debug

---

#### Risk 8.2: Confidence Score Inflation/Deflation Spiral

**Current Logic Enables:**
- usage_count grows unbounded (no cap)
- Confirmation boost capped but usage not
- Popular suppliers accumulate infinite positive signals
- Rare suppliers stuck at base confidence

**Plausible Future Manifestation:**
```
Supplier A (frequently used):
- usage_count=500
- Suggestion confidence: 100 (always)
- Appears for MANY inputs (over-generalized)

Supplier B (rarely used but correct):
- usage_count=1
- Suggestion confidence: 70 (Level D)
- Appears weak despite being correct match
```

**Business Impact:**
- "Rich get richer" feedback loop
- Rare suppliers never gain confidence
- System bias toward popular suppliers
- Incorrect auto-selections for ambiguous inputs

---

#### Risk 8.3: Silent Wrong High-Confidence Suggestions

**Current Logic Enables:**
- First-learned alias locks supplier forever (Part 1, Rule 5.2)
- usage_count increments on every use
- No conflict detection when manual selection differs from alias

**Attack Scenario:**
```
T=0: User A mistakenly selects Supplier 5 for input "الوطنية"
     (Should have been Supplier 12)
T=1: Alias learned: "الوطنية" → Supplier 5, usage_count=1
T=10: 50 more users encounter "الوطنية"
      System suggests Supplier 5 (alias match, 100 confidence)
      Users accept without checking (high confidence)
      usage_count → 51
T=100: usage_count = 500
      Confidence = 100 + learning boosts
      Level = B (high confidence)
      
WRONG supplier suggested with MAXIMUM confidence
NO indication of error
NO recovery path
```

**Business Impact:**
- Financial errors (wrong supplier payments)
- Data integrity collapse (garbage in, garbage amplified)
- User trust destroyed when error discovered
- Manual correction required for 500+ guarantees

---

#### Risk 8.4: Inconsistent UX Leading to Wrong Human Decisions

**Current Logic Enables:**
- Different flows call different subsystems (Part 1, Section 0)
- Same supplier shows different confidence in different contexts
- Users build mental model based on inconsistent data

**User Confusion Scenario:**
```
User in Flow A (calls LearningSuggestionService):
- Input: "شركة النورس"
- Sees: Supplier X, 85% confidence, Level B, "تطابق مع كلمة مميزة"

Same User in Flow B (calls SupplierCandidateService):
- Input: "شركة النورس"
- Sees: Supplier X, 0.92 score, no level, source="fuzzy_official"

User's mental model:
- "Is 0.92 better or worse than 85%?"
- "Why was there a matched anchor before but not now?"
- "Can I trust this system?"

User decision:
- Abandons suggestion system
- Defaults to manual lookup every time
- System investment wasted
```

**Business Impact:**
- Low suggestion acceptance rate
- Users don't trust automation
- No efficiency gain from learning system
- Support overhead (users ask "why different?")

---

#### Risk 8.5: Debugging Nightmare (Cache-Live Divergence)

**Current Logic Enables:**
- supplier_learning_cache exists with unclear population
- Legacy code may query cache, new code queries live tables
- No invalidation triggers when live data changes

**Support Nightmare:**
```
User Report: "Yesterday I saw suggestion for Supplier A, today it's gone. Same input."

Investigation:
- Check cache: Supplier A present, effective_score=90
- Check live tables: Supplier A alias deleted (admin cleanup)
- Cache not invalidated
- Some code paths use cache (shows A), others use live (no A)

Resolution Time: 4 hours
Root Cause: Cache-live divergence
Fix: Manual cache clear
Prevention: ???
```

**Current system enables non-reproducible bugs:**
- Same input, different results based on code path
- Cannot reproduce in development (different cache state)
- Cannot debug with logs (divergence hidden in query layer)

**Business Impact:**
- Developer productivity loss
- User frustration (inconsistent behavior)
- Support ticket volume increase
- System reliability reputation damage

---

#### Risk 8.6: Maintenance Cost Explosion

**Current Logic Enables:**
- 5 parallel subsystems (Part 1, Section 0)
- Each with independent logic
- Bug in one subsystem may not exist in others
- Fix requires code changes in multiple places

**Maintenance Scenario:**
```
Bug Discovered: Normalization breaks on certain Unicode characters

Files to Fix:
1. ArabicNormalizer.php (source)
2. Normalizer.php (wrapper)
3. SupplierLearningRepository.php (calls normalize)
4. LearningRepository.php (does NOT call normalize - should it?)
5. SupplierCandidateService.php (calls normalize)
6. LearningSuggestionService.php (uses subsystems that normalize)

Test Files to Update:
1. ArabicNormalizerTest.php
2. Each subsystem's tests
3. Integration tests (5 different test suites)

Regression Risk:
- Fix in one place breaks another
- Not all subsystems updated consistently
- Tests pass but production fails (test coverage gaps)

Time to Fix: 3 days (should be 1 hour)
```

**Compounding Over Time:**
- Each new feature added to wrong subsystem
- Divergence grows
- Unified fix becomes impossible
- System rewrite required (months of work)

---

#### Risk 8.7: Data Loss Through Fragmentation

**Current Logic Enables:**
- Confirmations fragmented by raw input variants (Part 1, Section 5.2)
- True confirmation count hidden across multiple raw_supplier_name values
- System under-counts total learning

**Data Loss Example:**
```
Ground Truth:
- Supplier X confirmed by users 100 times total
- Input variants:
  * "شركة النورس": 40 confirmations
  * "شركة  النورس" (extra space): 30 confirmations  
  * "شركة النورس " (trailing space): 20 confirmations
  * "شركة النورس." (with period): 10 confirmations

System View (per variant):
- "شركة النورس": 40 confirmations → confidence boost +15
- "شركة  النورس": 30 confirmations → confidence boost +15
- etc.

IF AGGREGATED (should be):
- 100 confirmations → confidence boost +15 (capped)
- But true signal strength hidden

Loss:
- 60 confirmations effectively ignored
- User effort wasted (60 explicit confirmations lost)
- Confidence artificially low
```

**Business Impact:**
- Users repeatedly confirm same supplier (frustration)
- Learning system doesn't "learn" from all data
- ROI on user feedback collection: Near zero

---

#### Risk 8.8: Regulatory/Audit Compliance Failure

**Current Logic Enables:**
- Decision provenance scattered across multiple tables
- No single source of truth for "why was this supplier selected?"
- Conflicting confidence values cannot be explained

**Audit Scenario:**
```
Auditor: "Explain why Supplier X was auto-selected for Guarantee #12345"

System Query:
- supplier_decisions_log: Shows manual selection
- learning_confirmations: Shows 0 confirmations for exact raw input
- supplier_alternative_names: Shows alias match, usage_count=5
- supplier_learning_cache: Shows effective_score=90

Which is correct? All? None? Cannot determine.

Auditor Question: "Is 90 score same as 90% confidence? What's the difference?"

Answer: Cannot explain without code review.

Result: Non-compliant, manual audit required for 10,000+ guarantees
```

**Business Impact:**
- Audit failure
- Regulatory fines (if financial domain)
- Loss of customer trust
- Mandatory system overhaul under time pressure

---

## 9. UNKNOWN ZONES (MINIMUM ARTIFACTS NEEDED)

### 9.1 UNKNOWN: Cache Population Mechanism

**What We Don't Know:**
- When is `supplier_learning_cache` populated?
- What process computes `fuzzy_score`, `source_weight`, `effective_score`?
- Is there a cron job? Background worker? Manual admin action?
- How are triggers defined (if any)?

**Minimum Artifacts to Resolve:**
```
Required files:
1. Cron job definitions: /etc/cron.d/* or app/Console/Kernel.php (Laravel) or equivalent
2. Background worker code: app/Jobs/* or app/Workers/*
3. Admin panel code: app/Http/Controllers/Admin/*SupplierCache*
4. Cache population script: scripts/populate_learning_cache.php or similar
5. Database triggers: migrations/*_create_cache_triggers.sql

OR: Explicit documentation stating cache is DEPRECATED/UNUSED
```

**Impact on Charter:**
- If cache is populated via known mechanism → integrate as feeder or deprecate
- If cache is manual/ad-hoc → recommend deprecation
- If cache is unused → remove from codebase

---

### 9.2 UNKNOWN: Endpoint-to-Service Mapping

**What We Don't Know:**
- Which HTTP endpoints call which suggestion services?
- Do endpoints call one service or multiple?
- How are results merged if multiple services called?
- Which flows use which subsystems?

**Minimum Artifacts to Resolve:**
```
Required files:
1. API route definitions: routes/api.php or app/Http/routes.php
2. Controller code: app/Http/Controllers/*Supplier* or *Guarantee*
3. Service provider bindings: app/Providers/*ServiceProvider.php
4. Frontend API client code: public/js/*api* or resources/js/*

OR: API documentation showing endpoint → service flow
```

**Impact on Charter:**
- Defines which endpoints need refactoring to call Authority
- Identifies UI merge logic (if any) that needs removal
- Determines dual-run strategy (gradual migration vs big-bang)

---

### 9.3 UNKNOWN: Database Uniqueness Constraints

**What We Don't Know:**
- Does `supplier_alternative_names` have UNIQUE on `normalized_name` alone?
- OR UNIQUE on `(supplier_id, normalized_name)` pair?
- Does `supplier_learning_cache` have UNIQUE on `(normalized_input, supplier_id)`?

**Minimum Artifacts to Resolve:**
```
Required files:
1. Database schema file: storage/database/schema.sql or migrations/create_tables.sql
2. Migration history: migrations/*_create_supplier_*.sql or .php
3. Live database schema dump: mysqldump --no-data or sqlite3 .schema

OR: Database inspection query results
```

**Impact on Charter:**
- If normalized_name is UNIQUE → explains first-lock behavior, validates collision rules
- If (supplier_id, normalized_name) UNIQUE → allows shared aliases, different collision rules
- Determines whether current code behavior is bug or by-design

---

### 9.4 UNKNOWN: Historical Selections Query Reliability

**What We Don't Know:**
- What is actual structure of `guarantees.raw_data`?
- Is it valid JSON? Structured format? Free text?
- How often does JSON fragment matching fail or produce false positives?

**Minimum Artifacts to Resolve:**
```
Required data inspection:
1. Sample raw_data: SELECT raw_data FROM guarantees LIMIT 10
2. Data format documentation: docs/data-model.md or README
3. Schema definition for raw_data: migrations/*guarantees*.sql
4. Error logs showing match failures (if any)

OR: Refactor to use guarantee_decisions.supplier_id directly (already structured)
```

**Impact on Charter:**
- If JSON parsing unreliable → recommend deprecating historical selections signal
- If structured query possible → improve feeder implementation
- Determines weight to assign historical signal (lower if fragile)

---

### 9.5 UNKNOWN: UI Aggregation Logic

**What We Don't Know:**
- Does UI call multiple suggestion APIs and merge results?
- Or does backend already provide unified response?
- How does UI handle format inconsistencies currently?

**Minimum Artifacts to Resolve:**
```
Required files:
1. Frontend suggestion display component: resources/views/*suggestions* or public/js/suggestions.js
2. API client layer: public/js/api-client.js or resources/js/services/supplier-service.js
3. Supplier selection modal code: partials/supplier-suggestions.php (already reviewed, but need to see API calls)

OR: Network trace from browser showing API requests during supplier selection
```

**Impact on Charter:**
- If UI already merges → identifies deduplication logic to deprecate
- If backend provides unified → identifies service orchestration to refactor
- Determines where SuggestionDTO contract is enforced

---

END OF LEARNING UNIFICATION CHARTER (DRAFT)
