# BGL3 Codebase Map

Generated on demand to ensure 100% visibility.


## Core App (app/)
### `app\Contracts\SignalFeederInterface.php`

- **Functions**: getSignals

### `app\DTO\SignalDTO.php`

- **Classes**: SignalDTO
- **Functions**: __construct, toArray

### `app\DTO\SuggestionDTO.php`

- **Classes**: SuggestionDTO
- **Functions**: __construct, validate, toArray

### `app\Models\AuditLog.php`

- **Classes**: AuditLog
- **Functions**: record

### `app\Models\Bank.php`

- **Classes**: Bank
- **Functions**: __construct

### `app\Models\Guarantee.php`

- **Classes**: Guarantee
- **Functions**: __construct, getSupplierName, getBankName, getAmount, getExpiryDate, getDocumentReference, getRelatedTo, toArray

### `app\Models\GuaranteeDecision.php`

- **Classes**: GuaranteeDecision
- **Functions**: __construct, isApproved, canModify, toArray

### `app\Models\ImportedRecord.php`

- **Classes**: ImportedRecord
- **Functions**: __construct

### `app\Models\ImportSession.php`

- **Classes**: ImportSession
- **Functions**: __construct

### `app\Models\LearningLog.php`

- **Classes**: LearningLog
- **Functions**: __construct

### `app\Models\Supplier.php`

- **Classes**: Supplier
- **Functions**: __construct

### `app\Models\SupplierAlternativeName.php`

- **Classes**: SupplierAlternativeName
- **Functions**: __construct

### `app\Models\TrustDecision.php`

- **Classes**: TrustDecision
- **Functions**: __construct, allow, block, override, shouldApplyTargetedPenalty

### `app\Repositories\AttachmentRepository.php`

- **Classes**: AttachmentRepository
- **Functions**: __construct, create, getByGuaranteeId, delete, find

### `app\Repositories\BankRepository.php`

- **Classes**: BankRepository
- **Functions**: findByNormalizedName, find, map, getBankDetails, allNormalized, search, create, update, delete

### `app\Repositories\BatchMetadataRepository.php`

- **Classes**: BatchMetadataRepository
- **Functions**: __construct, ensureBatchName

### `app\Repositories\GuaranteeDecisionRepository.php`

- **Classes**: GuaranteeDecisionRepository
- **Functions**: __construct, findByGuarantee, createOrUpdate, create, update, lock, setActiveAction, getActiveAction, clearActiveAction, getHistoricalSelections... (+2 more)

### `app\Repositories\GuaranteeHistoryRepository.php`

- **Classes**: GuaranteeHistoryRepository
- **Functions**: log, getHistory, find

### `app\Repositories\GuaranteeRepository.php`

- **Classes**: GuaranteeRepository
- **Functions**: __construct, getDb, find, findByNumber, create, updateRawData, getAll, count, buildWhereClause, hydrate... (+4 more)

### `app\Repositories\ImportedRecordRepository.php`

- **Classes**: ImportedRecordRepository
- **Functions**: __construct, find

### `app\Repositories\LearningRepository.php`

- **Classes**: LearningRepository
- **Functions**: __construct, getUserFeedback, getRejections, getHistoricalSelections, logDecision, logSupplierDecision, pruneOldDecisions

### `app\Repositories\NoteRepository.php`

- **Classes**: NoteRepository
- **Functions**: __construct, create, getByGuaranteeId

### `app\Repositories\SupplierAlternativeNameRepository.php`

- **Classes**: SupplierAlternativeNameRepository
- **Functions**: __construct, findByNormalized, findAllByNormalizedName, findAllByNormalized, allNormalized

### `app\Repositories\SupplierLearningRepository.php`

- **Classes**: SupplierLearningRepository
- **Functions**: __construct, findSuggestions, incrementUsage, decrementUsage, learnAlias, logDecision, findConflictingAliases, normalize

### `app\Repositories\SupplierOverrideRepository.php`

- **Classes**: SupplierOverrideRepository
- **Functions**: __construct, allNormalized

### `app\Repositories\SupplierRepository.php`

- **Classes**: SupplierRepository
- **Functions**: findAllByNormalized, allNormalized, search, update, delete, find, findByNormalizedName, create, findByNormalizedKey, getAllSuppliers... (+4 more)

### `app\Services\AuthManagerAgentService.php`

- **Classes**: AuthManagerAgentService
- **Functions**: validateSession, debugPing

### `app\Services\AutoAcceptService.php`

- *Type*: Script / Data / Documentation

### `app\Services\BankManagementService.php`

- **Classes**: BankManagementService
- **Functions**: create

### `app\Services\BatchService.php`

- **Classes**: BatchService
- **Functions**: __construct, isBatchClosed, getBatchGuarantees, extendBatch, releaseBatch, reduceBatch, normalizeIds, normalizeReductionMap, isValidDate, resolveExpiryDate... (+5 more)

### `app\Services\ConflictDetector.php`

- **Classes**: ConflictDetector
- **Functions**: __construct, detect

### `app\Services\DecisionService.php`

- **Classes**: DecisionService
- **Functions**: __construct, save, lock, canModify

### `app\Services\ExcelColumnDetector.php`

- **Classes**: ExcelColumnDetector
- **Functions**: detect, normalize

### `app\Services\FieldExtractionService.php`

- **Classes**: FieldExtractionService
- **Functions**: extractGuaranteeNumber, extractAmount, extractExpiryDate, extractIssueDate, extractSupplier, extractBank, extractContractNumber, detectType, detectIntent, extractWithPatterns... (+1 more)

### `app\Services\GuaranteeDataService.php`

- **Classes**: GuaranteeDataService
- **Functions**: getRelatedData

### `app\Services\ImportService.php`

- **Classes**: ImportService
- **Functions**: __construct, sanitizeFilename, importFromExcel, createManually, detectColumns, normalizeHeader, getColumn, normalizeAmount, normalizeDate, validateImportData... (+2 more)

### `app\Services\LetterBuilder.php`

- **Classes**: LetterBuilder
- **Functions**: prepare, buildHeader, buildSubjectParts, buildSubjectString, buildContent, buildReleaseContent, buildExtensionContent, getSignature, buildCC, render

### `app\Services\MatchEngine.php`

- **Classes**: MatchEngine
- **Functions**: calculateScore

### `app\Services\NavigationService.php`

- **Classes**: NavigationService
- **Functions**: getNavigationInfo, buildFilterConditions, getTotalCount, getCurrentPosition, getPreviousId, getNextId, getIdByIndex

### `app\Services\ParseCoordinatorService.php`

- **Classes**: ParseCoordinatorService
- **Functions**: parseText, processMultiRow, processSingleTableRow, processSingleText, extractFieldsFromText, maskExtractedValue, validateAndCreate, calculateConfidenceScores, calculateOverallConfidence, createGuaranteeFromRow... (+3 more)

### `app\Services\PreviewFormatter.php`

- **Classes**: PreviewFormatter
- **Functions**: toArabicNumerals, formatArabicDate, translateType, getIntroPhrase

### `app\Services\RecordHydratorService.php`

- **Classes**: RecordHydratorService
- **Functions**: __construct, hydrate, resolveSupplierName, resolveBankName

