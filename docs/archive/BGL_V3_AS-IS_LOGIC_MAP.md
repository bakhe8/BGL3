# BGL SYSTEM V3.0 - COMPLETE LOGIC MAP
## SYSTEM ARCHITECTURE & BUSINESS LOGIC FORENSICS ANALYSIS

> **Analysis Date:** 2025-12-26  
> **System Version:** 3.0  
> **Analysis Type:** Production Behavior Forensics (AS-IS, Not Corrective)

---

##  1) DOMAIN ENTITIES & STATES

### 1.1 GUARANTEE (Primary Entity)

**Storage:** `guarantees` table

**States/Lifecycle:**
- **RAW (Implicit Initial):** Freshly imported/created, no decision exists
  - Identifiable by: No row in `guarantee_decisions` for this `guarantee_id`
  - Transition: Created via import or manual entry
  
- **PENDING:** Decision record exists but incomplete
  - Identifiable by: `guarantee_decisions.status = 'pending'` OR `supplier_id IS NULL` OR `bank_id IS NULL`
  - Can occur: After partial save, after AI match failure, after decision update that removes fields
  
- **APPROVED:** Both supplier and bank resolved
  - Identifiable by: `guarantee_decisions.status = 'approved'` AND `supplier_id NOT NULL` AND `bank_id NOT NULL`
  - Source of status: Calculated in `save-and-next.php` line 241: `($supplierId && $bankId) ? 'approved' : 'pending'`
  
- **READY:** (Synonym for APPROVED in some contexts)
  - Ambiguity: Code uses both 'approved' and 'ready' interchangeably
  - Example: `DecisionService.php` line 50 defaults to 'ready'
  - Example: `SmartProcessingService.php` line 153 saves as 'approved'
  - **LOGIC CONFLICT:** No single source of truth for which string represents "complete"
  
- **EXTENDED:** Expiry date has been extended (+1 year)
  - Status transition: NOT recorded in `guarantee_decisions.status`
  - Evidence: Only tracked via `guarantee_actions` table with `action_type = 'extension'`
  - Side effect: `raw_data.expiry_date` is mutated directly (extend.php line 51-54)
  - **SILENT OVERRIDE:** Status may still show 'approved' even after extension
  
- **RELEASED:** Guarantee has been released (final state)
  - Storage: `guarantee_decisions.status = 'released'`
  - Lock mechanism: `is_locked = 1`, `locked_reason = 'released'` (ActionService.php line 103)
  - Irreversibility: Once locked, cannot be modified (enforced in DecisionService.php line 42-43)
  - **HACK WORKAROUND:** ActionService line 41 calls `voidReleases()` to allow extensions after release by voiding historical actions
  
- **REDUCED:** Amount has been reduced
  - Status transition: NOT recorded in `guarantee_decisions.status`
  - Only tracked in `guarantee_actions` table
  - Side effect: `raw_data.amount` is mutated

**Data Sources (Competing Truth):**
1. **Raw Data (`raw_data` JSON):** Original imported values
   - Fields: supplier, bank, guarantee_number, amount, expiry_date, issue_date, type, contract_number
   - Mutability: Modified directly by extend/reduce actions
   
2. **Decision Table (`guarantee_decisions`):** Resolved/normalized data
   - Fields: supplier_id, bank_id, status, is_locked
   - Source of Truth: For current resolved identities
   
3. **Actions Table (`guarantee_actions`):** Historical modifications
   - Evidence of: Extensions, releases, reductions
   - NOT queried to determine current state in most code paths
   
**Silent State Transitions:**
- Import → decision via `SmartProcessingService` (auto-match if confidence >90% supplier, >80% bank)
- Decision exists → locking via release action
- Extension can occur on "released" guarantee via action voiding hack

---

### 1.2 SUPPLIER

**Storage:** `suppliers` table

**States:**
- **CONFIRMED:** `is_confirmed = 1` (UNKNOWN what sets this)
- **UNCONFIRMED:** `is_confirmed = 0` (default)

**Identity Fields:**
1. `official_name` - Display name
2. `normalized_name` - Lowercase, no special chars, UNIQUE constraint
3. `supplier_normalized_key` - UNKNOWN purpose, UNIQUE constraint

**Creation Paths:**
1. **Auto-created during** `save-and-next.php` lines 67-82:
   - Exact match by `official_name`
   - Normalized match by `normalized_name`
   - If no match: INSERT new supplier with `normalized_name = mb_strtolower($supplierName)`
   - Race condition trap: catch exception, retry SELECT (line 75-81)
   
2. **Seeded via migration:** UNKNOWN initial suppliers exist

**Learning Artifacts:**
-`supplier_alternative_names`: Alias mappings learned from decisions
  - Created by: `LearningService.learnAlias()` on manual decisions (line 43)
  - Condition: Only when `source === 'manual'`
  - Uniqueness: `normalized_name` column (if duplicate exists, silently ignored line 92)
  - Usage tracking: `usage_count` incremented each time alias used

---

### 1.3 BANK

**Storage:** `banks` table

**States:**
- **CONFIRMED:** `is_confirmed = 1`
- **UNCONFIRMED:** `is_confirmed = 0`

**Identity Fields:**
1. `official_name` - Display name
2. `short_code` - UNIQUE, generated if missing (save-and-next.php line 118)
3. `normalized_name` - UNIQUE

**Creation Paths:**
1. **Auto-created during save-and-next.php** lines 98-135:
   - Similar dual-match strategy (exact then normalized)
   - Short code generation: `strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $bankName), 0, 10))`
   - Fallback if too short: `'BNK_' . rand(100, 999)` (line 119)
   - **BUG POTENTIAL:** Random short codes could theoretically collide

**No Learning System:** Banks do NOT have alternative names or usage tracking (asymmetric to suppliers)

---

### 1.4 GUARANTEE DECISION

**Storage:** `guarantee_decisions` table

**Relationship:** 1-to-1 with `guarantees` (one decision per guarantee)

**Key Fields:**
- `status`: 'pending', 'approved', 'ready', 'released' (string, NOT enum)
- `supplier_id`, `bank_id`: Foreign keys (nullable)
- `decision_source`: 'manual', 'auto', 'ai_match' (no validation)
- `confidence_score`: Float (nullable), used for AI decisions
- `is_locked`: Boolean (default 0)
- `locked_reason`: Text (set on release)
- `manual_override`: Boolean (always TRUE in save-and-next.php line 57)
- `decided_by`, `last_modified_by`: Text fields (user attribution)

**Lifecycle:**
1. Created via `REPLACE INTO` in save-and-next.php line 243-255
   - **REPLACE semantics:** Deletes existing row if `guarantee_id` matches, then inserts
   - Side effect: `id` changes on each save
   - Historical decisions: Lost (no versioning)
   
2. Updated via repository `createOrUpdate()` which does explicit UPDATE

**Lock Mechanism:**
- Set by: `ActionService.issueRelease()` line 103
- Effect: `DecisionService.save()` throws exception if locked (line 42-43)
- **BYPASS:** No lock check in `save-and-next.php` direct SQL path

