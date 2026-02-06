# تقرير وظائف وكلاسات .bgl_core (غير شامل لحزم .venv312)

هذا التقرير يغطي كل ملفات كود .bgl_core باستثناء بيئة .venv312. تفاصيل حزم .venv في ملف منفصل: analysis/venv_symbols.json.

## نظرة تدفقية مختصرة

- الفهرسة: sensors/ast_bridge.php → brain/indexer.py → brain/memory.py (knowledge.db)
- الفحص التشغيلي: brain/browser_sensor.py + brain/scenario_runner.py → runtime_events → brain/context_digest.py → experiences
- التدقيق: brain/guardian.py + brain/governor.py + brain/safety.py
- القرار: brain/outcome_signals.py → brain/intent_resolver.py → brain/decision_engine.py → brain/authority.py
- التنفيذ: brain/orchestrator.py → brain/sandbox.py → brain/patcher.py → actuators/patcher.php → safety.validate() → apply_to_main
- التقارير: brain/master_verify.py → brain/report_builder.py → logs/latest_report.*

## brain

### brain\agency_core.py
- الدور العام: مكوّن ضمن .bgl_core
- الكلاسات:
  - AgencyCore: BGL3 Agency Core (The Brain) - Singleton (مكوّن ضمن .bgl_core)
  - دوال AgencyCore:
    - __new__: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - __init__: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - run_full_diagnostic: Orchestrates a complete project-wide diagnostic. (مكوّن ضمن .bgl_core)
    - _execution_stats: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - get_active_blockers: Retrieves unresolved agent struggles from cognitive memory. (مكوّن ضمن .bgl_core)
    - solve_blocker: Marks a cognitive struggle as resolved. (مكوّن ضمن .bgl_core)
    - commit_proposed_rule: Appends a proposed rule to domain_rules.yml. (مكوّن ضمن .bgl_core)
    - request_permission: Logs a risky operation for human approval (delegates to Authority). (مكوّن ضمن .bgl_core)
    - is_permission_granted: Checks if the user has approved a specific operation (delegates to Authority). (مكوّن ضمن .bgl_core)
    - log_activity: Persistent activity logging for dashboard visibility. (مكوّن ضمن .bgl_core)
    - log_trace: Systemic tracing for audit trails. (مكوّن ضمن .bgl_core)

### brain\agent_tasks.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - simulate_agent_task: وظيفة ضمن مكوّن ضمن .bgl_core

### brain\agent_verify.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - run_all_checks: مصدر موحد لتشغيل كل checks المحددة في inference_patterns.json. (مكوّن ضمن .bgl_core)

### brain\apply_db_fixes.py
- الدور العام: Apply DB fixes in sandbox: indexes and FKs based on patch_templates.
- الدوال:
  - run_sqlite: يشغّل إجراء/تدفق (مكوّن ضمن .bgl_core)
  - main: يشغّل إجراء/تدفق (مكوّن ضمن .bgl_core)

### brain\apply_proposal.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - main: يشغّل إجراء/تدفق (مكوّن ضمن .bgl_core)

### brain\approve_playbook.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - append_rule: وظيفة ضمن مكوّن ضمن .bgl_core
  - approve: وظيفة ضمن مكوّن ضمن .bgl_core

### brain\archive\verify_confidence.py
- الدور العام: مكوّن ضمن .bgl_core

### brain\archive\verify_failure_modes.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - test_atomic_rollback: Scenario: Apply a valid patch but force a failure during validation. (مكوّن ضمن .bgl_core)
  - test_sandbox_isolation: Scenario: Large change in sandbox. (مكوّن ضمن .bgl_core)
  - test_guardrail_barrier: Scenario: Accessing forbidden files. (مكوّن ضمن .bgl_core)

### brain\archive\verify_final_agent.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - test_specialized_programming: وظيفة ضمن مكوّن ضمن .bgl_core

### brain\archive\verify_intelligence.py
- الدور العام: مكوّن ضمن .bgl_core
- الكلاسات:
  - AgentBenchmark: وظيفة ضمن مكوّن ضمن .bgl_core
  - دوال AgentBenchmark:
    - __init__: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - run_case: يشغّل إجراء/تدفق (مكوّن ضمن .bgl_core)
    - save_report: يخزن/يسجل بيانات (مكوّن ضمن .bgl_core)
- الدوال:
  - benchmark_intelligence: وظيفة ضمن مكوّن ضمن .bgl_core

### brain\archive\verify_patch.py
- الدور العام: مكوّن ضمن .bgl_core

### brain\archive\verify_phase_1.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - test_browser_sensor_integration: Test that SafetyNet correctly uses BrowserSensor to detect frontend errors. (مكوّن ضمن .bgl_core)

### brain\archive\verify_phase_2.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - test_unified_perception: Simulates a task execution where both backend and (simulated) frontend errors occur. (مكوّن ضمن .bgl_core)

### brain\archive\verify_phase_3.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - test_fault_localization: Verifies that SafetyNet can map a failing URL to a suspect PHP file. (مكوّن ضمن .bgl_core)

### brain\archive\verify_phase_5.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - verify_phase_5: وظيفة ضمن مكوّن ضمن .bgl_core

### brain\authority.py
- الدور العام: مكوّن ضمن .bgl_core
- الكلاسات:
  - Authority: وظيفة ضمن مكوّن ضمن .bgl_core
  - دوال Authority:
    - __init__: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - _cache_key: Stable fingerprint for deduping decisions within a run. (مكوّن ضمن .bgl_core)
    - _cache_get: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - _cache_set: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - _eligible_for_direct: Auto-trial policy: allow "direct" mode only after N consecutive successes. (مكوّن ضمن .bgl_core)
    - effective_execution_mode: Compute effective execution mode: (مكوّن ضمن .bgl_core)
    - _autonomous_enabled: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - dedupe_permissions: Keep only the latest PENDING permission per operation to avoid queue spam. (مكوّن ضمن .bgl_core)
    - has_permission: بوابة قرار/تصريح (مكوّن ضمن .bgl_core)
    - request_permission: Upserts a PENDING permission request and returns its id. (مكوّن ضمن .bgl_core)
    - permission_status: Return the status for a permission id (GRANTED/PENDING/REJECTED/...). (مكوّن ضمن .bgl_core)
    - is_permission_granted: بوابة قرار/تصريح (مكوّن ضمن .bgl_core)
    - _log_decision: Log intent + decision rows and return a GateResult with linkage ids. (مكوّن ضمن .bgl_core)
    - record_outcome: وظيفة ضمن مكوّن ضمن .bgl_core
    - gate: Gate an action. (مكوّن ضمن .bgl_core)
