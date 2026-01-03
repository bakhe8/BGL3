# DATABASE ROLE DECLARATION

## GOVERNING DOCUMENT FOR DATA LAYER DISCIPLINE

**Status:** Authoritative Declaration  
**Type:** Governance & Domain Boundary Document  
**Scope:** Database Schema Semantics & Behavioral Authority  
**Authority:** Learning Unification Charter (Parts 0-3)  
**Applies To:** All backend logic interacting with supplier-related tables  
**Binding Status:** Constitutional - Changes require Charter amendment  

---

## PURPOSE (الغرض)

تهدف هذه الوثيقة إلى تحديد الدور الوظيفي والحاكمي لكل مكوّن في قاعدة البيانات،  
وإزالة أي غموض حول:

- **هل الجدول يمثل حقيقة؟**
- **أم إشارة؟**
- **أم قرارًا؟**
- **أم ذاكرة مؤقتة؟**

وذلك لضمان أن قاعدة البيانات:

✓ تخدم نظام تعلم موحد  
✓ تدعم Backend منضبط  
✓ تنتج واجهة بسيطة، واضحة، وقابلة للثقة  

---

## CORE PRINCIPLE — DATABASE IS NOT THE DECISION MAKER

### المبدأ الحاكم الأعلى:

> **قاعدة البيانات لا تتخذ قرارات.**  
> **قاعدة البيانات تخزّن حقائق وإشارات فقط.**

أي سلوك يوحي بأن:

- جدولًا ما **"يقرر"**
- أو **"يحجب"**
- أو **"يرجّح"**

**يُعد تجاوزًا لدور قاعدة البيانات**  
**حتى لو كان يعمل فعليًا.**

### What This Means:

**Database MAY:**
- Store facts (entities exist)
- Store signals (events happened)
- Store audit trails (what was decided)

**Database MUST NOT:**
- Compute final confidence
- Filter suggestions via embedded logic
- Suppress results via column values acting as switches
- Make mapping decisions permanent via constraints

**ALL DECISION AUTHORITY belongs to Learning Authority service, NOT to database.**

---

## ARTICLE 1: ROLE TAXONOMY

### 1.1 Definitions

**SIGNAL Table:**
- Stores RAW user actions, system observations, or external data
- Contains NO computed confidence, NO final decisions
- Data is IMMUTABLE after creation (append-only or versioned)
- Can be re-interpreted with new logic without data migration

**DECISION Table:**
- Stores FINAL outputs of Authority (suggestions shown to users)
- Contains computed confidence, ordering, presentation metadata
- Represents "what the system decided" not "what happened"
- Must include provenance (which signals contributed)

**ENTITY Table:**
- Stores domain objects (suppliers, users, guarantees)
- Contains identifying information, stable attributes
- NOT signals, NOT decisions - just facts about what exists

**AUDIT Table:**
- Immutable log of events for compliance/debugging
- READ-ONLY after write
- Never used for active learning or suggestion generation

**CACHE Table (DEPRECATED ROLE):**
- Pre-computed values for performance
- MUST be derivable from SIGNAL tables + Authority logic
- MUST NOT contain data that cannot be reconstructed
- Subject to invalidation and regeneration

---

### 1.2 Prohibited Hybrids

The following combinations are FORBIDDEN:

**SIGNAL + DECISION:**
- Table stores raw actions AND computed confidence
- Results in: Cannot re-score without data migration
- Example violation: `usage_count` acting as both accumulator (signal) and filter (decision)

**ENTITY + SIGNAL:**
- Table stores domain facts AND learning observations
- Results in: Entity changes corrupt learning history
- Example violation: Supplier name changes invalidate alias matches

**CACHE + AUTHORITY:**
- Cached values treated as source of truth
- Results in: Cache becomes decision-maker, not Authority
- Example violation: `effective_score` returned as final confidence

**DECISION + SIGNAL:**
- System decisions recycled as input signals
- Results in: Circular reinforcement, runaway confidence
- Example violation: Confirmations of system suggestions treated as independent signal

---

## ARTICLE 2: CURRENT TABLE ROLE ASSIGNMENTS

