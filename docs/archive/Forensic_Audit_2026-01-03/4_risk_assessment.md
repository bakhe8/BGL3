# RISK ASSESSMENT

## 🔴 HIGH-RISK AREAS

### RISK #1: Single Monolithic Entry Point (index.php - 2551 lines)

**Location**: `index.php`  
**Risk Type**: Architectural - Single Point of Failure  
**Severity**: CRITICAL

**Vulnerability**:
- 2551 lines mixing data access, business logic, and presentation
- Any syntax error crashes entire application
- No separation of concerns
- Difficult to test individual components
- Changes ripple unpredictably

**Failure Modes**:
- **PHP Parse Error**: Application completely down
- **Database Query Failure**: Page shows partial state or white screen
- **Logic Error**: Incorrect data displayed, hard to trace
- **Memory Exhaustion**: Large result sets could crash page

**Silent vs Visible**:
- Visible: Parse errors, fatal exceptions → white screen/500
- Silent: Logic errors → incorrect data displayed as if correct
- Silent: Query failures with try-catch → empty data, looks like "no records"

**Evidence of Risk**:
- Line 345-408: Timeline loading with fallback that hides errors
- Line 415-433: Try-catch wrapping notes/attachments → silent fail, empty arrays
- Line 456-471: Suggestion generation → silent fail on exception

**What Would Break First**:
- Large import → memory limit during suggestion generation (line 459)
- Database lock → timeout, partial page render
- Syntax error in embedded JavaScript (lines 1500+) → UI broken, data shown

**Impact Radius**: ENTIRE APPLICATION

---

### RISK #2: Global Database Singleton with No Failover

**Location**: `app/Support/Database.php`  
**Risk Type**: Infrastructure - No Resilience  
**Severity**: HIGH

**Vulnerability**:
- Singleton pattern with static instance (line 11)
- One failed connection = entire app down
- No retry logic
- No connection pooling
- No fallback database

**Failure Modes**:
- SQLite file locked by another process → PDOException → app crash
- File permissions changed → connection fails → JSON error or die()
- Disk full → writes fail → silent corruption or exceptions
- Database corrupted → reads fail → app unusable

**Silent vs Visible**:
- Visible: Initial connection failure → JSON error response or die() (line 39-42)
- Silent: Connection succeeds but database corrupted → unpredictable query failures
- Visible: Write failures typically throw exceptions

**Evidence**:
- Line 34-43: Catch PDOException, either JSON response or die() - no retry
- Line 19: Returns existing instance or fails - no fallback
- Global usage (TimelineRecorder, all repositories) - no null checks

**What Would Break First**:
- First database operation after corruption/lock
- Could be index.php load, could be API call
- Depends on user action timing

**Blast Radius**: ENTIRE APPLICATION

---

### RISK #3: Fragile JSON Query Pattern

**Location**: `app/Repositories/LearningRepository.php:51`, `GuaranteeDecisionRepository.php:218`  
**Risk Type**: Data Integrity - Brittle Query Logic  
**Severity**: HIGH

**Vulnerability**:
```php
$jsonFragment = '"supplier":"' . str_replace('"', '\"', $rawName) . '"';
WHERE g.raw_data LIKE '%' . $jsonFragment . '%'
```

**Risks**:
1. JSON format change breaks all queries
2. Special characters in supplier names could cause false positives/negatives
3. No SQL injection (PDO prepared), but fragile string matching
4. Full table scan (no index on JSON field) → performance degrades with scale
5. False positives if supplier name substring matches elsewhere in JSON

**Failure Modes**:
- **Format Change**: Developer reformats JSON (pretty print, key order) → queries miss records
- **Encoding Change**: UTF-8 normalization differences → mismatches
- **Partial Matches**: Supplier "شركة" matches "شركة النورس" AND "شركة الفجر"
- **Performance**: 10K+ records → query takes seconds → timeout

**Silent vs Visible**:
- Silent: Gradual performance degradation as data grows
- Silent: False positives/negatives in historical learning data → incorrect suggestions
- Visible: Timeout errors on very large datasets