- الدوال:
  - _ensure_agent_permissions_table: بوابة قرار/تصريح (مكوّن ضمن .bgl_core)
  - _safe_json: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)

### brain\autonomous_policy.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - _load_rules: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _save_rules: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _rule_key: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _apply_patch: يحدّث/يطبّق تغييرات (مكوّن ضمن .bgl_core)
  - apply_autonomous_policy_edit: Uses LLM to propose a policy_expectations patch and applies it immediately. (مكوّن ضمن .bgl_core)

### brain\brain_rules.py
- الدور العام: مكوّن ضمن .bgl_core
- الكلاسات:
  - RuleRegistry: Hardcoded structural rules that override LLM reasoning. (مكوّن ضمن .bgl_core)
  - دوال RuleRegistry:
    - get_core_rules: يجلب بيانات/حالة (مكوّن ضمن .bgl_core)
  - RuleEngine: وظيفة ضمن مكوّن ضمن .bgl_core
  - دوال RuleEngine:
    - __init__: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - evaluate: Applies rules to the current context and state. (مكوّن ضمن .bgl_core)
    - _check_is_arabic: Returns True if text is predominantly Arabic. (مكوّن ضمن .bgl_core)
    - _apply_action: Executes the side-effect of a rule. (مكوّن ضمن .bgl_core)

### brain\brain_types.py
- الدور العام: مكوّن ضمن .bgl_core
- الكلاسات:
  - Intent: وظيفة ضمن مكوّن ضمن .bgl_core
  - HealthScore: وظيفة ضمن مكوّن ضمن .bgl_core
  - Context: Level 3: Context Schema ◼◼ (مكوّن ضمن .bgl_core)
  - OperationalMode: وظيفة ضمن مكوّن ضمن .bgl_core
  - CognitiveState: Tracks the agent's internal reasoning state. (مكوّن ضمن .bgl_core)
  - Rule: A deterministic logic unit. (مكوّن ضمن .bgl_core)
  - ActionKind: Unified action taxonomy for authority/gating. (مكوّن ضمن .bgl_core)
  - ActionRequest: Request to perform an action that may have side effects. (مكوّن ضمن .bgl_core)
  - GateResult: Output of the Authority gate. (مكوّن ضمن .bgl_core)

### brain\browser_core.py
- الدور العام: browser_core.py (Deprecated)
- الكلاسات:
  - BrowserCore: Compatibility wrapper around BrowserSensor. (مكوّن ضمن .bgl_core)
  - دوال BrowserCore:
    - navigate: بوابة قرار/تصريح (مكوّن ضمن .bgl_core)

### brain\browser_manager.py
- الدور العام: مكوّن ضمن .bgl_core
- الكلاسات:
  - BrowserManager: Singleton-style manager for Playwright browser/context reuse. (مكوّن ضمن .bgl_core)
  - دوال BrowserManager:
    - __init__: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - _ensure_browser: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - _cleanup_idle_pages: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - get_page: يجلب بيانات/حالة (مكوّن ضمن .bgl_core)
    - new_page: وظيفة ضمن مكوّن ضمن .bgl_core
    - _install_filechooser_guard: Block native OS file dialogs by default. (مكوّن ضمن .bgl_core)
    - close: وظيفة ضمن مكوّن ضمن .bgl_core
    - status: وظيفة ضمن مكوّن ضمن .bgl_core

### brain\browser_sensor.py
- الدور العام: مكوّن ضمن .bgl_core
- الكلاسات:
  - BrowserSensor: وظيفة ضمن مكوّن ضمن .bgl_core
  - دوال BrowserSensor:
    - __init__: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - _ensure_browser: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - close: Close the singleton Playwright resources. (مكوّن ضمن .bgl_core)
    - scan_url: Scans a specific URL for frontend errors (Console and Network). (مكوّن ضمن .bgl_core)
    - _write_status: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
- الدوال:
  - main: يشغّل إجراء/تدفق (مكوّن ضمن .bgl_core)

### brain\callgraph_builder.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - _guess_layer: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _get_dependencies: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _get_entity_method_id: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - build_callgraph: Rich Callgraph: route -> controller -> service -> repo (مكوّن ضمن .bgl_core)

### brain\check_mouse_layer.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - main: يشغّل إجراء/تدفق (مكوّن ضمن .bgl_core)

### brain\checks\__init__.py
- الدور العام: مكوّن ضمن .bgl_core

### brain\checks\authority_drift.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - _read_text: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - run: Hard check: (مكوّن ضمن .bgl_core)

### brain\checks\css_bloat.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - run: Simple CSS bloat detector: flags files over size/line thresholds. (مكوّن ضمن .bgl_core)

### brain\checks\db_fk_missing.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - run: Detect missing foreign keys between guarantees -> banks/suppliers. (مكوّن ضمن .bgl_core)

### brain\checks\db_index_missing.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - run: Detect missing indexes on common filter/sort columns for reporting. (مكوّن ضمن .bgl_core)

### brain\checks\hypothesis_meta_separation.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - run: Advisory check: (مكوّن ضمن .bgl_core)

### brain\checks\js_bloat.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - run: JS bloat detector: flags large/long files and reports top offenders. (مكوّن ضمن .bgl_core)

### brain\checks\missing_alerts_aggregator.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - run: وظيفة ضمن مكوّن ضمن .bgl_core

### brain\checks\missing_audit_trail.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - run: وظيفة ضمن مكوّن ضمن .bgl_core

### brain\checks\missing_caching_reports.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - run: وظيفة ضمن مكوّن ضمن .bgl_core