### `app\Services\SafetyTestService.php`

- **Classes**: SafetyTestService

### `app\Services\SmartProcessingService.php`

- **Classes**: SmartProcessingService
- **Functions**: __construct, processNewGuarantees, createAutoDecision, createBankOnlyDecision, fetchDecisionRow, logAutoMatchEvents, updateBankNameInRawData, logBankAutoMatchEvent, evaluateTrust

### `app\Services\StatsService.php`

- **Classes**: StatsService
- **Functions**: getImportStats

### `app\Services\StatusEvaluator.php`

- **Classes**: StatusEvaluator
- **Functions**: evaluate, evaluateFromDatabase, getReasons

### `app\Services\SupplierCandidateService.php`

- **Classes**: SupplierCandidateService
- **Functions**: __construct, supplierCandidates

### `app\Services\SupplierManagementService.php`

- **Classes**: SupplierManagementService
- **Functions**: create

### `app\Services\TableDetectionService.php`

- **Classes**: TableDetectionService
- **Functions**: detectTable, parseRow, calculateConfidence, validateRow, isAmount, isDate, isBankCode, isGuaranteeNumber, isContractNumber, isSupplier

### `app\Services\TimelineDisplayService.php`

- **Classes**: TimelineDisplayService
- **Functions**: getEventsForDisplay

### `app\Services\TimelineRecorder.php`

- **Classes**: TimelineRecorder
- **Functions**: createSnapshot, generateLetterSnapshot, recordExtensionEvent, recordReductionEvent, recordReleaseEvent, recordDecisionEvent, recordEvent, detectChanges, calculateStatus, recordImportEvent... (+7 more)

### `app\Services\ValidationService.php`

- **Classes**: ValidationService
- **Functions**: __construct, canExtend, canRelease, canAutoMatch

### `app\Support\Alert.php`

- **Classes**: Alert
- **Functions**: logFailure

### `app\Support\ArabicNormalizer.php`

- **Classes**: ArabicNormalizer
- **Functions**: normalize, getCurrentPhase, isPhaseActive

### `app\Support\autoload.php`

- **Classes**: is
- **Functions**: base_path, storage_path

### `app\Support\BankNormalizer.php`

- **Classes**: BankNormalizer
- **Functions**: normalize

### `app\Support\Config.php`

- **Classes**: Config

### `app\Support\Database.php`

- **Classes**: Database
- **Functions**: connect, connection

### `app\Support\DateTime.php`

- **Classes**: DateTime
- **Functions**: getTimezone, now, today, timestamp, format, fromUnix, getConfiguredTimezone, getTimezoneOffset, resetTimezone, parse

### `app\Support\Input.php`

- **Classes**: Input
- **Functions**: string, int, bool, array

### `app\Support\Logger.php`

- **Classes**: Logger
- **Functions**: getLogPath, isProductionMode, shouldLog, write, debug, info, warning, error

### `app\Support\mb_levenshtein.php`

- **Functions**: mb_levenshtein

### `app\Support\Normalizer.php`

- **Classes**: Normalizer
- **Functions**: normalizeName, normalizeSupplierName, normalizeBankName, normalizeBankShortCode, makeSupplierKey

### `app\Support\QuickTrace.php`

- **Classes**: QuickTrace
- **Functions**: log, normalizeQuery

### `app\Support\RateLimiter.php`

- **Classes**: RateLimiter
- **Functions**: allow, persist

### `app\Support\ScoringConfig.php`

- **Classes**: ScoringConfig
- **Functions**: getStarRating, calculateUsageBonus

### `app\Support\Settings.php`

- **Classes**: manages, Settings
- **Functions**: __construct, all, save, get, getInstance, isProductionMode, normalizePercentage

### `app\Support\SimilarityCalculator.php`

- **Classes**: SimilarityCalculator
- **Functions**: fastLevenshteinRatio, safeLevenshteinRatio, tokenJaccardSimilarity

### `app\Support\SimpleXlsxReader.php`

- **Classes**: SimpleXlsxReader
- **Functions**: read

### `app\Support\TracedPDO.php`

- **Classes**: TracedPDO
- **Functions**: __construct, exec

### `app\Support\TracedStatement.php`

- **Classes**: TracedStatement
- **Functions**: __construct, execute

### `app\Support\TypeNormalizer.php`

- **Classes**: TypeNormalizer
- **Functions**: normalize

### `app\Support\Validation.php`

- **Classes**: Validation
- **Functions**: validateBank, isValidIban

### `app\Services\Learning\AuthorityFactory.php`

- **Classes**: AuthorityFactory
- **Functions**: create, createAliasFeeder, createLearningFeeder, createFuzzyFeeder, createAnchorFeeder, createHistoricalFeeder

### `app\Services\Learning\ConfidenceCalculatorV2.php`

- **Classes**: ConfidenceCalculatorV2
- **Functions**: __construct, loadBaseScores, calculate, assignLevel, meetsDisplayThreshold, identifyPrimarySignal, getBaseScore, calculateConfirmationBoost, calculateRejectionPenalty, calculateStrengthModifier

### `app\Services\Learning\SuggestionFormatter.php`

- **Classes**: SuggestionFormatter
- **Functions**: __construct, toDTO, generateReasonArabic, requiresConfirmation

### `app\Services\Learning\UnifiedLearningAuthority.php`

- **Classes**: UnifiedLearningAuthority
- **Functions**: __construct, registerFeeder, getSuggestions, gatherSignals, aggregateBySupplier, computeConfidenceScores, identifyPrimarySignal, detectAmbiguity

### `app\Services\SmartPaste\ConfidenceCalculator.php`

- **Classes**: ConfidenceCalculator, for
- **Functions**: calculateSupplierConfidence, calculateBankConfidence, calculateAmountConfidence, calculateDateConfidence, normalizeNumber, isGibberish, getConfidenceLevel, getConfidenceClass

### `app\Services\Suggestions\ArabicEntityAnchorExtractor.php`

- **Classes**: ArabicEntityAnchorExtractor
- **Functions**: extract, isAnchorCandidate, classifyAnchorType, tryCompoundAnchor, isPersonNamePattern, extractActivityWords

### `app\Services\Suggestions\ArabicEntityExtractor.php`

- **Classes**: ArabicEntityExtractor
- **Functions**: extractAnchors, stripCompanyPrefix, stripLeadingParticles, buildCompoundAnchors, isNumericToken

### `app\Services\Suggestions\ArabicLevelBSuggestions.php`

- **Classes**: ArabicLevelBSuggestions
- **Functions**: __construct, find, searchByAnchor, inArrayById, isUniqueAnchor, scoreMatch, deduplicateBySupplier, logSilentRejection

### `app\Services\Suggestions\ConfidenceCalculator.php`

- **Classes**: ConfidenceCalculator
- **Functions**: calculate, getLevel, getBaseConfidence, getConfirmationBoost, getHistoricalBoost

### `app\Services\Suggestions\LearningSuggestionService.php`

- **Classes**: LearningSuggestionService
- **Functions**: __construct, getSuggestions

### `app\Services\Learning\Feeders\AliasSignalFeeder.php`