**Evidence**:
- Comment in query_pattern_audit.md acknowledges this fragility
- No error handling for edge cases
- Recommendation exists to use JSON_EXTRACT, not implemented

**Impact Radius**: Learning System, Historical Analysis

---

### RISK #4: Dual Learning Systems (Unsynchronized)

**Location**: `LearningRepository.php`, `SupplierLearningRepository.php`  
**Risk Type**: Logic - Data Inconsistency  
**Severity**: HIGH

**Vulnerability**:
- Two repositories logging decisions to different tables
- `learning_confirmations` vs `supplier_decisions_log`
- No synchronization mechanism
- Unclear which is authoritative

**Failure Modes**:
- Logging succeeds in one table, fails in other → partial state
- Logic changes in one repo not reflected in other → desynch
- Future feature reads from wrong table → incorrect behavior
- Data migration nightmare if consolidation needed

**Silent vs Visible**:
- Silent: Desynchronization between tables → inconsistent learning
- Silent: One table full, other growing → backup size mismatch
- Silent: Query results differ based on which table used
- Visible: Exception in one logDecision() call (but which?)

**Evidence**:
- save-and-next.php:270-307: Logs to learning_confirmations
- SupplierLearningRepository:142-161: Logs to supplier_decisions_log
- No cross-reference or consistency checks

**What Would Break First**:
- If learning_confirmations used for new feature, but supplier_decisions_log has newer data
- Confusion during debugging ("I logged this, why isn't it showing?")

**Impact Radius**: Learning System Accuracy

---

### RISK #5: Timeline Recorder with Global $db Dependency

**Location**: `app/Services/TimelineRecorder.php`  
**Risk Type**: Coupling - Hidden Dependency  
**Severity**: MEDIUM-HIGH

**Vulnerability**:
- Uses global `$db` variable (line 22, 84, 300, etc.)
- Assumes it exists and is valid
- No null checks
- No error handling for missing global
- Static methods = hard to test

**Failure Modes**:
- Global $db not set → fatal error "undefined variable"
- Global $db different from repository $db → inconsistent state
- Testing requires global state setup → brittle tests

**Silent vs Visible**:
- Visible: Missing global → immediate fatal error
- Silent: Wrong global scope → writes to unexpected database
- Visible: Any PDOException in recordEvent → uncaught (line 339)

**Evidence**:
- Line 22: `global $db;` without null check
- Line 377: Another `global $db;` in recordImportEvent
- No defensive programming

**What Would Break First**:
- First call to TimelineRecorder from context without global $db
- Likely: API endpoint test, CLI script, or async job

**Impact Radius**: ALL timeline recording (extend, reduce, release, save)

---

### RISK #6: Active Action State Confusion

**Location**: `guarantee_decisions` table, `active_action` field usage  
**Risk Type**: Logic - State Management  
**Severity**: MEDIUM-HIGH

**Vulnerability**:
- `active_action` field cleared on data change (save-and-next.php:232)
- But data might change AFTER action (e.g., extend, then edit supplier)
- UI might show stale preview if not reloaded
- No validation that active_action matches actual state

**Failure Modes**:
- User clicks extend → active_action='extension' → preview shown
- User edits supplier → active_action cleared
- User navigates away, back → what preview shows? (depends on reload)
- Race condition: extend API call in-flight, user saves → which wins?

**Silent vs Visible**:
- Silent: Stale preview shown (if frontend cached)
- Silent: Preview cleared but user expects to see it
- Visible: Error if trying to generate letter with cleared action? (not seen in code)

**Evidence**:
- ADR-007 logic (save-and-next.php:221-238): Clears on any change
- No validation in letter generation (TimelineRecorder:83-136)
- Frontend behavior unknown (JavaScript not analyzed)

**What Would Break First**:
- User workflow: extend → save supplier → expect extension preview → sees nothing
- Confusion, not crash

**Impact Radius**: User Experience, Preview Accuracy

---

## 🟡 MEDIUM-RISK AREAS

### RISK #7: Bank Matching Logic Duplication