### brain\checks\missing_import_safety.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - run: وظيفة ضمن مكوّن ضمن .bgl_core

### brain\checks\missing_rate_limit_middleware.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - run: Check if any rate limiting middleware/guard is present. (مكوّن ضمن .bgl_core)

### brain\checks\missing_validation_guards.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - run: وظيفة ضمن مكوّن ضمن .bgl_core

### brain\checks\self_regulation_runtime_link.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - run: Advisory check: (مكوّن ضمن .bgl_core)

### brain\commit_rule.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - main: يشغّل إجراء/تدفق (مكوّن ضمن .bgl_core)

### brain\config_loader.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - _deep_merge: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - load_config: يحمّل/يقرأ بيانات (مكوّن ضمن .bgl_core)

### brain\context_digest.py
- الدور العام: Context Digest
- الدوال:
  - fetch_events: يجلب بيانات/حالة (مكوّن ضمن .bgl_core)
  - load_route_map: يحمّل/يقرأ بيانات (مكوّن ضمن .bgl_core)
  - summarize: يستنتج/يلخّص (مكوّن ضمن .bgl_core)
  - upsert_experiences: وظيفة ضمن مكوّن ضمن .bgl_core
  - main: يشغّل إجراء/تدفق (مكوّن ضمن .bgl_core)

### brain\context_extractor.py
- الدور العام: Lightweight context extractor for LLM prompts.
- الدوال:
  - _read_snippet: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - extract: targets: list of relative paths or symbols. (مكوّن ضمن .bgl_core)

### brain\contract_seeder.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - _load_yaml: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _guess_value: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _extract_post_fields: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _extract_get_params: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _param_example_is_placeholder: Decide whether an existing OpenAPI parameter example should be updated. (مكوّن ضمن .bgl_core)
  - _ensure_method: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _post_only: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _example_is_placeholder: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - seed_contract: وظيفة ضمن مكوّن ضمن .bgl_core

### brain\contract_tests.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - run_contract_suite: Runs optional API contract/property tests if specs exist. (مكوّن ضمن .bgl_core)

### brain\decision_db.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - init_db: وظيفة ضمن مكوّن ضمن .bgl_core
  - _connect: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - insert_intent: يخزن/يسجل بيانات (مكوّن ضمن .bgl_core)
  - insert_decision: يخزن/يسجل بيانات (مكوّن ضمن .bgl_core)
  - insert_outcome: يخزن/يسجل بيانات (مكوّن ضمن .bgl_core)

### brain\decision_engine.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - decide: Smart Decision Engine. (مكوّن ضمن .bgl_core)
  - _deterministic_decision: Rule-based decision when LLM is unavailable. (مكوّن ضمن .bgl_core)

### brain\embeddings.py
- الدور العام: Simple embedding cache using bag-of-words hashing stored in SQLite.
- الدوال:
  - _tokenize: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _vectorize: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _cosine: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _ensure_table: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - add_text: وظيفة ضمن مكوّن ضمن .bgl_core
  - search: وظيفة ضمن مكوّن ضمن .bgl_core

### brain\execution_gate.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - check: Central gate. For الآن يبقى غير مانع إلا في الحالات الواضحة. (مكوّن ضمن .bgl_core)

### brain\experience_replay.py
- الدور العام: Experience replay store for LLM:
- الدوال:
  - _ensure: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - save: وظيفة ضمن مكوّن ضمن .bgl_core
  - fetch: وظيفة ضمن مكوّن ضمن .bgl_core

### brain\fault_locator.py
- الدور العام: مكوّن ضمن .bgl_core
- الكلاسات:
  - FaultLocator: وظيفة ضمن مكوّن ضمن .bgl_core
  - دوال FaultLocator:
    - __init__: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - get_context_from_log: Reads the last N lines from the application's log file. (مكوّن ضمن .bgl_core)
    - _locate_url: Maps a URL to its backend route information. (Internal method) (مكوّن ضمن .bgl_core)
    - locate_url: Public wrapper used by Guardian and Safety layers. (مكوّن ضمن .bgl_core)
    - diagnose_fault: Diagnoses a fault by locating the URL and grabbing recent log context. (مكوّن ضمن .bgl_core)

### brain\fingerprint.py
- الدور العام: مكوّن ضمن .bgl_core
- الكلاسات:
  - Fingerprint: وظيفة ضمن مكوّن ضمن .bgl_core
- الدوال:
  - _stat_sig: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _walk_globs: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - compute_fingerprint: Focus on files that meaningfully affect routes/runtime behavior: (مكوّن ضمن .bgl_core)
  - fingerprint_is_fresh: Avoid thrashing on self-modifying repositories (logs, db, etc.) by allowing a (مكوّن ضمن .bgl_core)
  - fingerprint_equal: وظيفة ضمن مكوّن ضمن .bgl_core
  - fingerprint_to_payload: وظيفة ضمن مكوّن ضمن .bgl_core

### brain\generate_openapi.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - generate: Generates a minimal OpenAPI spec from indexed routes (knowledge.db -> routes table). (مكوّن ضمن .bgl_core)

### brain\generate_playbooks.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - generate_from_proposed: يبني/يولّد مخرجات (مكوّن ضمن .bgl_core)

### brain\governor.py
- الدور العام: مكوّن ضمن .bgl_core
- الكلاسات:
  - BGLGovernor: وظيفة ضمن مكوّن ضمن .bgl_core
  - دوال BGLGovernor:
    - __init__: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - _load_rules: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - audit: يتحقق/يدقق (مكوّن ضمن .bgl_core)
    - _check_content_rule: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - _classify_entities: Maps entity_id to its classified type (controller, service, etc) (مكوّن ضمن .bgl_core)
    - _check_relationship_rule: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - _check_naming_rule: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - _get_entities_by_type: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)