- **Classes**: AliasSignalFeeder
- **Functions**: __construct, getSignals

### `app\Services\Learning\Feeders\AnchorSignalFeeder.php`

- **Classes**: AnchorSignalFeeder
- **Functions**: __construct, getSignals, calculateAnchorFrequencies, determineSignalType, calculateAnchorStrength, classifyAnchorType

### `app\Services\Learning\Feeders\FuzzySignalFeeder.php`

- **Classes**: FuzzySignalFeeder
- **Functions**: __construct, getSignals, calculateSimilarity, determineSignalType, hasDistinctiveKeywordMatch

### `app\Services\Learning\Feeders\HistoricalSignalFeeder.php`

- **Classes**: HistoricalSignalFeeder
- **Functions**: __construct, getSignals, determineSignalType, calculateHistoricalStrength

### `app\Services\Learning\Feeders\LearningSignalFeeder.php`

- **Classes**: LearningSignalFeeder
- **Functions**: __construct, getSignals

### `app\Http\Requests\BaseApiRequest.php`

- **Classes**: BaseApiRequest
- **Functions**: rules, validate

### `app\Http\Requests\CreateBankRequest.php`

- **Classes**: CreateBankRequest
- **Functions**: rules

### `app\Http\Requests\CreateSupplierRequest.php`

- **Classes**: CreateSupplierRequest
- **Functions**: rules

### `app\Config\agent.json`

- *Type*: Script / Data / Documentation


## API Layer (api/)
### `api\agent-event.php`

- *Type*: Script / Data / Documentation

### `api\batches.php`

- *Type*: Script / Data / Documentation

### `api\convert-to-real.php`

- *Type*: Script / Data / Documentation

### `api\create-bank.php`

- *Type*: Script / Data / Documentation

### `api\create-guarantee.php`

- *Type*: Script / Data / Documentation

### `api\create-supplier.php`

- *Type*: Script / Data / Documentation

### `api\delete_bank.php`

- *Type*: Script / Data / Documentation

### `api\delete_supplier.php`

- *Type*: Script / Data / Documentation

### `api\export_banks.php`

- *Type*: Script / Data / Documentation

### `api\export_suppliers.php`

- *Type*: Script / Data / Documentation

### `api\extend.php`

- *Type*: Script / Data / Documentation

### `api\get-current-state.php`

- *Type*: Script / Data / Documentation

### `api\get-history-snapshot.php`

- *Type*: Script / Data / Documentation

### `api\get-letter-preview.php`

- *Type*: Script / Data / Documentation

### `api\get-record.php`

- *Type*: Script / Data / Documentation

### `api\get-timeline.php`

- *Type*: Script / Data / Documentation

### `api\get_banks.php`

- **Functions**: renderPagination

### `api\get_suppliers.php`

- **Functions**: renderPagination

### `api\history.php`

- *Type*: Script / Data / Documentation

### `api\import.php`

- *Type*: Script / Data / Documentation

### `api\import_banks.php`

- *Type*: Script / Data / Documentation

### `api\import_suppliers.php`

- *Type*: Script / Data / Documentation

### `api\learning-action.php`

- *Type*: Script / Data / Documentation

### `api\learning-data.php`

- *Type*: Script / Data / Documentation

### `api\manual-entry.php`

- *Type*: Script / Data / Documentation

### `api\parse-paste-v2.php`

- *Type*: Script / Data / Documentation

### `api\parse-paste.php`

- *Type*: Script / Data / Documentation

### `api\reduce.php`

- *Type*: Script / Data / Documentation

### `api\release.php`

- *Type*: Script / Data / Documentation

### `api\save-and-next.php`

- *Type*: Script / Data / Documentation

### `api\save-note.php`

- *Type*: Script / Data / Documentation

### `api\settings.php`

- *Type*: Script / Data / Documentation

### `api\smart-paste-confidence.php`

- **Functions**: extractSupplierFromText

### `api\suggestions-learning.php`

- *Type*: Script / Data / Documentation

### `api\update_bank.php`

- *Type*: Script / Data / Documentation

### `api\update_supplier.php`

- *Type*: Script / Data / Documentation

### `api\upload-attachment.php`

- *Type*: Script / Data / Documentation


## Interfaces (views/ & index.php)
### `agent-dashboard.php`

- *Type*: Script / Data / Documentation

### `index.php`

- **Classes**: for
- **Functions**: showToast, showNoteInput, cancelNote, saveNote, uploadFile

### `views\batch-detail.php`

- **Functions**: handleBatchAction, confirmBatchAction, executeBulkAction, openMetadataModal, saveMetadata, printReadyGuarantees

### `views\batch-print.php`

- *Type*: Script / Data / Documentation

### `views\batches.php`

- *Type*: Script / Data / Documentation

### `views\confidence-demo.php`

- **Functions**: addConfidence

### `views\index.php`

- *Type*: Script / Data / Documentation

### `views\maintenance.php`

- *Type*: Script / Data / Documentation

### `views\settings.php`

- **Functions**: showAlert, hideAlerts, openModal, closeModal, showConfirm, createBank, addAliasFieldSettings, createSupplier, switchTab, loadBanks... (+11 more)

### `views\statistics.php`

- **Functions**: formatMoney, formatNumber


## Agent Core (.bgl_core/)
### `.bgl_core\actuators\patcher.php`

- **Classes**: name, references, not, not, was
- **Functions**: parseMethodBody, __temp__, __construct, enterNode, __construct, matches, enterNode, __construct, enterNode

### `.bgl_core\brain\logic_bridge.php`

- **Functions**: safe_output

### `.bgl_core\debug_tools\debug_kpi.php`

- *Type*: Script / Data / Documentation

### `.bgl_core\debug_tools\tmp_runner.php`

- *Type*: Script / Data / Documentation

### `.bgl_core\sensors\ast_bridge.php`

- **Classes**: SensorVisitor, methods, instanceof, instanceof
- **Functions**: beforeTraverse, enterNode, leaveNode, getVisibility

### `.bgl_core\brain\agency_core.py`

- **Classes**: AgencyCore
- **Functions**: __new__, __init__, run_full_diagnostic, _execution_stats, get_active_blockers, solve_blocker, commit_proposed_rule, request_permission, is_permission_granted, log_activity... (+1 more)

### `.bgl_core\brain\agent_tasks.py`

- **Functions**: simulate_agent_task

### `.bgl_core\brain\agent_verify.py`

- **Functions**: run_all_checks

### `.bgl_core\brain\apply_db_fixes.py`

- **Functions**: run_sqlite, main

### `.bgl_core\brain\apply_proposal.py`

- **Functions**: insert_outcome, insert_decision, main

### `.bgl_core\brain\approve_playbook.py`

- **Functions**: append_rule, approve

### `.bgl_core\brain\brain_rules.py`

- **Classes**: RuleRegistry, RuleEngine
- **Functions**: get_core_rules, __init__, evaluate, _check_is_arabic, _apply_action

### `.bgl_core\brain\brain_types.py`

- **Classes**: Intent, class, class, OperationalMode, class, class

### `.bgl_core\brain\browser_core.py`

- **Classes**: BrowserCore
- **Functions**: __init__, _env_float, start, stop, heartbeat, restart, navigate, handle_request_failed, _resource_overloaded, _main_test

