# Data Trace Map (Raw Data, Supplier Creation, Confidence, Threshold)

## 0) Scope
- Document current `raw_data` keys and where they are written/read.
- Document supplier creation paths and why they exist.
- Document the confidence score pipeline from data sources to UI.
- Document where `MATCH_AUTO_THRESHOLD` is applied and how its runtime value is resolved.

## 1) raw_data keys (current)
Key | Writers (entry points) | Primary readers | Notes
--- | --- | --- | ---
`supplier` | `ImportService::importFromExcel` `app/Services/ImportService.php:136`<br>`ImportService::createManually` `app/Services/ImportService.php:244`<br>`api/create-guarantee.php:33`<br>`ParseCoordinatorService` `app/Services/ParseCoordinatorService.php:325`/`393` | `RecordHydratorService::hydrate` `app/Services/RecordHydratorService.php:31`<br>`api/get-record.php`<br>`api/get-current-state.php`<br>`TimelineRecorder::createSnapshot` `app/Services/TimelineRecorder.php:45` | Source of raw supplier name used for matching and display fallback.
`bank` | Same writers as `supplier` | `RecordHydratorService::hydrate` `app/Services/RecordHydratorService.php:31`<br>`api/get-record.php`<br>`api/get-current-state.php`<br>`SmartProcessingService` raw context<br>`TimelineRecorder::createSnapshot` | Bank name is auto-resolved to ID; raw value kept as fallback.
`guarantee_number` | `ImportService::importFromExcel` `app/Services/ImportService.php:136`<br>`ImportService::createManually` `app/Services/ImportService.php:244` | `TimelineRecorder::recordImportEvent` fallback `app/Services/TimelineRecorder.php:435` | Used only in import/manual flows; other flows use `bg_number`.
`bg_number` | `api/create-guarantee.php:33`<br>`ParseCoordinatorService` `app/Services/ParseCoordinatorService.php:325`/`393` | `SmartProcessingService` snapshot fallback `app/Services/SmartProcessingService.php:278`<br>`TimelineRecorder::recordImportEvent` fallback `app/Services/TimelineRecorder.php:435` | Legacy/manual/paste key; treated as fallback for `guarantee_number`.
`amount` | Same writers as `supplier` | `RecordHydratorService::hydrate` `app/Services/RecordHydratorService.php:31`<br>`DecisionService` `app/Services/DecisionService.php:88`<br>`TimelineRecorder` snapshots | Used for preview/letters and history.
`issue_date` | `ImportService` `app/Services/ImportService.php:136`/`244`<br>`ParseCoordinatorService` `app/Services/ParseCoordinatorService.php:393` | `RecordHydratorService::hydrate`<br>`TimelineRecorder` snapshots | Optional in some flows.
`expiry_date` | `ImportService` `app/Services/ImportService.php:136`/`244`<br>`ParseCoordinatorService` `app/Services/ParseCoordinatorService.php:325`/`393` | `BatchService` extension updates `app/Services/BatchService.php:136`<br>`RecordHydratorService::hydrate`<br>`TimelineRecorder` snapshots | Updated on extend actions.
`contract_number` | `ImportService` `app/Services/ImportService.php:136`/`244`<br>`api/create-guarantee.php:33`<br>`ParseCoordinatorService` `app/Services/ParseCoordinatorService.php:325`/`393` | `DecisionService` `app/Services/DecisionService.php:88`<br>`LetterBuilder` `app/Services/LetterBuilder.php:75`<br>`TimelineRecorder` snapshots | Primary contract reference.
`document_reference` | (Legacy data only) | `TimelineRecorder` fallbacks `app/Services/TimelineRecorder.php:59`/`135`/`434` | Used only as fallback for `contract_number`.
`type` | `ImportService` `app/Services/ImportService.php:136`/`244`<br>`api/create-guarantee.php:33`<br>`ParseCoordinatorService` `app/Services/ParseCoordinatorService.php:325`/`393` | `DecisionService` `app/Services/DecisionService.php:88`<br>`LetterBuilder` `app/Services/LetterBuilder.php:149` | Normalized via `TypeNormalizer`.
`related_to` | `ImportService` `app/Services/ImportService.php:136`/`244`<br>`api/create-guarantee.php:33` | `LetterBuilder` `app/Services/LetterBuilder.php:29` | Used in letter context.
`bank_center` | (Not written in core import flows) | `api/get-record.php` sets from DB; fallback in `views/batch-print.php:240` | Expected to come from `banks` table; raw_data fallback only.
`bank_po_box` | (Not written in core import flows) | Same as `bank_center` | Same note.
`bank_email` | (Not written in core import flows) | Same as `bank_center` | Same note.
`currency` | `api/create-guarantee.php:33` | No readers found | Stored only (no active consumers).
`details` | `api/create-guarantee.php:33` | No readers found | Stored only (no active consumers).
`source` | `api/create-guarantee.php:33`<br>`ParseCoordinatorService` `app/Services/ParseCoordinatorService.php:325`/`393` | No readers found | Stored only (no active consumers).
`original_text` | `ParseCoordinatorService` `app/Services/ParseCoordinatorService.php:325`/`393` | No readers found | Stored only (no active consumers).
`detected_intent` | `ParseCoordinatorService` `app/Services/ParseCoordinatorService.php:393` | No readers found | Stored only (no active consumers).
`session_id` | (Legacy/mock only) | `api/get-current-state.php:61` | No writer in live flows; appears only in `views/index.php` mock.

