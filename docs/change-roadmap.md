# Change Roadmap (Current Decisions)

## 0) Scope
- This document is an operational roadmap tied to current decisions.
- It is not a design spec or a quality judgment.
- References:
  - `docs/decisions-6-13-14.md`
  - `docs/decisions-16-18.md`
  - `docs/decisions-19-28.md`
  - `docs/data-trace-map.md`

## 1) Baseline snapshot (to prevent silent regressions)
- Snapshot time: 20260113-015850
- Version (from `VERSION`): 1.0.0
- Git commit (HEAD): cf191da8ecb069d4f0485e74ce8ede295b65a529
- Backup locations:
  - Full backup: `C:\Users\Bakheet\Documents\Projects\BGL3_backups\BGL3-full-20260113-015754.zip`
  - Baseline snapshot: `C:\Users\Bakheet\Documents\Projects\BGL3_backups\baseline-20260113-015850`
- Included artifacts:
  - `storage/database/app.sqlite`
  - `storage/settings.json` (missing at snapshot time)
  - `storage/attachments` (missing at snapshot time)
  - `storage/uploads` (missing at snapshot time)

## 2) Change inventory (Decision -> Files -> Risk -> Status)
Decision | Primary files | Risk | Status / notes
--- | --- | --- | ---
6 (auto-match threshold) | `app/Support/Settings.php`, `app/Support/Config.php`, `api/get-record.php`, `app/Services/SmartProcessingService.php`, `api/settings.php`, `views/settings.php` | High | Implemented in code; confirm acceptance and sync docs if needed.
13 (Toast unify) | `public/js/main.js`, `public/js/records.controller.js`, `public/js/input-modals.controller.js`, `public/js/timeline.controller.js`, `views/batch-detail.php` | Medium | Implemented in code; UI-only.
14/28 (partial batch + one action) | `app/Services/BatchService.php`, `api/batches.php`, `views/batch-detail.php` | High | Implemented in code.
16 (duplicate scripts) | `index.php` | Low | Implemented in code; original decision asked for evidence first.
17 (app.js ref) | `views/index.php` (reference only) | Low | Evidence required; no change.
18 (doc drift) | `docs/*` | Low | Document-only, no code change.
19 (levenshtein guard) | `app/Services/Learning/Feeders/FuzzySignalFeeder.php` | High | Implemented in code.
20 (ShadowExecutor) | `app/Services/ShadowExecutor.php` | Low | Document-only; confirm not used.
21 (manual entry paths) | `api/create-guarantee.php`, `api/manual-entry.php`, `app/Services/ImportService.php` | Low | Document-only; clarify usage.
22 (add supplier button) | `public/js/records.controller.js`, `partials/record-form.php` | Low | Description-only; no change.
23 (bankSelect) | `public/js/records.controller.js`, `partials/record-form.php` | Low | Description-only; no change.
24 (decision_source/decided_by) | `api/save-and-next.php`, `api/extend.php`, `api/reduce.php`, `api/release.php`, `app/Services/BatchService.php`, `app/Services/SmartProcessingService.php` | High | Implemented in code.
25 (batch trace) | `app/Services/ImportService.php`, `api/manual-entry.php`, `app/Services/ParseCoordinatorService.php` | Low | Implemented: one batch per Excel import (fixed identifier).
26 (extended/reduced) | `views/batch-detail.php`, `views/index.php` | Low | Description-only; no change.
27 (batch JSON contract) | `api/batches.php`, `views/batch-detail.php` | Medium | Implemented in code.

## 3) Critical flows + checklists (fixed test data)
Use the same fixed data set across all tests. Define it once and keep it stable.

### Test data (fixed)
- Bank: `TEST BANK A` (exists in `banks`)
- Supplier (existing): `ALPHA SUPPLY LLC`
- Supplier (new): `BETA SUPPLY LTD` (not in DB)
- Guarantee numbers:
  - `G-TEST-001` (import)
  - `G-TEST-002` (manual entry)
  - `G-TEST-003` (paste)
- Contract number: `C-TEST-001`
- Amount: `1000.00`
- Currency: `USD`

### Import (Excel)
- Steps:
  - Import a file containing `G-TEST-001` with `TEST BANK A` and `ALPHA SUPPLY LLC`.
  - Include one row with an unknown supplier (`BETA SUPPLY LTD`).
- Expected:
  - Guarantees created.
  - Suggestions appear for unknown supplier until manual decision.
  - No 500 errors; import events recorded.

### Manual entry (UI modal)
- Steps:
  - Create `G-TEST-002` via manual entry UI.
- Expected:
  - Record created with `importSource = Manual Entry`.
  - Auto-processing runs without errors.

### Save and next (supplier match)
- Steps:
  - Open a record with no supplier match.
  - Pick suggestion or type `BETA SUPPLY LTD` and save.
- Expected:
  - Supplier decision recorded with `decision_source = manual`.
  - Bank decision is not altered by supplier-only save.

### Extend / Reduce / Release
- Steps:
  - Apply extend, reduce, and release on a ready guarantee.
- Expected:
  - Only release changes `status` to `released`.
  - `decision_source`/`decided_by` set correctly.