### `.bgl_core\brain\browser_manager.py`

- **Classes**: BrowserManager
- **Functions**: __init__, _ensure_browser, _cleanup_idle_pages, get_page, new_page, close, status

### `.bgl_core\brain\browser_sensor.py`

- **Classes**: BrowserSensor
- **Functions**: __init__, _ensure_browser, scan_url, handle_request_failed, _write_status, main

### `.bgl_core\brain\callgraph_builder.py`

- **Classes**: we
- **Functions**: _guess_layer, _get_dependencies, _get_entity_method_id, build_callgraph

### `.bgl_core\brain\check_mouse_layer.py`

- **Functions**: main

### `.bgl_core\brain\commit_rule.py`

- **Functions**: main

### `.bgl_core\brain\config_loader.py`

- **Functions**: load_config

### `.bgl_core\brain\context_digest.py`

- **Functions**: fetch_events, load_route_map, summarize, upsert_experiences, main

### `.bgl_core\brain\context_extractor.py`

- **Functions**: _read_snippet, extract

### `.bgl_core\brain\contract_tests.py`

- **Functions**: run_contract_suite

### `.bgl_core\brain\decision_db.py`

- **Functions**: init_db, _connect, insert_intent, insert_decision, insert_outcome

### `.bgl_core\brain\decision_engine.py`

- **Functions**: decide

### `.bgl_core\brain\embeddings.py`

- **Functions**: _tokenize, _vectorize, _cosine, _ensure_table, add_text, search

### `.bgl_core\brain\execution_gate.py`

- **Functions**: check

### `.bgl_core\brain\experience_replay.py`

- **Functions**: _ensure, save, fetch

### `.bgl_core\brain\fault_locator.py`

- **Classes**: FaultLocator
- **Functions**: __init__, get_context_from_log, _locate_url, locate_url, diagnose_fault

### `.bgl_core\brain\generate_openapi.py`

- **Functions**: generate

### `.bgl_core\brain\generate_playbooks.py`

- **Functions**: generate_from_proposed

### `.bgl_core\brain\governor.py`

- **Classes**: BGLGovernor
- **Functions**: __init__, _load_rules, audit, _check_content_rule, _classify_entities, _check_relationship_rule, _check_naming_rule, _get_entities_by_type

### `.bgl_core\brain\guardian.py`

- **Classes**: BGLGuardian
- **Functions**: __init__, perform_full_audit, _update_route_health, auto_remediate, log_maintenance, _prune_logs, _check_learning_confirmations, _check_business_conflicts_real, _get_proxied_routes, _has_permission... (+14 more)

### `.bgl_core\brain\guardrails.py`

- **Classes**: BGLGuardrails
- **Functions**: __init__, is_path_allowed, validate_changes, filter_paths

### `.bgl_core\brain\hand_profile.py`

- **Classes**: import, class
- **Functions**: generate

### `.bgl_core\brain\indexer.py`

- **Classes**: EntityIndexer
- **Functions**: __init__, update_impacted, index_project, close, _should_index, _index_file

### `.bgl_core\brain\inference.py`

- **Classes**: ReasoningEngine
- **Functions**: __init__, _get_project_structure, _get_file_hash, reason, _analyze_backend_logic, _json_serializer, _build_reasoning_prompt, _extract_json_from_text, _query_llm, chat... (+1 more)

### `.bgl_core\brain\intent_resolver.py`

- **Functions**: resolve_intent

### `.bgl_core\brain\interpretation.py`

- **Functions**: interpret

### `.bgl_core\brain\llm_tools.py`

- **Functions**: tool_run_checks, tool_route_index, tool_logic_bridge, tool_layout_map, tool_context_pack, tool_score_response, tool_schema, dispatch

### `.bgl_core\brain\master_verify.py`

- **Functions**: log_activity, master_assurance_diagnostic

### `.bgl_core\brain\memory.py`

- **Classes**: StructureMemory
- **Functions**: __init__, _connect, _init_schema_once, register_file, get_file_info, clear_file_data, store_nested_symbols, _store_calls, close

### `.bgl_core\brain\metrics_guard.py`

- **Functions**: load_summary, main

### `.bgl_core\brain\metrics_summary.py`

- **Functions**: summarize, stats

### `.bgl_core\brain\migrate_decision_to_knowledge.py`

- **Functions**: ensure_schema, migrate

### `.bgl_core\brain\motor.py`

- **Classes**: MouseState, Motor
- **Functions**: __init__, move_to

### `.bgl_core\brain\orchestrator.py`

- **Classes**: ExecutionReport, BGLOrchestrator
- **Functions**: __init__, execute_task

### `.bgl_core\brain\patcher.py`

- **Classes**: BGLPatcher, name, using, to, requires
- **Functions**: __init__, rename_class, add_method, _run_action, _update_references, _derive_impacted_tests, _derive_impacted_files, _post_patch_index, _post_patch_index_all, _discover_composer... (+2 more)

### `.bgl_core\brain\perception.py`

- **Functions**: capture_local_context

### `.bgl_core\brain\playbook_loader.py`

- **Functions**: load_playbooks_meta

### `.bgl_core\brain\policy.py`

- **Classes**: Policy
- **Functions**: __init__, perform_click, perform_goto, _start_dom_watch, _wait_dom_change, _find_alternative

### `.bgl_core\brain\report_builder.py`

- **Functions**: build_report, load_latest_health

### `.bgl_core\brain\route_indexer.py`

- **Classes**: LaravelRouteIndexer
- **Functions**: __init__, run, index_project, _analyze_file, _infer_method

### `.bgl_core\brain\run_scenarios.py`

- **Functions**: simulate_traffic

### `.bgl_core\brain\safety.py`

- **Classes**: SafetyNet
- **Functions**: __init__, _safe_float, create_backup, preflight, validate, _gather_unified_logs, _read_backend_logs, _tests_from_experiences, rollback, clear_backup... (+5 more)

### `.bgl_core\brain\sandbox.py`

- **Classes**: BGLSandbox
- **Functions**: __init__, setup, apply_to_main, cleanup, remove_readonly, _copy_untracked, _prepare_sandbox_db, _prepare_decision_db

### `.bgl_core\brain\scenario_runner.py`

- **Functions**: ensure_cursor, ensure_dev_mode, exploratory_action, run_step, log_event, run_scenario, handle_request_failed, handle_response, main

### `.bgl_core\brain\utils.py`

- **Functions**: load_route_usage

### `.bgl_core\brain\verify_phase_8_simulation.py`

- **Functions**: simulate_recurring_blockers

### `.bgl_core\debug_tools\check_dashboard.py`

- *Type*: Script / Data / Documentation

### `.bgl_core\debug_tools\check_deps.py`

- *Type*: Script / Data / Documentation

### `.bgl_core\debug_tools\debug_kpi.py`

- *Type*: Script / Data / Documentation

### `.bgl_core\debug_tools\tmp_add.py`

- *Type*: Script / Data / Documentation

### `.bgl_core\debug_tools\tmp_print.py`

- *Type*: Script / Data / Documentation

### `.bgl_core\debug_tools\tmp_ren.py`