**Location**: `SmartProcessingService.php:120-180`, `save-and-next.php:127-141`  
**Risk Type**: Maintenance - Logic Drift  
**Severity**: MEDIUM

**Vulnerability**:
- Identical bank matching logic in two files
- If one gets bug fix, other might not
- If logic change needed (e.g., add fuzzy matching), must update both
- No shared service

**Failure Modes**:
- Bug fixed in one location, not other → inconsistent behavior
- New requirement (fuzzy match) added to one, not other → users confused
- Testing updates one, production uses other

**Silent vs Visible**:
- Silent: Different match results between auto-match and manual fallback
- Visible: Only if users report discrepancy

**Mitigation**: Refactor to shared BankMatchingService

**Impact Radius**: Bank matching consistency

---

### RISK #8: Status Calculation Ambiguity

**Location**: Multiple (Status_Evaluator, index.php, save-and-next.php)  
**Risk Type**: Logic - Unclear Source of Truth  
**Severity**: MEDIUM

**Vulnerability**:
- Status both stored (guarantee_decisions.status) and calculated
- Three state indicators: status, is_locked, active_action
- no clear documentation of which takes precedence when

**Failure Modes**:
- Status field says "ready", but supplier_id null → calculation says "pending"
- is_locked=1, but status="ready" → which to trust for filtering?
- Timeline created with old status value, new calculation differs

**Silent vs Visible**:
- Silent: Display shows "ready", filters use "pending" → record hidden
- Silent: Status drift between DB and calculated value
- Visible: Lifecycle gates (extend/reduce/release) check status field explicitly

**Evidence**:
- index.php:180: Calculates status
- index.php:266: Comment says "Any decision = ready"
- guarantee_decisions table has status column (stored)

**Required Clarification**:
1. Is status field denormalized cache or source of truth?
2. Should status always be recalculated, or trusted from DB?
3. Why have both?

**Impact Radius**: Filtering, display, action gating

---

### RISK #9: Snapshot/Update/Record Pattern - No Transactions

**Location**: All action endpoints (extend.php, reduce.php, release.php, save-and-next.php)  
**Risk Type**: Concurrency - Race Conditions  
**Severity**: MEDIUM

**Vulnerability**:
- Snapshot → Update → Record pattern not atomic
- No database transactions wrapping the sequence
- If record fails after update, state changed but no audit trail
- If two users edit same record simultaneously, last-write-wins

**Failure Modes**:
- **Crash Mid-Flight**: Update succeeds, record fails → ghost change (no timeline)
- **Concurrent Edits**: User A snapshots, User B updates, User A updates → B's change lost
- **Rollback Impossible**: No transaction = can't undo partial failure

**Silent vs Visible**:
- Silent: Ghost changes (updated but not logged)
- Silent: Lost updates (concurrent edits)
- Visible: If record fails with exception (but update already committed)

**Evidence**:
- No `$db->beginTransaction()` seen in any action endpoints
- Each query is auto-committed
- Concurrent access not considered

**Likelihood**: LOW (single-user system?), but severity increases with multiple users

**Impact Radius**: Data integrity, audit completeness

---

### RISK #10: Implicit Supplier Resolution Strategy

**Location**: `save-and-next.php:48-79`  
**Risk Type**: Logic - Hidden Behavior  
**Severity**: MEDIUM

**Vulnerability**:
- Tries exact match, then normalized match
- If both fail, returns error (no auto-create)
- But what if:
  - User types "شركة النورس " (extra space) → normalized match finds "شركة النورس"
  - User types "شركة النورس الجديدة" → no match, error
  - Official name changes in DB → old name no longer matches

**Failure Modes**:
- False positives: Normalization too aggressive → wrong supplier matched
- False negatives: Expected supplier not found due to variation → frustration
- Depends on normalization algorithm quality

**Silent vs Visible**:
- Silent: Wrong supplier matched (if normalization flawed)
- Visible: Match failure → error message (good)

**Evidence**:
- Lines 66-78: Returns error, doesn't auto-create (good!)
- Normalization algorithm not examined (in ArabicNormalizer)

**Impact Radius**: Manual decision workflow