### Batch actions (partial execution)
- Steps:
  - Open a batch with multiple guarantees.
  - Select a subset (e.g., 2 out of 5) and run extend or release.
- Expected:
  - Only selected guarantees processed.
  - Per-guarantee result reported (processed/blocked/failed).
  - No duplicate action per guarantee inside the same batch.

### Print
- Steps:
  - Use batch print button.
- Expected:
  - Prints only guarantees that have an action applied.

### Settings UI
- Steps:
- Set `MATCH_AUTO_THRESHOLD` to a known value (e.g., 95).
  - Save and reopen settings.
- Expected:
  - Value persists and is used in matching logic.

## 4) Incremental change batches (apply + test immediately)
- Batch A (low risk): completed 2026-01-13; documentation-only updates + test run log.
- Batch B (medium risk): completed 2026-01-13; API contract review (no new code changes required).
- Batch C (high risk): completed 2026-01-13; matching, saving, and batch execution logic.
- After each batch: run smoke test + the critical flow checklist (skip only if no code changes).

## 5) API contract review (Batch B)
- Date: 2026-01-13 02:31:17 (local)
- JSON endpoints verified:
  - `api/create-bank.php`, `api/create_supplier.php`, `api/update_bank.php`, `api/update_supplier.php`,
    `api/delete_bank.php`, `api/delete_supplier.php`, `api/learning-action.php`, `api/settings.php`
  - `api/create-guarantee.php`, `api/parse-paste.php`, `api/save-and-next.php`,
    `api/extend.php`, `api/reduce.php`, `api/release.php`, `api/create-supplier.php`
  - `api/batches.php` (JSON merged with `$_POST` for compatibility)
- Form-data endpoints verified:
  - `api/import.php`, `api/import_banks.php`, `api/import_suppliers.php`, `api/upload-attachment.php`
- Result: no remaining JSON/POST contract mismatches detected.

## 6) Temporary guardrails (during testing)
- Log auto-match decisions: confidence, threshold, and source.
- Log batch actions: selected IDs, blocked reasons, and outcomes.
- Log save-and-next changes: supplier decision + bank decision presence.
  - Implemented in:
    - `app/Services/SmartProcessingService.php`
    - `api/get-record.php`
    - `api/save-and-next.php`
    - `app/Services/BatchService.php`

## 7) Acceptance criteria
- No HTTP 500 errors in critical flows.
- Matching/auto-save behavior changes only where intended.
- Batch print output matches the actioned guarantees shown in UI.

## 8) Test run log (manual)
- Date: 2026-01-13 02:26:38 (local)
- Environment: local server `http://localhost:8000`
- Test data: temporary bank/suppliers/guarantees created, then removed; DB restored to baseline snapshot.
- Flows executed:
  - Import (`api/import.php`): success, imported 2, auto_matched 0.
  - Manual entry (`api/create-guarantee.php`): success.
  - Save-and-next (`api/save-and-next.php`): success.
  - Batch extend partial (`api/batches.php`): success; blocked not-ready guarantees.
  - Extend/reduce/release (`api/extend.php`, `api/reduce.php`, `api/release.php`): HTTP 200.
  - Batch print (`views/batch-print.php`): HTTP 200.
  - Settings (`api/settings.php` POST/GET): success.
- Cleanup:
  - Removed test records and files.
  - Restored `storage/database/app.sqlite` from baseline snapshot.

- Date: 2026-01-13 02:41:52 (local)
- Environment: local server `http://localhost:8000`
- Test data:
  - Bank alias from live DB: `مصرف الراجحي` (id 1)
  - Suppliers created for test: `ALPHA SUPPLY LLC`, `UNMATCHABLE_SUPPLIER_12345` (removed after run)
- Flows executed:
  - Import (`api/import.php`): success, imported 3 (two auto-matched, one pending supplier).
  - Manual entry (`api/create-guarantee.php`): success.
  - Save-and-next (`api/save-and-next.php`): success; pending supplier became ready after save due to existing bank_id.
  - Batch extend partial (`api/batches.php`): success on selected guarantees.
  - Extend/reduce/release (`api/extend.php`, `api/reduce.php`, `api/release.php`): HTTP 200.
  - Batch print (`views/batch-print.php`): HTTP 200.
- Cleanup:
  - Removed test files.
  - Restored `storage/database/app.sqlite` from baseline snapshot.

## 9) Decision row policy (adopted)
- Decision: Option A (single-row authority) is adopted.
- Constraint: `guarantee_decisions` enforces `UNIQUE(guarantee_id)` in schema.
- Rule: one row per guarantee; all writes must update that row (or upsert if missing).
- Guidance:
- If issues occur, verify there is only one decision row for the guarantee and that it holds the current supplier_id/bank_id.

## 10) Manual test case (design-only)
- Goal: verify single-row update across bank-only -> full decision.
- Setup:
  - Import a guarantee with a known bank and an unknown supplier to create a bank-only decision.
  - Then save supplier manually via `save-and-next`.
- Expected:
  - Exactly one decision row exists for the guarantee.
  - That row contains both supplier_id and bank_id and status becomes `ready`.
  - UI reflects the saved supplier immediately (no stale pending state).