- *Type*: Script / Data / Documentation

### `.bgl_core\debug_tools\tmp_rename.py`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\archive\verify_confidence.py`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\archive\verify_failure_modes.py`

- **Functions**: test_atomic_rollback, test_sandbox_isolation, test_guardrail_barrier

### `.bgl_core\brain\archive\verify_final_agent.py`

- **Classes**: AuthManager
- **Functions**: login, test_specialized_programming

### `.bgl_core\brain\archive\verify_intelligence.py`

- **Classes**: AgentBenchmark
- **Functions**: __init__, run_case, save_report, benchmark_intelligence

### `.bgl_core\brain\archive\verify_patch.py`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\archive\verify_phase_1.py`

- **Functions**: test_browser_sensor_integration

### `.bgl_core\brain\archive\verify_phase_2.py`

- **Functions**: test_unified_perception

### `.bgl_core\brain\archive\verify_phase_3.py`

- **Functions**: test_fault_localization

### `.bgl_core\brain\archive\verify_phase_5.py`

- **Functions**: verify_phase_5

### `.bgl_core\brain\checks\css_bloat.py`

- **Functions**: run

### `.bgl_core\brain\checks\db_fk_missing.py`

- **Functions**: run, fk_exists

### `.bgl_core\brain\checks\db_index_missing.py`

- **Functions**: run, has_index

### `.bgl_core\brain\checks\js_bloat.py`

- **Functions**: run

### `.bgl_core\brain\checks\missing_alerts_aggregator.py`

- **Functions**: run

### `.bgl_core\brain\checks\missing_audit_trail.py`

- **Functions**: run

### `.bgl_core\brain\checks\missing_caching_reports.py`

- **Functions**: run

### `.bgl_core\brain\checks\missing_import_safety.py`

- **Functions**: run

### `.bgl_core\brain\checks\missing_rate_limit_middleware.py`

- **Functions**: run

### `.bgl_core\brain\checks\missing_validation_guards.py`

- **Functions**: run

### `.bgl_core\brain\checks\__init__.py`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\decision_schema.sql`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\schema.sql`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\patch_templates\audit_trigger.sql`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\patch_templates\db_add_foreign_keys.sql`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\patch_templates\db_add_indexes.sql`

- *Type*: Script / Data / Documentation

### `.bgl_core\README.md`

- **Classes**: name, rename

### `.bgl_core\brain\CORE_OPERATIONS.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\business_rules.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\code_map.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\logs\benchmark_report.md`

- **Classes**: node

### `.bgl_core\logs\BGL3_Technical_Assurance_Manual.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\logs\failure_modes_report.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\agent.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\agent_audit.py.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\Alert.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\AlertsTest.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\AliasSignalFeeder.php.insight.md`

- **Classes**: named

### `.bgl_core\knowledge\auto_insights\AnchorSignalFeeder.php.insight.md`

- **Classes**: appears, has

### `.bgl_core\knowledge\auto_insights\ArabicEntityAnchorExtractor.php.insight.md`

- **Classes**: named

### `.bgl_core\knowledge\auto_insights\ArabicEntityExtractor.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\ArabicLevelBSuggestions.php.insight.md`

- **Classes**: named, seems, with

### `.bgl_core\knowledge\auto_insights\ArabicLevelBSuggestions.php.insight.md.insight.md`

- **Classes**: named

### `.bgl_core\knowledge\auto_insights\ArabicNormalizer.php.insight.md`

- **Classes**: provides, doesn
- **Functions**: normalize

### `.bgl_core\knowledge\auto_insights\ask_ai.py.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\ast_bridge.php.insight.md`

- **Classes**: relationships, SecureASTSensor, remains
- **Functions**: analyzeFile, parseCode, validateFilePath, getMemoryBytes, errorResponse

### `.bgl_core\knowledge\auto_insights\AttachmentRepository.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\AuditLog.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\AuditTrailTest.php.insight.md`

- **Classes**: AuditTrailTest, exists, must
- **Functions**: testAuditTrailCapturesGuaranteeLifecycle, testAuditTrailLogsCriticalEvents

### `.bgl_core\knowledge\auto_insights\AuthManagerAgentService.php.insight.md`

- **Classes**: is

### `.bgl_core\knowledge\auto_insights\AuthManagerTest.php.insight.md`

- **Classes**: that, AuthManagerTest
- **Functions**: setUp, testDebugPingReturnsPong, testSessionValidationForGuaranteeAccess, testUnauthorizedAccessToSensitiveOperations, testSessionExpiration

### `.bgl_core\knowledge\auto_insights\AuthorityFactory.php.insight.md`

- **Classes**: named, is, to

### `.bgl_core\knowledge\auto_insights\autoload.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\BackupExportTest.php.insight.md`

- **Classes**: BackupExportTest
- **Functions**: testBackupCommandExistsAndFunctions, testBackupIncludesCriticalTables

### `.bgl_core\knowledge\auto_insights\Bank.php.insight.md`

- **Classes**: named, also, named

### `.bgl_core\knowledge\auto_insights\BankManagementService.php.insight.md`

- **Classes**: serves
- **Functions**: create

### `.bgl_core\knowledge\auto_insights\BankNormalizer.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\BankRepository.php.insight.md`

- **Classes**: named, in

### `.bgl_core\knowledge\auto_insights\BaseApiRequest.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\batch-detail.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\batch-print.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\BatchMetadataRepository.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\BatchService.php.insight.md`

- **Classes**: named

### `.bgl_core\knowledge\auto_insights\blockers.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\bootstrap.php.insight.md`

- **Classes**: class
- **Functions**: validateProposalId, sanitizePath

### `.bgl_core\knowledge\auto_insights\CachingTest.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\callgraph_builder.meta.json.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\confidence-demo.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\ConfidenceCalculator.php.insight.md`

- **Classes**: named

### `.bgl_core\knowledge\auto_insights\ConfidenceCalculatorV2.php.insight.md`

- **Classes**: serves, ConfidenceCalculatorV2
- **Functions**: __construct, loadBaseScores, calculate, meetsDisplayThreshold, calculateCached

### `.bgl_core\knowledge\auto_insights\ConfidenceCalculatorV2.php.insight.md.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\Config.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\config_loader.py.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\ConflictDetector.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\create-supplier.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\CreateBankRequest.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\CreateSupplierRequest.php.insight.md`

- **Classes**: to, is, doesn, serves
- **Functions**: rules, messages

### `.bgl_core\knowledge\auto_insights\CriticalFlowTest.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\CriticalFlowTest.php.insight.md.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\Database.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\DataValidationTest.php.insight.md`

- **Functions**: testBankCreationValidation, testIbanAndEmailValidation

### `.bgl_core\knowledge\auto_insights\DateTime.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\db_schema.md.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\DecisionService.php.insight.md`

- **Classes**: in

### `.bgl_core\knowledge\auto_insights\delete_supplier.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\domain_map.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\events.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\ExcelColumnDetector.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\experiences.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\fault_locator.py.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\FieldExtractionService.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\filters.data.json.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\fix_schema.data.json.insight.md`

- **Classes**: is

