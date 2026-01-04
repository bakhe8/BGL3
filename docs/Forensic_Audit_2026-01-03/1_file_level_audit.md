# FILE-LEVEL AUDIT

## 📂 ROOT DIRECTORY

### `server.php` (34 lines)
**Purpose**: Development server router for PHP built-in server  
**When Loaded**: PHP CLI server startup  
**Dependencies**: None  
**Execution Context**: Routes all requests - serves static files directly, forwards others to `index.php`  
**Critical Logic**: MIME type mapping for static assets (css, js, png, jpg, gif, svg)

**Observations**:
- Simple, single-responsibility router
- No external dependencies
- Hardcoded MIME types (could be centralized if extended)

---

### `index.php` (2551 lines) ⚠️ **MASSIVE FILE**
**Purpose**: Main application entry point - renders entire UI and business logic  
**When Loaded**: Every page request (via server.php)  
**Dependencies**: Database, AuthorityFactory, GuaranteeRepository, GuaranteeDecisionRepository, SupplierRepository, BankRepository, StatusEvaluator  
**Execution Context**: Server-side rendering for primary UI

**Critical Sections**:
- **Lines 1-50**: Initialization, database connection, filter parameters
- **Lines 52-91**: Record loading logic with filter support  
- **Lines 93-133**: Statistics calculation  
- **Lines 135-226**: Current index calculation and prev/next navigation
- **Lines 229-481**: Record preparation, decision loading, supplier suggestions
- **Lines 483-2551**: HTML/CSS/JavaScript embedded inline

**Observations**:
- 🔴 **ARCHITECTURAL VIOLATION**: Mixing data access, business logic, and presentation in single file
- Contains inline CSS (500+ lines) and JavaScript (1000+ lines)
- Direct database queries intermixed with rendering logic
- Comment on line 26: "LearningService removed - deprecated in Phase 4"
- Comment on line 41: "PHASE 4: Using UnifiedLearningAuthority directly"
- Multiple filter types: 'all', 'ready', 'pending', 'released'
- Status mapping logic duplicated in multiple places (lines 243, 266, 319, etc.)

**Hidden Assumptions**:
- Guarantees always have an ID
- Database connection never fails after initial creation
- Status filter parameter is trusted (no validation)
- Timeline events always exist or fallback to import event

**Risk Level**: HIGH - Single point of failure, difficult to test, hard to maintain

---

## 📁 app/Support/

### `autoload.php` (33 lines)
**Purpose**: Custom PSR-4 autoloader  
**Dependencies**: spl_autoload_register  
**When Loaded**: First thing in every PHP file  