### 2.1 ENTITY Tables (APPROVED)

**`suppliers`**

**Declared Role:** ENTITY  
**Stores:** Canonical supplier registry  
**Current Usage:** Compliant  
**Required Disciplines:**
- supplier_id is STABLE (never changes for same supplier)
- official_name changes are schema evolution, not learning
- normalized_name (if exists) is DERIVED, not authoritative

**Verdict:** **COMPLIANT**

---

### 2.2 SIGNAL Tables (APPROVED)

**`learning_confirmations`**

**Declared Role:** SIGNAL  
**Stores:** User confirm/reject actions  
**Current Usage:** PARTIALLY NON-COMPLIANT  

**What IS Signal (Correct):**
- `action` ('confirm'/'reject')
- `supplier_id` (what user acted on)
- `guarantee_id` (context)
- `decision_time_seconds` (signal quality metadata)
- `created_at` (temporal context)

**What is NOT Signal (Violation):**
- `confidence` - This is Authority's DECISION, not user's signal
- `matched_anchor`, `anchor_type` - Authority's DECISION context

**Required Disciplines:**
- Append-only (NO updates)
- `raw_supplier_name` MUST be supplemented with `normalized_supplier_name` for efficient aggregation
- Metadata fields (confidence, matched_anchor) acceptable for AUDIT, FORBIDDEN for re-scoring

**Future Compliance Path:**
- Add `normalized_supplier_name` column
- Index `normalized_supplier_name` for Authority aggregation
- Document that confidence/anchor fields are AUDIT ONLY

**Verdict:** **SIGNAL with AUDIT metadata** (acceptable if metadata not used for aggregation)

---

**`guarantee_decisions`** (subset of guarantees relationship)

**Declared Role:** HISTORICAL SIGNAL  
**Stores:** Past supplier selections  
**Current Usage:** Under-utilized (JSON querying instead of structured)  

**What IS Signal:**
- `supplier_id` selected
- `guarantee_id` context
- Timestamp of decision

**Required Disciplines:**
- Structured queries ONLY (no JSON fragment matching)
- Signal quality weighting (recent > old)
- Never treated as equal authority to explicit confirmations

**Verdict:** **COMPLIANT** (if queried correctly)

---

### 2.3 HYBRID Tables (REQUIRES REMEDIATION)

**`supplier_alternative_names`**

**Declared Role:** **SIGNAL STORE** (currently HYBRID - non-compliant)  

**حكم حاكمي:**

> يمثل **إشارات لغوية وتاريخية**  
> لا يمثل: قبولًا، رفضًا، أو قرار تطابق نهائي

**تصريحات حاكمة:**

- **كل سجل = إشارة** (observation, not decision)
- **لا سجل ≠ رفض** (absence of signal ≠ negative decision)
- **انخفاض القيم = إشارة ضعف، لا قرار حجب** (low value = weak signal, NOT suppression decision)

**هذا الجدول لا يملك سلطة قرار.**

---

**What IS Signal (Correct):**
- `alternative_name` (raw input observed)
- `normalized_name` (normalized observation)
- `source` (provenance: how alias was learned)
- `created_at` (when first observed)

**What IS Decision (VIOLATION):**
- `usage_count` used as BOTH:
  - Signal accumulator (times used)
  - Decision filter (usage_count > 0 = show suggestion)
  - Confidence weight (higher count = higher confidence)

**What IS Mapping:**
- `supplier_id` linkage (input → supplier)

**Current Violation Mechanisms:**

1. **First-Learned Lock:**
   - Mapping (input → supplier_id) becomes PERMANENT on first write
   - Subsequent conflicts silently ignored
   - **Effect:** Decision baked into schema, not revisable by Authority

2. **usage_count Conflation:**
   - Incremented on use (signal)
   - Decremented on penalty (decision)
   - Filtered by value (decision)
   - **Effect:** Cannot separate "how many times used" from "should we show it"

**Required Separation:**

**SIGNAL portion:**
```
Table: supplier_input_observations
- raw_input
- normalized_input
- observed_supplier_id
- source (learning/import/manual)
- observed_at
- observer_context (guarantee_id, user_id, etc.)
```