Notes on "Stored only / No readers found":
- These keys are written to `raw_data` but no active readers were found in the codebase at the time of this map.
- Treat them as stored payload/archival fields unless future code starts reading them.
- No current flow is known to depend on them directly or indirectly.

Notes on `guarantee_number` vs `bg_number`:
- This map is descriptive of current usage; there is no single "primary" key enforced globally.
- `guarantee_number` is written by import/manual flows and used as a fallback in timeline events.
- `bg_number` is written by create-guarantee and parse flows and used as a fallback when `guarantee_number` is missing.
- If both exist in `raw_data`, usage is context-specific as shown above (no universal override).

## 2) Supplier creation paths
- Record UI (manual add inside decision screen):
  - `public/js/records.controller.js:769` -> `api/create-supplier.php`
  - Uses `SupplierManagementService::create` to add a supplier quickly and return `supplier_id` for the current record.
- Settings UI (admin table):
  - `views/settings.php:593` -> `api/create_supplier.php`
  - Uses `SupplierManagementService::create` but returns only `success` (no record binding).
- Auto-create on save (when user typed a name not found):
  - `api/save-and-next.php:75` -> `SupplierManagementService::create`
  - Triggered if `supplier_id` is missing and name does not match an existing supplier.

Note:
- These paths are context-specific (record binding vs admin management vs free-text save).
- There is no single "primary" path; all use `SupplierManagementService::create` but differ in payload and response.

## 3) Confidence score pipeline (DB -> Service -> API -> UI)
Data sources (DB) -> repositories -> feeders -> authority -> DTO -> API -> UI:
- `supplier_alternative_names` -> `SupplierAlternativeNameRepository::findAllByNormalizedName` `app/Repositories/SupplierAlternativeNameRepository.php:62` -> `AliasSignalFeeder` `app/Services/Learning/Feeders/AliasSignalFeeder.php:28`
- `learning_confirmations` -> `LearningRepository::getUserFeedback` `app/Repositories/LearningRepository.php:23` -> `LearningSignalFeeder` `app/Services/Learning/Feeders/LearningSignalFeeder.php:31`
- `suppliers` -> `SupplierRepository::getAllSuppliers` (fuzzy) -> `FuzzySignalFeeder` `app/Services/Learning/Feeders/FuzzySignalFeeder.php:60`
- `suppliers` (anchors) -> `SupplierRepository::findByAnchor`/`countSuppliersWithAnchor` -> `AnchorSignalFeeder` `app/Services/Learning/Feeders/AnchorSignalFeeder.php:31`
- `guarantee_decisions` -> `GuaranteeDecisionRepository::getHistoricalSelections` `app/Repositories/GuaranteeDecisionRepository.php:175` -> `HistoricalSignalFeeder` `app/Services/Learning/Feeders/HistoricalSignalFeeder.php:31`
- All feeders are registered in `AuthorityFactory::create` `app/Services/Learning/AuthorityFactory.php:40`
- `UnifiedLearningAuthority::getSuggestions` computes confidence via `ConfidenceCalculatorV2::calculate` `app/Services/Learning/ConfidenceCalculatorV2.php:60`
- `SuggestionDTO->confidence` is mapped to `score` in `api/get-record.php:181`
- UI chips render `%` in `partials/record-form.php:137`
- `api/get-current-state.php` returns `supplierMatch` only if `supplier_id` is missing (pending state).

Example (illustrative):
Raw input "ACME CO" -> Authority computes confidence 92 -> API returns `score: 92` -> UI shows `92%`.

## 4) MATCH_AUTO_THRESHOLD (runtime value and usage)
Runtime value:
- Defaults to `90` via `Config::MATCH_AUTO_THRESHOLD` `app/Support/Config.php:8`.
- If `storage/settings.json` exists, `Settings::get` loads it and normalizes 0-1 values to 0-100 in `app/Support/Settings.php:82`.
- In this repo, `storage/settings.json` is not present, so default `90` is used at runtime.

Where it is applied:
- Auto-approval gate in `SmartProcessingService::evaluateTrust` `app/Services/SmartProcessingService.php:417`
- Conflict checks in `ConflictDetector` `app/Services/ConflictDetector.php:25`
- Auto-accept in `AutoAcceptService` `app/Services/AutoAcceptService.php:43`
- UI auto-fill in `api/get-record.php:199`
- Validation in `api/settings.php:24` and UI control in `views/settings.php:218`

Meaning:
- The threshold represents the auto-accept confidence cutoff for supplier matching.
- All usages above refer to the same numeric scale (0-100) and the same logical cutoff, even if applied in different steps (conflict check, auto-save, UI auto-fill).