**Logic**: Maps `App\` namespace to `/app/` directory with PSR-4 conventions

**Observations**:
- Simple, effective
- No fallback mechanism if file not found

---

### `Database.php` (56 lines)
**Purpose**: Singleton database connection  
**Dependencies**: PDO  
**Pattern**: Singleton with static instance

**Critical Methods**:
- `connect()`: Returns singleton PDO instance for SQLite
- `connection()`: Alias for connect()

**Observations**:
- ✅ Proper singleton pattern
- Sets timezone to 'Asia/Riyadh' on every connect
- Database path: `storage/database/app.sqlite`
- Creates directory if missing
- Error handling returns JSON for API context, dies for others
- ⚠️ **GLOBAL STATE**: Singleton makes testing harder

---

### `Settings.php` (88 lines)
**Purpose**: Application configuration manager  
**Storage**: JSON file at `storage/settings.json`  
**Dependencies**: Config class for defaults

**Critical Settings**:
- `MATCH_AUTO_THRESHOLD` = 0.90 (auto-accept confidence)
- `MATCH_REVIEW_THRESHOLD` = 0.70 (minimum to display)
- `CONFLICT_DELTA` = 0.1 (score difference for conflicts)
- Score weights for different match types

**Methods**:
- `all()`: Returns merged defaults + file data
- `save()`: Persists to JSON
- `get()`: Retrieves single setting

**Observations**:
- ✅ Good separation of concerns
- ⚠️ No validation on save
- File may not exist on first run (handled gracefully)

---

### `SimilarityCalculator.php`, `Normalizer.php`, `BankNormalizer.php`
**Purpose**: Text normalization and similarity scoring for Arabic text  
**When Used**: Supplier/bank matching

**Observations**:
- Specialized for Arabic text processing
- Complex normalization rules (remove diacritics, handle variations)

---

## 📁 app/Models/

### `Guarantee.php` (90 lines)
**Purpose**: Value object representing a guarantee  
**Storage**: Uses `rawData` array (JSON) for flexible schema  
**Dependencies**: None

**Fields**:
- `id`, `guaranteeNumber`, `rawData`, `importSource`, `importedAt`, `importedBy`

**Helper Methods**:
- `getSupplierName()`, `getBankName()`, `getAmount()`, `getExpiryDate()`, `getDocumentReference()`, `getRelatedTo()`

**Observations**:
- ✅ Clean value object pattern
- Uses PHP 8 constructor property promotion
- All data access goes through rawData array
- **SCHEMA FLEXIBILITY**: No fixed columns for guarantee details

---

### `GuaranteeDecision.php` (85 lines)
**Purpose**: Represents decision state for a guarantee  
**Fields**: 
- Status fields: `status`, `isLocked`, `locked Reason`
- Decision fields: `supplierId`, `bankId`, `decisionSource`, `confidenceScore`
- Audit fields: `decidedAt`, `decidedBy`, `lastModifiedAt`, `lastModifiedBy`
- **Phase 3 Addition**: `activeAction`, `activeActionSetAt` (extension|reduction|release|null)

**Observations**:
- Contains both current state AND historical metadata
- `active_action` added in Phase 3 for preview pointer
- No business logic, pure data structure

---

### Other Models
- `LearningLog.php`, `ImportSession.php`, `ImportedRecord.php`, `TrustDecision.php`, `Supplier.php`, `Bank.php`, `SupplierAlternativeName.php`

All follow similar clean value object pattern.

---

## 📁 app/Repositories/

### `GuaranteeRepository.php` (195 lines)
**Purpose**: Data access for guarantees table  
**Dependencies**: Database, Guarantee model

**Critical Methods**:
- `find($id)`: Fetch by ID
- `findByNumber($guaranteeNumber)`: Fetch by guarantee number
- `create($guarantee)`: Insert new guarantee, re-fetches to ensure DB state
- `updateRawData($id, $rawData)`: **MUTATION POINT** for raw_data changes
- `getAll()`, `count()`: Query with filters

**Observations**:
- ✅ Clean repository pattern
- ✅ Re-fetches after create (line 79-88) to ensure consistency
- Centralizes raw_data mutations (line 103)
- Uses JSON encoding for raw_data storage

---

### `GuaranteeDecisionRepository.php` (292 lines)
**Purpose**: Data access for guarantee_decisions table  
**Dependencies**: Database, GuaranteeDecision model

**Critical Methods**:
- `findByGuarantee($id)`: Get decision for guarantee
- `createOrUpdate()`: Upsert pattern
- `lock($id, $reason)`: Mark as released
- `setActiveAction($id, $action)`: **Phase 3** - Set active action pointer
- `getActiveAction($id)`: Retrieve current action
- `clearActiveAction($id)`: Reset action pointer
- `getHistoricalSelections($normalized)`: **FRAGILE** - JSON LIKE query

**Observations**:
- Line 196-221: **QUERY PATTERN AUDIT #3** - Fragile JSON search
- Pattern: `WHERE g.raw_data LIKE '%"supplier":"<name>"%'`
- ⚠️ **RISK**: JSON field search is brittle, breaks on format changes
- Active action validation (line 142-148): Only allows 'extension', 'reduction', 'release', null

---

### `LearningRepository.php` (88 lines) ⚠️ **DUPLICATION ALERT**
**Purpose**: Learning system data access  
**Tables**: `learning_confirmations`, `guarantees` (via JOIN)

**Methods**:
- `getUserFeedback($rawName)`: Aggregates confirm/reject counts
- `getRejections($rawName)`: Gets rejected supplier IDs
- `getHistoricalSelections($rawName)`: **FRAGILE JSON QUERY** (line 48-63)
- `logDecision($data)`: Records learning confirmation/rejection

**Observations**:
- **DUPLICATION**: Similar to SupplierLearningRepository
- **FRAGILE**: Line 51-62 uses JSON LIKE pattern matching
- Logs to `learning_confirmations` table
- Action field: 'confirm' or 'reject'

---

### `SupplierLearningRepository.php` (216 lines) ⚠️ **DUPLICATION ALERT**
**Purpose**: Supplier learning and caching  
**Tables**: `supplier_alternative_names`, `suppliers`, `supplier_decisions_log`

**Methods**:
- `findSuggestions()`: Get supplier suggestions
- `incrementUsage()`: Positive learning
- `decrementUsage()`: **Negative learning** (down to -5 limit)
- `learnAlias()`: Create new alias mapping
- `logDecision()`: Log to supplier_decisions_log
- `findConflictingAliases()`: Find alternate names for same supplier

**Observations**:
- **DUPLICATION**: Overlaps with LearningRepository responsibilities
- ⚠️ **TWO LOGGING TABLES**: `learning_confirmations` AND `supplier_decisions_log`
- Negative learning capped at -5 (line 103)
- Public `$db` property (line 8) - breaks encapsulation for "session tracking"

---

### Other Repositories
- `SupplierRepository.php`, `BankRepository.php`, `SupplierAlternativeNameRepository.php`, `SupplierLearningCacheRepository.php`, `AttachmentRepository.php`, `NoteRepository.php`, `GuaranteeHistoryRepository.php`, `ImportedRecordRepository.php`

All follow standard repository pattern with clean data access methods.

---

## 📁 app/Services/

### `SmartProcessingService.php` (477 lines)
**Purpose**: Auto-matching suppliers and banks for new guarantees  
**Dependencies**: Multiple repositories, UnifiedLearningAuthority, BankNormalizer

**Critical Methods**:
- `processNewGuarantees($limit)`: Main entry point - processes pending guarantees
- `createAutoDecision()`: Creates decision record
- `logAutoMatchEvents()`: Timeline recording for supplier match
- `updateBankNameInRawData()`: Mutations raw_data with matched bank
- `logBankAutoMatchEvent()`: Timeline recording for bank match
- `evaluateTrust()`: **Trust Gate** - decides if match is trustworthy enough to auto-approve

**Flow** (lines 47-227):
1. Query pending guarantees (no decision)
2. For each guarantee:
   - Get supplier suggestions from UnifiedLearningAuthority
   - Evaluate trust for top suggestion
   - If trusted → get bank match → create auto-decision
   - Log timeline events

**Observations**:
- Line 120: Bank name update mutates raw_data
- Line 136: SmartProcessingService stores matched bank name in `raw_data['bank']`
- ⚠️ **MUTATION**: Changes source data (raw_data) during auto-match
- Trust evaluation checks for conflicts, score threshold, source type

---

### `TimelineRecorder.php` (631 lines) ⚠️ **COMPLEX**
**Purpose**: Central timeline/history event recording  
**Pattern**: Static utility class with global $db dependency

**Critical Methods**:
- `createSnapshot($id)`: Captures current state before change
- `generateLetterSnapshot($id, $action, $data)`: **ADR-007** - Renders HTML preview
- `recordExtensionEvent()`: UE-02 event
- `recordReductionEvent()`: UE-03 event
- `recordReleaseEvent()`: UE-04 event
- `recordDecisionEvent()`: UE-01 or SY-03 event
- `recordImportEvent()`: LE-00 event
- `recordStatusTransitionEvent()`: SE-01/SE-02 event
- `recordEvent()`: **CORE** private method - writes to guarantee_history

**Flow Pattern** (all actions):
1. **Snapshot**: Capture old state
2. **Update**: Execute mutation
3. **Record**: Log event with snapshot + changes

**Observations**:
- Uses global `$db` variable (line 22, 84, etc.)
- Line 83-136: Generates full HTML snapshot by including `partials/preview-section.php`
- ⚠️ **SIDE EFFECT**: Uses `ob_start()` / `ob_get_clean()` to capture HTML
- Letter snapshot stored directly as HTML (not JSON encoded) - line 331
- Event structure: `event_type`, `event_subtype`, `snapshot_data`, `event_details` letter_snapshot`
- Comprehensive display label mapping (line 526-610)