### brain\guardian.py
- الدور العام: مكوّن ضمن .bgl_core
- الكلاسات:
  - BGLGuardian: وظيفة ضمن مكوّن ضمن .bgl_core
  - دوال BGLGuardian:
    - __init__: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - perform_full_audit: Scans all indexed routes and provides a proactive health report. (مكوّن ضمن .bgl_core)
    - _update_route_health: Upserts health status to avoid failing when a route record is missing. (مكوّن ضمن .bgl_core)
    - _preflight_services: Readiness gate before deep audits to reduce timing false negatives. (مكوّن ضمن .bgl_core)
    - _load_api_contract: Load merged OpenAPI spec and return paths map + summary. (مكوّن ضمن .bgl_core)
    - _contract_missing_routes: Return API routes present in code but missing in contract spec. (مكوّن ضمن .bgl_core)
    - _contract_quality_gaps: Identify contract paths that exist but lack enough detail to run a probe. (مكوّن ضمن .bgl_core)
    - auto_remediate: Experimental: Attempts to solve a high-confidence suggestion using pre-defined rules. (مكوّن ضمن .bgl_core)
    - log_maintenance: Standard maintenance tasks for the system. (مكوّن ضمن .bgl_core)
    - _prune_logs: Clears old log entries to preserve performance. (مكوّن ضمن .bgl_core)
    - _check_learning_confirmations: Queries the memory for confirmed anomalies or rejected false positives. (مكوّن ضمن .bgl_core)
    - _check_business_conflicts_real: Calls the PHP logic bridge with REAL data to detect business-level conflicts. (مكوّن ضمن .bgl_core)
    - _get_proxied_routes: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - _is_api_route: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - _scan_api_route: Scan API routes without opening them in the browser. (مكوّن ضمن .bgl_core)
    - _classify_expected_failure: Classify expected policy failures based on local rules. (مكوّن ضمن .bgl_core)
    - _maybe_record_policy_candidate: Use tools to verify if a failure could be policy-expected, then log candidate. (مكوّن ضمن .bgl_core)
    - _auto_promote_policy_candidates: Promote high-confidence candidates into policy_expectations.json. (مكوّن ضمن .bgl_core)
    - _render_example: Replace placeholder tokens in examples to avoid duplicate conflicts. (مكوّن ضمن .bgl_core)
    - _dedupe_permissions: Keep only the latest PENDING permission per operation to avoid queue spam. (مكوّن ضمن .bgl_core)
    - _ensure_routes_indexed: Ensure routes table is populated at least once (read-only safety net). (مكوّن ضمن .bgl_core)
    - _has_permission: بوابة قرار/تصريح (مكوّن ضمن .bgl_core)
    - _request_permission: بوابة قرار/تصريح (مكوّن ضمن .bgl_core)
    - _gate_reindex: Gate large reindex operations via decision layer and hardware limits. (مكوّن ضمن .bgl_core)
    - _detect_log_anomalies: Identifies recurring patterns in the Laravel log. (مكوّن ضمن .bgl_core)
    - _generate_suggestions: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - _worst_routes: Infer worst routes from experiences + failing routes + http errors count. (مكوّن ضمن .bgl_core)
    - _check_permissions: Verify write access to critical paths; report issues without modifying files. (مكوّن ضمن .bgl_core)
    - _pending_approvals: Return pending human approvals from agent_permissions (best-effort). (مكوّن ضمن .bgl_core)
    - _recent_outcomes: Return recent outcomes joined with their decisions/intents (best-effort). (مكوّن ضمن .bgl_core)
    - _load_recent_experiences: Fetch recent experiential summaries to inform audit suggestions. (مكوّن ضمن .bgl_core)
    - _load_route_stats: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - _persist_route_stats: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - _target_duration: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - _compute_adaptive_limit: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - run_daemon: يشغّل إجراء/تدفق (مكوّن ضمن .bgl_core)

### brain\guardrails.py
- الدور العام: مكوّن ضمن .bgl_core
- الكلاسات:
  - BGLGuardrails: وظيفة ضمن مكوّن ضمن .bgl_core
  - دوال BGLGuardrails:
    - __init__: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - is_path_allowed: وظيفة ضمن مكوّن ضمن .bgl_core
    - validate_changes: يتحقق/يدقق (مكوّن ضمن .bgl_core)
    - filter_paths: وظيفة ضمن مكوّن ضمن .bgl_core

### brain\hand_profile.py
- الدور العام: مكوّن ضمن .bgl_core
- الكلاسات:
  - HandProfile: يمثل "هوية يد" ثابتة طوال الجلسة: سرعات وجيتّر وتصحيحات بسيطة. (مكوّن ضمن .bgl_core)
  - دوال HandProfile:
    - generate: يبني/يولّد مخرجات (مكوّن ضمن .bgl_core)

### brain\hardware_sensor.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - get_gpu_info: Attempts to get GPU load and memory using nvidia-smi. (مكوّن ضمن .bgl_core)
  - main: يشغّل إجراء/تدفق (مكوّن ضمن .bgl_core)

### brain\indexer.py
- الدور العام: مكوّن ضمن .bgl_core
- الكلاسات:
  - EntityIndexer: يفهرس/يبني خريطة (مكوّن ضمن .bgl_core)
  - دوال EntityIndexer:
    - __init__: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - update_impacted: Re-index only the provided relative paths (for targeted updates after patch). (مكوّن ضمن .bgl_core)
    - index_project: يفهرس/يبني خريطة (مكوّن ضمن .bgl_core)
    - close: وظيفة ضمن مكوّن ضمن .bgl_core
    - _should_index: Only index if mtime differs from memory. (مكوّن ضمن .bgl_core)
    - _index_file: يفهرس/يبني خريطة (مكوّن ضمن .bgl_core)