### `.bgl_core\knowledge\auto_insights\FuzzySignalFeeder.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\get-letter-preview.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\get_banks.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\get_suppliers.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\Guarantee.php.insight.md`

- **Classes**: named

### `.bgl_core\knowledge\auto_insights\GuaranteeCreateFlowTest.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\GuaranteeDataService.php.insight.md`

- **Classes**: named

### `.bgl_core\knowledge\auto_insights\GuaranteeDataService.php.insight.md.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\GuaranteeDecision.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\GuaranteeDecisionRepository.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\GuaranteeFlowValidationTest.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\GuaranteeHistoryRepository.php.insight.md`

- **Classes**: manages
- **Functions**: log, getHistory

### `.bgl_core\knowledge\auto_insights\GuaranteeRepository.php.insight.md`

- **Classes**: named

### `.bgl_core\knowledge\auto_insights\HistoricalSignalFeeder.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\ImportedRecord.php.insight.md`

- **Classes**: ImportedRecord
- **Functions**: __construct, isValidAmount, isValidDate, isReadyForProcessing

### `.bgl_core\knowledge\auto_insights\ImportedRecordRepository.php.insight.md`

- **Classes**: named, in

### `.bgl_core\knowledge\auto_insights\ImportFlowSmokeTest.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\ImportFlowSmokeTest.php.insight.md.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\ImportSafetyTest.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\ImportSafetyTest.php.insight.md.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\ImportService.php.insight.md`

- **Classes**: named, uses

### `.bgl_core\knowledge\auto_insights\ImportSession.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\index.php.insight.md`

- **Classes**: class, GuaranteeSearchService
- **Functions**: validateNavigationParams, buildSearchConditions

### `.bgl_core\knowledge\auto_insights\index_codebase.py.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\inference.py.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\Input.php.insight.md`

- **Classes**: for, to, is, that, doesn, currently
- **Functions**: amount, date, iban

### `.bgl_core\knowledge\auto_insights\JsSmokeTest.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\js_split_placeholder.md.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\LearningLog.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\LearningLog.php.insight.md.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\LearningRepository.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\LearningSignalFeeder.php.insight.md`

- **Classes**: appears, is

### `.bgl_core\knowledge\auto_insights\LearningSuggestionService.php.insight.md`

- **Classes**: still, loading

### `.bgl_core\knowledge\auto_insights\LetterBuilder.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\llm_tools.py.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\Logger.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\logic_bridge.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\maintenance.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\MatchEngine.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\MatchEngine.php.insight.md.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\mb_levenshtein.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\metrics_summary.json.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\metrics_summary.py.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\metrics_summary_enhanced.json.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\missing_validation_guards.py.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\ModelIntegrityTest.php.insight.md`

- **Classes**: for, assumes, to

### `.bgl_core\knowledge\auto_insights\NavigationService.php.insight.md`

- **Classes**: named

### `.bgl_core\knowledge\auto_insights\NavigationService.php.insight.md.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\Normalizer.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\Normalizer.php.insight.md.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\NoteRepository.php.insight.md`

- **Classes**: in

### `.bgl_core\knowledge\auto_insights\package.json.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\ParseCoordinatorService.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\ParseCoordinatorService.php.insight.md.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\patcher.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\PreviewFormatter.php.insight.md`

- **Classes**: named

### `.bgl_core\knowledge\auto_insights\process.data.json.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\QuickTrace.php.insight.md`

- **Classes**: provides, doesn, QuickTrace
- **Functions**: init, log, flushBuffer, normalizeQuery, registerShutdown

### `.bgl_core\knowledge\auto_insights\QuickTrace.php.insight.md.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\RateLimiter.php.insight.md`

- **Classes**: named, in

### `.bgl_core\knowledge\auto_insights\RateLimitTest.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\README.md.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\RecordHydratorService.php.insight.md`

- **Classes**: serves, RecordHydratorService
- **Functions**: __construct, hydrate, hydrateBatch, hydrateWithPreloaded

### `.bgl_core\knowledge\auto_insights\RenameAliasTraitTest.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\RenamePipelinePlaybookTest.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\SafetyTestService.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\SafetyTestServiceTest.php.insight.md`

- **Classes**: for, exists

### `.bgl_core\knowledge\auto_insights\SafetyTestServiceTest.php.insight.md.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\ScoringConfig.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\ScoringConfigTest.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\ScoringConfigTest.php.insight.md.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\Settings.php.insight.md`

- **Classes**: named

### `.bgl_core\knowledge\auto_insights\SettingsUxTest.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\SignalDTO.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\SignalFeederInterface.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\SimilarityCalculator.php.insight.md`

- **Classes**: named

### `.bgl_core\knowledge\auto_insights\SimpleXlsxReader.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\SimpleXlsxReader.php.insight.md.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\smart-paste-confidence.php.insight.md`

- **Functions**: extractSupplierFromText

### `.bgl_core\knowledge\auto_insights\SmartProcessingService.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\statistics.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\StatsService.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\StatusEvaluator.php.insight.md`

- **Classes**: is

### `.bgl_core\knowledge\auto_insights\SuggestionDTO.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\SuggestionFormatter.php.insight.md`

- **Classes**: that

### `.bgl_core\knowledge\auto_insights\summary.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\Supplier.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\SupplierAlternativeName.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\SupplierAlternativeName.php.insight.md.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\SupplierAlternativeNameRepository.php.insight.md`

- **Classes**: name

### `.bgl_core\knowledge\auto_insights\SupplierCandidateService.php.insight.md`

- **Classes**: is, still, loading

### `.bgl_core\knowledge\auto_insights\SupplierLearningRepository.php.insight.md`

- **Classes**: manages
- **Functions**: findSuggestions, getAdvancedSuggestions, incrementUsage

### `.bgl_core\knowledge\auto_insights\SupplierManagementService.php.insight.md`

- **Classes**: named

### `.bgl_core\knowledge\auto_insights\SupplierOverrideRepository.php.insight.md`

- **Classes**: manages
- **Functions**: findByNormalizedName, createOverride, getActiveOverrides

### `.bgl_core\knowledge\auto_insights\SupplierRepository.php.insight.md`

- **Classes**: named, in

### `.bgl_core\knowledge\auto_insights\TableDetectionService.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\TimelineDisplayService.php.insight.md`

- **Classes**: named, named

### `.bgl_core\knowledge\auto_insights\TimelineRecorder.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\tmp_ren.py.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\TracedPDO.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\TracedStatement.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\TrustDecision.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\TypeNormalizer.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\unified-header.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\unified-header.php.insight.md.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\UnifiedLearningAuthority.php.insight.md`

- **Classes**: named

### `.bgl_core\knowledge\auto_insights\UnifiedLearningAuthority.php.insight.md.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\update_supplier.php.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\Validation.php.insight.md`

- **Classes**: to, provides, doesn, currently
- **Functions**: validateBank, isValidInternationalPhone, isValidSwiftCode

### `.bgl_core\knowledge\auto_insights\ValidationService.php.insight.md`

- **Classes**: named, seems