**DECISION portion:**
```
Derived by Authority from signals:
- Which supplier to map to normalized input
- Whether to show suggestion
- What confidence to assign
```

**MAPPING portion:**
```
Table: supplier_canonical_aliases (admin-controlled)
- normalized_name
- supplier_id
- is_official (true for imports, false for learned)
- verified_at
- verifier (admin user)
```

**Interim Verdict:** **NON-COMPLIANT** (requires decomposition in Phase 1 of roadmap)

---

**`supplier_learning_cache`**

**Declared Role:** CACHE (should be)  
**Current Reality:** DECISION + AUTHORITY BYPASS  

**What SHOULD Be Cached:**
- Pre-computed Authority decisions for performance
- Clearly marked with computation timestamp
- Invalidated when signals change

**What IS Currently:**
- `effective_score` - OPAQUE decision (computation unknown)
- `block_count` - SUPPRESSION decision (dual mechanism with usage_count)
- `star_rating` - PRESENTATION decision (UI logic in data layer)
- `source_weight` - WEIGHTING decision (should be in Authority logic)

**Critical Violation:**
- Cache queried as ALTERNATIVE suggestion source
- NOT as accelerator for Authority
- Bypasses unified decision-making

**Required Disciplines:**

**Option A: Deprecate Entirely**
- Remove table
- Authority computes live
- Accept performance cost for correctness