---

### RISK #11: Letter Snapshot HTML Inclusion Side Effect

**Location**: `TimelineRecorder.php:83-136` (generateLetterSnapshot)  
**Risk Type**: Coupling - Unexpected Side Effects  
**Severity**: MEDIUM

**Vulnerability**:
```php
ob_start();
include __DIR__ . '/../../partials/preview-section.php';
$letterHtml = ob_get_clean();
```

- Uses output buffering to capture HTML
- Includes partial file that might have its own logic
- Partial could:
  - Set headers
  - Echo debug output
  - Modify global state
  - Throw exceptions
- No isolation or sandboxing

**Failure Modes**:
- Partial has syntax error → snapshot generation fails
- Partial echoes debug output → HTML corrupted
- Partial modifies $record variable → snapshot inconsistent
- Partial slow (database queries inside?) → letter generation timeouts

**Silent vs Visible**:
- Silent: Partial logic runs unexpectedly (side effects)
- Visible Error if partial fails to include or throws
- Silent: HTML captured but includes unintended output

**Evidence**:
- Line 132: Direct include with no error handling
- Relies on partial being side-effect-free

**Mitigation**: Use templating engine with isolated scope

**Impact Radius**: Timeline event accuracy, letter snapshot correctness

---

## 🟢 LOW-RISK (But Suspicious)

### RISK #12: Change Detection String Building vs Structured Arrays

**Location**: `save-and-next.php:93-168`, `TimelineRecorder.php:244-283`  
**Risk Type**: Maintenance - Inconsistent Formats  
**Severity**: LOW

**Observation**:
- save-and-next builds change description strings ("تغيير المورد من [X] إلى [Y]")
- TimelineRecorder builds change arrays (`['field' => 'supplier_id', 'old_value' => ...]`)
- Both representing same concept differently

**Potential Issue**:
- If change format needs update, must edit both
- String format harder to parse/analyze later
- Array format structured but verbose

**Not a Bug**: Intentional (strings for display, arrays for storage)

**Impact**: Low (just maintenance burden)

---

### RISK #13: Frontend Validation Unknown

**Location**: `index.php` (embedded JavaScript, ~1000+ lines)  
**Risk Type**: Unknown - Not Analyzed  
**Severity**: LOW-MEDIUM

**Gap**: JavaScript code not thoroughly examined due to size and embedding

**Assumptions**:
- Frontend likely validates supplier selection
- Frontend likely handles button states (extend/reduce/release)
- Frontend likely caches some state

**Unknown Risks**:
- Does frontend validation match backend?
- Could frontend send invalid requests backend doesn't expect?
- Could frontend show stale state when backend changed?

**Recommendation**: Separate frontend code into modules for analysis

**Impact**: Potentially MEDIUM if frontend/backend desynced

---

### RISK #14: Settings File Mutation (No Validation)

**Location**: `app/Support/Settings.php:74-79`  
**Risk Type**: Input Validation  
**Severity**: LOW

**Vulnerability**:
```php
public function save(array $data): array
{
    $merged = array_merge($current, $data);
    file_put_contents($this->path, json_encode($merged, ...));
    return $merged;
}
```

- No validation of input data
- Could write invalid threshold values (e.g., -1, 2.0, "abc")
- No schema enforcement
- Merged silently

**Failure Modes**:
-Invalid threshold stored → matching logic breaks
- String stored instead of float → type error in calculations
- Missing required key → defaults used, but file corrupted

**Silent vs Visible**:
- Silent: Invalid data written successfully
- Visible: Later usage throws exception (type error)

**Mitigation**: Validate before save (check types, ranges)

**Impact Radius**: Settings integrity, matching behavior

---

## 📊 RISK MATRIX (Likelihood x Impact)