---

### 1.5 TIMELINE/HISTORY EVENTS

**Storage:** `guarantee_history` table

**Event Types (from code analysis):**
- `import`: Initial creation (LE-00)
- `reimport`: Duplicate guarantee number (LE-00 variant)
- `modified`: Data changes (UE-01, UE-02, UE-03)
- `status_change`: Pending ↔ Approved transitions (SE-01, SE-02)
- `release`: Release action (UE-04)
- `decision`: UNKNOWN if used (legacy?)

**Event Structure:**
```json
{
  "event_type": "string",
  "snapshot_data": "JSON of guarantee state BEFORE change",
  "event_details": {
    "changes": [
      {
        "field": "supplier_id | bank_id | expiry_date | amount | status",
        "old_value": "...",
        "new_value": "...",
        "trigger": "manual | ai_match | extension_action | reduction_action | release_action"
      }
    ],
    "reason": "optional metadata"
  },
  "created_by": "User | System | النظام"
}
```

**Recording Discipline:**
- **Intended:** All mutations recorded via `TimelineRecorder`
- **Actual:** Multiple code paths bypass timeline:
  - Direct SQL updates to `guarantees.raw_data`
  - Historical action voiding (ActionService.php line 41)

**Import Event Rule (LE-00):**
- Code comment: "Event 1 only... No event can precede it" (TimelineRecorder.php line 269)
- Enforcement: `recordImportEvent()` checks for existing events and returns false (line 264-273)
- **CONTRADICTION:** Multiple imports for same guarantee should trigger reimport event, but save-and-next.php doesn't call this

---

###1.6 ACTIONS (Extensions, Reductions, Releases)

**Storage:** `guarantee_actions` table

**Fields:**
- `action_type`: 'extension', 'reduction', 'release'
- `action_status`: 'pending', 'issued', 'voided'
- `previous_expiry_date`, `new_expiry_date`: For extensions
- `previous_amount`, `new_amount`: For reductions
- `release_reason`: Text for releases

**Workflow:**
1. Create action (`action_status = 'pending'`)
2. Issue action (`action_status = 'issued'`)
   - Extension: Updates `raw_data.expiry_date`
   - Release: Locks decision + updates status
3. (Hack) Void action (`action_status = 'voided'`)
   - Used to unblock extensions after release

**Source of Truth Confusion:**
- Current expiry: `raw_data.expiry_date` (mutated)
- Extension history: `guarantee_actions` (may be voided)
- NO explicit "current vs historical" distinction

---

## 2) COMPLETE DECISION INVENTORY

### DECISION POINT 1: Import Entry (Excel or Manual)

**Location:** `ImportService.importFromExcel()`, `ImportService.createManually()`

**Inputs:**
- Excel file OR manual form data
- User identifier (`importedBy`)

**Preconditions:**
- Excel: File must exist, ≥2 rows
- Manual: Required fields present (guarantee_number, supplier, bank, amount, expiry_date, contract_number)

**Branches:**

**Branch A: Validation Success**
1. Parse/normalize data
2. Create `Guarantee` model with `raw_data` JSON
3. Insert into `guarantees` table
4. **NO DECISION CREATED** (guarantee is in RAW state)
5. Side effect: `imported_at` timestamp set

**Branch B: Validation Failure (Missing Fields)**
- Skip row (Excel) or throw exception (Manual)
- Log to `$skipped` array (Excel only)
- **NO DATABASE MUTATION**

**Branch C: Excel Header Detection Failure**
- Test first 5 rows for supplier & bank columns (ImportService.php line 55-64)
- If not found: Throw exception "لم يتم العثور على عمود المورد أو البنك"
- **ENTIRE IMPORT FAILS**

**Branch D: Duplicate Guarantee Number**
- UNKNOWN behavior  - no explicit duplicate check in ImportService
- **ASSUMPTION:** Database allows duplicates (no UNIQUE constraint visible in schema)

**Silent Defaults:**
- `type`: Defaults to 'ابتدائي' (line 120)
- `import_source`: Set to 'excel_YYYYMMDD_HHIISS' or 'manual_entry'

**Assumptions:**
- Excel column names contain keywords like "supplier", "المورد", "bank", "البنك"
- Dates can be parsed via `strtotime()` or Excel serial numbers
- Amounts may contain commas or "SAR"/"ريال" (stripped)

---

### DECISION POINT 2: Smart Processing (Auto-Match AI)

**Location:** `SmartProcessingService.processNewGuarantees()`

**Trigger:** Called after import (UNKNOWN where - no explicit call found in import flow)

**Inputs:**
- Guarantees with NO decision record (LEFT JOIN IS NULL, line 57)
- Limit: 500 at once

**Preconditions:**
- `raw_data.supplier` AND `raw_data.bank` must be non-empty (line 76)

**Decision Logic:**

**Sub-Decision 2.1: Supplier Match**
1. Call `LearningService.getSuggestions(supplierName)`
2. If top suggestion.score >= 90: Accept (line 90)
3. Else: supplier_id remains null

**Sub-Decision 2.2: Bank Match**
1. Call `BankLearningRepository.findSuggestions(bankName)`
2. If top suggestion.score >= 80: Accept (line 105)
3. Else: bank_id remains null

**Sub-Decision 2.3: Conflict Check**
1. Call `ConflictDetector.detect()` with both candidate lists
2. If conflicts non-empty: BLOCK auto-approval (line 131)

**Branch A: Both Matched + No Conflicts**
1. Insert into `guarantee_decisions` with `status = 'approved'` (line 153)
2. Log 3 timeline events: supplier auto-match, bank auto-match, approval (lines 163-192)
3. Increment `stats['auto_matched']`

**Branch B: Partial Match OR Conflicts Detected**
- NO decision created
- Guarantee remains in RAW state
- **SILENT:** No notification to user

**Assumptions:**
- Supplier score >=90% is "safe"
- Bank score >=80% is "safe" (asymmetric thresholds)
- Conflict detector is comprehensive (returns all ambiguities)

**Edge Cases:**
- Empty supplier/bank names: Skipped (line 76)
- All guarantees pending: Processes up to 500 (line 59)
- **UNKNOWN:** When/if this is called automatically vs manually

---

### DECISION POINT 3: Supplier Candidate Generation

**Location:** `SupplierCandidateService.supplierCandidates()`

**Inputs:**
- Raw supplier name (from Excel/manual entry)

**Normalization:**
- Convert to lowercase
- Remove special characters: `/[^\p{L}\p{N}\s]/u`
- Collapse whitespace
- Result: `normalized_name`

**Candidate Sources (in priority order):**