### `.bgl_core\knowledge\auto_insights\verify_final_agent.py.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\verify_phase_3.py.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\auto_insights\_input.meta.json.insight.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\patch_templates\js_split_placeholder.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\playbooks\alerts.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\playbooks\audit_trail.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\playbooks\backup_export.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\playbooks\caching.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\playbooks\critical_tests.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\playbooks\data_validation.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\playbooks\import_safety.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\playbooks\PAT_CSS_BLOAT.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\playbooks\PAT_DATA_VALIDATION.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\playbooks\PAT_DB_FK_MISSING.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\playbooks\PAT_DB_INDEX_MISSING.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\playbooks\PAT_JS_BLOAT.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\playbooks\PAT_RATE_LIMIT.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\playbooks\production_guard.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\playbooks\rate_limit.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\playbooks\rename_class.md`

- **Classes**: بطريقة

### `.bgl_core\brain\playbooks\settings_ux.md`

- *Type*: Script / Data / Documentation

### `.bgl_core\config.yml`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\domain_rules.yml`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\runtime_safety.yml`

- **Classes**: must

### `.bgl_core\brain\style_rules.yml`

- **Classes**: under

### `.bgl_core\brain\css_inventory.json`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\db_schema.json`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\inference_patterns.json`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\js_inventory.json`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\metrics_summary.json`

- *Type*: Script / Data / Documentation

### `.bgl_core\brain\proposed_patterns.json`

- *Type*: Script / Data / Documentation

### `.bgl_core\knowledge\arch_state.json`

- *Type*: Script / Data / Documentation

### `.bgl_core\logs\benchmark_results.json`

- *Type*: Script / Data / Documentation

### `.bgl_core\logs\latest_report.json`

- *Type*: Script / Data / Documentation

### `.bgl_core\logs\route_scan_stats.json`

- *Type*: Script / Data / Documentation

### `.bgl_core\logs\browser_reports\browser_status.json`

- *Type*: Script / Data / Documentation


## Scripts & Tools (scripts/)
### `scripts\ask_ai.py`

- **Functions**: compute_dynamic_params, build_system_messages, ask_llm, run_ab, build_snapshot, log_chat_intent, main

### `scripts\cli_brain_adapter.py`

- **Functions**: main

### `scripts\debug_inference.py`

- **Functions**: debug

### `scripts\dev_runner.py`

- **Functions**: get_file_mtimes, main, start_server, stop_server

### `scripts\dream_mode.py`

- **Functions**: discover_files, purge_orphans, dream_cycle, get_file_hash

### `scripts\index_codebase.py`

- **Functions**: index_codebase

### `scripts\run_verification_cycle.py`

- **Functions**: run_command, main, run_shadow_phase

### `scripts\seed_blockers.py`

- **Functions**: seed

### `scripts\smart_nav.py`

- **Functions**: get_interactive_elements, think, run_smart_agent

### `scripts\stress_test.py`

- **Functions**: make_request, worker

### `scripts\test_grounding.py`

- **Functions**: test_grounding

### `scripts\tool_gateway.py`

- **Functions**: main

### `scripts\tool_server.py`

- **Classes**: Handler
- **Functions**: _set_headers, do_OPTIONS, do_POST, _handle_tool, _context_snapshot, _log_tail, _phpunit_run, _master_verify, _scenario_run, _route_show... (+10 more)


## Tests (tests/)
### `tests\verify_trace.php`

- *Type*: Script / Data / Documentation

### `tests\Flows\GuaranteeCreateFlowTest.php`

- **Classes**: GuaranteeCreateFlowTest
- **Functions**: testCreateGuaranteeHappyPath

### `tests\Gap\AlertsTest.php`

- **Classes**: AlertsTest
- **Functions**: testAlertsOnRepeatedFailures

### `tests\Gap\AuditTrailTest.php`

- **Classes**: AuditTrailTest
- **Functions**: testAuditTrailHookExists

### `tests\Gap\BackupExportTest.php`

- **Classes**: BackupExportTest
- **Functions**: testBackupCommandExists

### `tests\Gap\CachingTest.php`

- **Classes**: CachingTest
- **Functions**: testReportsAreCached

### `tests\Gap\CriticalFlowTest.php`

- **Classes**: CriticalFlowTest
- **Functions**: testCriticalFlow

### `tests\Gap\DataValidationTest.php`

- **Classes**: DataValidationTest
- **Functions**: testIbanValidation

### `tests\Gap\GuaranteeFlowValidationTest.php`

- **Classes**: GuaranteeFlowValidationTest
- **Functions**: testCreateGuaranteeValidationFailsForMissingFields

### `tests\Gap\ImportFlowSmokeTest.php`

- **Classes**: ImportFlowSmokeTest
- **Functions**: testImportSuppliersRejectsBadFile

### `tests\Gap\ImportSafetyTest.php`

- **Classes**: ImportSafetyTest
- **Functions**: testRejectLargeImport

### `tests\Gap\JsSmokeTest.php`

- **Classes**: JsSmokeTest
- **Functions**: testHomePageLoadsAndScriptsPresent

### `tests\Gap\ModelIntegrityTest.php`

- **Classes**: ModelIntegrityTest
- **Functions**: setUp, postJson, testGuaranteeLifecycle, testBankValidation

### `tests\Gap\RateLimitTest.php`

- **Classes**: RateLimitTest
- **Functions**: testRateLimitReturns429

### `tests\Gap\SettingsUxTest.php`

- **Classes**: SettingsUxTest
- **Functions**: testSettingsAutosave

### `tests\Unit\AuthManagerTest.php`

- **Classes**: AuthManagerTest
- **Functions**: testDebugPingReturnsPong

### `tests\Unit\RenameAliasTraitTest.php`

- **Classes**: RenameAliasTraitTest, MyClass
- **Functions**: testStringAndTraitAliasesAreRenamed, check

### `tests\Unit\RenamePipelinePlaybookTest.php`

- **Classes**: RenamePipelinePlaybookTest
- **Functions**: testPlaybookDocumentedAndConfigPresent

### `tests\Unit\SafetyTestServiceTest.php`

- **Classes**: SafetyTestServiceTest
- **Functions**: testServiceExists

### `tests\Unit\ScoringConfigTest.php`

- **Classes**: ScoringConfigTest
- **Functions**: testStarRatingBoundaries, testUsageBonusCapped

### `tests\agent_audit.py`

- **Classes**: class
- **Functions**: call_agent_via_cli, call_agent_via_http, _norm, _hash, _similarity, detect_over_reasoning, detect_confidence_language, detect_citations_or_refs, detect_policy_violations, detect_language_mismatch... (+5 more)

### `tests\test_hybrid_intelligence_flow.py`

- **Functions**: setup_test_db, test_hybrid_flow

### `tests\test_llm_tools.py`

- **Functions**: test_tool_schema, test_unknown_tool, test_context_pack, test_score_response_records

### `tests\test_local_inference.py`

- **Functions**: test_local_llm

### `tests\test_logic_bridge_contract.py`

- **Functions**: run_bridge, test_contract_success, test_contract_missing_keys, test_contract_bad_json

### `tests\verify_architecture_gap_closure.py`

- **Functions**: test_guardian_daemon_mode, test_audit_rollback_system, test_hybrid_intelligence, test_smart_indexing_logic


## Others
### `agent.php`

- **Classes**: AgentConsole
- **Functions**: __construct, handle, showStatus, showStats, showRules, showHelp, handleChat, showReasoning