### brain\inference.py
- الدور العام: مكوّن ضمن .bgl_core
- الكلاسات:
  - ReasoningEngine: LLM-First Reasoning Engine. (مكوّن ضمن .bgl_core)
  - دوال ReasoningEngine:
    - __init__: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - _get_project_structure: Discovers real files to prevent hallucination. (مكوّن ضمن .bgl_core)
    - _get_file_hash: Calculates SHA-256 hash of a file. (مكوّن ضمن .bgl_core)
    - reason: Main reasoning loop: Plan -> Act -> Reflect. (مكوّن ضمن .bgl_core)
    - _analyze_backend_logic: Maps a URL to a local file and reads its key logic to understand 'False Positives' etc. (مكوّن ضمن .bgl_core)
    - _json_serializer: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - _build_reasoning_prompt: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - _extract_json_from_text: Robustly extracts JSON object from text (e.g. Markdown code fences or preambles). (مكوّن ضمن .bgl_core)
    - _query_llm: Queries LLM (Local Ollama or OpenAI) with zero external dependencies. (مكوّن ضمن .bgl_core)
    - _get_brain_state: Diagnose Brain State: "HOT" (Ready), "COLD" (Loading), or "OFFLINE". (مكوّن ضمن .bgl_core)
    - chat: Conversational entry point with grounding. (مكوّن ضمن .bgl_core)
    - _parse_structured_plan: Robustly parses JSON even if wrapped in markdown or containing garbage. (مكوّن ضمن .bgl_core)

### brain\intent_resolver.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - resolve_intent: Smart Intent Resolver. (مكوّن ضمن .bgl_core)

### brain\interpretation.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - interpret: وظيفة ضمن مكوّن ضمن .bgl_core

### brain\llm_client.py
- الدور العام: مكوّن ضمن .bgl_core
- الكلاسات:
  - LLMClientConfig: وظيفة ضمن مكوّن ضمن .bgl_core
  - LLMClient: وظيفة ضمن مكوّن ضمن .bgl_core
  - دوال LLMClient:
    - __init__: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - _brain_state: Diagnose brain state using Ollama /api/ps: (مكوّن ضمن .bgl_core)
    - state: وظيفة ضمن مكوّن ضمن .bgl_core
    - _warm: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - _auto_warm_in_background: ROOT CAUSE FIX: Background model warming. (مكوّن ضمن .bgl_core)
    - ensure_hot: Best-effort warm-up. Returns final state. (مكوّن ضمن .bgl_core)
    - chat_json: Chat completion expecting a JSON object in message content. (مكوّن ضمن .bgl_core)
- الدوال:
  - _swap_localhost: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _normalize_urls: Returns (chat_url, base_api_url). (مكوّن ضمن .bgl_core)

### brain\llm_tools.py
- الدور العام: Lightweight tool layer to let the local LLM (or any caller) trigger safe utilities.
- الدوال:
  - tool_run_checks: Run all static checks from inference_patterns.json. (مكوّن ضمن .bgl_core)
  - tool_route_index: Rebuild route index and return the discovered routes. (مكوّن ضمن .bgl_core)
  - tool_logic_bridge: Call the PHP logic bridge with a JSON payload. (مكوّن ضمن .bgl_core)
  - tool_layout_map: Capture a lightweight layout map from a page (headless Chromium). (مكوّن ضمن .bgl_core)
  - tool_context_pack: Gather hot context: latest intents/decisions/outcomes + routes count. (مكوّن ضمن .bgl_core)
  - tool_score_response: Score an LLM response with simple rule-based checks and agent_verify outcome. (مكوّن ضمن .bgl_core)
  - _method_exists: Check if a method exists in knowledge.db structure memory. (مكوّن ضمن .bgl_core)
  - tool_schema: Return available tools and their parameters for prompt wiring. (مكوّن ضمن .bgl_core)
  - dispatch: request = {"tool": "run_checks"} or {"tool": "logic_bridge", "payload": {...}} (مكوّن ضمن .bgl_core)

### brain\master_verify.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - log_activity: Logs an event to the agent_activity table for dashboard visibility. (مكوّن ضمن .bgl_core)
  - master_assurance_diagnostic: Main entry point for Master Technical Assurance. (مكوّن ضمن .bgl_core)

### brain\memory.py
- الدور العام: مكوّن ضمن .bgl_core
- الكلاسات:
  - StructureMemory: وظيفة ضمن مكوّن ضمن .bgl_core
  - دوال StructureMemory:
    - __init__: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - _connect: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - _init_schema_once: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - register_file: وظيفة ضمن مكوّن ضمن .bgl_core
    - get_file_info: يجلب بيانات/حالة (مكوّن ضمن .bgl_core)
    - clear_file_data: وظيفة ضمن مكوّن ضمن .bgl_core
    - store_nested_symbols: يخزن/يسجل بيانات (مكوّن ضمن .bgl_core)
    - _store_calls: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - close: وظيفة ضمن مكوّن ضمن .bgl_core

### brain\metrics_guard.py
- الدور العام: Metrics guard: يقارن ملخص mouse_metrics بحدود مطلوبة.
- الدوال:
  - load_summary: يحمّل/يقرأ بيانات (مكوّن ضمن .bgl_core)
  - main: يشغّل إجراء/تدفق (مكوّن ضمن .bgl_core)

### brain\metrics_summary.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - summarize: يستنتج/يلخّص (مكوّن ضمن .bgl_core)

### brain\migrate_decision_to_knowledge.py
- الدور العام: One-time migration: copy intents/decisions/outcomes from decision.db to knowledge.db.
- الدوال:
  - ensure_schema: وظيفة ضمن مكوّن ضمن .bgl_core
  - migrate: وظيفة ضمن مكوّن ضمن .bgl_core

### brain\motor.py
- الدور العام: مكوّن ضمن .bgl_core
- الكلاسات:
  - MouseState: وظيفة ضمن مكوّن ضمن .bgl_core
  - Motor: طبقة الحركة الفيزيائية: تحوّل أمر "اذهب هنا" إلى مسار بشري نسبي. (مكوّن ضمن .bgl_core)
  - دوال Motor:
    - __init__: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - move_to: ينفّذ حركة محسوبة مع تسارع/تباطؤ، overshoot بسيط، وجيتّر خفيف. (مكوّن ضمن .bgl_core)

### brain\observations.py
- الدور العام: Unified Observations
- الدوال:
  - _ensure_tables: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - store_env_snapshot: يخزن/يسجل بيانات (مكوّن ضمن .bgl_core)
  - latest_env_snapshot: وظيفة ضمن مكوّن ضمن .bgl_core
  - _safe_get: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _diff_scalar: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - compute_diagnostic_delta: A compact, stable delta between two diagnostic snapshots. (مكوّن ضمن .bgl_core)
  - store_latest_diagnostic_delta: Compute and store delta against the previous diagnostic snapshot (if any). (مكوّن ضمن .bgl_core)
  - diagnostic_to_snapshot: Keep the snapshot compact and stable (schema-ish). (مكوّن ضمن .bgl_core)
  - compute_skip_recommendation: Decide what to skip next run when things look stable. (مكوّن ضمن .bgl_core)