|Risk| Likelihood | Impact | Priority |
|----|------------|--------|----------|
| Monolithic index.php | High | Critical | P0 |
| Database singleton fail | Medium | Critical | P0 |
| JSON query fragility | Medium | High | P1 |
| Dual learning systems | Low | High | P1 |
| Global $db in Timeline | Low | High | P1 |
| Active action confusion | Medium | Medium | P2 |
| Bank logic duplication | Medium | Medium | P2 |
| Status ambiguity | Medium | Medium | P2 |
| No transactions | Low | Medium | P3 |
| Supplier resolution | Low | Medium | P3 |
| Letter snapshot side effects | Low | Medium | P3 |
| Settings validation | Low | Low | P4 |

---

## 🔥 FAILURE MODE ANALYSIS

### Scenario 1: Database File Corrupted

**Trigger**: Disk error, power loss, manual edit

**Flow**:
1. User requests page → index.php loads
2. Database::connect() → PDO connection attempt
3. SQLite detects corruption → throws exception
4. No retry, no fallback → exception bubbles
5. index.php catches? NO (no try-catch at top level)
6. PHP default handler → white screen / 500 error

**Visibility**: VISIBLE (error immediately)  
**Recovery**: Manual (restore from backup)  
**Data Loss**: All changes since last backup

---

### Scenario 2: Learning Table Desynchronization

**Trigger**: Exception in one logging call, success in other

**Flow**:
1. User saves decision → save-and-next.php:270
2. Logs to learning_confirmations → SUCCESS
3. Logs rejection (line 293) → Database lock exception
4. Try-catch absorbs exception (line 305) → silently fails
5. learning_confirmations has confirm, no reject logged
6. supplier_decisions_log not called (different repo)
7. Historical analysis shows incomplete data

**Visibility**: SILENT (logged as error_log only)  
**Recovery**: Manual correction or data reconciliation script  
**Data Loss**: Negative learning signal lost

---

### Scenario 3: Concurrent Extension Race

**Trigger**: Two users extend same guarantee simultaneously

**Flow**:
1. User A clicks extend → extend.php loads
2. User B clicks extend → extend.php loads
3. Both create snapshots (old expiry: 2026-01-01)
4. User A calculates new: 2027-01-01
5. User B calculates new: 2027-01-01
6. User A updates raw_data → committed
7. User B updates raw_data → committed (overwrites A)
8. User A records timeline event (old: 2026-01-01, new: 2027-01-01)
9. User B records timeline event (old: 2026-01-01, new: 2027-01-01)
10. **Result**: Two identical timeline events, expiry extended once

**Visibility**: SEMI-SILENT (duplicate events visible in timeline, but not flagged as error)  
**Recovery**: Manual deletion of duplicate event  
**Data Integrity**: Not corrupted, but history confusing

---

### Scenario 4: Partial Timeline Recording Failure

**Trigger**: Exception during recordEvent after data updated

**Flow**:
1. User reduces amount → reduce.php
2. Snapshot created
3. raw_data updated → COMMITTED
4. setActiveAction → COMMITTED
5. recordReductionEvent called
6. TimelineRecorder.recordEvent executes
7. Database write fails (disk full, lock, etc.) → exception thrown
8. Exception bubbles to reduce.php try-catch (line 106)
9. Returns error HTML to user
10. **Result**: Amount reduced, active_action set, but NO timeline event

**Visibility**: SEMI-VISIBLE (error shown to user, but data already changed)  
**Recovery**: User sees error, might retry (creates duplicate change)  
**Data Integrity**: COMPROMISED (audit trail incomplete)

---

### Scenario 5: JSON Format Change Breaks Learning

**Trigger**: Developer reformats raw_data JSON (prettier, key reorder)

**Flow**:
1. Migration script updates all raw_data with pretty-printed JSON
2. OLD FORMAT: `{"supplier":"شركة النورس","bank":"البنك الأهلي"}`
3. NEW FORMAT:
```json
{
  "supplier": "شركة النورس",
  "bank": "البنك الأهلي"
}
```
4. Learning queries search for `"supplier":"شركة النورس"`
5. NEW FORMAT has spaces after colons → MISMATCH
6. Historical selections query returns ZERO results
7. Suggestions based on history degraded

**Visibility**: SILENT (queries succeed, return empty results)  
**Recovery**: Update query pattern or normalize JSON in migrations  
**Data Integrity**: Data intact, queries broken