---

### `StatusEvaluator.php`, `DecisionService.php`, `ActionService.php`, `ValidationService.php`
**Purpose**: Business logic utilities  
**Pattern**: Service layer for specific concerns

---

### Services/Learning/ Subdirectory

#### `UnifiedLearningAuthority.php` (239 lines) ⚠️ **CENTRAL AUTHORITY**
**Purpose**: **SINGLE** source of supplier suggestions  
**Dependencies**: Normalizer, ConfidenceCalculatorV2, SuggestionFormatter, SignalFeeders

**Architecture**:
- Feeders: Pluggable signal sources implementing `SignalFeederInterface`
- Signals: Individual data points with supplier_id, strength, type
- Confidence: Calculated from aggregated signals
- Output: `SuggestionDTO` array ordered by confidence

**Critical Method**: `getSuggestions($rawInput)`
1. Normalize input once
2. Gather signals from all registered feeders
3. Aggregate by supplier ID
4. Compute confidence scores
5. Filter by threshold
6. Order by confidence descending
7. Return as DTOs

**Observations**:
- ✅ **GOOD ARCHITECTURE**: Clear separation via interfaces
- Feeders are registered via `registerFeeder()``
- No direct database access (enforces Database Role Declaration)
- Comment (line 14-27): Explicitly states it does NOT query DB, make arbitrary decisions, or bypass governance

---

#### `AuthorityFactory.php`, `ConfidenceCalculatorV2.php`, `SuggestionFormatter.php`
**Purpose**: Support classes for UnifiedLearningAuthority  
**Observations**: Clean, focused responsibilities

---

### Services/Suggestions/ Subdirectory
**Contains**: ArabicEntityExtractor, ArabicLevelBSuggestions, etc.  
**Purpose**: Legacy/alternative suggestion logic  
**Status**: Unclear if still used or deprecated

---

## 📁 api/

### `save-and-next.php` (383 lines)
**Purpose**: Save current decision and load next record  
**Dependencies**: GuaranteeRepository, LearningRepository, AuthorityFactory, StatusEvaluator, TimelineRecorder

**Flow**:
1. Validate inputs (guarantee_id, supplier_id/name)
2. **SAFEGUARD** (lines 34-46): Check ID/Name mismatch, trust name if conflict
3. Resolve supplier ID if missing (exact match → normalized match)
4. Detect changes from previous decision
5. **Timeline**: Snapshot → Update → Record (lines 176-260)
6. **Learning Feedback** (lines 262-307):
   - Log 'confirm' for chosen supplier
   - **IMPLICIT REJECTION** (lines 283-303): if top suggestion ≠ chosen, log 'reject'
7. Load next record and return JSON

**Observations**:
- Line 44: Nullifies supplier_id if name mismatch detected (prevents stale ID)
- Lines 66-78: **NO AUTO-CREATE** - requires explicit supplier selection
- Line 221-238: Clears `active_action` when data changes (ADR-007)
- Lines 283-303: **ALREADY IMPLEMENTS** implicit rejection learning (documented in LEARNING_ANALYSIS.md)
- ⚠️ **DUPLICATION**: Learning logic repeated here and in SmartProcessingService

---

### `extend.php` (113 lines)
**Purpose**: Extend guarantee expiry date by 1 year  
**Dependencies**: GuaranteeRepository, GuaranteeDecisionRepository, TimelineRecorder

**Flow**:
1. **Lifecycle Gate** (lines 30-45): Reject if status ≠ 'ready'
2. **Timeline Discipline**: Snapshot → Update → Record (lines 48-77)
3. Calculate new expiry (+1 year)
4. Update raw_data via repository
5. Set active_action to 'extension'
6. Record extension event
7. Return HTML partial

**Observations**:
- ✅ Follows strict snapshot/update/record pattern
- Line 60: Uses `date('Y-m-d', strtotime($oldExpiry . ' +1 year'))`
- Line 69: Sets `active_action` pointer
- Returns HTML fragment for client-side replacement

---

### `reduce.php` (114 lines)
**Purpose**: Reduce guarantee amount  
**Flow**: Identical to extend.php but mutates `amount` field

**Observations**:
- Same lifecycle gate pattern
- Same timeline discipline
- Sets active_action to 'reduction'

---

### `release.php` (112 lines)
**Purpose**: Lock guarantee as released  
**Flow**: Similar to extend/reduce with additional validations

**Critical Logic**:
- Line 58-59: Validates supplier_id AND bank_id exist
- Line 63-65: Prevents re-release
- Line 68: Calls `decisionRepo->lock()` to set is_locked flag
- Line 71: Sets active_action to 'release'

**Observations**:
- More stringent validation than extend/reduce
- Locks record (prevents further changes)

---

### Other API Endpoints
- `import.php`, `parse-paste.php`: Data ingestion
- `get-record.php`, `get-timeline.php`, `get-current-state.php`: Data retrieval
- `create-guarantee.php`, `create-supplier.php`, `create_bank.php`: Entity creation
- `learning-action.php`, `learning-data.php`: Learning system interaction
- `settings.php`: Settings management
- `upload-attachment.php`, `save-note.php`: Metadata management

---

## 📁 views/

### `index.php` (15,845 bytes)
**Purpose**: Main record view (unclear if different from root index.php)

### `settings.php` (41,093 bytes)
**Purpose**: Settings configuration UI

### `statistics.php` (31,054 bytes)
**Purpose**: Analytics and reporting UI

### `batch-print.php` (13,298 bytes)
**Purpose**: Batch letter printing

**Observations**:
- ⚠️ All views are large, monolithic files
- Likely mixing logic and presentation

---

## 📁 partials/

### `record-form.php`, `preview-section.php`, `timeline-section.php`
**Purpose**: Reusable UI components  
**Inclusion Context**: Server-side include in index.php and API responses

**Observations**:
- `preview-section.php` included by TimelineRecorder for snapshot generation (line 132)
- Server-driven partials pattern (reduces client-side logic)

---

## 📁 public/js/

### JavaScript Files (6 total)
- `main.js`, `records.controller.js`, `input-modals.controller.js`, `timeline.controller.js`, `pilot-auto-load.js`, `preview-formatter.js`

**Observations**:
- Limited JavaScript (system is server-heavy)
- Controllers handle UI interactions
- Minimal client-side state

---

## Summary Statistics

| Category | Count | Average Size |
|----------|-------|--------------|
| PHP Files | 124 | ~500 lines |
| JS Files | 6 | ~200 lines |
| Repositories | 14 | ~150 lines |
| Services | 15+ | ~300 lines |
| Models | 9 | ~80 lines |
| API Endpoints | 33 | ~150 lines |
| Views | 4 | 10K+ bytes |

## Critical Files by Line Count

1. `index.php` - 2,551 lines ⚠️
2. `TimelineRecorder.php` - 631 lines
3. `SmartProcessingService.php` - 477 lines
4. `save-and-next.php` - 383 lines
5. `GuaranteeDecisionRepository.php` - 292 lines

## Dependency Graph (High-Level)

```
index.php (Entry Point)
  ├─→ Database (Singleton)
  ├─→ GuaranteeRepository
  │     └─→ Guarantee model
  ├─→ GuaranteeDecisionRepository  
  │     └─→ GuaranteeDecision model
  ├─→ AuthorityFactory
  │     └─→ UnifiedLearningAuthority
  │           ├─→ Signal Feeders
  │           ├─→ ConfidenceCalculatorV2
  │           └─→ SuggestionFormatter
  ├─→ StatusEvaluator
  └─→ BankRepository, SupplierRepository

API Endpoints
  ├─→ TimelineRecorder (global $db)
  ├─→ Repositories (various)
  └─→ Services (SmartProcessingService, etc.)
```