**Option B: True Cache (Strict Rules)**
- Authority populates ONLY
- Includes `computed_at`, `computed_by_version`
- Invalidated on ANY signal change
- NEVER queried as alternative source
- Returns SuggestionDTO format (Authority's output)

**Interim Verdict:** **STRUCTURALLY MISALIGNED** (deprecate or strict cache discipline)

---

### 2.4 AUDIT Tables (APPROVED)

**`supplier_decisions_log`**

**Declared Role:** AUDIT  
**Stores:** Log of all supplier selections for compliance  
**Current Usage:** Compliant (not used for active learning)  

**What IS Audit:**
- `raw_input`, `normalized_input` (what was queried)
- `chosen_supplier_id` (what was selected)
- `decision_source` ('manual', 'auto', etc.)
- `confidence_score` (what confidence Authority assigned)
- `was_top_suggestion` (whether user accepted top or chose other)
- `decided_at` (timestamp)

**Required Disciplines:**
- Write-only (no updates)
- NOT queried for learning aggregation
- Used for compliance reports, debugging, analysis ONLY

**Verdict:** **COMPLIANT**

---

## ARTICLE 3: SIGNAL VS DECISION — HARD BOUNDARY

### القاعدة الصريحة:

> **لا جدول في قاعدة البيانات يُخزّن قرارًا نهائيًا قابلًا للتنفيذ.**

**القرار:**
- يُتخذ في Learning Authority
- يُشتق من مجموع الإشارات
- ولا يُخزّن كحقيقة ثابتة

**أي عمود:**
- يُستخدم كـ **filter نهائي**
- أو **suppression دائم**
- أو **"truth switch"** (قيمة تُحدد العرض/عدم العرض)

**يُعد تجاوزًا لهذا الإعلان.**

### Examples of Violations:

**VIOLATION:** `WHERE usage_count > 0` as suggestion filter
- This makes `usage_count` a DECISION (show/hide)
- Should be: Authority retrieves ALL signals, applies threshold

**VIOLATION:** `block_count > 0` as suppression switch
- This makes database the suppression authority
- Should be: Authority queries block signals, decides suppression

**VIOLATION:** First-learned alias locks mapping permanently
- This makes schema constraint a DECISION authority
- Should be: Authority handles conflicts, allows updates

---

## ARTICLE 4: PROHIBITED PATTERNS

### 4.1 Forbidden: Signal Treated as Decision

**Pattern:**
```sql
-- FORBIDDEN
SELECT * FROM signal_table WHERE signal_value > threshold
-- Signal value becomes decision filter
```

**Why Forbidden:**
- Signal threshold is Authority's decision
- Embedding in query bypasses Authority
- Cannot change threshold without schema/query change

**Correct Pattern:**
```sql
-- Authority retrieves ALL signals
SELECT * FROM signal_table WHERE context_matches

-- Authority applies threshold logic
filtered = signals.filter(s => authority.meetsThreshold(s))
```

---

### 3.2 Forbidden: Computed Values Without Provenance

**Pattern:**
```sql
-- FORBIDDEN
confidence_score INTEGER  -- No record of how computed
```

**Why Forbidden:**
- Cannot verify
- Cannot debug
- Cannot re-compute with new logic

**Correct Pattern:**
```sql
-- Include computation metadata
confidence_score INTEGER,
computed_by_version TEXT,  -- e.g., 'Authority-v2.1'
computed_at TIMESTAMP,
signal_snapshot JSON       -- Which signals contributed
```

---

### 3.3 Forbidden: Mutable Signals

**Pattern:**
```sql
-- FORBIDDEN
UPDATE signal_table SET signal_value = new_value
```

**Why Forbidden:**
- Loses history
- Cannot audit changes
- Creates temporal inconsistency

**Correct Pattern:**
```sql
-- Append new signal row
INSERT INTO signal_table (signal_value, observed_at, supersedes_id)
VALUES (new_value, NOW(), old_row_id)

-- OR: Use versioning
UPDATE with updated_at, version++
```

---

### 3.4 Forbidden: Cache as Source of Truth

**Pattern:**
```sql
-- FORBIDDEN
SELECT confidence FROM cache WHERE input = ?
RETURN to_user(confidence)
```

**Why Forbidden:**
- Cache may be stale
- Bypasses Authority
- No provenance

**Correct Pattern:**
```sql
-- Check cache
cached = cache.get(input)
IF cached AND cache.is_fresh(cached):
    RETURN cached
ELSE:
    result = Authority.compute(input, signals)
    cache.set(input, result)
    RETURN result
```

---

## ARTICLE 4: NORMALIZATION & IDENTITY DECLARATION

### 4.1 التطبيع (Normalization)

**حكم حاكمي:**

> **التطبيع يُعامل كـ إشارة مساعدة**  
> **لا يُعتبر حقيقة مستقلة**  
> **لا يُنشئ هوية جديدة**

**What This Means:**

- Normalization is a TOOL for matching, not a TRUTH
- `normalized_name` column is DERIVED, not authoritative
- Normalization algorithm changes do NOT create new entities
- Multiple normalization versions of same input = SAME signal

**Consequence:**

- Authority MUST aggregate signals across normalization variants
- Database SHOULD support normalization-aware queries
- Schema MUST NOT treat normalization as identity

---

### 4.2 الهوية (Identity)

**القاعدة:**

> **هوية المورد = `suppliers.id` فقط**

**أي اختلاف نصي:**
- لا يعني اختلاف هوية
- ولا يُنشئ مسار تعلم مستقل بذاته

**What This Means:**

- "شركة النورس" and "شركة  النورس" → SAME supplier (if mapped to same ID)
- Input variants → SAME learning history (aggregated by normalized form)
- Learning fragments due to normalization = SCHEMA FAILURE, not feature

---

## ARTICLE 5: AUTHORITY ALIGNMENT

### 5.1 سلطة التعلم (Learning Authority)

**حكم صريح:**

> **قاعدة البيانات:**
> - لا تحسب ثقة
> - لا ترتّب اقتراحات
> - لا تحجب نتائج

**كل ذلك: مسؤولية Learning Authority فقط**

### 5.2 Database Responsibilities

**Database MAY:**
- Store signals efficiently
- Index for fast retrieval
- Enforce referential integrity (foreign keys)
- Provide aggregation support (GROUP BY, COUNT)

**Database MUST NOT:**
- Embed confidence formulas (belongs in Authority)
- Apply suggestion filters (belongs in Authority)
- Make suppression decisions (belongs in Authority)
- Order results by "best match" (belongs in Authority)

### 5.3 UI CONSEQUENCE RULE

**قاعدة حاكمة:**

> **أي تعقيد ناتج عن تخزين البيانات**  
> **يجب أن يُمتص داخل Backend**  
> **ولا يُرحّل إلى الواجهة.**

**Explicitly:**

- Database complexity → Backend handles it → UI sees clean SuggestionDTO
- Database does NOT justify: Different UI colors, multiple badges, inconsistent explanations
- If UI is complex, problem is in Backend/Authority, NOT acceptable database behavior

---

## ARTICLE 6: ROLE COMPLIANCE RULES

### 6.1 Signal Table Requirements

**MUST:**
- Be append-only OR use versioning
- Include timestamp of observation
- Include context (who, where, when)
- Store RAW data (no pre-processing that loses information)

**MUST NOT:**
- Contain computed confidence
- Contain decision logic (filters, thresholds)
- Be updated (except for append or versioning)
- Be queried with decision logic embedded in SQL

**MUST SUPPORT:**
- Efficient aggregation by Authority
- Normalization-aware queries (if applicable)
- Filtering by context (time ranges, users, etc.)

---

### 4.2 Decision Table Requirements

**MUST:**
- Include provenance (signal IDs or snapshot)
- Include computation metadata (version, timestamp)
- Be clearly marked as Authority output
- Follow SuggestionDTO schema (if user-facing)

**MUST NOT:**
- Be recycled as signal input (circular reference)
- Be queried as alternative suggestion source
- Override signal data

**MUST SUPPORT:**
- Audit queries (what was decided when)
- Replay (re-compute decision from signals)

---

### 4.3 Entity Table Requirements

**MUST:**
- Have stable primary keys
- Separate mutable attributes from identity
- Not conflate entity changes with learning

**MUST NOT:**
- Store learning signals (separate table)
- Store decisions (separate table)

---

### 4.4 Cache Table Requirements (If Retained)

**MUST:**
- Include `computed_at` timestamp
- Include `computed_by` version identifier
- Be invalidatable (clear mechanism)
- Be regenerable from signals + Authority

**MUST NOT:**
- Contain data that cannot be recomputed
- Be queried as alternative authority
- Outlive signal changes without invalidation

---

## ARTICLE 5: MIGRATION FROM CURRENT STATE

### 5.1 Immediate Prohibitions (Phase 0)

Effective immediately upon Charter approval:

**FORBIDDEN:**
- Creating new tables that mix signals and decisions
- Adding computed columns to signal tables
- Using cache tables as decision authority
- Embedding decision logic in SQL queries

**REQUIRED:**
- All new tables MUST declare role in migration
- All new queries MUST respect role boundaries
- All new features MUST route through Authority

---

### 5.2 Tolerance for Legacy (Temporary)

During consolidation phases:

**TOLERATED (with documentation):**
- `supplier_alternative_names.usage_count` conflation (until Phase 2)
- `learning_confirmations` lack of normalized column (until Phase 1 extraction)
- `supplier_learning_cache` existence (until Phase 6 deprecation decision)

**NOT TOLERATED:**
- NEW hybrids
- NEW caches treated as authority
- NEW signal-decision conflation

---

### 5.3 Compliance Verification

**Automated Checks:**
```sql
-- Example: Verify no decision columns in signal tables
SELECT table_name, column_name 
FROM information_schema.columns
WHERE table_name IN (signal_tables)
  AND column_name IN ('confidence', 'score', 'effective_score');
-- Should return EMPTY
```

**Manual Review:**
- Quarterly audit of new tables against this declaration
- Architecture review for schema changes
- Compliance verification before Phase transitions

---

## ARTICLE 6: ROLE DECLARATION TABLE

### 6.1 Current State Declaration

| Table Name | Declared Role | Compliance Status | Notes |
|------------|---------------|-------------------|-------|
| `suppliers` | ENTITY | ✓ COMPLIANT | Stable, clean |
| `supplier_alternative_names` | SIGNAL+DECISION HYBRID | ⚠️ NON-COMPLIANT | usage_count conflation, requires decomposition |
| `learning_confirmations` | SIGNAL (with audit metadata) | ⚠️ PARTIAL | Needs normalized_supplier_name column |
| `supplier_decisions_log` | AUDIT | ✓ COMPLIANT | Write-only, not used for learning |
| `supplier_learning_cache` | CACHE (misused as AUTHORITY) | ✗ MISALIGNED | Deprecate or strict cache discipline |
| `guarantees` | ENTITY | ✓ COMPLIANT | Historical records |
| `guarantee_decisions` | HISTORICAL SIGNAL | ✓ COMPLIANT | If queried via structured SQL |

---

### 6.2 Future State Declaration (Post-Consolidation)

| Table Name | Target Role | Action Required |
|------------|-------------|-----------------|
| `suppliers` | ENTITY | No change |
| `supplier_input_observations` (NEW) | SIGNAL | Extract from alternative_names |
| `supplier_canonical_aliases` (NEW) | ENTITY | Admin-verified mappings only |
| `learning_confirmations` | SIGNAL | Add normalized_supplier_name |
| `supplier_decisions_log` | AUDIT | No change |
| `supplier_authority_suggestions` (NEW) | DECISION | Authority output log |
| `supplier_learning_cache` | DEPRECATED or STRICT CACHE | Decision pending Phase 3 |
| `guarantees` | ENTITY | No change |
| `guarantee_decisions` | HISTORICAL SIGNAL | No change |

---

## ARTICLE 7: ENFORCEMENT

### 7.1 Schema Change Process

**Before ANY table creation/modification:**

1. Declare role using this taxonomy
2. Verify no prohibited hybrids
3. Document compliance OR exception justification
4. Submit to Architecture Review Board

**No schema change without role declaration.**

---

### 7.2 Query Audit Process

**Before deploying queries that:**
- Mix signal and decision logic
- Treat cache as authority
- Embed thresholds in SQL

**MUST:**
- Refactor to separate signal retrieval from decision logic
- Move decision logic to Authority
- Document provenance

---

### 7.3 Violation Severity

**Critical (Block Deployment):**
- New signal+decision hybrid table
- Cache queried as alternative authority
- Mutable signal updates without versioning

**Major (Fix in Sprint):**
- Decision logic embedded in signal queries
- Computed values without provenance
- Missing role declaration

**Minor (Fix in Backlog):**
- Sub-optimal indexing
- Missing audit timestamps
- Documentation gaps

---

## ARTICLE 8: DECLARATION AMENDMENT

This declaration can be amended ONLY via:

1. Charter amendment process (Article 2.4 of Preamble)
2. Architecture Review Board approval
3. Update to this document with version increment
4. Communication to all stakeholders

**Individual developers, teams, or managers CANNOT unilaterally change role assignments.**

---

## DECLARATION SUMMARY

**This document establishes:**

✓ Clear role taxonomy (Signal/Decision/Entity/Audit/Cache)  
✓ Table-by-table role assignments  
✓ Prohibited hybrid patterns  
✓ Compliance requirements per role  
✓ Migration path from current state  
✓ Enforcement mechanisms  

**Purpose:**

Serve as **THE BRIDGE** between Charter principles and database implementation.

No implementation work proceeds without respecting these role boundaries.

---

---

## FINAL STATEMENT

### What This Declaration Does NOT Aim To Do:

❌ إعادة تصميم قاعدة البيانات  
❌ تقليل قدراتها  
❌ تعقيد العمل عليها  

### What This Declaration AIMS To Do:

✓ **تحرير قاعدة البيانات من عبء القرار**  
✓ **جعلها أساسًا ثابتًا**  
✓ **لنظام تعلم موحد**  
✓ **ومنضبط**  
✓ **وقابل للثقة**

---

**The database is freed from decision-making responsibility.**  
**The database serves as stable foundation.**  
**The database enables, not dictates.**

This is the path to:
- Unified learning system
- Disciplined backend
- Calm, clear, trustworthy user interface

---

**END OF DATABASE ROLE DECLARATION**

**Version:** 1.0  
**Effective Date:** Upon Charter Approval  
**Authority:** Learning Unification Charter  
**Amendment Process:** Charter Article 2.4  