---

## 🎯 WHAT WOULD BREAK FIRST?

### Most Likely Failure Points (in order):

1. **Database connection failure** (corrup file, permissions)
   - **Impact**: Total system down
   - **Visibility**: Immediate, visible

2. **Large dataset query timeout** (index.php with 10K+ records)
   - **Impact**: Page load failure
   - **Visibility**: Visible after timeout

3. **Learning query returning wrong results** (JSON format edge case)
   - **Impact**: Incorrect suggestions
   - **Visibility**: Silent, gradual

4. **Dual learning table desynchronization** (one log fails)
   - **Impact**: Inconsistent learning data
   - **Visibility**: Silent

5. **Timeline recording failure after update** (disk full mid-operation)
   - **Impact**: Audit trail gap
   - **Visibility**: Semi-visible (error shown, data changed)

---

## 💥 SILENT vs VISIBLE FAILURES

### Silent Failures (Most Dangerous):

| Failure | Location | Detection Method |
|---------|----------|------------------|
| Learning table desynch | LearningRepo vs SupplierLearningRepo | Data audit, cross-check counts |
| JSON query misses | LIKE pattern mismatch | Compare results with known data |
| Status calculation drift | Stored vs calculated | Query inconsistencies |
| Stale active_action | Cleared but not reloaded | User sees no preview |
| Ghost changes | Update without timeline | Diff DB backups |
| Wrong supplier matched | Normalization bug | User reports incorrect match |
| Concurrent edit conflicts | Last-write-wins | Compare timestamps, user confusion |

### Visible Failures (Good - Immediate Feedback):

| Failure | Location | Error Type |
|---------|----------|----------|
| Database connection | Database::connect() | Exception / white screen |
| Parse error | Any PHP file | Fatal error |
| Missing supplier | save-and-next validation | 400 error, JSON response |
| Lifecycle gate | extend/reduce/release | 400 error, HTML message |
| File write fail | Settings.php | Exception bubbles |

---

## 🛡️ EXISTING PROTECTIONS

### What's Working Well:

1. **Backend Validation**: save-and-next.php refuses invalid data (no auto-create)
2. **Lifecycle Gates**: extend/reduce/release check status before acting
3. **ID/Name Mismatch Safeguard**: save-and-next:34-46 prevents stale ID poisoning
4. **Try-Catch on Non-Critical**: Note/attachment loading failures don't crash page
5. **Strict Repository Pattern**: Most data access centralized

---

## 🚨 MISSING PROTECTIONS

1. **Transactions**: No atomicity for multi-step operations
2. **Connection Retry**: Database fails = immediate crash
3. **Concurrency Control**: No locks, no versioning
4. **Input Validation**: Settings can store invalid values
5. **JSON Schema Validation**: No enforcement on raw_data structure
6. **Logging Consistency**: No check that dual learning tables stay synchronized
7. **Fallback UI**: No graceful degradation if database slow/down

---

## 📈 SCALABILITY CONCERNS

### Current Limits (Estimated):

- **Records**: ~10K guarantees before index.php slows noticeably (no pagination seen)
- **Concurrent Users**: 1-5 (no locking = conflicts)
- **Timeline Events per Guarantee**: ~50 before index.php load struggles
- **Learning Data**: JSON LIKE queries slow at ~50K guarantees
- **File Size**: SQLite handles GB, but no backup strategy seen

### Bottlenecks:

1. index.php loads ALL timeline events (line 346) → O(n) per guarantee
2. Learning historical query scans all guarantees → O(n)
3. No query result caching
4. No async processing (all synchronous)

---

## Summary

**The system is OPERATIONAL but FRAGILE.**

**Strengths**:
- Clean repository pattern (mostly)
- Strong snapshot/record audit discipline  
- Explicit validation gates

**Weaknesses**:
- Monolithic entry point
- Dual learning systems
- Brittle JSON queries
- No transaction safety
-Hidden concurrency issues

**Recommendation**: Safe for SINGLE-USER, LOW-VOLUME use. Requires hardening for production multi-user deployment.