**Source 1: Learning Cache**
- Query: `supplier_suggestions` table WHERE `normalized_input = $normalized`
- If `source = 'learning' OR 'user_history'`: Add with `score = 1.0` (line 122-136)
- **ASSUMPTION:** Cache is pre-populated (service doesn't populate it)

**Source 2: Overrides**
- Query: `supplier_overrides` table (via `SupplierOverrideRepository`)
- Fuzzy match: Calculate similarity between normalized input and `override_name`
- Score threshold: >= `MATCH_REVIEW_THRESHOLD` (default 0.7, line 149)
- Weighted score: `scoreRaw * WEIGHT_OFFICIAL` (line 161)

**Source 3: Official Suppliers**
- Query: ALL suppliers (cached in memory, line 140-142)
- For each supplier:
  - Calculate similarity: exact (1.0), starts-with (0.85), contains (0.75), Levenshtein, token overlap
  - Use max similarity as `score_raw`
  - Apply threshold and weighting
  - Categorize: 'exact' (>=1.0), 'fuzzy_strong' (>=0.9), 'fuzzy_weak' (<0.9)
  
**Source 4: Alternative Names (Exact)**
- Query: `supplier_alternative_names` WHERE `normalized_name = $normalized`
- Score: 1.0 * `WEIGHT_ALT_CONFIRMED`

**Source 5: Alternative Names (Fuzzy)**
- Query: ALL alternative names (via `allNormalized()`)
- Fuzzy match: Same algorithm as official suppliers
- Threshold: >= `MATCH_WEAK_THRESHOLD` (default 0.8)

**Filtering:**
- **Blocked IDs:** Remove candidates in `blockedIds` list (from `supplier_suggestions.block_count`, line 117)
- **Threshold:** Remove if `score_raw < MATCH_REVIEW_THRESHOLD` (0.7)
- **Deduplication:** Keep best score per `supplier_id` (line 260-266)
- **Limit:** Top 20 candidates (line 272-275)

**Outcome Branches:**

**Branch A: Perfect Match (score = 1.0)**
- Single supplier with exact normalized name match
- Auto-accept eligible (if no conflicts)

**Branch B: Strong Fuzzy (score >= 0.9)**
- High confidence match
- Auto-accept eligible

**Branch C: Weak Fuzzy (0.7 <= score < 0.9)**
- Requires human review
- Will appear in suggestions but not auto-accepted

**Branch D: No Candidates (score < 0.7 for all)**
- Returns empty array
- Forces manual supplier creation OR manual selection from full list

**Silent Behaviors:**
- Learning cache checked but NEVER populated by this service
- All suppliers loaded into memory (performance issue if >>1000 suppliers)
- Normalized name collisions: First match wins

---

### DECISION POINT 4: Conflict Detection

**Location:** `ConflictDetector.detect()`

**Inputs:**
- Supplier candidates array
- Bank candidates array
- Record context (raw supplier name, raw bank name)

**Conflict Rules:**

**Rule 1: Missing Raw Data**
- If `raw_supplier_name` empty: "لا يوجد اسم مورد خام"
- If `raw_bank_name` empty: "لا يوجد اسم بنك خام"

**Rule 2: Close Scores (Multiple Strong Candidates)**
- If top 2 suppliers differ by < `CONFLICT_DELTA` (default 0.05): "مرشحا مورد متقاربان"
- If top 2 banks differ by < `CONFLICT_DELTA`: "مرشحا بنك متقاربان"

**Rule 3: Low Confidence Alternative/Override**
- If top supplier is `source = 'alternative'` AND `score < MATCH_AUTO_THRESHOLD`: "يحتاج مراجعة"
- If top supplier is `source = 'override'` AND `score < 0.9`: "راجع المدخلات"

**Rule 4: Override Conflict**
- If override exists but is NOT top candidate: "تحقق من التعارض"
- **LOGIC:** Assumes override should always win (not enforced)

**Rule 5: Normalization Failure**
- If normalized supplier < 3 characters: "التطبيع أرجع قيمة قصيرة"
- If normalized bank < 3 characters: Same
- If raw input < 3 characters after trim: "اسم قصير جداً"

**Outcomes:**
- Returns array of conflict messages (empty if none)
- **NO BLOCKING:** Caller decides whether to block based on presence of conflicts

**Assumptions:**
- Delta of 0.05 (5%) is meaningful difference
- Short names (<3 chars) are always problematic
- Overrides are always correct (but not enforced)

---

### DECISION POINT 5: Manual Save Decision

**Location:** `api/save-and-next.php`

**Inputs (from POST JSON):**
- `guarantee_id`: Required
- `supplier_id`: Optional (may be null)
- `bank_id`: Optional (may be null)
- `supplier_name`: Required
- `bank_name`: Required
- `current_index`: For pagination

**Mismatch Safeguard (Lines 37-47 for supplier, 87-95 for bank):**

**Sub-Decision 5.1: ID/Name Consistency Check**
- If `supplier_id` AND `supplier_name` both present:
  - Query: SELECT official_name WHERE id = supplier_id
  - Compare: Normalized DB name vs Normalized input name
  - If mismatch: Set `supplier_id = null` (trust name over ID)
  - **SILENT:** No error, user not notified

**Sub-Decision 5.2: Supplier Resolution**

**Path A: supplier_id already valid**
- Use as-is

**Path B: supplier_id is null but supplier_name exists**
1. Try exact match: `official_name = $supplierName` (line 55-57)
2. Try normalized match: `normalized_name = mb_strtolower($supplierName)` (line 60-63)
3. If still null: **Auto-create new supplier** (line 67-82)
   - Generate `normalized_name = mb_strtolower($supplierName)`
   - INSERT with official_name and normalized_name
   - Get `lastInsertId()`
   - **RACE CONDITION TRAP:** Catch exception, retry SELECT (assumes another request created it)

**Sub-Decision 5.3: Bank Resolution**
- Identical logic to supplier
- Additional field: `short_code` generation (line 118-119)
  - Extract alphanumeric from bank name, uppercase, max 10 chars
  - If too short: Generate `'BNK_' . rand(100, 999)`

**Validation (Line 138-147):**
- If any of (guarantee_id, supplier_id, bank_id) is null: Return 400 error
- Error message includes which field is missing + any exception messages

**Change Detection (Lines 149-200):**

**Old State Determination:**
1. Query last decision: SELECT supplier_id, bank_id FROM guarantee_decisions (line 158)
2. If decision exists: Resolve old names via ID lookups
3. Else: Use raw_data values from guarantee (lines 168-169, 178-179)

**Change Comparison:**
- Supplier change: `trim($oldSupplier) !== trim($newSupplier)` (line 188)
- Bank change: `trim($oldBank) !== trim($newBank)` (line 198)
- Build Arabic change messages (line 189, 199)

**Timeline Recording (Lines 206-269):**

1. **Snapshot BEFORE** (line 215)
2. **Calculate status** (line 241): `($supplierId && $bankId) ? 'approved' : 'pending'`
3. **Save decision** via REPLACE INTO (line 243-255)
   - **CRITICAL:** Uses REPLACE which deletes old row
   - Fields: guarantee_id, supplier_id, bank_id, status, decided_at, decision_source='manual', created_at
4. **Record decision event** (line 265)
5. **Record status transition event** (line 269)

**Learning Feedback (Lines 271-289):**
- Try to learn mapping: raw supplier name → chosen supplier_id
- Calls `LearningService.learnFromDecision()` with:
  - `source = 'manual'`
  - `confidence = 100`
- **SILENT FAILURE:** Wrapped in try-catch, errors ignored

**Next Record Loading (Lines 291-359):**
- Increment index
- Query next guarantee by position in sorted ID list
- If no more records: Return "تم الانتهاء" message
- Return JSON with next record data + banks dropdown

**Edge Cases:**

**Case 1: Supplier name changed but ID not cleared**
- Safeguard catches mismatch, clears ID
- Triggers resolution logic
- **MAY CREATE DUPLICATE** supplier if name slightly different

**Case 2: Both supplier_id and bank_id null**
- Returns 400 error
- User must retry with valid selections
- **NO PARTIAL SAVE**

**Case 3: Decision already locked**
- **NO CHECK** in save-and-next.php
- REPLACE INTO succeeds
- **BYPASSES LOCK MECHANISM**

**Case 4: Concurrent saves**
- Race condition in auto-create supplier/bank
- retry logic may succeed or fail depending on timing

---

### DECISION POINT 6: Extension Action

**Location:** `api/extend.php`, `ActionService.createExtension()`

**Inputs:**
- `guarantee_id`

**Preconditions:**

**Check 1: Guarantee exists**
- If not: throw "Guarantee not found" (ActionService.php line 30)

**Check 2: Not currently released**
- Query `guarantee_decisions` for this ID
- If `status = 'released'`: throw "Cannot extend after release" (line 36-37)
- **HOWEVER:** Line 41 calls `voidReleases()` which may unblock
  - **SEQUENTIAL LOGIC:** Check happens AFTER void
  - **EFFECTIVE:** Extension CAN occur on released guarantees via voiding trick

**Check 3: Has expiry date**
- Read from `raw_data.expiry_date`
- If null/empty: throw "No expiry date found" (line 45)

**Calculation:**
- New expiry = current expiry + 1 year (line 49)
- **HARD-CODED:** No custom extension periods

**Workflow:**

1. **Void historical releases** (line 41)
   - Update `guarantee_actions` SET `action_status = 'voided'` WHERE action_type='release'
   - **SIDE EFFECT:** Release history still exists but marked voided

2. **Create extension action** (line 51-57)
   - Insert with `action_status = 'pending'`
   - Store previous_expiry_date and new_expiry_date

3. **Issue extension** (line 46 in extend.php)
   - Update action to `action_status = 'issued'`

4. **Update raw data** (extend.php lines 49-54)
   - Direct SQL: UPDATE guarantees SET raw_data = ? WHERE id = ?
   - Mutate `raw_data.expiry_date` to new value
   - **NO VERSIONING:** Old value only in action record

5. **Record timeline event** (line 57-62)
   - Event type: 'modified'
   - Changes: [{field: 'expiry_date', trigger: 'extension_action'}]

**Outcomes:**

**Branch A: Success**
- Action created with `action_id`
- Raw data updated
- Timeline event logged
- Return HTML for refreshed record

**Branch B: Released guarantee**
- Voiding allows extension to proceed
- **CONTRADICTION:** "Cannot extend after release" is false

**Branch C: No expiry date**
- Throw exception
- Return 400 error HTML

**Silent Behaviors:**
- Release lock NOT removed (only actions voided)
- Decision status remains 'released' (no automatic revert to 'approved')
- **INCONSISTENCY:** Guarantee is released + extended simultaneously

---

### DECISION POINT 7: Release Action

**Location:** `api/release.php`, `ActionService.createRelease()`

**Inputs:**
- `guarantee_id`
- `reason`: Optional text

**Workflow:**

1. **Check guarantee exists** (ActionService.php line 79-82)

2. **Create release action** (line 84-89)
   - `action_type = 'release'`
   - `release_reason = $reason ?? 'إفراج عن ضمان'`
   - `action_status = 'pending'`

3. **Issue release** (release.php line 46)
   - Calls `issueRelease($actionId, $guaranteeId)`
   - Updates action to `action_status = 'issued'`
   - **LOCKS DECISION:** (ActionService.php line 103)
     - UPDATE guarantee_decisions SET is_locked=1, locked_reason='released'

4. **Record timeline event** (release.php line 49)
   - Event type: 'release'
   - Changes: [{field: 'status', old: previous, new: 'released', trigger: 'release_action'}]

**Outcomes:**

**Branch A: Success**
- Decision locked
- Status becomes 'released'
- Timeline event logged
- **IRREVERSIBLE** (except via voiding hack)

**Branch B: No decision exists**
- Lock fails silently (UPDATE affects 0 rows)
- Action still created
- **INCONSISTENCY:** Released action without locked decision

**Silent Behaviors:**
- No check if already released
- Multiple release actions can exist
- Only latest releases matters for extension blocking

---

### DECISION POINT 8: Learning System (Supplier Alias)

**Location:** `LearningService.learnFromDecision()`

**Trigger:** Called after manual save (save-and-next.php line 282)

**Inputs:**
- `guarantee_id`
- `supplier_id`: Chosen supplier
- `raw_supplier_name`: Original Excel/manual input

**Preconditions:**
- Both supplier_id and raw_supplier_name must be non-empty (line 26)
- Supplier must exist in database (line 34-36)

**Decision Logic:**

**Sub-Decision 8.1: Should learn alias?**
- If `source === 'manual'`: Yes (line 42)
- Else: No
- **ASSUMPTION:** Auto-matched decisions don't teach new aliases

**Sub-Decision 8.2: Alias already exists?**
- Normalize raw name: lowercase, remove special chars, collapse spaces
- Query: SELECT FROM supplier_alternative_names WHERE normalized_name = $norm (line 90)
- If exists: Return early (line 92-93)
- **SILENT:** No usage count increment if alias exists

**Sub-Decision 8.3: Create alias**
- Insert: (supplier_id, alternative_name=$raw, normalized_name=$norm, source='learning', usage_count=1)
- **NO ERROR HANDLING:** Assumes unique constraint prevents duplicates

**Sub-Decision 8.4: Increment usage**
- Always called (line 47)
- UPDATE SET usage_count+1 WHERE supplier_id=$id AND normalized_name=$norm (line 73-77)
- **AFFECTS:** Only existing aliases
- **NO EFFECT:** If alias was just created (race condition)

**Sub-Decision 8.5: Log decision**
- Insert into `supplier_decisions_log` (line 109-126)
- Fields: guarantee_id, raw_input, normalized_input, chosen_supplier_id, chosen_supplier_name, source, confidence_score, was_top_suggestion, decided_at

**Outcomes:**

**Branch A: New alias learned**
- Alternative name created
- Usage count = 1 (initial)
- Will appear in future suggestions with score 1.0

**Branch B: Existing alias**
- No new row
- **BUG:** Usage count NOT incremented (line 73-77 won't match just-created row)

**Branch C: Auto decision**
- No alias learned
- Usage count still incremented (if alias exists)
- Decision logged

**Edge Cases:**

**Case 1: Normalized collision**
- Two different raw names normalize to same value
- First one creates alias
- Second one silently skipped
- **LOSS:** Second variant not tracked

**Case 2: Supplier resolution creates new supplier**
- Raw name → new supplier_id
- Alias maps raw name to its own ID
- **CIRCULAR:** Alias becomes self-reference (not useful)

---

## 3) COMPLETE OUTCOME SPACE (FINAL STATES)

### OUTCOME 1: Fully Processed Guarantee (Happy Path)

**Final State:**
- `guarantees.raw_data`: Original import data
- `guarantee_decisions`: status='approved', supplier_id=X, bank_id=Y, is_locked=0
- `guarantee_history`: Import event + Decision event + Maybe status transition
- `guarantee_actions`: Empty OR previous extensions/reductions
- `supplier_alternative_names`: May contain aliases learned

**Paths to Outcome:**

**Path 1A: Auto AI Match**
1. Import → Smart Processing → Both scores high (S>=90%, B>=80%)
2. No conflicts detected
3. Auto-create decision
4. Log 3 timeline events
5. **User never sees guarantee in manual review**

**Path 1B: Manual Save**
1. Import → Raw state
2. User navigates to record
3. Selects/creates supplier + bank
4. Saves decision
5. Learning service logs alias (if manual)
6. Timeline events recorded

**Path 1C: Partial Auto + Manual**
1. Import → Smart Processing → Only supplier matched (or only bank)
2. Partial decision created? **NO** (requires both)
3. User completes missing field
4. Saves decision

---

### OUTCOME 2: Pending Guarantee (Incomplete)

**Final State:**
- `guarantee_decisions`: status='pending' OR supplier_id=NULL OR bank_id=NULL
- Could also have NO decision record at all

**Paths to Outcome:**

**Path 2A: Low Confidence Auto-Match**
1. Import → Smart Processing
2. Supplier score < 90% OR Bank score < 80%
3. **NO decision created**
4. Guarantee stuck in raw state

**Path 2B: Conflicts Detected**
1. Import → Smart Processing
2. Both scores high BUT ConflictDetector returns messages
3. Auto-match blocked (SmartProcessingService line 131)
4. **NO decision created**

**Path 2C: Manual Save Failure**
1. User saves with only supplier (no bank)
2. **ERROR:** save-and-next.php line 138-147 returns 400
3. **NO MUTATION:** Decision not saved
4. User must retry

**Path 2D: User Skips**
1. User navigates through records without saving
2. **ASSUMPTION:** No auto-save
3. Guarantee remains in previous state

---

### OUTCOME 3: Extended Guarantee

**Final State:**
- `guarantee_decisions`: status='approved' (or 'released' if hack used)
- `guarantees.raw_data.expiry_date`: Updated to new date
- `guarantee_actions`: Extension action with action_status='issued'
- `guarantee_history`: Extension event (event_type='modified', field='expiry_date')

**Paths to Outcome:**

**Path 3A: Standard Extension (Non-Released)**
1. Guarantee in 'approved' state
2. User clicks extend
3. Validation passes
4. Raw data mutated
5. Action created + issued

**Path 3B: Extension After Release (Hack)**
1. Guarantee in 'released' state (is_locked=1)
2. User clicks extend
3. `voidReleases()` called (line 41)
4. All release actions marked 'voided'
5. Validation passes (status still 'released' but check on line 36 doesn't see active releases because actions voided)
6. **CONTRADICTION:** Decision is locked+released but also extended

---

### OUTCOME 4: Released Guarantee (Locked)

**Final State:**
- `guarantee_decisions`: status='released', is_locked=1, locked_reason='released'
- `guarantee_actions`: Release action with action_status='issued' (or 'voided')
- `guarantee_history`: Release event

**Paths to Outcome:**

**Path 4A: Standard Release**
1. Guarantee in 'approved' state
2. User clicks release
3. Action created + issued
4. Decision locked via `ActionService.issueRelease()`

**Path 4B: Release After Extension**
1. Guarantee has been extended
2. User clicks release
3. Same workflow, no special handling

**Path 4C: Re-Release After Voided Release**
1. Previous release voided (for extension)
2. User clicks release again
3. New release action created
4. **MULTIPLE RELEASES:** Historical releases (voided) + new active release

**Edge Case:** Release without Decision
1. Guarantee has no decision record
2. User clicks release
3. Release action created
4. Lock UPDATE affects 0 rows (no decision to lock)
5. **INCONSISTENT:** Action says 'released' but decision doesn't exist

---

### OUTCOME 5: Duplicate Supplier Created

**Final State:**
- Multiple rows in `suppliers` table with similar/identical official_name
- Different `id` values

**Paths to Outcome:**

**Path 5A: Normalization Collision**
1. User saves with supplier name "ABC Company"
2. No exact or normalized match found
3. Auto-create: INSERT (official_name='ABC Company', normalized_name='abc company')
4. Later, user saves "ABC  COMPANY" (extra space)
5. Normalizes to 'abc company' (same)
6. **BUT:** Exact match on 'ABC  COMPANY' fails
7. Auto-create: INSERT fails on UNIQUE(normalized_name)
8. Retry SELECT finds first supplier
9. **NO DUPLICATE** (race condition handled)

**Path 5B: Slight Variation**
1. User saves "ABC Co"
2. Auto-create: normalized_name='abc co'
3. User saves "ABC Company"
4. normalized_name='abc company' (different)
5. **BOTH EXIST:** Two suppliers for same entity

**Path 5C: Concurrent Requests**
1. Request A: Check for "XYZ Ltd"—not found
2. Request B: Check for "XYZ Ltd"—not found
3. Request A: INSERT XYZ Ltd
4. Request B: INSERT XYZ Ltd → UNIQUE violation
5. Request B: Retry SELECT → finds Request A's supplier
6. **NO DUPLICATE** (retry logic works)

---

### OUTCOME 6: Orphaned/Inconsistent Data

**State 6A: Action Without Decision**
- `guarantee_actions` has extension/release
- NO corresponding `guarantee_decisions` row
- **POSSIBLE if:** Release issued before any decision saved

**State 6B: Decision Without Guarantee**
- `guarantee_decisions.guarantee_id` doesn't exist in `guarantees`
- **IMPOSSIBLE:** Foreign key constraint (assumed from code, not visible in provided schema)

**State 6C: Timeline Events Incomplete**
- Extension/release happened
- NO corresponding `guarantee_history` event
- **POSSIBLE if:** Timeline recording failed (exception caught, ignored)

**State 6D: Voided Releases + Locked Decision**
- All `guarantee_actions` with type='release' are `voided`
- BUT `guarantee_decisions.is_locked = 1`
- **INCONSISTENT:** No active release but still locked

---

## 4) EDGE-CASE & FAILURE SCENARIO CATALOG

### EDGE CASE 1: Empty/Null Data

**Scenario 1.1: Null Expiry Date on Extension**
- Trigger: User extends guarantee with `raw_data.expiry_date = null`
- Code path: ActionService.php line 44-46
- Outcome: Throw exception "No expiry date found"
- Side effect: No action created, no timeline event

**Scenario 1.2: Null Supplier/Bank in Raw Data**
- Trigger: Import validation allows null (contradicts line 100-101 which checks empty)
- Smart Processing: Skips processing (line 76)
- Manual Save: User must provide names, IDs auto-resolved
- Outcome: Guarantee processable but not auto-matchable

**Scenario 1.3: Empty Conflict List**
- Trigger: ConflictDetector returns `[]`
- Smart Processing: Proceeds with auto-match (line 136)
- Outcome: Even if scores are borderline, auto-approval happens

---

### EDGE CASE 2: Conflicting Data Sources

**Scenario 2.1: Decision Status vs Action Status Mismatch**
- State: `guarantee_decisions.status = 'approved'` BUT `guarantee_actions` has active release
- Trigger: Extension voids releases but doesn't update decision status
- Effect: UI might show "Approved" but action log shows "Released (Voided)"
- **USER CONFUSION:** Which is the truth?

**Scenario 2.2: Raw Data vs Decision Data Divergence**
- State: `raw_data.supplier = 'ABC Corp'` but `decision.supplier_id` points to "XYZ Ltd"
- Trigger: Manual override OR learning taught wrong alias
- Effect: index.php line 155 displays official_name (decision wins)
- **LOSS:** Original import data hidden from user

**Scenario 2.3: Multiple Decision Sources**
- State: `decision_source = 'manual'` but `confidence_score = 95`
- Trigger: User manually confirmed an AI suggestion
- Effect: Cannot distinguish "user override" from "user accepted suggestion"

---

### EDGE CASE 3: Duplicates

**Scenario 3.1: Duplicate Guarantee Numbers**
- Trigger: Import same Excel twice
- Effect: Two rows in `guarantees` with same `guarantee_number`
- Timeline: Both get 'import' event (violates LE-00 uniqueness assumption)
- **NO ENFORCEMENT:** No unique constraint on guarantee_number

**Scenario 3.2: Duplicate Normalized Suppliers**
- Trigger: "ABC Company" and "ABC COMPANY" (different casing)
- Normalization: Both become 'abc company'
- Effect: Second INSERT fails on UNIQUE(normalized_name)
- Result: Second save uses first supplier's ID
- **MERGE:** Two names map to one supplier (may be intended)

**Scenario 3.3: Duplicate Decision Log Entries**
- Trigger: Save same decision multiple times
- Effect: Multiple rows in `supplier_decisions_log` with same guarantee_id
- Result: Usage statistics inflated
- **NO DEDUPLICATION:** Log is append-only

---

### EDGE CASE 4: Partial/Fuzzy Matches

**Scenario 4.1: 80% Match - Is It Enough?**
- Supplier score = 0.85 (between REVIEW=0.7 and AUTO=0.9)
- Effect: Appears in suggestions but not auto-accepted
- User decision: Accept or search manually
- **AMBIGUITY:** Is 85% "good enough" subjectively?

**Scenario 4.2: Multiple 90%+ Matches**
- Two suppliers both score 0.92
- Conflict detector: Score delta = 0.00 < 0.05
- Effect: Auto-match blocked
- **HUMAN REQUIRED:** Even for high scores

**Scenario 4.3: Typo in Input**
- Raw: "Acme Corporrrration" (extra 'r's)
- Normalized: "acme corporrrration"
- Fuzzy match: Fails to find "Acme Corporation" (Levenshtein ~0.88)
- Effect: Auto-create new supplier
- **DUPLICATE:** Typo creates new entity

---

### EDGE CASE 5: Timing/Sequence Issues

**Scenario 5.1: Save During Smart Processing**
- Thread A: Smart Processing creates decision for guarantee #123
- Thread B: User manually saves decision for #123
- Both: Execute REPLACE INTO
- Outcome: Last writer wins
- **LOSS:** One decision overwrites the other (no conflict detection)

**Scenario 5.2: Extend During Release**
- Thread A: Issues release (locks decision)
- Thread B: Starts extension (checks lock BEFORE Thread A commits)
- Thread B: Validation passes (no lock seen yet)
- Thread A: Commits lock
- Thread B: Tries to save extension—succeeds (no lock check in extend.php)
- **BYPASS:** Extension applied to locked guarantee

**Scenario 5.3: Learning Cache Staleness**
- Supplier alias created in request A
- Request B: Calls `getSuggestions()` which queries cache
- Cache: Not yet updated (if cache is separate system)
- Effect: New alias not used immediately
- **UNKNOWN:** Cache update mechanism not visible in provided code

---

### EDGE CASE 6: Cached/Stale State

**Scenario 6.1: In-Memory Supplier Cache**
- `SupplierCandidateService` caches all suppliers (line 140-142)
- New supplier created by concurrent request
- Cache: Still empty for this supplier
- Effect: Fuzzy match doesn't find new supplier
- **STALE:** Until service instance recreated

**Scenario 6.2: Learning Suggestions Cache**
- `supplier_suggestions` table (referenced but not populated in provided code)
- Effect: If stale, incorrect suggestions shown
- **UNKNOWN:** When/how cache is refreshed

---

## 5) HUMAN-IN-THE-LOOP MAP

### INTERVENTION POINT 1: Import Review (Implicit)

**Expected:** User reviews import results
**Enforced:** No - import returns success count but doesn't require confirmation
**Triggered by:** User clicking import button
**User Can:**
- See skipped rows (Excel import result)
- Cannot edit mid-import
- Cannot rollback (no transaction shown)

**Outcome:** Import is atomic per-row (individual try-catch), not atomic for file

**Bypass:**
- User can ignore skipped rows
- No warning if >50% skipped

---

### INTERVENTION POINT 2: Manual Decision Approval

**Expected:** User must explicitly save each guarantee's supplier/bank
**Enforced:** Yes for non-auto-matched guarantees
**Triggered by:** Guarantee in RAW or PENDING state

**User Can:**
- Select from suggestions (if any)
- Search all suppliers/banks (UNKNOWN - not shown in provided code)
- Type new supplier/bank name (auto-creates if no match)
- Skip to next record without saving

**Outcome:**
- Save: Decision created, learning happens, next record loaded
- Skip: No change, user manually navigates elsewhere

**Bypass:**
- Auto-match via Smart Processing (user never sees it)

---

### INTERVENTION POINT 3: Conflict Resolution

**Expected:** User resolves conflicts when auto-match blocked
**Enforced:** No - conflicts are detected but not surfaced to user in provided code
**Triggered by:** `ConflictDetector.detect()` returns non-empty array

**User Can:** **UNKNOWN**
- No UI code provided showing conflict messages
- **ASSUMPTION:** User sees suggestions but not conflict reasons

**Outcome:**
- User selects best match
- Conflict logged? **NO** - not stored

**Bypass:**
- User can select any candidate, ignoring conflict warnings
- No forced resolution workflow

---

### INTERVENTION POINT 4: Extension Approval

**Expected:** User reviews expiry date before extending
**Enforced:** Partially - user must click extend button
**Triggered by:** User action (explicit)

**User Can:**
- See current expiry date (in UI)
- Confirm extension (+1 year hard-coded)
- **CANNOT:** Choose custom extension period

**Outcome:**
- Extension applied immediately
- Raw data mutated
- Timeline logged

**Irreversible:** No undo, no revert (voiding is internal hack, not user-facing)

---

### INTERVENTION POINT 5: Release Approval

**Expected:** User confirms release action
**Enforced:** Yes - explicit user action required
**Triggered by:** User clicks release button

**User Can:**
- Enter release reason (optional text field)
- Confirm release
- See that guarantee will be locked

**Outcome:**
- Decision locked
- **IRREVERSIBLE** (no UI to unlock)

**Bypass:**
- None for user
- Code hack via `voidReleases()` for extensions

---

### INTERVENTION POINT 6: Override Creation

**Expected:** User explicitly creates supplier override mapping
**Enforced:** **UNKNOWN** - no code provided for override management
**Triggered by:** **UNKNOWN**

**User Can:**
- **ASSUMPTION:** Create override in separate admin UI
- Map raw name → official supplier

**Outcome:**
- Override stored in `supplier_overrides` table (referenced in code)
- Used in future matching with high priority

**Effect on Auto-Match:**
- If override exists with score >=90%, auto-match happens
- If override score <90%, conflict raised

---

## 6) HIDDEN COUPLING & LOGIC SHORTCUTS

### COUPLING 1: "If Alias Exists → Auto-Accept"

**Location:** `SupplierCandidateService` line 122-136

**Logic:**
- If `supplier_alternative_names` has exact normalized match
- Return as candidate with `score = 1.0`
- No further validation

**Assumption:**
- All aliases are correct
- User-taught aliases never wrong

**Risk:**
- One wrong manual mapping propagates to all future guarantees
- "ABC Corp" mislinked to "XYZ Ltd" once → always wrong

**Bypass:**
- User must manually intervene (suggest correct supplier)
- OR delete alias from database

---

### COUPLING 2: "Both IDs Present → Approved"

**Location:** `save-and-next.php` line 241

**Logic:**
```php
$statusToSave = ($supplierId && $bankId) ? 'approved' : 'pending';
```

**Assumption:**
- Having supplier_id + bank_id means guarantee is complete
- No validation of data quality
- No human review flag

**Risk:**
- Wrong supplier/bank selected by mistake
- Auto-approved without review

**Bypass:**
- Manual correction requires new save (overwrites)

---

### COUPLING 3: "Score >=90% → Skip Conflict Check"

**Location:** `SmartProcessingService` line 90, 105

**Logic:**
- If top supplier score >= 90% AND top bank >= 80%
- AND conflicts array is empty
- Auto-approve

**Assumption:**
- 90% is "safe enough"
- Conflict detector catches all ambiguities

**Risk:**
- False confidence (90% of what baseline?)
- Conflict detector misses edge case → wrong auto-match

**Hidden Dependency:**
- Conflict detector rules (threshold 0.05, name length >3, etc.)
- If those rules change, auto-match behavior changes

---

### COUPLING 4: "REPLACE INTO → History Lost"

**Location:** `save-and-next.php` line 243

**Logic:**
```sql
REPLACE INTO guarantee_decisions (...) VALUES (...)
```

**Effect:**
- If decision exists, DELETE then INSERT
- `id` column changes
- No version history

**Assumption:**
- Only current decision matters
- Historical decisions not needed

**Risk:**
- Audit trail incomplete
- Cannot trace who modified what when

**Workaround:**
- Timeline events provide partial history

---

### COUPLING 5: "Voiding Releases → Unblocks Extensions"

**Location:** `ActionService` line 41

**Logic:**
```php
$this->actions->voidReleases($guaranteeId);
```

**Effect:**
- Mark all release actions as 'voided'
- Extension validation passes (despite decision being locked)

**Assumption:**
- Voiding actions is equivalent to unlocking
- Decision lock is just advisory

**Risk:**
- Inconsistent state: is_locked=1 but can be modified
- **CONTRADICTION:** System says "locked" but allows changes

**Hidden Side Effect:**
- Multiple release/extend cycles create bloated action history
- All voided releases remain in database

---

### COUPLING 6: "Normalized Name Collision → Automatic Merge"

**Location:** Multiple (supplier creation, alias creation)

**Logic:**
- UNIQUE constraint on `normalized_name`
- Auto-create fails → retry SELECT finds existing

**Effect:**
- Different raw names map to same supplier
- "ABC Company", "ABC COMPANY", "ABC  Company" → one supplier

**Assumption:**
- Normalization correctly identifies duplicates
- Case/spacing differences are insignificant

**Risk:**
- Over-merging: "ABC Corp" and "ABC Corporation" might be different entities
- Under-merging: "ABC Co." and "ABC Company" NOT merged

---

### COUPLING 7: "Learning Only on Manual Decisions"

**Location:** `LearningService` line 42

**Logic:**
```php
if ($source === 'manual') {
    $this->learningRepo->learnAlias($supplierId, $rawName);
}
```

**Assumption:**
- Auto-matched decisions already learned (from AI training)
- Only user corrections are new knowledge

**Risk:**
- AI matches don't reinforce existing knowledge
- Usage counts not updated for auto decisions

**Effect:**
- Learning bias toward manually-reviewed guarantees
- Popular auto-matches never recorded

---

### COUPLING 8: "Empty Conflicts Array → Proceed"

**Location:** `SmartProcessingService` line 136

**Logic:**
```php
if ($supplierId && $bankId && empty($conflicts)) {
    $this->createAutoDecision(...);
}
```

**Assumption:**
- Empty conflicts = no problems
- Conflict detector is exhaustive

**Risk:**
- Conflict detector bug → false negatives
- New conflict type not detected → wrong auto-match

**Hidden Dependency:**
- ConflictDetector implementation
- Settings (CONFLICT_DELTA, MATCH_AUTO_THRESHOLD)

---

## 7) INTENDED vs ACTUAL LOGIC (WITHOUT FIXING)

### DISCREPANCY 1: "Locked Decisions Cannot Be Modified"

**Intended (DecisionService.php line 42-43):**
```php
if ($existing && $existing->isLocked) {
    throw new \RuntimeException("Cannot modify locked decision...");
}
```

**Actual (save-and-next.php line 243):**
```php
REPLACE INTO guarantee_decisions (...) VALUES (...)
```

**Outcome:**
- DecisionService path: Enforces lock
- save-and-next.php path: Bypasses lock
- **BOTH PATHS EXIST:** User can modify locked decision via certain UI flows

**Conditions Enabling Discrepancy:**
- Direct API call to save-and-next.php vs using DecisionService class
- No lock check in save-and-next.php before REPLACE

---

### DISCREPANCY 2: "Extensions Not Allowed After Release"

**Intended (ActionService.php line 36-37):**
```php
if ($decision && $decision->status === 'released') {
    throw new \RuntimeException("Cannot extend after release");
}
```

**Actual (ActionService.php line 41):**
```php
$this->actions->voidReleases($guaranteeId); // Called BEFORE check
```

**Outcome:**
- Check happens but voiding negates it
- Extensions ALWAYS succeed (hack built into intended path)
- Error message misleading: "Cannot extend after release" is false

**Conditions:**
- Voiding occurs before validation
- No separate "can extend?" permission check

---

### DISCREPANCY 3: "Import Event is First and Only"

**Intended (TimelineRecorder.php line 269):**
```php
// "Import... Event 1 only... No event can precede it"
// Check for existing events
if ($stmt->fetch()) {
    return false; // Event exists, can't record import
}
```

**Actual:**
- No code calls `recordImportEvent()` during import
- ImportService doesn't create timeline events
- **ASSUMPTION:** Smart Processing logs import? **NO**

**Outcome:**
- Import events may not exist at all
- Or multiple events created if guarantees re-imported
- **RULE NOT ENFORCED** in practice

**Conditions:**
- Disconnect between ImportService and TimelineRecorder
- No documented contract on who logs imports

---

### DISCREPANCY 4: "Status is 'approved' OR 'ready'"

**Intended:** Unclear (code uses both)

**Actual:**
- `save-and-next.php` saves `'approved'`
- `DecisionService` defaults to `'ready'`
- `SmartProcessingService` saves `'approved'`
- `GuaranteeDecision` model checks for `status === 'ready'` (line 37)

**Outcome:**
- Mixed statuses in database
- `isReady()` method may return false for 'approved' decisions
- **SEMANTIC DRIFT:** Two words mean same thing but code doesn't treat them as equivalent

**Conditions:**
- No enum or validation on status column
- Different code paths use different strings

---

### DISCREPANCY 5: "Supplier Auto-Creation is Safety Net"

**Intended:** Create supplier if unforeseen name appears

**Actual:**
- Every save WITHOUT supplier_id triggers auto-create attempt
- Even if suggestions exist BUT user typed new name
- Normalized collision → merges with existing supplier

**Outcome:**
- Users can create suppliers unknowingly
- "Search didn't work, I'll just type it" → duplicate supplier (if norm differs)
- OR unexpected merge (if norm matches)

**Conditions:**
- No "Are you sure?" prompt
- No differentiation between "no match" and "user prefers new entity"

---

### DISCREPANCY 6: "Learning Teaches Correct Mappings"

**Intended:** User corrections train system

**Actual:**
- User can select WRONG supplier
- Learning records it as truth
- Future auto-matches use wrong alias

**Outcome:**
- "Garbage in, garbage out"
- One mistake cascades to all similar names

**Conditions:**
- No validation that user selection is correct
- No confidence weighting for learned aliases
- No mechanism to flag/review learned aliases

---

### DISCREPANCY 7: "Timeline is Complete Audit Trail"

**Intended:** Every change logged (TimelineRecorder comments suggest strict discipline)

**Actual:**
- Direct SQL updates bypass timeline (extend.php line 53)
- Action voiding not logged as timeline event
- Learning alias creation not logged
- Auto-supplier creation not logged

**Outcome:**
- Timeline is partial, not complete
- Cannot reconstruct full history from guarantee_history alone
- Must cross-reference actions, decisions, aliases

**Conditions:**
- Timeline recording is opt-in per code path
- No centralized mutation layer that enforces logging

---

### DISCREPANCY 8: "Conflicts Block Auto-Matching"

**Intended (SmartProcessingService line 131):**
```php
$conflicts = $this->conflictDetector->detect(...);
```

**Actual:**
- Conflict messages generated but NOT stored
- NOT surfaced to user (no UI code provided)
- User never sees why auto-match failed

**Outcome:**
- Conflicts detected → auto-match blocked → guarantee pending
- User doesn't know WHY
- May re-attempt manual selection, hitting same conflict

**Conditions:**
- Conflict detection exists
- Conflict resolution workflow doesn't exist (or not in provided code)

---

## UNKNOWN GAPS (INSUFFICIENT EVIDENCE)

### UNKNOWN 1: Supplier Override Management
- How are overrides created? (No CRUD code seen)
- Who can create them? (Admin only? Any user?)
- Are overrides validated?

### UNKNOWN 2: Learning Cache Population
- `supplier_suggestions` table referenced but never written to in provided code
- Batch process? Triggered by cron?
- How often updated?

### UNKNOWN 3: Import Duplicate Handling
- guarantee_number has no UNIQUE constraint visible
- Are duplicates allowed?
- Is there de-duplication logic elsewhere?

### UNKNOWN 4: Bank Learning
- Suppliers have alternative names and learning
- Banks do NOT (no bank_alternative_names table)
- Why the asymmetry?
- Is bank matching less complex?

### UNKNOWN 5: Historical Decision Tracking
- REPLACE INTO deletes old decision
- Is there a decisions_history table?
- Are old IDs stored elsewhere?

### UNKNOWN 6: Usage Count Semantics
- `supplier_alternative_names.usage_count` incremented
- Used for what? (Scoring? Display?)
- Does it affect match ranking? (Not seen in provided code)

### UNKNOWN 7: Confirmed Suppliers/Banks
- `is_confirmed` column exists
- What sets it to 1?
- Does it affect matching priority?

### UNKNOWN 8: Workflow Trigger for Smart Processing
- `SmartProcessingService.processNewGuarantees()` exists
- When/how is it called?
- Automatic after import? Manual admin action? Cron job?

### UNKNOWN 9: Supplier Search UI
- Code shows suggestions displayed
- Can user search ALL suppliers if suggestions fail?
- Is there pagination? Filtering?

### UNKNOWN 10: Error Recovery
- If timeline recording throws exception (line 289 catches and ignores)
- Is there a separate error log?
- How are silent failures detected?

---

## MINIMUM ADDITIONAL ARTIFACTS TO REDUCE AMBIGUITY

To complete understanding of this system, the following would be needed:

1. **Database Schema DDL:** Full CREATE TABLE statements with all constraints, indexes, triggers
2. **Frontend JavaScript:** UI logic for supplier selection, conflict display, workflow navigation
3. **Cron/Background Jobs:** Any scheduled tasks (Smart Processing trigger, cache updates)
4. **Admin/Override Management UI:** How overrides are created and managed
5. **Configuration files:** All settings (thresholds, weights, limits)
6. **Historical Migrations:** To understand schema evolution and `is_confirmed` semantics
7. **Error logs/monitoring:** To see what failures occur in production
8. **User documentation:** To understand intended workflows and business rules
9. **Unit/Integration tests:** To reveal intended behavior via test assertions
10. **API documentation:** To understand all endpoints and their contracts

---

**END OF LOGIC MAP**
