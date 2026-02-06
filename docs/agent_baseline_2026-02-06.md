# Agent Baseline (2026-02-06)

Timestamp: 2026-02-06 10:59:30

## knowledge.db
Path: C:\Users\Bakheet\Documents\Projects\BGL3\.bgl_core\brain\knowledge.db
Size: 5763072

## Table Counts
- agent_activity: 316
- agent_permissions: 2
- autonomy_goals: 64
- decisions: 684
- env_snapshots: 103
- experience_actions: 1
- experience_proposal_links: None
- experiences: 4227
- hypotheses: 2
- intents: 684
- learning_events: 698
- outcomes: 507
- runtime_events: 2730
- runtime_events_last_ts: 1770363695.211595
- ui_semantic_snapshots: 2

## config.yml (excerpt)
- agent_mode: autonomous
- agent_mode_bypass_env: BGL_LIGHT_MODE
- api_scan_force_examples: 1
- api_scan_mode: all
- autonomous_max_steps: 12
- autonomous_only: 0
- autonomous_policy: 1
- autonomous_policy_fallback: 1
- autonomous_scenario: 1
- base_url: http://localhost:8000
- browser_cpu_max: None
- browser_enabled: 1
- browser_mode: visible
- browser_ram_min_gb: None
- decision: {'mode': 'autonomous', 'mode_bypass_env': 'BGL_LIGHT_MODE', 'auto_fix': {'min_confidence': 0.6, 'max_risk': 'medium'}, 'refactor': {'requires_human': True}, 'reindex_full': {'requires_human': False}}
- diagnostic_timeout_sec: 300
- execution_mode: autonomous
- feature_flags: {'deprecated_routes': []}
- force_autonomous_policy: 1
- force_contract_seed: 1
- force_reindex: 1
- headless: 0
- hover_wait_ms: 300
- keep_browser: 1
- llm: {'base_url': 'http://127.0.0.1:11434', 'model': 'qwen2.5-coder:7b', 'auto_start': 1}
- max_pages: 3
- measure_perf: 1
- page_idle_timeout: 120
- policy_auto_promote_threshold: 0.2
- policy_force_promote: 1
- policy_strict: 0
- post_wait_ms: 1500
- reasoning_enabled: 1
- run_api_contract: 1
- run_gap_tests: 1
- run_scenarios: 1
- scenario_exploration: 1
- scenario_include_api: 1
- scenario_include_autonomous: 1
- slow_mo_ms: 400