### brain\orchestrator.py
- الدور العام: مكوّن ضمن .bgl_core
- الكلاسات:
  - ExecutionReport: وظيفة ضمن مكوّن ضمن .bgl_core
  - BGLOrchestrator: وظيفة ضمن مكوّن ضمن .bgl_core
  - دوال BGLOrchestrator:
    - __init__: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - execute_task: Executes a task based on the formal Task Spec JSON and returns an Execution Report. (مكوّن ضمن .bgl_core)

### brain\outcome_signals.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - _as_list: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _top: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _candidate_conf_by_uri: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _looks_like_scan_artifact: Best-effort heuristic: (مكوّن ضمن .bgl_core)
  - compute_outcome_signals: Input expects a diagnostic-like dict: (مكوّن ضمن .bgl_core)

### brain\patcher.py
- الدور العام: مكوّن ضمن .bgl_core
- الكلاسات:
  - BGLPatcher: وظيفة ضمن مكوّن ضمن .bgl_core
  - دوال BGLPatcher:
    - __init__: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - rename_class: وظيفة ضمن مكوّن ضمن .bgl_core
    - add_method: وظيفة ضمن مكوّن ضمن .bgl_core
    - _run_action: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - _update_references: Best-effort reference rename across key project directories. (مكوّن ضمن .bgl_core)
    - _derive_impacted_tests: Find tests touching callers of the renamed class using call graph (entity+method) and fallback heuristics. (مكوّن ضمن .bgl_core)
    - _derive_impacted_files: Find caller files of the renamed class to reindex selectively. (مكوّن ضمن .bgl_core)
    - _post_patch_index: Re-index modified PHP files to keep knowledge.db fresh. (مكوّن ضمن .bgl_core)
    - _post_patch_index_all: Full project reindex (used after rename for accurate dependency graph). (مكوّن ضمن .bgl_core)
    - _discover_composer: Find composer executable/phar. Hard requirement: return path or None (fail). (مكوّن ضمن .bgl_core)
    - _refresh_autoload: Run composer dump-autoload in sandbox. Policy: hard requirement. (مكوّن ضمن .bgl_core)

### brain\perception.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - capture_ui_map: Return a compact UI map for interactive elements: (مكوّن ضمن .bgl_core)
  - project_interactive_elements: Backwards-compatible projection for older consumers (no coordinates). (مكوّن ضمن .bgl_core)
  - capture_local_context: يجمع ما نراه موضعياً: الهدف، جار قريب، عنوان قريب، وسبب الاختيار (selector/hint). (مكوّن ضمن .bgl_core)

### brain\playbook_loader.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - load_playbooks_meta: Load front-matter metadata from playbooks/*.md (مكوّن ضمن .bgl_core)

### brain\policy.py
- الدور العام: مكوّن ضمن .bgl_core
- الكلاسات:
  - Policy: طبقة القرار: تأخذ hint (من السيناريو أو من الاستكشاف) وتقرر ماذا تفعل بعد الوصول. (مكوّن ضمن .bgl_core)
  - دوال Policy:
    - __init__: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - perform_click: اقتراب → تقييم موضعي → قرار نقر أو تغيير فرضية. (مكوّن ضمن .bgl_core)
    - perform_goto: وظيفة ضمن مكوّن ضمن .bgl_core
    - _start_dom_watch: يزرع MutationObserver قبل النقر لالتقاط أول تغيير DOM بعد الحدث. (مكوّن ضمن .bgl_core)
    - _wait_dom_change: ينتظر نتيجة المراقبة المزروعة مسبقاً ويعيد delta ms أو None. (مكوّن ضمن .bgl_core)
    - _find_alternative: يحاول العثور على عنصر بديل قريب من آخر موضع للماوس (زر/رابط) عند فشل الـ selector. (مكوّن ضمن .bgl_core)

### brain\policy_verifier.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - _find_app_db: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _read_file: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _method_guard: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _required_fields: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _foreign_keys_for: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - verify_failure: Build evidence for whether a failure is policy-expected. (مكوّن ضمن .bgl_core)

### brain\readiness_gate.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - _swap_localhost: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _http_check: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _port_check: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _ollama_tags: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _ollama_warm: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - run_readiness: Readiness gate: checks HTTP base_url, tool_server port, and local LLM health. (مكوّن ضمن .bgl_core)

### brain\report_builder.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - build_report: يبني/يولّد مخرجات (مكوّن ضمن .bgl_core)
  - load_latest_health: يحمّل/يقرأ بيانات (مكوّن ضمن .bgl_core)

### brain\route_indexer.py
- الدور العام: مكوّن ضمن .bgl_core
- الكلاسات:
  - LaravelRouteIndexer: يفهرس/يبني خريطة (مكوّن ضمن .bgl_core)
  - دوال LaravelRouteIndexer:
    - __init__: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - run: وظيفة ضمن مكوّن ضمن .bgl_core
    - index_project: يفهرس/يبني خريطة (مكوّن ضمن .bgl_core)
    - _analyze_file: Attempts to find the primary Service/Controller used in a PHP file. (مكوّن ضمن .bgl_core)
    - _infer_method: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)

### brain\run_scenarios.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - _log_activity: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _ensure_env: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _run_real_scenarios: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - simulate_traffic: Legacy simulator (disabled by default). (مكوّن ضمن .bgl_core)
  - main: يشغّل إجراء/تدفق (مكوّن ضمن .bgl_core)