### `server.php`

- *Type*: Script / Data / Documentation

### `agentfrontend\bootstrap.php`

- **Classes**: SimpleYamlParser, PremiumDashboard
- **Functions**: parseFile, bgl_yaml_parse, __construct, getSystemVitals, getAgentStats, getBlockers, getProposals, getPermissions, getRecentActivity, getExperiences... (+8 more)

### `agentfrontend\layout.php`

- *Type*: Script / Data / Documentation

### `partials\confirm-modal.php`

- *Type*: Script / Data / Documentation

### `partials\excel-import-modal.php`

- *Type*: Script / Data / Documentation

### `partials\historical-banner.php`

- *Type*: Script / Data / Documentation

### `partials\letter-renderer.php`

- *Type*: Script / Data / Documentation

### `partials\manual-entry-modal.php`

- *Type*: Script / Data / Documentation

### `partials\paste-modal.php`

- *Type*: Script / Data / Documentation

### `partials\preview-placeholder.php`

- *Type*: Script / Data / Documentation

### `partials\record-form.php`

- *Type*: Script / Data / Documentation

### `partials\suggestions.php`

- *Type*: Script / Data / Documentation

### `partials\supplier-suggestions.php`

- *Type*: Script / Data / Documentation

### `partials\timeline-section.php`

- *Type*: Script / Data / Documentation

### `partials\unified-header.php`

- **Functions**: isActive

### `templates\letter-template.php`

- *Type*: Script / Data / Documentation

### `agentfrontend\partials\activity.php`

- *Type*: Script / Data / Documentation

### `agentfrontend\partials\blockers.php`

- *Type*: Script / Data / Documentation

### `agentfrontend\partials\copilot_chat.php`

- *Type*: Script / Data / Documentation

### `agentfrontend\partials\decisions.php`

- *Type*: Script / Data / Documentation

### `agentfrontend\partials\domain_map.php`

- *Type*: Script / Data / Documentation

### `agentfrontend\partials\events.php`

- *Type*: Script / Data / Documentation

### `agentfrontend\partials\experiences.php`

- *Type*: Script / Data / Documentation

### `agentfrontend\partials\external_checks.php`

- *Type*: Script / Data / Documentation

### `agentfrontend\partials\extra_widget.php`

- *Type*: Script / Data / Documentation

### `agentfrontend\partials\flows.php`

- *Type*: Script / Data / Documentation

### `agentfrontend\partials\gap_tests.php`

- *Type*: Script / Data / Documentation

### `agentfrontend\partials\health.php`

- *Type*: Script / Data / Documentation

### `agentfrontend\partials\intents.php`

- *Type*: Script / Data / Documentation

### `agentfrontend\partials\js_inventory.php`

- *Type*: Script / Data / Documentation

### `agentfrontend\partials\kpis.php`

- *Type*: Script / Data / Documentation

### `agentfrontend\partials\monitoring_widgets.php`

- *Type*: Script / Data / Documentation

### `agentfrontend\partials\permissions.php`

- *Type*: Script / Data / Documentation

### `agentfrontend\partials\proposals_simple.php`

- *Type*: Script / Data / Documentation

### `agentfrontend\partials\proposed_playbooks_simple.php`

- *Type*: Script / Data / Documentation

### `agentfrontend\partials\quick_actions.php`

- *Type*: Script / Data / Documentation

### `agentfrontend\partials\rules.php`

- *Type*: Script / Data / Documentation

### `agentfrontend\partials\simple_mode.php`

- *Type*: Script / Data / Documentation

### `agentfrontend\partials\summary.php`

- *Type*: Script / Data / Documentation

### `agentfrontend\partials\tool_evidence.php`

- *Type*: Script / Data / Documentation

### `agentfrontend\partials\topbar.php`

- *Type*: Script / Data / Documentation

### `agentfrontend\partials\worst_routes.php`

- *Type*: Script / Data / Documentation

### `raw_chat_test.py`

- **Functions**: main

### `test_github.py`

- **Functions**: main

### `test_github_word.py`

- **Functions**: main

### `test_knowledge.py`

- **Functions**: main

### `analysis\analyze_trace.py`

- **Functions**: analyze_traces, generate_sql_patch, analyze_backend_traces

### `README.md`

- *Type*: Script / Data / Documentation

### `docs\agent_continuous_verification.md`

- *Type*: Script / Data / Documentation

### `docs\agent_improvement_plan.md`

- *Type*: Script / Data / Documentation

### `docs\BGL3_AGENT_MANUAL.md`

- *Type*: Script / Data / Documentation

### `docs\Conversational Runtime Interface (cri).md`

- *Type*: Script / Data / Documentation

### `docs\CRI_OpenWebUI.md`

- *Type*: Script / Data / Documentation

### `docs\db_schema.md`

- *Type*: Script / Data / Documentation

### `docs\decision_layer.md`

- *Type*: Script / Data / Documentation

### `docs\logic_reference.md`

- *Type*: Script / Data / Documentation

### `docs\mouse_agent_plan.md`

- *Type*: Script / Data / Documentation

### `docs\README.md`

- *Type*: Script / Data / Documentation

### `docs\roadmap.md`

- *Type*: Script / Data / Documentation

### `docs\adr\ADR-rename-class-sandbox-autoload.md`

- **Classes**: يتطلب

### `docs\flows\create_guarantee.md`

- *Type*: Script / Data / Documentation

### `docs\flows\export_suppliers.md`

- *Type*: Script / Data / Documentation

### `docs\flows\extend_guarantee.md`

- *Type*: Script / Data / Documentation

### `docs\flows\import_suppliers.md`

- *Type*: Script / Data / Documentation

### `docs\flows\release_guarantee.md`

- *Type*: Script / Data / Documentation

### `docs\domain_map.yml`

- *Type*: Script / Data / Documentation

### `audit_results.json`

- *Type*: Script / Data / Documentation

### `audit_results_clean.json`

- *Type*: Script / Data / Documentation

### `audit_results_debug.json`

- *Type*: Script / Data / Documentation

### `audit_results_final.json`

- *Type*: Script / Data / Documentation

### `audit_results_final_v3.json`

- *Type*: Script / Data / Documentation

### `audit_results_phase3.json`

- *Type*: Script / Data / Documentation

### `audit_results_phase3_final.json`

- *Type*: Script / Data / Documentation

### `audit_results_phase4.json`

- *Type*: Script / Data / Documentation

### `composer.json`

- *Type*: Script / Data / Documentation

### `.vscode\extensions.json`

- *Type*: Script / Data / Documentation

### `.vscode\settings.json`

- *Type*: Script / Data / Documentation

### `agentfrontend\package-lock.json`

- *Type*: Script / Data / Documentation

### `agentfrontend\package.json`

- *Type*: Script / Data / Documentation

### `analysis\metrics_summary_enhanced.json`

- *Type*: Script / Data / Documentation

### `analysis\suggestions.json`

- *Type*: Script / Data / Documentation

### `docs\api_callgraph.json`

- *Type*: Script / Data / Documentation

### `agentfrontend\app\Config\agent.json`

- *Type*: Script / Data / Documentation