### brain\safety.py
- الدور العام: مكوّن ضمن .bgl_core
- الكلاسات:
  - SafetyNet: وظيفة ضمن مكوّن ضمن .bgl_core
  - دوال SafetyNet:
    - __init__: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - _safe_float: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - create_backup: Creates a protected backup of a file before patching. (مكوّن ضمن .bgl_core)
    - preflight: Pre-checks runtime safety rules (e.g., writability) before patching. (مكوّن ضمن .bgl_core)
    - validate: Runs the validation chain: php -l -> PHPUnit -> Architectural Audit. (مكوّن ضمن .bgl_core)
    - validate_async: Async-safe variant of validate(). (مكوّن ضمن .bgl_core)
    - _gather_unified_logs: Correlates frontend and backend logs. (مكوّن ضمن .bgl_core)
    - _read_backend_logs: Reads and filters Laravel logs. (مكوّن ضمن .bgl_core)
    - _tests_from_experiences: Derive test files to run based on experiential memory linking to the file. (مكوّن ضمن .bgl_core)
    - rollback: Restores file from backup. Explicitly called on validation failure. (مكوّن ضمن .bgl_core)
    - clear_backup: Deletes backup after successful validation. (مكوّن ضمن .bgl_core)
    - _check_lint: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - _check_phpunit: Runs PHPUnit on targeted tests if available; otherwise, falls back to fast suite if configured. (مكوّن ضمن .bgl_core)
    - _async_browser_check: Internal async bridge for Playwright. (مكوّن ضمن .bgl_core)
    - _browser_audit_async: Async browser audit safe to call from within an event loop. (مكوّن ضمن .bgl_core)
    - _check_browser_audit: Synchronous wrapper for the async browser scan. (مكوّن ضمن .bgl_core)
    - _check_architectural_rules: Runs BGLGovernor to ensure no domain rules were broken. (مكوّن ضمن .bgl_core)

### brain\sandbox.py
- الدور العام: مكوّن ضمن .bgl_core
- الكلاسات:
  - BGLSandbox: وظيفة ضمن مكوّن ضمن .bgl_core
  - دوال BGLSandbox:
    - __init__: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
    - setup: وظيفة ضمن مكوّن ضمن .bgl_core
    - _copy_all_with_excludes: OPTIMIZED: Copy everything (tracked + untracked) using single robocopy. (مكوّن ضمن .bgl_core)
    - apply_to_main: Applies all modified files from the sandbox back to the main project using git diff. (مكوّن ضمن .bgl_core)
    - cleanup: وظيفة ضمن مكوّن ضمن .bgl_core
    - _copy_untracked: Copy files that git clone might omit (untracked) from main project to sandbox. (مكوّن ضمن .bgl_core)
    - _prepare_sandbox_db: Copy knowledge.db to a sandbox-local temp DB to avoid locking the main file. (مكوّن ضمن .bgl_core)
    - _prepare_decision_db: Legacy hook: now uses knowledge.db; keeps env var for backward compatibility. (مكوّن ضمن .bgl_core)

### brain\scenario_deps.py
- الدور العام: مكوّن ضمن .bgl_core
- الكلاسات:
  - ScenarioDepsReport: وظيفة ضمن مكوّن ضمن .bgl_core
  - دوال ScenarioDepsReport:
    - to_dict: وظيفة ضمن مكوّن ضمن .bgl_core
- الدوال:
  - _module_present: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _playwright_version: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - check_scenario_deps: Lightweight dependency check for scenario execution. (مكوّن ضمن .bgl_core)
  - check_scenario_deps_async: Async-safe variant for use inside asyncio loops (guardian). (مكوّن ضمن .bgl_core)

### brain\scenario_runner.py
- الدور العام: Scenario Runner
- الدوال:
  - ensure_cursor: Inject ghost-cursor overlay once per page. (مكوّن ضمن .bgl_core)
  - ensure_dev_mode: Guard: لا تُشغّل السيناريوهات في وضع الإنتاج. (مكوّن ضمن .bgl_core)
  - _dom_state_hash: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - exploratory_action: تنفيذ تفاعل آمن واحد غير مذكور (hover/scroll) لا يغيّر البيانات. (مكوّن ضمن .bgl_core)
  - run_step: يشغّل إجراء/تدفق (مكوّن ضمن .bgl_core)
  - log_event: وظيفة ضمن مكوّن ضمن .bgl_core
  - _ensure_outcomes_tables: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _log_outcome: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _log_relation: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _derive_outcomes_from_runtime: يستنتج/يلخّص (مكوّن ضمن .bgl_core)
  - _derive_outcomes_from_learning: يستنتج/يلخّص (مكوّن ضمن .bgl_core)
  - _last_selector_from_payload: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _apply_exploration_reward: يحدّث/يطبّق تغييرات (مكوّن ضمن .bgl_core)
  - _reward_exploration_from_outcomes: Positive reward for useful outcomes; penalty for no-effect outcomes. (مكوّن ضمن .bgl_core)
  - _score_outcomes: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _seed_goals_from_outcome_scores: Convert strongly negative outcomes into 'gap_deepen' goals. (مكوّن ضمن .bgl_core)
  - _load_seen_novel: Return a set of routes previously used in novel probes to avoid repeats. (مكوّن ضمن .bgl_core)
  - _is_safe_novel_href: Conservative safety filter: only allow read-only navigation. (مكوّن ضمن .bgl_core)
  - _autonomous_enabled: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _routes_table_count: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _route_source_files: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _routes_last_index_ts: يفهرس/يبني خريطة (مكوّن ضمن .bgl_core)
  - _routes_need_reindex: يفهرس/يبني خريطة (مكوّن ضمن .bgl_core)
  - _auto_reindex_routes: يفهرس/يبني خريطة (مكوّن ضمن .bgl_core)
  - _unknown_routes_from_runtime: Return recent runtime routes not present in the canonical routes table. (مكوّن ضمن .bgl_core)
  - _maybe_reindex_after_exploration: يفهرس/يبني خريطة (مكوّن ضمن .bgl_core)
  - _cfg_value: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _recent_runtime_routes: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _recent_routes_within_days: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _load_insight_basenames: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _normalize_route_path: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _resolve_route_to_file: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _collect_dream_targets: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _routes_for_file: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _ingest_insights_to_goals: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _should_trigger_dream: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _trigger_dream_from_exploration: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _href_basename: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _build_search_terms: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _ensure_exploration_table: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _ensure_exploration_novelty_table: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _load_explored_selectors: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _record_explored_selector: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _record_exploration_outcome: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _novelty_score: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _rank_exploration_candidate: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _ensure_autonomy_goals_table: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _cleanup_autonomy_goals: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _read_autonomy_goals: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _write_autonomy_goal: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _read_latest_delta: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _read_recent_routes_from_db: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _read_log_highlights: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _recent_error_routes: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _seed_goals_from_system_signals: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _goal_to_scenario: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _goal_route_kind: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _ensure_goal_strategy_table: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _record_goal_strategy_result: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _pick_goal_strategy: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _http_check: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - run_goal_scenario: يشغّل إجراء/تدفق (مكوّن ضمن .bgl_core)
  - _autonomous_plan_hash: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _record_autonomous_plan: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _is_recent_autonomous_plan: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _is_simple_token: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _selector_from_element: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _build_selector_candidates: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _list_upload_fixtures: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _sanitize_steps: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _fallback_autonomous_steps: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _generate_autonomous_plan: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - run_autonomous_scenario: يشغّل إجراء/تدفق (مكوّن ضمن .bgl_core)
  - run_novel_probe: Attempt one safe, novel navigation per run. (مكوّن ضمن .bgl_core)
  - run_scenario: يشغّل إجراء/تدفق (مكوّن ضمن .bgl_core)
  - _is_api_url: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - _is_api_scenario: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - run_api_scenario: يشغّل إجراء/تدفق (مكوّن ضمن .bgl_core)
  - main: يشغّل إجراء/تدفق (مكوّن ضمن .bgl_core)

### brain\utils.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - load_route_usage: Compute simple usage frequency from runtime_events in knowledge.db. (مكوّن ضمن .bgl_core)

### brain\verify_phase_8_simulation.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - simulate_recurring_blockers: وظيفة ضمن مكوّن ضمن .bgl_core

### brain\volition.py
- الدور العام: مكوّن ضمن .bgl_core
- الدوال:
  - _ensure_table: دالة داخلية مساعدة (مكوّن ضمن .bgl_core)
  - store_volition: يخزن/يسجل بيانات (مكوّن ضمن .bgl_core)
  - latest_volition: وظيفة ضمن مكوّن ضمن .bgl_core
  - derive_volition: Produce a lightweight volition string from the current diagnostic context. (مكوّن ضمن .bgl_core)

## debug_tools

### debug_tools\check_dashboard.py
- الدور العام: مكوّن ضمن .bgl_core

### debug_tools\check_deps.py
- الدور العام: مكوّن ضمن .bgl_core

### debug_tools\debug_kpi.py
- الدور العام: مكوّن ضمن .bgl_core

### debug_tools\tmp_add.py
- الدور العام: مكوّن ضمن .bgl_core

### debug_tools\tmp_print.py
- الدور العام: مكوّن ضمن .bgl_core

### debug_tools\tmp_ren.py
- الدور العام: مكوّن ضمن .bgl_core

### debug_tools\tmp_rename.py
- الدور العام: مكوّن ضمن .bgl_core

## ملفات PHP/JS داخل .bgl_core (وصف وظيفي)

### .bgl_core/actuators/patcher.php
- الدور العام: منفّذ تعديلات AST للـ PHP (إعادة تسمية كلاس/مراجع + إضافة دالة) مع الحفاظ على التنسيق.
- الدوال:
  - parseMethodBody: يحوّل نص جسم دالة إلى AST statements لإدراجها بأمان داخل كلاس.
- الكلاسات (زوّار AST مجهولة الاسم داخليًا):
  - Visitor(rename_class):
    - __construct: تهيئة أسماء الكلاس القديم/الجديد.
    - enterNode: يبدّل اسم الكلاس ويحدث الـ Name المطابقة داخل الملف.
  - Visitor(rename_reference):
    - __construct: تهيئة الاسم القديم/الجديد بعد التطبيع.
    - matches: مطابقة اسم/مسار الصنف.
    - enterNode: تحديث use/trait/Name/String_ للمراجع المطابقة.
  - Visitor(add_method):
    - __construct: يحدد الكلاس المستهدف والدالة الجديدة وجسمها.
    - enterNode: يتحقق من عدم وجود الدالة ثم يضيفها إلى الكلاس.

### .bgl_core/sensors/ast_bridge.php
- الدور العام: حسّاس AST للـ PHP يبني شجرة علاقات (كلاسات/دوال/استدعاءات/تبعيات) لاستخدامها في knowledge.db.
- الكلاسات:
  - SensorVisitor:
    - beforeTraverse: يهيّئ الجذر والسياق المتسلسل.
    - enterNode: يلتقط الكلاسات والدوال واستدعاءات الميثود والستاتيك وعمليات new و app().
    - leaveNode: يغلق السياق عند الخروج من الكلاس/الدالة.
    - getVisibility: يحدد public/protected/private.

### .bgl_core/brain/logic_bridge.php
- الدور العام: جسر منطقي يسمح للبايثون باستدعاء منطق PHP (ConflictDetector) وإرجاع JSON آمن.
- الدوال:
  - safe_output: إخراج موحّد بصيغة JSON مع رمز HTTP مناسب حتى عند fatal.

### .bgl_core/debug_tools/debug_kpi.php
- الدور العام: سكربت تشخيص KPI؛ يتحقق من bootstrap.php وقراءة KPIs وربط قاعدة المعرفة.
- لا يحتوي على كلاسات/دوال معرفة صراحة (سكربت مباشر).

### .bgl_core/debug_tools/tmp_runner.php
- الدور العام: تشغيل تجريبي سريع لاستدعاء patcher.php بعملية rename_reference.
- لا يحتوي على كلاسات/دوال معرفة صراحة (سكربت مباشر).

### .bgl_core/brain/patch_templates/settings_autosave.js
- الدور العام: قالب JS لتفعيل الحفظ التلقائي لإعدادات الواجهة مع Toast.
- الدوال:
  - autoSaveSetting: يرسل POST للإعدادات ويعرض نجاح/فشل.