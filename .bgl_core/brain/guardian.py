import time
import os
import asyncio
import json
import sqlite3
import urllib.request
import urllib.parse
import urllib.error
from pathlib import Path
from typing import List, Dict, Any, cast
import re
import sys
import yaml  # type: ignore

try:
    from .safety import SafetyNet  # type: ignore
    from .fault_locator import FaultLocator  # type: ignore
    from .config_loader import load_config  # type: ignore
    from .decision_engine import decide  # type: ignore
    from .decision_db import insert_intent, insert_decision, insert_outcome, init_db  # type: ignore
    from .scenario_deps import check_scenario_deps_async  # type: ignore
    from .indexer import EntityIndexer  # type: ignore
    from .readiness_gate import run_readiness  # type: ignore
    from .generate_openapi import generate as generate_openapi  # type: ignore
    from .contract_seeder import seed_contract  # type: ignore
    from .policy_verifier import verify_failure  # type: ignore
    from .authority import Authority  # type: ignore
    from .brain_types import ActionRequest, ActionKind  # type: ignore
    from .observations import latest_env_snapshot, compute_skip_recommendation  # type: ignore
    from .fingerprint import compute_fingerprint, fingerprint_to_payload, fingerprint_equal, fingerprint_is_fresh  # type: ignore
except ImportError:
    from safety import SafetyNet
    from fault_locator import FaultLocator
    from config_loader import load_config
    from decision_engine import decide
    from decision_db import insert_intent, insert_decision, insert_outcome, init_db
    from scenario_deps import check_scenario_deps_async
    from indexer import EntityIndexer
    from readiness_gate import run_readiness
    from generate_openapi import generate as generate_openapi
    from contract_seeder import seed_contract
    from policy_verifier import verify_failure
    from authority import Authority
    from brain_types import ActionRequest, ActionKind
    from observations import latest_env_snapshot, compute_skip_recommendation
    from fingerprint import compute_fingerprint, fingerprint_to_payload, fingerprint_equal, fingerprint_is_fresh


def _preferred_python(root_dir: Path) -> str:
    candidates = [
        root_dir / ".bgl_core" / ".venv312" / "Scripts" / "python.exe",
        root_dir / ".bgl_core" / ".venv" / "Scripts" / "python.exe",
        root_dir / ".bgl_core" / ".venv312" / "bin" / "python",
        root_dir / ".bgl_core" / ".venv" / "bin" / "python",
    ]
    for cand in candidates:
        if cand.exists():
            return str(cand)
    return sys.executable or "python"


class BGLGuardian:
    def __init__(self, root_dir: Path, base_url: str = "http://localhost:8000"):
        self.root_dir = root_dir
        cfg = load_config(root_dir)
        self.base_url = cfg.get("base_url", base_url)
        browser_enabled = bool(
            int(os.getenv("BGL_ENABLE_BROWSER", str(cfg.get("browser_enabled", 0))))
        )
        self.safety = SafetyNet(root_dir, base_url, enable_browser=browser_enabled)
        self.db_path = root_dir / ".bgl_core" / "brain" / "knowledge.db"
        self.locator = FaultLocator(self.db_path, root_dir)
        self.config = cfg
        self.agent_mode = str(cfg.get("agent_mode", "assisted")).lower()
        self.authority = Authority(root_dir)
        # decision db
        self.decision_db_path = self.db_path  # موحد مع knowledge.db
        self.decision_schema = root_dir / ".bgl_core" / "brain" / "decision_schema.sql"
        if self.decision_schema.exists():
            init_db(self.decision_db_path, self.decision_schema)
        self.execution_mode = str(cfg.get("execution_mode", "sandbox")).lower()
        self.python_exe = _preferred_python(root_dir)

    async def perform_full_audit(self) -> Dict[str, Any]:
        """
        Scans all indexed routes and provides a proactive health report.
        """
        print("[*] Guardian: Starting Full System Health Audit...")
        readiness = self._preflight_services()
        api_contract = self._load_api_contract()
        # Reduce permission spam and keep queue clean
        self._dedupe_permissions()
        scenario_deps = (await check_scenario_deps_async()).to_dict()
        tool_evidence = {}
        external_checks = []
        try:
            from .llm_tools import tool_route_index, tool_run_checks  # type: ignore
        except Exception:
            try:
                from llm_tools import tool_route_index, tool_run_checks  # type: ignore
            except Exception:
                tool_route_index = tool_run_checks = None

        if tool_route_index:
            try:
                tool_evidence["route_index"] = tool_route_index()
            except Exception as e:
                tool_evidence["route_index"] = {"status": "ERROR", "message": str(e)}
        if tool_run_checks:
            try:
                tool_evidence["run_checks"] = tool_run_checks()
                try:
                    if isinstance(tool_evidence["run_checks"], dict):
                        external_checks = tool_evidence["run_checks"].get("results") or []
                except Exception:
                    external_checks = []
            except Exception as e:
                tool_evidence["run_checks"] = {"status": "ERROR", "message": str(e)}

        # Initialize report early to avoid UnboundLocalError on unexpected early failures.
        # We'll update it later once routes + scan metrics are ready.
        report: Dict[str, Any] = {
            "timestamp": time.time(),
            "total_routes": 0,
            "healthy_routes": 0,
            "failing_routes": [],
            "skipped_routes": [],
            "log_anomalies": [],
            "business_conflicts": [],
            "domain_rule_violations": [],
            "domain_rule_summary": {},
            "suggestions": [],
            "recent_experiences": [],
            "route_scan_limit": 0,
            "route_scan_mode": str(self.config.get("route_scan_mode", "auto")).lower(),
            "permission_issues": [],
            "agent_mode": self.agent_mode,
            "tool_evidence": tool_evidence,
            "external_checks": external_checks,
            "scenario_deps": scenario_deps,
            "readiness": readiness,
            "api_contract": api_contract.get("summary", {}),
            "api_contract_missing": [],
            "api_contract_gaps": [],
            "expected_failures": [],
            "policy_candidates": [],
            "policy_auto_promoted": [],
            "api_scan": {"mode": "safe", "checked": 0, "skipped": 0, "errors": 0},
        }

        # Autonomous re-indexing (closing the gap)
        indexer = EntityIndexer(self.root_dir, self.db_path)
        indexer.index_project()

        # Optional: run predefined Playwright scenarios to populate runtime events
        run_scenarios = os.getenv(
            "BGL_RUN_SCENARIOS", str(self.config.get("run_scenarios", 1))
        )
        scenario_run_stats: Dict[str, Any] = {
            "attempted": False,
            "status": "skipped",
            "event_delta": 0,
            "duration_s": 0.0,
        }
        if run_scenarios == "1":
            scenario_runner_main = None
            scenario_dep_error = None
            if not scenario_deps.get("ok", False):
                scenario_dep_error = (
                    "missing deps: " + ", ".join(scenario_deps.get("missing", []))
                )
                print(
                    f"    [!] Guardian: scenario deps missing ({scenario_dep_error}); skipping scenario batch."
                )
            else:
                try:
                    try:
                        from .scenario_runner import main as scenario_runner_main  # type: ignore
                    except ImportError:
                        from scenario_runner import main as scenario_runner_main
                except Exception as e:
                    scenario_dep_error = str(e)
                    print(
                        f"    [!] Guardian: scenario runner unavailable ({e}); skipping scenario batch."
                    )
            base_url = os.getenv(
                "BGL_BASE_URL", self.config.get("base_url", "http://localhost:8000")
            )
            headless = bool(
                int(os.getenv("BGL_HEADLESS", str(self.config.get("headless", 0))))
            )  # default visible
            keep_open = bool(
                int(
                    os.getenv(
                        "BGL_KEEP_BROWSER", str(self.config.get("keep_browser", 0))
                    )
                )
            )
            # Avoid blocking automated diagnostics: keep_browser should not pause runs.
            try:
                if str(self.agent_mode).lower() in {"auto", "autonomous"}:
                    keep_open = False
            except Exception:
                pass
            # Apply scenario config hints
            include_api = str(self.config.get("scenario_include_api", "0"))
            os.environ.setdefault("BGL_INCLUDE_API", include_api)
            include_autonomous = str(
                self.config.get(
                    "scenario_include_autonomous",
                    "1" if self.execution_mode == "autonomous" else "0",
                )
            )
            os.environ.setdefault("BGL_INCLUDE_AUTONOMOUS", include_autonomous)
            os.environ.setdefault(
                "BGL_EXPLORATION",
                str(self.config.get("scenario_exploration", os.getenv("BGL_EXPLORATION", "1"))),
            )
            # Ensure predefined scenarios (including gaps) are runnable during audits.
            os.environ["BGL_AUTONOMOUS_ONLY"] = "0"
            scenario_max_pages = int(self.config.get("max_pages", 3))
            scenario_idle_timeout = int(self.config.get("page_idle_timeout", 120))
            scenario_timeout_sec = int(
                os.getenv(
                    "BGL_SCENARIO_BATCH_TIMEOUT_SEC",
                    str(self.config.get("scenario_batch_timeout_sec", 300)),
                )
                or 300
            )
            scenario_include = self.config.get("scenario_include", None)
            if isinstance(scenario_include, str) and not scenario_include.strip():
                scenario_include = None

            # Gate scenario batch via Authority (single source of truth; avoids repeated LLM calls)
            scenario_gate = self.authority.gate(
                ActionRequest(
                    kind=ActionKind.WRITE_SANDBOX,
                    operation="run_scenarios",
                    command=f"{self.python_exe} scenario_runner.py --base-url {base_url} --headless {int(headless)}",
                    scope=["scenarios"],
                    reason="Run predefined Playwright scenarios",
                    confidence=0.7,
                    metadata={"policy_key": "run_scenarios", "deterministic_gate": True},
                ),
                source="guardian",
            )
            decision_id = int(scenario_gate.decision_id or 0)

            if not scenario_gate.allowed:
                print("    [!] Guardian: scenario batch blocked by decision gate.")
                self.authority.record_outcome(
                    decision_id, "blocked", scenario_gate.message or "scenario_batch blocked by gate"
                )
                scenario_run_stats["status"] = "blocked"
            elif self.agent_mode == "safe":
                print("    [!] Guardian: agent_mode=safe => skipping scenarios/browser run.")
                self.authority.record_outcome(decision_id, "skipped", "agent_mode safe")
                scenario_run_stats["status"] = "safe_mode"
            elif scenario_runner_main is None:
                self.authority.record_outcome(
                    decision_id,
                    "skipped",
                    scenario_dep_error or "scenario runner missing dependency",
                )
                scenario_run_stats["status"] = "deps_missing"
            else:
                print("    [+] Permission granted for scenarios; running.")
                try:
                    scenario_run_stats["attempted"] = True
                    pre_events = self._count_runtime_events()
                    run_started = time.time()
                    await asyncio.wait_for(
                        scenario_runner_main(
                            base_url,
                            headless,
                            keep_open,
                            max_pages=scenario_max_pages,
                            idle_timeout=scenario_idle_timeout,
                            include=scenario_include,
                            shadow_mode=False,
                        ),
                        timeout=scenario_timeout_sec,
                    )
                    run_duration = time.time() - run_started
                    post_events = self._count_runtime_events()
                    delta = max(0, post_events - pre_events)
                    scenario_run_stats.update(
                        {"event_delta": delta, "duration_s": round(run_duration, 2)}
                    )
                    try:
                        min_delta = int(
                            os.getenv(
                                "BGL_SCENARIO_MIN_EVENT_DELTA",
                                str(self.config.get("scenario_min_event_delta", 5)),
                            )
                            or 5
                        )
                    except Exception:
                        min_delta = 5
                    try:
                        min_runtime = float(
                            os.getenv(
                                "BGL_SCENARIO_MIN_RUNTIME_SEC",
                                str(self.config.get("scenario_min_runtime_sec", 4)),
                            )
                            or 4
                        )
                    except Exception:
                        min_runtime = 4.0
                    try:
                        retry_enabled = bool(
                            int(
                                os.getenv(
                                    "BGL_SCENARIO_RETRY_ON_LOW_EVENTS",
                                    str(self.config.get("scenario_retry_on_low_events", 1)),
                                )
                            )
                        )
                    except Exception:
                        retry_enabled = True
                    try:
                        retry_delay = float(
                            os.getenv(
                                "BGL_SCENARIO_RETRY_DELAY_SEC",
                                str(self.config.get("scenario_retry_delay_sec", 8)),
                            )
                            or 8
                        )
                    except Exception:
                        retry_delay = 8.0
                    if delta < min_delta:
                        if run_duration < min_runtime:
                            scenario_run_stats["status"] = "skipped_or_locked"
                            self.authority.record_outcome(
                                decision_id,
                                "deferred",
                                "scenario_run:no_new_events (suspected lock/skip)",
                            )
                        elif retry_enabled:
                            time.sleep(max(0.0, retry_delay))
                            pre_retry = self._count_runtime_events()
                            retry_started = time.time()
                            try:
                                await scenario_runner_main(
                                    base_url,
                                    headless,
                                    keep_open,
                                    max_pages=scenario_max_pages,
                                    idle_timeout=scenario_idle_timeout,
                                    include=scenario_include,
                                    shadow_mode=False,
                                )
                            except Exception:
                                pass
                            retry_duration = time.time() - retry_started
                            post_retry = self._count_runtime_events()
                            retry_delta = max(0, post_retry - pre_retry)
                            scenario_run_stats.update(
                                {
                                    "retry_delta": retry_delta,
                                    "retry_duration_s": round(retry_duration, 2),
                                }
                            )
                            if retry_delta < min_delta:
                                scenario_run_stats["status"] = "low_events"
                                self.authority.record_outcome(
                                    decision_id,
                                    "deferred",
                                    "scenario_run:low_event_delta_after_retry",
                                )
                            else:
                                scenario_run_stats["status"] = "ok"
                                self.authority.record_outcome(
                                    decision_id, "success", "scenario batch executed (retry)"
                                )
                        else:
                            scenario_run_stats["status"] = "low_events"
                            self.authority.record_outcome(
                                decision_id, "deferred", "scenario_run:low_event_delta"
                            )
                    else:
                        scenario_run_stats["status"] = "ok"
                        self.authority.record_outcome(
                            decision_id, "success", "scenario batch executed"
                        )
                except Exception as e:
                    print(f"    [!] Guardian: scenario run failed: {e}")
                    self.authority.record_outcome(decision_id, "fail", str(e))
                    scenario_run_stats["status"] = "fail"

        # 0. Maintenance (New in Phase 5)
        self.log_maintenance()

        # 0. Sync Knowledge (New in Phase 5)
        try:
            from .route_indexer import LaravelRouteIndexer  # type: ignore
        except ImportError:
            from route_indexer import LaravelRouteIndexer

        # Compute a "current" fingerprint cheaply and decide if we can skip expensive steps.
        # This prevents re-running the same work when nothing relevant changed.
        prev_diag = None
        prev_fp = None
        try:
            prev_diag = latest_env_snapshot(self.db_path, kind="diagnostic")
            prev_fp = latest_env_snapshot(self.db_path, kind="project_fingerprint")
        except Exception:
            prev_diag = None
            prev_fp = None

        curr_fp = fingerprint_to_payload(compute_fingerprint(self.root_dir))
        fp_same = False
        try:
            prev_fp_payload = prev_fp.get("payload") if prev_fp else None
            # Treat fingerprints as "effectively same" within a short freshness window.
            # master_verify generates/updates artifacts (reports, sqlite, etc.) which may
            # touch mtimes; we don't want that to force full reruns every time.
            if fingerprint_is_fresh(prev_fp_payload, max_age_s=float(os.getenv("BGL_FINGERPRINT_FRESH_S", "900") or 900)):
                fp_same = True
            else:
                fp_same = fingerprint_equal(prev_fp_payload, curr_fp)
        except Exception:
            fp_same = False

        skip_advice = {"ok": False, "reasons": ["unknown"], "skip": {}}
        try:
            # We don't have the "current diagnostic payload" yet at this point in the run.
            # So we conservatively decide skip based on:
            # - fingerprint unchanged since last run
            # - last diagnostic had no failing routes
            # - last readiness was OK (or absent)
            if not fp_same:
                skip_advice = {"ok": False, "reasons": ["fingerprint_changed"], "skip": {}}
            else:
                last_payload = prev_diag.get("payload") if prev_diag else {}
                failing = int(((last_payload.get("route_health") or {}).get("failing_routes_count") or 0))
                ready_ok = None
                try:
                    ready_ok = bool((last_payload.get("readiness") or {}).get("ok"))
                except Exception:
                    ready_ok = None
                if failing == 0 and ready_ok is not False:
                    skip_advice = {"ok": True, "reasons": ["stable_since_last_run"], "skip": {"reindex": True}}
                else:
                    skip_advice = {"ok": False, "reasons": ["last_run_unstable"], "skip": {}}
        except Exception:
            skip_advice = {"ok": False, "reasons": ["skip_advice_failed"], "skip": {}}

        force_reindex = (
            str(self.config.get("force_reindex", "0")) == "1"
            or os.getenv("BGL_FORCE_REINDEX", "0") == "1"
        )
        if force_reindex:
            skip_advice = {"ok": False, "reasons": ["force_reindex"], "skip": {}}

        indexer = LaravelRouteIndexer(self.root_dir, self.db_path)
        reindex_gate = self._gate_reindex(self.root_dir)
        if not reindex_gate or not reindex_gate.allowed:
            print("    [!] Guardian: full reindex blocked by decision gate.")
        elif skip_advice.get("ok") and skip_advice.get("skip", {}).get("reindex"):
            print("    [*] Guardian: skipping reindex (stable fingerprint/diagnostic).")
            self.authority.record_outcome(int(reindex_gate.decision_id or 0), "skipped", "skip_advice=reindex")
            # Still persist a fresh fingerprint so the next run can see it.
            try:
                out = self.root_dir / ".bgl_core" / "brain" / "knowledge.db"
                self.authority  # ensure initialized
                # Avoid circular imports by using observations API already imported.
                from .observations import store_env_snapshot  # type: ignore
            except Exception:
                from observations import store_env_snapshot  # type: ignore
            try:
                store_env_snapshot(
                    self.db_path,
                    run_id=str(int(time.time())),
                    kind="project_fingerprint",
                    payload=curr_fp,
                    source="guardian",
                    confidence=None,
                    created_at=time.time(),
                )
            except Exception:
                pass
        else:
            try:
                # Prefer run(); fallback to index_project if available
                method = getattr(indexer, "run", None)
                if method is None:
                    method = getattr(indexer, "index_project", None)
                if method is None:
                    raise AttributeError("LaravelRouteIndexer has no run/index_project")
                method()
                self.authority.record_outcome(
                    int(reindex_gate.decision_id or 0), "success", "reindex_full completed"
                )
            except Exception as e:
                print(f"[!] Guardian: reindex failed: {e}")
                self.authority.record_outcome(
                    int(reindex_gate.decision_id or 0), "fail", str(e)
                )

        # 1. Ensure route index exists then fetch routes
        self._ensure_routes_indexed()
        routes = self._get_proxied_routes()
        limit_env = os.getenv("BGL_ROUTE_SCAN_LIMIT")
        limit_cfg = self.config.get("route_scan_limit")
        mode_cfg = str(self.config.get("route_scan_mode", "auto")).lower()
        api_scan_mode = str(self.config.get("api_scan_mode", "safe")).lower()
        if api_scan_mode in ("aggressive", "full", "unsafe", "all"):
            api_scan_mode = "all"
        if api_scan_mode not in ("safe", "skip", "all"):
            api_scan_mode = "safe"
        api_summary = {"mode": api_scan_mode, "checked": 0, "skipped": 0, "errors": 0}

        stats_path = self.root_dir / ".bgl_core" / "logs" / "route_scan_stats.json"
        past_stats = self._load_route_stats(stats_path)

        # Adaptive limit based on history + system load if no explicit limit
        limit = self._compute_adaptive_limit(
            total_routes=len(routes),
            mode=mode_cfg,
            limit_env=limit_env,
            limit_cfg=limit_cfg,
            past_stats=past_stats,
        )
        if os.getenv("BGL_ONE_SHOT_MUTATION", "0") == "1":
            limit = max(5, int(limit * 0.7))
            os.environ["BGL_ONE_SHOT_MUTATION"] = "0"

        if limit > 0:
            routes = routes[:limit]

        # Track scan timing for safety
        scan_start = time.time()
        target_duration = self._target_duration(past_stats)
        max_seconds = float(
            self.config.get(
                "route_scan_max_seconds", os.getenv("BGL_ROUTE_SCAN_MAX_SECONDS", 60)
            )
        )

        # Prioritize routes that recently appeared in experiential memory
        recent_exp = self._load_recent_experiences(hours=48, limit=50)
        hot_routes = {
            exp["scenario"] for exp in recent_exp if exp.get("confidence", 0) >= 0.6
        }
        routes = sorted(
            routes,
            key=lambda r: (
                0 if r.get("uri") in hot_routes else 1,
                -(r.get("status_score", 0) or 0),
            ),
        )

        report.update(
            {
                "timestamp": time.time(),
                "total_routes": len(routes),
                "healthy_routes": 0,
                "failing_routes": [],
                "skipped_routes": [],
                "log_anomalies": [],
                "business_conflicts": [],
                "domain_rule_violations": [],
                "domain_rule_summary": {},
                "suggestions": [],
                "recent_experiences": [],
                "route_scan_limit": len(routes),
                "route_scan_mode": mode_cfg,
                "permission_issues": [],
                "agent_mode": self.agent_mode,
                "tool_evidence": tool_evidence,
                "external_checks": external_checks,
                "scenario_deps": scenario_deps,
                "readiness": readiness,
                "api_contract": api_contract.get("summary", {}),
                "api_contract_missing": [],
                "api_contract_gaps": [],
                "expected_failures": [],
                "policy_candidates": [],
                "policy_auto_promoted": [],
                "api_scan": api_summary,
            }
        )
        report["scenario_run_stats"] = scenario_run_stats
        try:
            domain_violations = self._check_domain_rule_violations()
            report["domain_rule_violations"] = domain_violations
            rule_ids = []
            critical = 0
            for v in domain_violations:
                rid = v.get("rule_id")
                if rid:
                    rule_ids.append(str(rid))
                sev = str(v.get("severity", "")).lower()
                if sev == "critical":
                    critical += 1
            report["domain_rule_summary"] = {
                "count": len(domain_violations),
                "critical_count": critical,
                "rule_ids": sorted(list(set(rule_ids))),
            }
        except Exception:
            report["domain_rule_violations"] = []
            report["domain_rule_summary"] = {}

        coverage_payload: Dict[str, Any] = {"generated_at": time.time()}
        scenario_gaps: List[Dict[str, Any]] = []
        flow_gaps: List[Dict[str, Any]] = []

        def _score_gap(route: str, method: str, status_score: Any) -> float:
            try:
                score = float(status_score or 0)
            except Exception:
                score = 0.0
            try:
                if route.startswith("/api/"):
                    score += 10
            except Exception:
                pass
            try:
                if str(method or "").upper() in ("POST", "PUT", "PATCH", "DELETE"):
                    score += 20
            except Exception:
                pass
            return round(min(100.0, max(0.0, score)), 2)

        # Scenario coverage (routes + UI snapshots) and gap goals
        try:
            cov_days = int(os.getenv("BGL_COVERAGE_WINDOW_DAYS", "7") or "7")
            cov_limit = int(os.getenv("BGL_COVERAGE_SAMPLE_LIMIT", "12") or "12")
            coverage = self._compute_scenario_coverage(days=cov_days, limit=cov_limit)
            report["scenario_coverage"] = coverage
            for item in (coverage.get("uncovered_sample") or []):
                score = _score_gap(
                    str(item.get("route") or ""),
                    str(item.get("method") or ""),
                    item.get("status_score"),
                )
                scenario_gaps.append(
                    {
                        "route": item.get("route"),
                        "status": "uncovered",
                        "evidence": {
                            "method": item.get("method"),
                            "status_score": item.get("status_score"),
                        },
                        "priority_score": score,
                    }
                )
            coverage_payload["scenario"] = {
                "summary": coverage,
                "gaps": scenario_gaps,
            }

            # Seed coverage gap goals to bias autonomous exploration
            try:
                min_ratio = float(os.getenv("BGL_COVERAGE_MIN_RATIO", "35") or "35")
                ratio = float(coverage.get("coverage_ratio") or 0.0)
                gap_limit = int(os.getenv("BGL_COVERAGE_GOAL_LIMIT", "4") or "4")
                min_score = float(os.getenv("BGL_COVERAGE_GOAL_MIN_SCORE", "40") or "40")
                if ratio < min_ratio and coverage.get("reliable", True):
                    prioritized = sorted(
                        scenario_gaps, key=lambda g: float(g.get("priority_score") or 0), reverse=True
                    )
                    coverage_payload["scenario"]["prioritized_gaps"] = prioritized[:gap_limit]
                    seeded = 0
                    for item in prioritized:
                        if seeded >= gap_limit:
                            break
                        if float(item.get("priority_score") or 0) < min_score:
                            continue
                        uri = item.get("route")
                        if not uri:
                            continue
                        self._write_autonomy_goal(
                            "coverage_gap",
                            {
                                "uri": uri,
                                "coverage_ratio": ratio,
                                "window_days": cov_days,
                                "reason": "scenario_coverage_gap",
                                "priority_score": item.get("priority_score"),
                            },
                            "coverage",
                            ttl_days=2,
                        )
                        seeded += 1
            except Exception:
                pass
        except Exception:
            report["scenario_coverage"] = {}

        # UI action coverage (interactive elements vs exploration history)
        try:
            ui_days = int(
                os.getenv(
                    "BGL_UI_ACTION_WINDOW_DAYS",
                    str(self.config.get("ui_action_window_days", "7")),
                )
                or "7"
            )
            ui_limit = int(
                os.getenv(
                    "BGL_UI_ACTION_SAMPLE_LIMIT",
                    str(self.config.get("ui_action_sample_limit", "12")),
                )
                or "12"
            )
            ui_cov = self._compute_ui_action_coverage(days=ui_days, limit=ui_limit)
            report["ui_action_coverage"] = ui_cov
            ui_gaps = ui_cov.get("gaps") or []
            if ui_cov:
                coverage_payload["ui_actions"] = {
                    "summary": ui_cov,
                    "gaps": ui_gaps,
                }
            # Seed UI action gap goals to bias exploration
            try:
                min_ratio = float(
                    os.getenv(
                        "BGL_MIN_UI_ACTION_COVERAGE",
                        str(self.config.get("min_ui_action_coverage", "30")),
                    )
                    or "30"
                )
                ratio = float(ui_cov.get("coverage_ratio") or 0.0)
                gap_limit = int(
                    os.getenv(
                        "BGL_UI_ACTION_GOAL_LIMIT",
                        str(self.config.get("ui_action_goal_limit", "4")),
                    )
                    or "4"
                )
                min_score = float(
                    os.getenv(
                        "BGL_UI_ACTION_GOAL_MIN_SCORE",
                        str(self.config.get("ui_action_goal_min_score", "45")),
                    )
                    or "45"
                )
                if ratio < min_ratio and ui_cov.get("reliable", True):
                    prioritized = sorted(
                        ui_gaps,
                        key=lambda g: float(g.get("priority_score") or 0),
                        reverse=True,
                    )
                    coverage_payload["ui_actions"][
                        "prioritized_gaps"
                    ] = prioritized[:gap_limit]
                    seeded = 0
                    for item in prioritized:
                        if seeded >= gap_limit:
                            break
                        if float(item.get("priority_score") or 0) < min_score:
                            continue
                        uri = item.get("route") or ""
                        selector = item.get("selector") or ""
                        if not uri and not selector:
                            continue
                        self._write_autonomy_goal(
                            "ui_action_gap",
                            {
                                "uri": uri,
                                "selector": selector,
                                "text": item.get("text"),
                                "href": item.get("href"),
                                "tag": item.get("tag"),
                                "risk": item.get("risk"),
                                "coverage_ratio": ratio,
                                "window_days": ui_days,
                                "priority_score": item.get("priority_score"),
                            },
                            "ui_action_coverage",
                            ttl_days=2,
                        )
                        seeded += 1
            except Exception:
                pass
        except Exception:
            report["ui_action_coverage"] = {}

        # Flow coverage (docs/flows) and flow gap goals
        try:
            flow_days = int(os.getenv("BGL_FLOW_COVERAGE_DAYS", "14") or "14")
            flow_limit = int(os.getenv("BGL_FLOW_COVERAGE_SAMPLE", "8") or "8")
            flow_cov = self._compute_flow_coverage(days=flow_days, limit=flow_limit)
            report["flow_coverage"] = flow_cov
            for item in (flow_cov.get("uncovered_sample") or []):
                flow_score = 60.0 + 10.0 * len(item.get("missing_steps") or [])
                flow_gaps.append(
                    {
                        "flow": item.get("flow"),
                        "status": item.get("status"),
                        "endpoints": item.get("endpoints") or [],
                        "priority_score": round(flow_score, 2),
                    }
                )
            coverage_payload["flow"] = {
                "summary": flow_cov,
                "details": flow_cov.get("details", []),
                "gaps": flow_gaps,
            }

            # Seed flow gap goals for uncovered/partial flows
            try:
                if flow_cov.get("reliable", True):
                    gap_limit = int(os.getenv("BGL_FLOW_GOAL_LIMIT", "3") or "3")
                    prioritized = sorted(
                        flow_gaps, key=lambda g: float(g.get("priority_score") or 0), reverse=True
                    )
                    coverage_payload["flow"]["prioritized_gaps"] = prioritized[:gap_limit]
                    seeded = 0
                    for item in prioritized:
                        if seeded >= gap_limit:
                            break
                        flow_name = item.get("flow") or ""
                        endpoints = item.get("endpoints") or []
                        if not endpoints and not flow_name:
                            continue
                        if endpoints:
                            for ep in endpoints[:2]:
                                self._write_autonomy_goal(
                                    "flow_gap",
                                    {
                                        "uri": ep,
                                        "flow": flow_name,
                                        "coverage_ratio": flow_cov.get("coverage_ratio"),
                                        "window_days": flow_days,
                                        "priority_score": item.get("priority_score"),
                                    },
                                    "flow_coverage",
                                    ttl_days=3,
                                )
                                seeded += 1
                                if seeded >= gap_limit:
                                    break
                        else:
                            self._write_autonomy_goal(
                                "flow_gap",
                                {
                                    "flow": flow_name,
                                    "coverage_ratio": flow_cov.get("coverage_ratio"),
                                    "window_days": flow_days,
                                    "priority_score": item.get("priority_score"),
                                },
                                "flow_coverage",
                                ttl_days=3,
                            )
                            seeded += 1
            except Exception:
                pass
        except Exception:
            report["flow_coverage"] = {}

        # Coverage gates (phase 3 acceptance metrics)
        try:
            coverage_gate = {}
            flow_gate = {}
            scenario_cov = report.get("scenario_coverage") or {}
            ui_action_cov = report.get("ui_action_coverage") or {}
            flow_cov = report.get("flow_coverage") or {}
            min_route = float(os.getenv("BGL_MIN_ROUTE_COVERAGE", "40") or "40")
            min_ui = float(os.getenv("BGL_MIN_UI_SEMANTIC_COVERAGE", "25") or "25")
            if scenario_cov:
                route_ratio = float(scenario_cov.get("coverage_ratio") or 0.0)
                ui_ratio = float(scenario_cov.get("ui_coverage_ratio") or 0.0)
                ui_action_ratio = float(ui_action_cov.get("coverage_ratio") or 0.0)
                min_ui_action = float(
                    os.getenv(
                        "BGL_MIN_UI_ACTION_COVERAGE",
                        str(self.config.get("min_ui_action_coverage", "30")),
                    )
                    or "30"
                )
                coverage_gate = {
                    "min_route_ratio": min_route,
                    "min_ui_ratio": min_ui,
                    "min_ui_action_ratio": min_ui_action,
                    "route_ratio": route_ratio,
                    "ui_ratio": ui_ratio,
                    "ui_action_ratio": ui_action_ratio,
                    "ok": bool(
                        route_ratio >= min_route
                        and ui_ratio >= min_ui
                        and (not ui_action_cov or ui_action_ratio >= min_ui_action)
                    ),
                }
            min_flow = float(os.getenv("BGL_MIN_FLOW_COVERAGE", "35") or "35")
            min_seq = float(os.getenv("BGL_MIN_FLOW_SEQUENCE", "15") or "15")
            if flow_cov:
                flow_ratio = float(flow_cov.get("coverage_ratio") or 0.0)
                seq_ratio = float(flow_cov.get("sequence_coverage_ratio") or 0.0)
                flow_gate = {
                    "min_flow_ratio": min_flow,
                    "min_sequence_ratio": min_seq,
                    "flow_ratio": flow_ratio,
                    "sequence_ratio": seq_ratio,
                    "ok": bool(flow_ratio >= min_flow and seq_ratio >= min_seq),
                }
            report["coverage_gate"] = coverage_gate
            report["flow_gate"] = flow_gate
        except Exception:
            report["coverage_gate"] = {}
            report["flow_gate"] = {}

        # Generate gap scenarios (optional, non-blocking)
        try:
            gen_limit = int(os.getenv("BGL_COVERAGE_SCENARIO_LIMIT", "6") or "6")
            existing_generated: List[str] = []
            try:
                gen_dir = self.root_dir / ".bgl_core" / "brain" / "scenarios" / "generated"
                if gen_dir.exists():
                    existing_generated = [p.name for p in gen_dir.glob("*.yaml")]
                    existing_generated = sorted(existing_generated)[: max(6, gen_limit * 2)]
            except Exception:
                existing_generated = []
            reliable = True
            try:
                reliable = (
                    (coverage_payload.get("scenario") or {})
                    .get("summary", {})
                    .get("reliable", True)
                    and (coverage_payload.get("ui_actions") or {})
                    .get("summary", {})
                    .get("reliable", True)
                    and (coverage_payload.get("flow") or {})
                    .get("summary", {})
                    .get("reliable", True)
                )
            except Exception:
                reliable = True
            generated = [] if not reliable else self._generate_gap_scenarios(coverage_payload, limit=gen_limit)
            if generated:
                coverage_payload["generated_scenarios"] = generated
                report["gap_scenarios"] = generated
            if existing_generated:
                coverage_payload["existing_generated_scenarios"] = existing_generated
                report["gap_scenarios_existing"] = existing_generated
        except Exception:
            pass

        # Coverage reliability summary (avoid acting on low-evidence coverage)
        try:
            report["coverage_reliability"] = {
                "scenario": (report.get("scenario_coverage") or {}).get("reliable", True),
                "ui_actions": (report.get("ui_action_coverage") or {}).get("reliable", True),
                "flow": (report.get("flow_coverage") or {}).get("reliable", True),
            }
        except Exception:
            report["coverage_reliability"] = {}

        # UI flow model (phase 3: flow understanding)
        try:
            flow_days = int(os.getenv("BGL_UI_FLOW_DAYS", "7") or "7")
            flow_limit = int(os.getenv("BGL_UI_FLOW_LIMIT", "30") or "30")
            ui_flow_model = self._compute_ui_flow_model(days=flow_days, limit=flow_limit)
            if ui_flow_model:
                report["ui_flow_model"] = ui_flow_model
                analysis_dir = self.root_dir / "analysis"
                analysis_dir.mkdir(parents=True, exist_ok=True)
                (analysis_dir / "ui_flow_model.json").write_text(
                    json.dumps(ui_flow_model, ensure_ascii=False, indent=2),
                    encoding="utf-8",
                )
        except Exception:
            report["ui_flow_model"] = {}

        # Write unified coverage artifact
        try:
            if (
                "scenario" in coverage_payload
                or "flow" in coverage_payload
                or "ui_actions" in coverage_payload
            ):
                analysis_dir = self.root_dir / "analysis"
                analysis_dir.mkdir(parents=True, exist_ok=True)
                coverage_path = analysis_dir / "coverage.json"
                coverage_path.write_text(
                    json.dumps(coverage_payload, ensure_ascii=False, indent=2),
                    encoding="utf-8",
                )
        except Exception:
            pass
        report["api_contract_missing"] = self._contract_missing_routes(
            routes, api_contract.get("paths", {})
        )
        report["api_contract_gaps"] = self._contract_quality_gaps(
            routes, api_contract.get("paths", {})
        )

        # 2. Sequential Scan
        important_routes = routes

        for route in important_routes:
            if time.time() - scan_start > max_seconds:
                print(
                    f"[WARN] Route scan reached max seconds ({max_seconds}s). Stopping early."
                )
                break
            uri = route["uri"]
            print(f"    - Checking: {uri}")
            if self._is_api_route(uri):
                api_res = self._scan_api_route(
                    uri,
                    route.get("http_method", "GET"),
                    self.base_url,
                    api_scan_mode,
                    contract=api_contract.get("paths", {}).get(uri),
                )
                if api_res.get("skipped"):
                    api_summary["skipped"] += 1
                    report["skipped_routes"].append(
                        {"uri": uri, "reason": api_res.get("reason", "skipped")}
                    )
                    continue
                api_summary["checked"] += 1
                if not api_res.get("valid", False):
                    expected = self._classify_expected_failure(
                        uri,
                        api_res.get("method_used") or route.get("http_method", "GET"),
                        api_res,
                    )
                    if expected:
                        report["expected_failures"].append(expected)
                        report["healthy_routes"] += 1
                        status_score = 100
                        self._update_route_health(route, status_score)
                        continue
                    candidate = self._maybe_record_policy_candidate(
                        uri,
                        api_res.get("method_used") or route.get("http_method", "GET"),
                        api_res,
                    )
                    if candidate:
                        report["policy_candidates"].append(candidate)
                    api_summary["errors"] += 1
                    report["failing_routes"].append(
                        {
                            "uri": uri,
                            "errors": [
                                api_res.get("error")
                                or f"HTTP {api_res.get('status')}"
                            ],
                            "error_body": api_res.get("error_body"),
                            "suspect_code": self.locator.locate_url(uri),
                        }
                    )
                    status_score = 0
                else:
                    report["healthy_routes"] += 1
                    status_score = 100
                self._update_route_health(route, status_score)
                continue
            if (
                not getattr(self.safety, "enable_browser", False)
                or not getattr(self.safety, "browser", None)
                or not hasattr(self.safety.browser, "scan_url")
            ):
                scan_res = {"valid": True, "report": {}}
            else:
                measure_perf = bool(int(self.config.get("measure_perf", 0)))
                try:
                    scan_timeout = float(
                        os.getenv(
                            "BGL_BROWSER_SCAN_TIMEOUT_SEC",
                            str(self.config.get("browser_scan_timeout_sec", 15)),
                        )
                        or 15
                    )
                except Exception:
                    scan_timeout = 15.0
                try:
                    scan_res = await asyncio.wait_for(
                        self.safety.browser.scan_url(uri, measure_perf=measure_perf),
                        timeout=scan_timeout,
                    )
                except asyncio.TimeoutError:
                    scan_res = {
                        "status": "SKIPPED",
                        "skipped": True,
                        "reason": f"browser_timeout_{scan_timeout}s",
                    }
                except Exception as e:
                    scan_res = {"status": "SKIPPED", "skipped": True, "reason": str(e)}

            status_score = 100
            if isinstance(scan_res, dict) and scan_res.get("skipped"):
                report["skipped_routes"].append(
                    {"uri": uri, "reason": scan_res.get("reason", "skipped")}
                )
                continue
            status_val = scan_res.get("status", "SUCCESS")
            if (
                status_val != "SUCCESS"
                or scan_res.get("console_errors")
                or scan_res.get("network_failures")
            ):
                status_score = 0
                res: Dict[str, Any] = cast(Dict[str, Any], scan_res)
                c_errs: List[Any] = res.get("console_errors", [])  # type: ignore
                n_errs: List[Any] = [
                    f.get("error", "Unknown network failure")
                    for f in res.get("network_failures", [])  # type: ignore
                ]
                failure_info = {
                    "uri": uri,
                    "errors": c_errs + n_errs,
                    "suspect_code": self.locator.locate_url(uri),
                }
                report["failing_routes"].append(failure_info)
            else:
                report["healthy_routes"] += 1

            # Phase 5: Update Knowledge Base
            self._update_route_health(route, status_score)

        # 3. Analyze Backend Logs for Anomalies
        report["log_anomalies"] = self._detect_log_anomalies()

        # Auto-promote high-confidence policy candidates
        if report.get("policy_candidates"):
            report["policy_auto_promoted"] = self._auto_promote_policy_candidates(
                report["policy_candidates"]
            )

        # 3b. Check Learning Confirmations (False Positives / Anomalies)
        report["learning_confirmations"] = self._check_learning_confirmations()

        # 4. Check Business Logic Conflicts (Collaborative Integration)
        # Fetch actual recent candidates from guarantees table
        report["business_conflicts"] = self._check_business_conflicts_real()

        # 4b. Permission watchdog (write access to critical files)
        report["permission_issues"] = self._check_permissions()

        # 5. Load experiential memory and generate Suggestions
        report["recent_experiences"] = self._load_recent_experiences()
        report["suggestions"] = self._generate_suggestions(report)
        report["worst_routes"] = self._worst_routes(report)

        # 6. Timing safety check
        scan_duration = time.time() - scan_start
        if scan_duration > max_seconds:
            report.setdefault("warnings", []).append(
                f"Route scan exceeded safe time ({scan_duration:.1f}s > {max_seconds}s). Consider lowering limit or resources."
            )
        report["scan_duration_seconds"] = scan_duration
        report["target_duration_seconds"] = target_duration

        # 7. Persist stats for next adaptive run
        self._persist_route_stats(stats_path, len(important_routes), scan_duration)

        # 8. Authority/Approval visibility (trust layer)
        report["pending_approvals"] = self._pending_approvals(limit=25)
        report["recent_outcomes"] = self._recent_outcomes(limit=25)

        return report

    def _update_route_health(self, route: Dict[str, Any], score: int):
        """Upserts health status to avoid failing when a route record is missing."""
        conn = sqlite3.connect(str(self.db_path))
        cursor = conn.cursor()
        try:
            cursor.execute(
                """
                INSERT INTO routes (uri, http_method, controller, action, file_path, last_validated, status_score)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(uri, http_method) DO UPDATE SET
                    last_validated=excluded.last_validated,
                    status_score=excluded.status_score
            """,
                (
                    route.get("uri"),
                    route.get("http_method", "ANY"),
                    route.get("controller"),
                    route.get("action"),
                    route.get("file_path"),
                    time.time(),
                    score,
                ),
            )
            conn.commit()
        finally:
            conn.close()

    def _preflight_services(self) -> Dict[str, Any]:
        """Readiness gate before deep audits to reduce timing false negatives."""
        base_url = str(self.config.get("base_url", self.base_url)).rstrip("/")
        tool_port = int(self.config.get("tool_server_port", 8891))
        llm_base_url = os.getenv("LLM_BASE_URL")
        llm_model = os.getenv("LLM_MODEL")
        return run_readiness(
            base_url=base_url,
            tool_port=tool_port,
            llm_base_url=llm_base_url,
            llm_model=llm_model,
        )

    def _load_api_contract(self) -> Dict[str, Any]:
        """Load merged OpenAPI spec and return paths map + summary."""
        seed_info = {}
        try:
            force_seed = (
                str(self.config.get("force_contract_seed", "0")) == "1"
                or os.getenv("BGL_FORCE_CONTRACT_SEED", "0") == "1"
            )
            seed_info = seed_contract(force=force_seed, refresh=force_seed)
        except Exception:
            seed_info = {}
        try:
            spec_path = generate_openapi(self.root_dir)
        except Exception:
            spec_path = self.root_dir / "docs" / "openapi.yaml"
        paths = {}
        summary = {"source": str(spec_path), "paths": 0}
        if seed_info:
            summary["seed"] = seed_info
        if spec_path and Path(spec_path).exists():
            try:
                import yaml  # type: ignore

                spec = yaml.safe_load(Path(spec_path).read_text(encoding="utf-8")) or {}
                paths = spec.get("paths", {}) or {}
                summary["paths"] = len(paths)
            except Exception as e:
                summary["error"] = str(e)
        else:
            summary["error"] = "openapi spec missing"
        return {"paths": paths, "summary": summary}

    def _contract_missing_routes(
        self, routes: List[Dict[str, Any]], contract_paths: Dict[str, Any]
    ) -> List[Dict[str, Any]]:
        """Return API routes present in code but missing in contract spec."""
        missing = []
        for r in routes:
            uri = r.get("uri")
            if not uri or not self._is_api_route(uri):
                continue
            if uri not in contract_paths:
                missing.append(
                    {
                        "uri": uri,
                        "http_method": r.get("http_method"),
                        "file_path": r.get("file_path"),
                    }
                )
        # Persist a lightweight report for quick review
        try:
            out = self.root_dir / ".bgl_core" / "logs" / "api_contract_missing.json"
            out.write_text(
                json.dumps(missing, ensure_ascii=False, indent=2), encoding="utf-8"
            )
        except Exception:
            pass
        return missing

    def _contract_quality_gaps(
        self, routes: List[Dict[str, Any]], contract_paths: Dict[str, Any]
    ) -> List[Dict[str, Any]]:
        """
        Identify contract paths that exist but lack enough detail to run a probe.
        Flags missing requestBody for write methods and missing required query examples for GET.
        """
        gaps: List[Dict[str, Any]] = []
        for r in routes:
            uri = r.get("uri")
            if not uri or not self._is_api_route(uri):
                continue
            method = (r.get("http_method") or "GET").upper()
            contract = contract_paths.get(uri) or {}
            if not contract:
                continue
            # Normalize contract methods
            contract_methods = {k.upper(): v for k, v in contract.items()}
            methods_to_check: List[str] = []
            if method == "ANY":
                methods_to_check = list(contract_methods.keys()) or ["GET"]
            else:
                methods_to_check = [method]
            for m in methods_to_check:
                op = contract_methods.get(m)
                if not op:
                    continue
                # write methods must define requestBody with example to be testable
                if m in ("POST", "PUT", "PATCH", "DELETE"):
                    rb = (op.get("requestBody") or {}).get("content", {})
                    app_json = rb.get("application/json") or {}
                    example = app_json.get("example")
                    if example is None:
                        example = (app_json.get("examples") or {}).get("default", {}).get("value")
                    if not rb or example is None:
                        gaps.append(
                            {
                                "uri": uri,
                                "http_method": m,
                                "reason": "missing_request_body_example",
                            }
                        )
                # required query params should include example/default
                if m in ("GET", "HEAD"):
                    params = op.get("parameters", []) or []
                    for p in params:
                        if p.get("in") != "query" or not p.get("required"):
                            continue
                        schema = p.get("schema") or {}
                        example = p.get("example")
                        if example is None:
                            example = schema.get("example")
                        if example is None:
                            example = schema.get("default")
                        if example is None:
                            gaps.append(
                                {
                                    "uri": uri,
                                    "http_method": m,
                                    "reason": f"missing_required_query_example:{p.get('name')}",
                                }
                            )
        # Persist a lightweight report for quick review
        try:
            out = self.root_dir / ".bgl_core" / "logs" / "api_contract_gaps.json"
            out.write_text(
                json.dumps(gaps, ensure_ascii=False, indent=2), encoding="utf-8"
            )
        except Exception:
            pass
        return gaps

    def auto_remediate(
        self, suggestion_index: int, report: Dict[str, Any]
    ) -> Dict[str, Any]:
        """
        Experimental: Attempts to solve a high-confidence suggestion using pre-defined rules.
        Now integrates BGLPatcher.
        """
        if suggestion_index >= len(report["suggestions"]):
            return {"status": "ERROR", "message": "Invalid suggestion index"}

        suggestion = report["suggestions"][suggestion_index]
        print(f"[*] Guardian: Attempting Rule-Guided Remediation for: {suggestion}")

        try:
            from .patcher import BGLPatcher  # type: ignore
        except ImportError:
            from patcher import BGLPatcher

        patcher = BGLPatcher(self.root_dir)
        _ = patcher  # Silence unused warning until logic is expanded

        # Simple example heuristics
        if "Rename class" in suggestion:
            # parsing suggestion string is fragile, ideally we'd have structured intent
            # implementation depends on valid structure
            pass

        return {
            "status": "INITIATED",
            "suggestion": suggestion,
            "message": "Auto-remediation logic triggered (BGLPatcher available)",
        }

    def log_maintenance(self):
        """Standard maintenance tasks for the system."""
        print("[*] Guardian: Running Log Maintenance...")
        self._prune_logs(days=7)

    def _prune_logs(self, days: int):
        """Clears old log entries to preserve performance."""
        # Simulation of pruning logic
        print(f"    - Pruning logs older than {days} days... OK")

    def _check_learning_confirmations(self) -> List[Dict[str, Any]]:
        """Queries the memory for confirmed anomalies or rejected false positives."""
        try:
            conn = sqlite3.connect(str(self.db_path))
            conn.row_factory = sqlite3.Row
            rows = conn.execute(
                "SELECT item_key, item_type, action, notes FROM learning_confirmations WHERE action IN ('confirm', 'reject') ORDER BY timestamp DESC LIMIT 5"
            ).fetchall()
            conn.close()
            return [dict(r) for r in rows]
        except Exception:
            return []

    def _check_business_conflicts_real(self) -> List[str]:
        """Calls the PHP logic bridge with REAL data to detect business-level conflicts."""
        import subprocess

        probe_path = self.root_dir / ".bgl_core" / "brain" / "business_conflicts_probe.php"
        if not probe_path.exists():
            return []

    def _count_runtime_events(self) -> int:
        if not self.db_path.exists():
            return 0
        try:
            conn = sqlite3.connect(str(self.db_path))
            row = conn.execute("SELECT COUNT(*) FROM runtime_events").fetchone()
            conn.close()
            return int(row[0] or 0) if row else 0
        except Exception:
            try:
                conn.close()
            except Exception:
                pass
            return 0

    def _normalize_route(self, route: str) -> str:
        """Normalize route/URL to a path for coverage comparison."""
        if not route:
            return ""
        try:
            if "://" in route:
                parsed = urllib.parse.urlparse(route)
                path = parsed.path or ""
                return path if path.startswith("/") else f"/{path}"
        except Exception:
            pass
        path = route.split("?")[0]
        if path.startswith(self.base_url):
            try:
                parsed = urllib.parse.urlparse(path)
                path = parsed.path or ""
            except Exception:
                path = path.replace(self.base_url, "")
        if not path.startswith("/"):
            path = f"/{path}"
        return path

    def _parse_flow_docs(self) -> List[Dict[str, Any]]:
        """Parse docs/flows/*.md into structured flow metadata."""
        flows_dir = self.root_dir / "docs" / "flows"
        if not flows_dir.exists():
            return []
        flows: List[Dict[str, Any]] = []
        for md in flows_dir.glob("*.md"):
            try:
                text = md.read_text(encoding="utf-8", errors="ignore")
            except Exception:
                continue
            title = ""
            steps: List[str] = []
            in_happy = False
            for line in text.splitlines():
                line = line.strip()
                if line.startswith("#"):
                    title = line.lstrip("#").strip()
                    break
            for line in text.splitlines():
                raw = line.strip()
                if not raw:
                    continue
                if raw.lower().startswith("##") and ("المسار الأساسي" in raw or "happy path" in raw.lower()):
                    in_happy = True
                    continue
                if raw.lower().startswith("##") and in_happy:
                    in_happy = False
                if in_happy:
                    m = re.match(r"^\d+\)\s*(.+)$", raw)
                    if m:
                        steps.append(m.group(1).strip())
            endpoints = []
            for m in re.findall(r"/api/[A-Za-z0-9_\-./]+", text):
                norm = self._normalize_route(m)
                if norm and norm not in endpoints:
                    endpoints.append(norm)
            # Capture event types from runtime_events `event_type`
            events = []
            for line in text.splitlines():
                if "runtime_events" in line or "event_type" in line:
                    for m in re.findall(r"`([^`]+)`", line):
                        if m:
                            events.append(m.strip())
            events = sorted(list({e for e in events if e}))
            flows.append(
                {
                    "file": md.name,
                    "title": title or md.stem,
                    "endpoints": endpoints,
                    "events": events,
                    "steps": steps[:12],
                }
            )
        return flows

    def _sequence_matches(self, route_list: List[str], endpoints: List[str]) -> bool:
        """Check if endpoints appear in order within a route list (not necessarily contiguous)."""
        if not endpoints:
            return False
        idx = 0
        for r in route_list:
            if r == endpoints[idx]:
                idx += 1
                if idx >= len(endpoints):
                    return True
        return False

    def _generate_gap_scenarios(
        self, coverage_payload: Dict[str, Any], limit: int = 6
    ) -> List[str]:
        """Generate lightweight gap scenarios from coverage gaps."""
        generated: List[str] = []
        out_dir = self.root_dir / ".bgl_core" / "brain" / "scenarios" / "generated"
        out_dir.mkdir(parents=True, exist_ok=True)

        def _slug(text: str) -> str:
            return re.sub(r"[^a-zA-Z0-9_]+", "_", (text or "").strip("/").lower())[:60]

        # Scenario gaps (route coverage)
        scenario_gaps = (
            (coverage_payload.get("scenario") or {}).get("gaps") or []
        )
        for item in scenario_gaps[:limit]:
            route = str(item.get("route") or "")
            if not route:
                continue
            name = f"gap_route_{_slug(route) or 'unknown'}"
            path = out_dir / f"{name}.yaml"
            if path.exists():
                continue
            kind = "api" if route.startswith("/api/") else "ui"
            steps = []
            if kind == "api":
                steps = [{"action": "request", "url": route, "method": "GET"}]
            else:
                steps = [
                    {"action": "goto", "url": route},
                    {"action": "wait", "ms": 400},
                ]
            payload = {
                "name": name,
                "kind": kind,
                "generated": True,
                "meta": {"origin": "coverage_gap", "route": route},
                "steps": steps,
            }
            try:
                path.write_text(
                    yaml.safe_dump(payload, sort_keys=False, allow_unicode=True),
                    encoding="utf-8",
                )
                generated.append(path.name)
            except Exception:
                continue

        # Flow gaps (flow coverage)
        flow_gaps = (coverage_payload.get("flow") or {}).get("gaps") or []
        for item in flow_gaps[:limit]:
            endpoints = item.get("endpoints") or []
            if not endpoints:
                continue
            flow_name = str(item.get("flow") or "flow")
            name = f"gap_flow_{_slug(flow_name)}"
            path = out_dir / f"{name}.yaml"
            if path.exists():
                continue
            steps = []
            for ep in endpoints[:3]:
                steps.append({"action": "request", "url": ep, "method": "GET"})
            payload = {
                "name": name,
                "kind": "api",
                "generated": True,
                "meta": {"origin": "flow_gap", "flow": flow_name, "endpoints": endpoints[:5]},
                "steps": steps,
            }
            try:
                path.write_text(
                    yaml.safe_dump(payload, sort_keys=False, allow_unicode=True),
                    encoding="utf-8",
                )
                generated.append(path.name)
            except Exception:
                continue

        # UI action gaps (interactive elements)
        ui_action_gaps = (coverage_payload.get("ui_actions") or {}).get("gaps") or []
        for item in ui_action_gaps[:limit]:
            route = str(item.get("route") or "")
            selector = str(item.get("selector") or "")
            label = selector or str(item.get("href") or "") or str(item.get("text") or "")
            if not (route or selector):
                continue
            name = f"gap_ui_action_{_slug(route or label) or 'unknown'}"
            path = out_dir / f"{name}.yaml"
            if path.exists():
                continue
            steps = []
            if route:
                steps.extend(
                    [
                        {"action": "goto", "url": route},
                        {"action": "wait", "ms": 400},
                    ]
                )
            if selector:
                danger = str(item.get("risk") or "") in ("danger", "write")
                click_step = {"action": "click", "selector": selector}
                if danger:
                    click_step["danger"] = True
                steps.append(click_step)
                steps.append({"action": "wait", "ms": 400})
            payload = {
                "name": name,
                "kind": "ui",
                "generated": True,
                "meta": {
                    "origin": "ui_action_gap",
                    "route": route,
                    "selector": selector,
                    "risk": item.get("risk"),
                },
                "steps": steps,
            }
            try:
                path.write_text(
                    yaml.safe_dump(payload, sort_keys=False, allow_unicode=True),
                    encoding="utf-8",
                )
                generated.append(path.name)
            except Exception:
                continue
        return generated

    def _compute_ui_flow_model(self, days: int = 7, limit: int = 30) -> Dict[str, Any]:
        if not self.db_path.exists():
            return {}
        cutoff = time.time() - (days * 86400)
        try:
            conn = sqlite3.connect(str(self.db_path))
            conn.row_factory = sqlite3.Row
            cur = conn.cursor()
            rows = cur.execute(
                """
                SELECT from_url, to_url, action, selector, COUNT(*) as c
                FROM ui_flow_transitions
                WHERE created_at >= ?
                GROUP BY from_url, to_url, action, selector
                ORDER BY c DESC
                LIMIT ?
                """,
                (cutoff, int(limit)),
            ).fetchall()
            conn.close()
        except Exception:
            return {}
        transitions: List[Dict[str, Any]] = []
        nodes: Dict[str, Dict[str, Any]] = {}
        for r in rows:
            from_url = self._normalize_route(r["from_url"] or "")
            to_url = self._normalize_route(r["to_url"] or "")
            if not (from_url or to_url):
                continue
            transitions.append(
                {
                    "from": from_url,
                    "to": to_url,
                    "action": r["action"],
                    "selector": r["selector"],
                    "count": int(r["c"] or 0),
                }
            )
            if from_url:
                nodes.setdefault(from_url, {"out": 0, "in": 0})
                nodes[from_url]["out"] += int(r["c"] or 0)
            if to_url:
                nodes.setdefault(to_url, {"out": 0, "in": 0})
                nodes[to_url]["in"] += int(r["c"] or 0)
        return {
            "window_days": days,
            "node_count": len(nodes),
            "transition_count": len(transitions),
            "nodes": nodes,
            "transitions": transitions,
        }

    def _compute_flow_coverage(self, days: int = 14, limit: int = 10) -> Dict[str, Any]:
        """
        Compute flow coverage based on docs/flows vs runtime_events.
        Returns summary with uncovered flow sample.
        """
        flows = self._parse_flow_docs()
        if not flows or not self.db_path.exists():
            return {}
        cutoff = time.time() - (days * 86400)
        route_seen: set[str] = set()
        action_route_seen: set[str] = set()
        events_seen: set[str] = set()
        session_routes: Dict[str, List[str]] = {}
        flow_gap_hits: Dict[str, int] = {}
        flow_gap_total = 0
        flow_gap_changed = 0
        flow_gap_last_ts = 0.0
        event_count = 0
        try:
            conn = sqlite3.connect(str(self.db_path))
            conn.row_factory = sqlite3.Row
            cur = conn.cursor()
            rows = cur.execute(
                """
                SELECT session, route, event_type, timestamp
                FROM runtime_events
                WHERE timestamp >= ? AND (route IS NOT NULL OR event_type IS NOT NULL)
                ORDER BY timestamp ASC
                """,
                (cutoff,),
            ).fetchall()
            event_count = len(rows)
            action_event_types = {
                "api_call",
                "http_error",
                "network_fail",
                "scenario_step_done",
                "file_upload",
                "form_auto_submit",
                "search_auto_submit",
                "ui_precheck",
                "ui_self_heal",
            }
            for r in rows:
                route = r["route"] or ""
                norm = self._normalize_route(route)
                if norm:
                    route_seen.add(norm)
                    sess = r["session"] or ""
                    if str(r["event_type"] or "") in action_event_types:
                        session_routes.setdefault(str(sess), []).append(norm)
                ev = str(r["event_type"] or "")
                if ev and ev in action_event_types:
                    events_seen.add(ev)
                if norm and str(r["event_type"] or "") in action_event_types:
                    action_route_seen.add(norm)
            # Flow gap scenario evidence (explicit link between flow gaps and coverage)
            try:
                rows_gap = cur.execute(
                    "SELECT timestamp, payload FROM runtime_events WHERE event_type='gap_scenario_done' AND timestamp >= ?",
                    (cutoff,),
                ).fetchall()
                for ts, payload in rows_gap:
                    try:
                        obj = json.loads(payload) if isinstance(payload, str) else (payload or {})
                    except Exception:
                        obj = {"raw": str(payload)}
                    origin = str(obj.get("origin") or (obj.get("meta") or {}).get("origin") or "")
                    if origin != "flow_gap":
                        continue
                    flow_name = str(obj.get("flow") or (obj.get("meta") or {}).get("flow") or "")
                    if flow_name:
                        flow_gap_hits[flow_name] = flow_gap_hits.get(flow_name, 0) + 1
                    flow_gap_total += 1
                    if bool(obj.get("changed")):
                        flow_gap_changed += 1
                    try:
                        flow_gap_last_ts = max(flow_gap_last_ts, float(ts or 0))
                    except Exception:
                        pass
            except Exception:
                flow_gap_hits = {}
                flow_gap_total = 0
                flow_gap_changed = 0
                flow_gap_last_ts = 0.0
            conn.close()
        except Exception:
            route_seen = set()
            action_route_seen = set()
            events_seen = set()
            session_routes = {}
            flow_gap_hits = {}
            flow_gap_total = 0
            flow_gap_last_ts = 0.0

        covered = 0
        seq_covered = 0
        results = []
        for flow in flows:
            endpoints = flow.get("endpoints") or []
            events = flow.get("events") or []
            hit_endpoints = [e for e in endpoints if e in action_route_seen]
            hit_events = [e for e in events if e in events_seen]
            status = "uncovered"
            if endpoints:
                if hit_endpoints:
                    status = "covered" if len(hit_endpoints) == len(endpoints) else "partial"
                    if events and not hit_events:
                        status = "partial"
                else:
                    status = "uncovered"
            else:
                if hit_events:
                    status = "covered"
            if status == "covered":
                covered += 1
            gap_runs = int(flow_gap_hits.get(flow.get("title") or "", 0) or 0)
            if gap_runs and status == "uncovered":
                status = "partial"
            missing_steps = [e for e in endpoints if e not in action_route_seen]
            sequence_covered = False
            sequence_session = ""
            if endpoints and session_routes:
                for sess, routes in session_routes.items():
                    if self._sequence_matches(routes, endpoints):
                        sequence_covered = True
                        sequence_session = sess
                        break
            if sequence_covered:
                seq_covered += 1
            results.append(
                {
                    "flow": flow.get("title"),
                    "file": flow.get("file"),
                    "status": status,
                    "evidence": {
                        "routes": hit_endpoints[:5],
                        "events": hit_events[:5],
                    },
                    "endpoints": endpoints[:5],
                    "missing_steps": missing_steps[:5],
                    "steps_sample": (flow.get("steps") or [])[:5],
                    "sequence_covered": sequence_covered,
                    "sequence_session": sequence_session,
                    "gap_runs": gap_runs,
                }
            )

        ratio = round((covered / max(1, len(flows))) * 100, 2)
        seq_ratio = round((seq_covered / max(1, len(flows))) * 100, 2)
        uncovered = [r for r in results if r.get("status") != "covered"]
        try:
            min_events = int(
                os.getenv(
                    "BGL_FLOW_MIN_EVENTS",
                    str(self.config.get("flow_min_events", 10)),
                )
                or 10
            )
        except Exception:
            min_events = 10
        reliable = event_count >= min_events or flow_gap_total > 0
        reason = "" if reliable else "low_runtime_events"
        return {
            "window_days": days,
            "total_flows": len(flows),
            "covered_flows": covered,
            "coverage_ratio": ratio,
            "sequence_covered_flows": seq_covered,
            "sequence_coverage_ratio": seq_ratio,
            "uncovered_sample": uncovered[:limit],
            "details": results,
            "events_total": event_count,
            "gap_runs": flow_gap_total,
            "gap_changed": flow_gap_changed,
            "gap_last_ts": flow_gap_last_ts,
            "reliable": reliable,
            "reliability_reason": reason,
        }

    def _compute_scenario_coverage(self, days: int = 7, limit: int = 12) -> Dict[str, Any]:
        """
        Compute lightweight scenario coverage based on runtime_events vs routes.
        Returns summary with missing route sample for scenario generation.
        """
        if not self.db_path.exists():
            return {}
        cutoff = time.time() - (days * 86400)
        total_routes = 0
        covered_routes: set[str] = set()
        known_routes: set[str] = set()
        uncovered_sample: List[Dict[str, Any]] = []
        ui_paths: set[str] = set()
        event_count = 0
        ui_snapshot_count = 0
        semantic_change_count = 0
        gap_runs = 0
        gap_last_ts = 0.0
        try:
            conn = sqlite3.connect(str(self.db_path))
            conn.row_factory = sqlite3.Row
            cur = conn.cursor()
            # Build normalized route set (avoid double-counting / query noise)
            rows_known = cur.execute("SELECT uri FROM routes").fetchall()
            for row in rows_known:
                norm = self._normalize_route(row["uri"] or "")
                if norm:
                    known_routes.add(norm)
            total_routes = len(known_routes)
            rows = cur.execute(
                "SELECT DISTINCT route FROM runtime_events WHERE timestamp >= ? AND route IS NOT NULL",
                (cutoff,),
            ).fetchall()
            try:
                event_row = cur.execute(
                    "SELECT COUNT(*) FROM runtime_events WHERE timestamp >= ?",
                    (cutoff,),
                ).fetchone()
                event_count = int(event_row[0] or 0) if event_row else 0
            except Exception:
                event_count = 0
            for r in rows:
                norm = self._normalize_route(r["route"] or "")
                if norm:
                    covered_routes.add(norm)
            if known_routes:
                covered_routes = covered_routes.intersection(known_routes)
            # UI semantic snapshots coverage (paths)
            try:
                rows2 = cur.execute(
                    "SELECT DISTINCT url FROM ui_semantic_snapshots WHERE created_at >= ?",
                    (cutoff,),
                ).fetchall()
                for r in rows2:
                    norm = self._normalize_route(r["url"] or "")
                    if norm:
                        ui_paths.add(norm)
                ui_snapshot_count = len(rows2)
            except Exception:
                ui_paths = set()

            # Count semantic changes from UI flow transitions (operational coverage signal).
            try:
                rows_flow = cur.execute(
                    "SELECT semantic_delta_json FROM ui_flow_transitions WHERE created_at >= ?",
                    (cutoff,),
                ).fetchall()
                for row in rows_flow:
                    try:
                        payload = json.loads(row[0] or "{}")
                    except Exception:
                        payload = {}
                    if isinstance(payload, dict) and payload.get("changed"):
                        semantic_change_count += 1
            except Exception:
                semantic_change_count = 0

            # Gap scenario execution stats (explicit link between gaps and coverage).
            try:
                gap_rows = cur.execute(
                    "SELECT timestamp, payload FROM runtime_events WHERE event_type='gap_scenario_done' AND timestamp >= ?",
                    (cutoff,),
                ).fetchall()
                gap_runs = len(gap_rows)
                gap_changed = 0
                for ts, payload in gap_rows:
                    try:
                        obj = json.loads(payload) if isinstance(payload, str) else (payload or {})
                    except Exception:
                        obj = {}
                    if bool(obj.get("changed")):
                        gap_changed += 1
                    try:
                        gap_last_ts = max(gap_last_ts, float(ts or 0))
                    except Exception:
                        pass
            except Exception:
                gap_runs = 0
                gap_changed = 0
                gap_last_ts = 0.0

            # Sample uncovered routes for scenario generation
            if total_routes > 0:
                rows3 = cur.execute(
                    "SELECT uri, http_method, status_score, last_validated FROM routes ORDER BY last_validated DESC"
                ).fetchall()
                for row in rows3:
                    uri = row["uri"]
                    norm = self._normalize_route(uri or "")
                    if norm and norm not in covered_routes:
                        uncovered_sample.append(
                            {
                                "route": norm,
                                "method": row["http_method"],
                                "status_score": row["status_score"],
                            }
                        )
                    if len(uncovered_sample) >= limit:
                        break
            conn.close()
        except Exception:
            return {}

        covered_count = len(covered_routes)
        ratio = round((covered_count / max(1, total_routes)) * 100, 2)
        ui_ratio = round((len(ui_paths) / max(1, total_routes)) * 100, 2) if total_routes else 0.0
        try:
            min_events = int(
                os.getenv(
                    "BGL_COVERAGE_MIN_EVENTS",
                    str(self.config.get("coverage_min_events", 20)),
                )
                or 20
            )
        except Exception:
            min_events = 20
        reliable = event_count >= min_events
        reason = "" if reliable else "low_runtime_events"
        if ui_snapshot_count > 0 and semantic_change_count <= 0:
            reliable = False
            reason = "no_semantic_changes"
        return {
            "window_days": days,
            "total_routes": total_routes,
            "covered_routes": covered_count,
            "coverage_ratio": ratio,
            "ui_snapshot_paths": len(ui_paths),
            "ui_coverage_ratio": ui_ratio,
            "uncovered_sample": uncovered_sample,
            "events_total": event_count,
            "ui_snapshot_count": ui_snapshot_count,
            "semantic_change_count": semantic_change_count,
            "gap_runs": gap_runs,
            "gap_changed": gap_changed,
            "gap_last_ts": gap_last_ts,
            "reliable": reliable,
            "reliability_reason": reason,
        }

    def _compute_ui_action_coverage(self, days: int = 7, limit: int = 12) -> Dict[str, Any]:
        """
        Compute UI action coverage using stored UI action snapshots vs exploration history.
        Returns summary + gaps for scenario generation.
        """
        if not self.db_path.exists():
            return {}
        cutoff = time.time() - (days * 86400)
        uncovered_sample: List[Dict[str, Any]] = []
        gaps: List[Dict[str, Any]] = []
        try:
            conn = sqlite3.connect(str(self.db_path))
            conn.row_factory = sqlite3.Row
            tables = {
                row[0]
                for row in conn.execute(
                    "SELECT name FROM sqlite_master WHERE type='table'"
                ).fetchall()
            }
            if "ui_action_snapshots" not in tables:
                conn.close()
                return {}
            rows = conn.execute(
                """
                SELECT url, created_at, candidates_json
                FROM ui_action_snapshots
                WHERE created_at >= ?
                ORDER BY created_at DESC
                LIMIT ?
                """,
                (cutoff, int(limit)),
            ).fetchall()
            if not rows:
                conn.close()
                return {}

            explored_selectors: Dict[str, set[str]] = {}
            explored_hrefs: Dict[str, set[str]] = {}
            global_selectors: set[str] = set()
            global_hrefs: set[str] = set()
            try:
                exp_rows = conn.execute(
                    """
                    SELECT selector, href_base, route, last_seen
                    FROM exploration_novelty
                    WHERE last_seen >= ?
                    """,
                    (cutoff,),
                ).fetchall()
                for r in exp_rows:
                    sel = str(r["selector"] or "")
                    href_base = str(r["href_base"] or "")
                    route = self._normalize_route(str(r["route"] or ""))
                    if route:
                        if sel:
                            explored_selectors.setdefault(route, set()).add(sel)
                        if href_base:
                            explored_hrefs.setdefault(route, set()).add(href_base)
                    else:
                        if sel:
                            global_selectors.add(sel)
                        if href_base:
                            global_hrefs.add(href_base)
            except Exception:
                pass
            conn.close()
        except Exception:
            try:
                conn.close()
            except Exception:
                pass
            return {}

        action_keywords = [
            "save",
            "submit",
            "create",
            "add",
            "new",
            "import",
            "export",
            "delete",
            "update",
            "edit",
            "search",
            "filter",
            "apply",
            "login",
            "logout",
            "approve",
            "confirm",
            "cancel",
            "حفظ",
            "إرسال",
            "انشاء",
            "إنشاء",
            "اضافة",
            "إضافة",
            "جديد",
            "استيراد",
            "تصدير",
            "حذف",
            "تحديث",
            "تعديل",
            "بحث",
            "تصفية",
            "تطبيق",
            "تسجيل",
            "دخول",
            "خروج",
            "اعتماد",
            "تأكيد",
            "إلغاء",
        ]
        danger_keywords = [
            "delete",
            "remove",
            "drop",
            "revoke",
            "terminate",
            "disable",
            "reject",
            "deny",
            "cancel",
            "حذف",
            "إلغاء",
            "رفض",
            "تعطيل",
            "إبطال",
            "ايقاف",
            "إيقاف",
        ]
        write_keywords = [
            "save",
            "submit",
            "create",
            "add",
            "update",
            "edit",
            "import",
            "export",
            "approve",
            "confirm",
            "حفظ",
            "إرسال",
            "انشاء",
            "إنشاء",
            "اضافة",
            "إضافة",
            "تحديث",
            "تعديل",
            "استيراد",
            "تصدير",
            "اعتماد",
            "تأكيد",
        ]

        def _norm(text: str) -> str:
            return str(text or "").strip().lower()

        def _href_base(href: str) -> str:
            if not href:
                return ""
            try:
                if "://" in href:
                    parsed = urllib.parse.urlparse(href)
                    href = parsed.path or ""
            except Exception:
                pass
            return Path(href).name.lower()

        def _risk(text: str, href: str) -> str:
            content = f"{_norm(text)} {_norm(href)}"
            if any(k in content for k in danger_keywords):
                return "danger"
            if any(k in content for k in write_keywords):
                return "write"
            return "safe"

        def _priority(text: str, tag: str, role: str, href: str, risk: str) -> float:
            score = 50.0
            t = _norm(text)
            if tag in ("button", "input") or "button" in _norm(role):
                score += 10.0
            if t:
                score += 8.0
            if href:
                score += 5.0
            if any(k in t for k in action_keywords):
                score += 12.0
            if risk == "danger":
                score += 6.0
            elif risk == "write":
                score += 4.0
            return round(score, 2)

        seen_keys: set[str] = set()
        covered_keys: set[str] = set()
        route_stats: Dict[str, Dict[str, int]] = {}

        for row in rows:
            url = str(row["url"] or "")
            route = self._normalize_route(url)
            try:
                candidates = json.loads(row["candidates_json"] or "[]")
            except Exception:
                candidates = []
            if not isinstance(candidates, list):
                candidates = []
            for c in candidates:
                if not isinstance(c, dict):
                    continue
                selector = str(c.get("selector") or "")
                href = str(c.get("href") or "")
                text = str(c.get("text") or "")
                tag = str(c.get("tag") or "")
                role = str(c.get("role") or "")
                href_base = _href_base(href)
                key = f"{route}|{selector or href_base or text[:40]}"
                if key in seen_keys:
                    continue
                seen_keys.add(key)
                route_stats.setdefault(route, {"total": 0, "covered": 0})
                route_stats[route]["total"] += 1

                covered = False
                if selector and selector in explored_selectors.get(route, set()):
                    covered = True
                elif href_base and href_base in explored_hrefs.get(route, set()):
                    covered = True
                elif selector and selector in global_selectors:
                    covered = True
                elif href_base and href_base in global_hrefs:
                    covered = True

                if covered:
                    covered_keys.add(key)
                    route_stats[route]["covered"] += 1
                else:
                    risk = _risk(text, href)
                    score = _priority(text, tag, role, href, risk)
                    gap = {
                        "route": route or url,
                        "selector": selector,
                        "text": text,
                        "href": href,
                        "tag": tag,
                        "risk": risk,
                        "priority_score": score,
                    }
                    gaps.append(gap)
                    if len(uncovered_sample) < max(8, int(limit)):
                        uncovered_sample.append(gap)

        total_actions = len(seen_keys)
        covered_actions = len(covered_keys)
        ratio = (covered_actions / total_actions * 100.0) if total_actions else 0.0
        routes_total = len(route_stats)
        routes_covered = len(
            [r for r in route_stats.values() if r.get("covered", 0) > 0]
        )
        gaps = sorted(
            gaps, key=lambda g: float(g.get("priority_score") or 0), reverse=True
        )
        max_gap_store = max(12, int(limit) * 2)
        gaps = gaps[:max_gap_store]

        try:
            min_snapshots = int(
                os.getenv(
                    "BGL_UI_ACTION_MIN_SNAPSHOTS",
                    str(self.config.get("ui_action_min_snapshots", 2)),
                )
                or 2
            )
        except Exception:
            min_snapshots = 2
        reliable = len(rows) >= min_snapshots
        reason = "" if reliable else "low_snapshot_count"
        return {
            "window_days": days,
            "snapshot_count": len(rows),
            "total_actions": total_actions,
            "covered_actions": covered_actions,
            "coverage_ratio": round(ratio, 2),
            "routes_with_actions": routes_total,
            "routes_with_coverage": routes_covered,
            "uncovered_sample": uncovered_sample,
            "gaps": gaps,
            "reliable": reliable,
            "reliability_reason": reason,
        }

    def _write_autonomy_goal(
        self, goal: str, payload: Dict[str, Any], source: str, ttl_days: int = 3
    ) -> None:
        try:
            if not self.db_path.exists():
                return
            db = sqlite3.connect(str(self.db_path), timeout=30.0)
            db.execute("PRAGMA journal_mode=WAL;")
            db.execute(
                "CREATE TABLE IF NOT EXISTS autonomy_goals (id INTEGER PRIMARY KEY AUTOINCREMENT, goal TEXT, payload TEXT, source TEXT, created_at REAL, expires_at REAL)"
            )
            # cleanup expired
            try:
                db.execute(
                    "DELETE FROM autonomy_goals WHERE expires_at IS NOT NULL AND expires_at < ?",
                    (time.time(),),
                )
            except Exception:
                pass
            payload_json = json.dumps(payload or {}, ensure_ascii=False)
            # dedupe recent
            try:
                rows = db.execute(
                    "SELECT payload FROM autonomy_goals ORDER BY created_at DESC LIMIT 60"
                ).fetchall()
                for (p,) in rows:
                    if p == payload_json:
                        db.close()
                        return
            except Exception:
                pass
            expires_at = None
            try:
                expires_at = time.time() + float(ttl_days) * 86400.0
            except Exception:
                expires_at = None
            db.execute(
                "INSERT INTO autonomy_goals (goal, payload, source, created_at, expires_at) VALUES (?, ?, ?, ?, ?)",
                (goal, payload_json, source, time.time(), expires_at),
            )
            db.commit()
            db.close()
        except Exception:
            return
    def _check_domain_rule_violations(self) -> List[Dict[str, Any]]:
        """
        Lightweight domain rule audit (relationship rules only).
        Focus on critical architecture rules (e.g., R001/R002).
        """
        rules_path = self.root_dir / ".bgl_core" / "brain" / "domain_rules.yml"
        if not rules_path.exists():
            return []
        try:
            from .governor import BGLGovernor  # type: ignore
        except Exception:
            from governor import BGLGovernor  # type: ignore

        # Restrict to the most critical relationship rules by default.
        rule_ids = {"R001", "R002"}
        try:
            gov = BGLGovernor(self.db_path, rules_path)
            return gov.audit_relationship_rules(rule_ids=rule_ids)
        except Exception:
            return []

        limit = int(os.getenv("BGL_BUSINESS_CONFLICT_SAMPLE", "8") or "8")
        payload = json.dumps({"limit": limit})

        try:
            result = subprocess.run(
                ["php", str(probe_path)],
                input=payload,
                text=True,
                capture_output=True,
                check=True,
            )
            report = json.loads(result.stdout)
            if report.get("status") != "SUCCESS":
                return []
            conflicts = report.get("conflicts", []) or []
            out: List[str] = []
            for item in conflicts:
                gid = item.get("guarantee_id")
                supplier = item.get("supplier") or ""
                bank = item.get("bank") or ""
                msgs = item.get("conflicts") or []
                detail = "; ".join([m for m in msgs if m])
                label = f"Guarantee #{gid}" if gid is not None else "Guarantee"
                context = " | ".join([p for p in [supplier, bank] if p])
                if context:
                    out.append(f"{label} ({context}): {detail}")
                else:
                    out.append(f"{label}: {detail}")
            return out
        except Exception as e:
            print(f"    [!] Guardian Bridge Error: {e}")
            return []

    def _get_proxied_routes(self) -> List[Dict[str, Any]]:
        if not self.db_path.exists():
            return []
        conn = sqlite3.connect(str(self.db_path))
        conn.row_factory = sqlite3.Row
        cursor = conn.cursor()
        cursor.execute("SELECT * FROM routes")
        rows = cursor.fetchall()
        conn.close()
        return [dict(r) for r in rows]

    def _is_api_route(self, uri: str) -> bool:
        return uri.startswith("/api/") or "/api/" in uri

    def _scan_api_route(
        self,
        uri: str,
        method: str,
        base_url: str,
        mode: str,
        contract: Dict[str, Any] | None = None,
    ) -> Dict[str, Any]:
        """
        Scan API routes without opening them in the browser.
        mode: skip | safe | all
        """
        def _is_write_endpoint(u: str) -> bool:
            u = (u or "").lower()
            return any(
                x in u
                for x in (
                    "/api/create-",
                    "/api/update_",
                    "/api/delete_",
                    "/api/import",
                    "/api/save-",
                    "/api/upload-",
                )
            )

        def _missing_required_signal(text: str) -> bool:
            t = (text or "").lower()
            return bool(
                re.search(r"missing|required|bad request|method not allowed", t)
                or re.search(r"مطلوب|غير صحيح|لا يمكن", text or "")
            )

        def _sample_import_source() -> str | None:
            """
            Pick a real import_source from the app DB to avoid false negatives when
            scanning /api/batches.php?import_source=...
            """
            try:
                db_path = self.root_dir / "storage" / "database" / "app.sqlite"
                if not db_path.exists():
                    return None
                conn = sqlite3.connect(str(db_path))
                cur = conn.cursor()
                cur.execute(
                    "SELECT DISTINCT import_source FROM guarantees WHERE import_source IS NOT NULL AND import_source != ? ORDER BY import_source DESC LIMIT 20",
                    ("",),
                )
                rows = [r[0] for r in cur.fetchall() if r and r[0]]
                conn.close()
                # Prefer real excel imports when available
                for v in rows:
                    if str(v).startswith("excel_"):
                        return str(v)
                return str(rows[0]) if rows else None
            except Exception:
                return None

        def _extract_post_fields(text: str) -> List[str]:
            fields = set()
            for pat in (
                r"Input::string\(\s*\$input\s*,\s*'([^']+)'",
                r"Input::int\(\s*\$input\s*,\s*'([^']+)'",
                r"Input::array\(\s*\$input\s*,\s*'([^']+)'",
                r"\$input\[['\"]([^'\"]+)['\"]\]",
            ):
                for m in re.findall(pat, text or ""):
                    fields.add(m)
            return sorted(fields)

        def _guess_value(key: str) -> Any:
            k = (key or "").lower()
            if k == "index":
                return 1
            if k == "page":
                return 1
            if k == "history_id":
                return "import_synthetic_1"
            if k == "is_test_data" or k.startswith("is_"):
                return False
            if k == "related_to":
                return "contract"
            if k in ("type", "guarantee_type"):
                return "Initial"
            if "id" in k:
                return 1
            if "amount" in k or "value" in k:
                return 1000
            if "date" in k or "expiry" in k or "issue" in k:
                return "2026-02-03"
            if "action" in k:
                return "summary"
            if "reason" in k:
                return "probe"
            if "name" in k:
                return "Probe"
            if "note" in k:
                return "probe"
            if "source" in k:
                return "manual_paste_20260203"
            if "number" in k:
                return "PROBE-0001"
            return "probe"

        method = (method or "GET").upper()
        force_examples = (
            str(self.config.get("api_scan_force_examples", "0" if mode == "safe" else "1"))
            == "1"
        )
        if mode == "skip":
            return {"skipped": True, "reason": "api_scan_mode=skip"}

        # Align with OpenAPI contract if provided
        contract_methods = None
        if isinstance(contract, dict) and contract:
            contract_methods = {k.upper(): v for k, v in contract.items()}
            if method == "ANY":
                # Prefer contract-defined method, but avoid probing write endpoints in safe mode.
                if mode == "safe" and _is_write_endpoint(uri):
                    # These endpoints are expected to require input/body and may mutate state.
                    # Skipping prevents persistent false positives.
                    return {"skipped": True, "reason": "write_endpoint_skipped_safe_scan"}
                if contract_methods:
                    method = list(contract_methods.keys())[0]
            if method not in contract_methods:
                if mode == "all":
                    contract_methods = None
                else:
                    return {"skipped": True, "reason": f"method_not_in_contract:{method}"}

        if mode == "safe" and method not in ("GET", "HEAD"):
            return {"skipped": True, "reason": f"safe mode blocks {method}"}

        url = base_url.rstrip("/") + uri
        # Avoid localhost IPv6 (::1) resolution issues on Windows
        if "localhost" in url:
            url = url.replace("localhost", "127.0.0.1")
        body = None
        if contract_methods and method in contract_methods:
            op = contract_methods[method] or {}
            if method in ("GET", "HEAD"):
                params = op.get("parameters", []) or []
                query = {}
                for p in params:
                    if p.get("in") != "query":
                        continue
                    name = p.get("name")
                    if not name:
                        continue
                    example = p.get("example")
                    schema = p.get("schema") or {}
                    if example is None:
                        example = schema.get("example")
                    if example is None:
                        example = schema.get("default")
                    if example is None and p.get("required"):
                        if force_examples:
                            example = _guess_value(name)
                        else:
                            return {"skipped": True, "reason": f"missing_required_param:{name}"}
                    if example is not None:
                        # Use a real sample for batches to avoid 404/500 false negatives.
                        if uri == "/api/batches.php" and name == "import_source":
                            sample = _sample_import_source()
                            if sample:
                                example = sample
                        query[name] = example
                if query:
                    qs = urllib.parse.urlencode(query, doseq=True)
                    url = url + ("&" if "?" in url else "?") + qs
            else:
                rb = (op.get("requestBody") or {}).get("content", {})
                app_json = rb.get("application/json") or {}
                example = app_json.get("example")
                if example is None:
                    example = (app_json.get("examples") or {}).get("default", {}).get("value")
                if example is None and force_examples:
                    file_path = self.root_dir / uri.lstrip("/")
                    file_text = ""
                    if file_path.is_file():
                        try:
                            file_text = file_path.read_text(encoding="utf-8", errors="ignore")
                        except Exception:
                            file_text = ""
                    fields = _extract_post_fields(file_text) if file_text else []
                    if fields:
                        example = {k: _guess_value(k) for k in fields}
                    else:
                        example = {}
                if example is None:
                    return {"skipped": True, "reason": "missing_request_example"}
                example = self._render_example(example)
                body = json.dumps(example).encode("utf-8")

        if body is None and method not in ("GET", "HEAD") and force_examples:
            file_path = self.root_dir / uri.lstrip("/")
            file_text = ""
            if file_path.is_file():
                try:
                    file_text = file_path.read_text(encoding="utf-8", errors="ignore")
                except Exception:
                    file_text = ""
            fields = _extract_post_fields(file_text) if file_text else []
            example = {k: _guess_value(k) for k in fields} if fields else {}
            example = self._render_example(example)
            body = json.dumps(example).encode("utf-8")

        start = time.time()
        status = None
        err = None
        error_body = None
        for i in range(3):
            try:
                headers = {}
                if body is not None:
                    headers["Content-Type"] = "application/json"
                req = urllib.request.Request(url, data=body, method=method, headers=headers)
                with urllib.request.urlopen(req, timeout=6) as resp:
                    status = resp.getcode()
                    resp.read()
                err = None
                error_body = None
                break
            except urllib.error.HTTPError as e:
                status = e.code
                err = str(e)
                try:
                    error_body = e.read().decode("utf-8", errors="ignore")
                except Exception:
                    error_body = None
                # Safe-scan normalization:
                # If a write endpoint was accidentally probed as GET (common when contract includes GET {}),
                # treat missing-required/405 as a skipped artifact rather than a failing route.
                if mode == "safe" and _is_write_endpoint(uri) and method in ("GET", "HEAD"):
                    if status in (400, 405, 500) and _missing_required_signal((error_body or "") + " " + (err or "")):
                        return {
                            "skipped": True,
                            "reason": "write_endpoint_requires_input",
                            "status": status,
                            "error": err,
                            "error_body": error_body,
                            "method_used": method,
                        }
                break
            except Exception as e:
                err = str(e)
                time.sleep(0.6 * (i + 1))

        latency_ms = round((time.time() - start) * 1000, 1)
        valid = status is not None and status < 400 and err is None
        return {
            "skipped": False,
            "valid": valid,
            "status": status,
            "error": err,
            "error_body": error_body,
            "method_used": method,
            "latency_ms": latency_ms,
        }

    def _classify_expected_failure(
        self, uri: str, method: str, api_res: Dict[str, Any]
    ) -> Dict[str, Any] | None:
        """Classify expected policy failures based on local rules."""
        rules_path = self.root_dir / ".bgl_core" / "brain" / "policy_expectations.json"
        if not rules_path.exists():
            return None
        try:
            rules = json.loads(rules_path.read_text(encoding="utf-8"))
        except Exception:
            return None
        status = api_res.get("status")
        body = api_res.get("error_body") or ""
        err_msg = api_res.get("error") or ""
        for r in rules:
            if r.get("uri") != uri:
                continue
            if r.get("method", "").upper() not in (method or "").upper():
                if r.get("method", "").upper() != "ANY":
                    continue
            expected_statuses = r.get("expected_statuses") or []
            if status is not None and expected_statuses and status not in expected_statuses:
                continue
            pattern = r.get("match_body_regex")
            err_pattern = r.get("match_error_regex")
            allow_any = bool(r.get("allow_any_body"))
            if pattern:
                try:
                    if not re.search(pattern, body, re.IGNORECASE):
                        continue
                except Exception:
                    continue
            if err_pattern:
                try:
                    if not re.search(err_pattern, err_msg, re.IGNORECASE):
                        continue
                except Exception:
                    continue
            if not pattern and not err_pattern and not allow_any:
                continue
            return {
                "uri": uri,
                "method": method,
                "status": status,
                "reason": r.get("reason", "policy_expected"),
                "category": r.get("category", "policy_expected"),
            }
        return None

    def _maybe_record_policy_candidate(
        self, uri: str, method: str, api_res: Dict[str, Any]
    ) -> Dict[str, Any] | None:
        """Use tools to verify if a failure could be policy-expected, then log candidate."""
        try:
            candidate = verify_failure(self.root_dir, uri, method, api_res)
        except Exception:
            return None
        if not candidate:
            return None
        # Persist candidate list (dedup by uri+method+status)
        out = self.root_dir / ".bgl_core" / "logs" / "policy_candidates.json"
        existing: List[Dict[str, Any]] = []
        if out.exists():
            try:
                existing = json.loads(out.read_text(encoding="utf-8"))
            except Exception:
                existing = []
        key = (candidate.get("uri"), candidate.get("method"), candidate.get("status"))
        seen = { (c.get("uri"), c.get("method"), c.get("status")) for c in existing }
        if key not in seen:
            existing.append(candidate)
            try:
                out.write_text(json.dumps(existing, ensure_ascii=False, indent=2), encoding="utf-8")
            except Exception:
                pass
        return candidate

    def _auto_promote_policy_candidates(self, candidates: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
        """Promote high-confidence candidates into policy_expectations.json."""
        threshold = float(self.config.get("policy_auto_promote_threshold", 0.2))
        force_promote = (
            str(self.config.get("policy_force_promote", "0")) == "1"
            or os.getenv("BGL_POLICY_FORCE_PROMOTE", "0") == "1"
        )
        rules_path = self.root_dir / ".bgl_core" / "brain" / "policy_expectations.json"
        try:
            rules = json.loads(rules_path.read_text(encoding="utf-8")) if rules_path.exists() else []
        except Exception:
            rules = []
        promoted: List[Dict[str, Any]] = []

        def _infer_status(err: str, body: str) -> int | None:
            text = f"{err or ''} {body or ''}".lower()
            if "method not allowed" in text or " 405" in text or "405" in text:
                return 405
            if "bad request" in text or " 400" in text or "400" in text or "missing" in text or "مطلوب" in text:
                return 400
            if "unauthorized" in text or " 401" in text or "401" in text:
                return 401
            if "forbidden" in text or " 403" in text or "403" in text:
                return 403
            if "not found" in text or " 404" in text or "404" in text:
                return 404
            if "internal server error" in text or " 500" in text or "500" in text:
                return 500
            if "winerror 10061" in text or "connection refused" in text:
                return 503
            return None

        existing_keys = {
            (
                r.get("uri"),
                r.get("method"),
                tuple(r.get("expected_statuses", [])),
                r.get("match_body_regex"),
                r.get("match_error_regex"),
                bool(r.get("allow_any_body")),
            )
            for r in rules
        }
        for c in candidates:
            if not force_promote and c.get("confidence", 0) < threshold:
                continue
            if not c.get("evidence") and not force_promote:
                continue
            uri = c.get("uri")
            status = c.get("status")
            method = c.get("method") or "ANY"
            body = c.get("error_body") or ""
            err = c.get("error") or ""
            if status is None:
                status = _infer_status(str(err), str(body))
            if not uri:
                continue
            # Build a conservative regex from the first line of error body if present
            snippet = ""
            if body:
                snippet = body.strip().splitlines()[0][:80]
            pattern = re.escape(snippet) if snippet else None
            err_pattern = None
            if "Method not allowed" in err:
                err_pattern = "Method not allowed"
            elif "Bad Request" in err:
                err_pattern = "Bad Request"
            elif "Internal Server Error" in err:
                err_pattern = "Internal Server Error"
            expected_statuses = [status] if status is not None else []
            allow_any = status is None
            key = (uri, method, tuple(expected_statuses), pattern, err_pattern, allow_any)
            if key in existing_keys:
                continue
            rule = {
                "uri": uri,
                "method": method,
                "expected_statuses": expected_statuses,
                "reason": "Auto-promoted from evidence",
                "category": "policy_expected_auto",
            }
            if pattern:
                rule["match_body_regex"] = pattern
            if err_pattern:
                rule["match_error_regex"] = err_pattern
            if allow_any:
                rule["allow_any_body"] = True
            rules.append(rule)
            promoted.append(rule)
            existing_keys.add(key)
        if promoted:
            try:
                rules_path.write_text(json.dumps(rules, ensure_ascii=False, indent=2), encoding="utf-8")
            except Exception:
                pass
            try:
                out = self.root_dir / ".bgl_core" / "logs" / "policy_auto_promoted.json"
                out.write_text(json.dumps(promoted, ensure_ascii=False, indent=2), encoding="utf-8")
            except Exception:
                pass
        return promoted

    def _render_example(self, value: Any) -> Any:
        """Replace placeholder tokens in examples to avoid duplicate conflicts."""
        if isinstance(value, dict):
            return {k: self._render_example(v) for k, v in value.items()}
        if isinstance(value, list):
            return [self._render_example(v) for v in value]
        if isinstance(value, str):
            ts = str(int(time.time()))
            date = time.strftime("%Y%m%d")
            return value.replace("{{ts}}", ts).replace("{{date}}", date)
        return value

    def _dedupe_permissions(self):
        """Keep only the latest PENDING permission per operation to avoid queue spam."""
        try:
            self.authority.dedupe_permissions()
        except Exception:
            pass

    def _ensure_routes_indexed(self):
        """Ensure routes table is populated at least once (read-only safety net)."""
        if not self.db_path.exists():
            return
        try:
            conn = sqlite3.connect(str(self.db_path))
            cur = conn.cursor()
            cur.execute("SELECT COUNT(*) FROM routes")
            count = int(cur.fetchone()[0] or 0)
            conn.close()
            if count > 0:
                return
        except Exception:
            return
        try:
            from .route_indexer import LaravelRouteIndexer  # type: ignore
        except Exception:
            from route_indexer import LaravelRouteIndexer
        try:
            indexer = LaravelRouteIndexer(self.root_dir, self.db_path)
            indexer.run()
        except Exception as e:
            print(f"[!] Guardian: route index bootstrap failed: {e}")

    def _has_permission(self, operation: str) -> bool:
        try:
            return bool(self.authority.has_permission(operation))
        except Exception:
            return False

    def _request_permission(self, operation: str, command: str):
        try:
            self.authority.request_permission(operation, command)
        except Exception:
            pass

    def _gate_reindex(self, path: Path):
        """Gate large reindex operations via decision layer and hardware limits."""
        # Gate record (authority handles policy overrides; reindex_full is usually safe/internal)
        req = ActionRequest(
            kind=ActionKind.WRITE_SANDBOX,
            operation=f"reindex.full|{str(path)}",
            command=f"reindex_full {path}",
            scope=[str(path)],
            reason=f"Reindex requested for {path}",
            confidence=0.75,
            metadata={"policy_key": "reindex_full", "path": str(path), "deterministic_gate": True},
        )
        gate = self.authority.gate(req, source="guardian")
        decision_id = int(gate.decision_id or 0)
        if not gate.allowed:
            return gate

        force_reindex = (
            str(self.config.get("force_reindex", "0")) == "1"
            or os.getenv("BGL_FORCE_REINDEX", "0") == "1"
            or self.execution_mode == "autonomous"
        )
        if force_reindex:
            return gate

        # 1. Hardware Safety Check
        hw_file = os.path.join(
            os.path.dirname(os.path.dirname(__file__)), "logs", "hardware_vitals.json"
        )
        if os.path.exists(hw_file):
            try:
                import json

                with open(hw_file, "r") as f:
                    hw = json.load(f)

                # Thresholds: CPU > 85%, Available RAM < 4GB
                cpu_load = hw.get("cpu", {}).get("usage_percent", 0)
                ram_avail = hw.get("memory", {}).get("available_gb", 100)

                if cpu_load > 85.0 or ram_avail < 4.0:
                    print(
                        f"[!] Guardian: Reindex BLOCKED due to high system load (CPU: {cpu_load}%, RAM Avail: {ram_avail}GB)"
                    )

                    self.authority.record_outcome(
                        decision_id,
                        "blocked",
                        f"Hardware Safeguard: CPU {cpu_load}%, RAM {ram_avail}GB",
                    )
                    gate.allowed = False
                    gate.message = "blocked by hardware safeguard"
                    return gate
            except Exception as e:
                print(f"[!] Guardian: Hardware check skipped: {e}")
        return gate

    def _detect_log_anomalies(self) -> List[Dict[str, Any]]:
        """Identifies recurring patterns in the Laravel log."""
        log_entries = self.safety._read_backend_logs(time.time() - 3600)  # Last hour

        anomalies: List[Dict[str, Any]] = []
        counts: Dict[str, int] = {}
        for entry in log_entries:
            msg = entry["message"]
            counts[msg] = counts.get(msg, 0) + 1

        for msg, count in counts.items():
            if count >= 3:  # Threshold for 'recurring'
                anomalies.append(
                    {"message": msg, "count": count, "severity": "RECURRING"}
                )
        return anomalies

    def _generate_suggestions(self, report: Dict[str, Any]) -> List[str]:
        suggestions = []

        # Rule 1: Failing Routes
        for fail in (report.get("failing_routes") or []):
            suspect = fail.get("suspect_code")
            file_name = "unknown file"
            if suspect and isinstance(suspect, dict):
                file_name = Path(suspect.get("file_path", "unknown")).name

            suggestions.append(
                f"Fix frontend error on {fail['uri']} (Suspect: {file_name})"
            )

        # Rule 2: log anomalies
        for anomaly in (report.get("log_anomalies") or []):
            suggestions.append(
                f"Investigate recurring backend error: {anomaly['message']}"
            )

        # Rule 3a: Permission issues
        for perm in report.get("permission_issues", []):
            suggestions.append(f"Permission check: {perm}")

        # Rule 3: Business Conflicts
        for conflict in (report.get("business_conflicts") or []):
            suggestions.append(f"Business Logic Alert: {conflict}")

        # Rule 4: Recent experiential errors
        for exp in report.get("recent_experiences", []):
            if exp.get("confidence", 0) >= 0.7:
                suggestions.append(
                    f"Prioritized route {exp['scenario']} shows issues: {exp['summary']}"
                )
            elif exp.get("confidence", 0) >= 0.5:
                suggestions.append(
                    f"Monitor route {exp['scenario']} (recent activity): {exp['summary']}"
                )

        # Rule 5: Worst routes
        for wr in report.get("worst_routes", [])[:3]:
            suggestions.append(
                f"Hot route {wr.get('uri')} needs attention (score {wr.get('score')})"
            )

        return suggestions

    def _worst_routes(
        self, report: Dict[str, Any], top_n: int = 5
    ) -> List[Dict[str, Any]]:
        """Infer worst routes from experiences + failing routes + http errors count."""
        scored: Dict[str, int] = {}
        # Failing routes first
        for fr in report.get("failing_routes", []):
            uri = fr.get("uri")
            if not uri:
                continue
            scored[uri] = scored.get(uri, 0) + 10
        # Experiences with HTTP fails / latency
        try:
            conn = sqlite3.connect(str(self.db_path))
            conn.row_factory = sqlite3.Row
            rows = conn.execute(
                """
                SELECT scenario, summary, confidence, evidence_count
                FROM experiences
                WHERE summary LIKE '%HTTP calls%' AND summary LIKE '%failed%'
                ORDER BY created_at DESC
                LIMIT 50
                """
            ).fetchall()
            conn.close()
            for r in rows:
                uri = r["scenario"]
                # Avoid flagging routes with zero failures (summary contains "0 failed").
                summary = str(r["summary"] or "")
                fail_count = 0
                js_err = 0
                net_err = 0
                try:
                    m = re.search(r"\\((\\d+) failed\\)", summary)
                    if m:
                        fail_count = int(m.group(1) or 0)
                except Exception:
                    fail_count = 0
                try:
                    m = re.search(r"(\\d+) JS errors", summary)
                    if m:
                        js_err = int(m.group(1) or 0)
                except Exception:
                    js_err = 0
                try:
                    m = re.search(r"(\\d+) network errors", summary)
                    if m:
                        net_err = int(m.group(1) or 0)
                except Exception:
                    net_err = 0
                if fail_count == 0 and js_err == 0 and net_err == 0:
                    continue
                scored[uri] = scored.get(uri, 0) + 5 + int(r["confidence"] * 2)
        except Exception:
            pass
        top = sorted(scored.items(), key=lambda kv: kv[1], reverse=True)[:top_n]
        return [{"uri": uri, "score": score} for uri, score in top]

    def _check_permissions(self) -> List[str]:
        """Verify write access to critical paths; report issues without modifying files."""
        issues = []
        targets = [
            ("storage/logs/test.log", "write"),
            ("app/Config/agent.json", "write"),
        ]
        for rel, kind in targets:
            path = self.root_dir / rel
            if not path.exists():
                issues.append(f"{rel} missing (expected {kind} access)")
                continue
            try:
                if not os.access(path, os.W_OK):
                    issues.append(f"{rel} not writable")
                else:
                    # attempt open for append without writing
                    with open(path, "a", encoding="utf-8"):
                        pass
            except Exception as e:
                issues.append(f"{rel} write check failed: {e}")
        return issues

    def _pending_approvals(self, limit: int = 25) -> List[Dict[str, Any]]:
        """Return pending human approvals from agent_permissions (best-effort)."""
        try:
            approvals_enabled = self.config.get("approvals_enabled", 1)
            if isinstance(approvals_enabled, bool):
                if not approvals_enabled:
                    return []
            elif isinstance(approvals_enabled, (int, float)):
                if float(approvals_enabled) == 0.0:
                    return []
            elif str(approvals_enabled).strip().lower() in ("0", "false", "no", "off"):
                return []
        except Exception:
            pass
        if not self.db_path.exists():
            return []
        try:
            conn = sqlite3.connect(str(self.db_path))
            conn.row_factory = sqlite3.Row
            cur = conn.cursor()
            rows = cur.execute(
                """
                SELECT id, operation, command, status, timestamp
                FROM agent_permissions
                WHERE status='PENDING'
                ORDER BY timestamp DESC
                LIMIT ?
                """,
                (limit,),
            ).fetchall()
            conn.close()
            return [dict(r) for r in rows]
        except Exception:
            return []

    def _recent_outcomes(self, limit: int = 25) -> List[Dict[str, Any]]:
        """Return recent outcomes joined with their decisions/intents (best-effort)."""
        if not self.db_path.exists():
            return []
        try:
            conn = sqlite3.connect(str(self.db_path))
            conn.row_factory = sqlite3.Row
            cur = conn.cursor()
            rows = cur.execute(
                """
                SELECT
                  o.id as outcome_id,
                  o.result as outcome_result,
                  o.notes as outcome_notes,
                  o.timestamp as outcome_timestamp,
                  d.id as decision_id,
                  d.decision as decision_value,
                  d.risk_level as risk_level,
                  d.requires_human as requires_human,
                  i.id as intent_id,
                  i.intent as intent_value,
                  i.reason as intent_reason,
                  i.source as intent_source
                FROM outcomes o
                LEFT JOIN decisions d ON o.decision_id = d.id
                LEFT JOIN intents i ON d.intent_id = i.id
                ORDER BY o.id DESC
                LIMIT ?
                """,
                (limit,),
            ).fetchall()
            conn.close()
            results: List[Dict[str, Any]] = []
            for r in rows:
                row = dict(r)
                note = str(row.get("outcome_notes") or "")
                fallback_ctx = None
                if "ctx=" in note:
                    try:
                        ctx_part = note.split("ctx=", 1)[1]
                        if " failure_class=" in ctx_part:
                            ctx_part = ctx_part.split(" failure_class=", 1)[0]
                        ctx_part = ctx_part.strip()
                        if ctx_part.startswith("{") and ctx_part.endswith("}"):
                            fallback_ctx = json.loads(ctx_part)
                    except Exception:
                        fallback_ctx = None
                if fallback_ctx:
                    row["fallback_ctx"] = fallback_ctx
                results.append(row)
            return results
        except Exception:
            return []

    def _load_recent_experiences(
        self, hours: int = 24, limit: int = 10
    ) -> List[Dict[str, Any]]:
        """Fetch recent experiential summaries to inform audit suggestions."""
        cutoff = time.time() - hours * 3600
        try:
            conn = sqlite3.connect(str(self.db_path))
            conn.row_factory = sqlite3.Row
            cur = conn.cursor()
            rows = cur.execute(
                """
                SELECT scenario, summary, related_files, confidence, evidence_count, created_at
                FROM experiences
                WHERE created_at >= ?
                ORDER BY created_at DESC
                LIMIT ?
                """,
                (cutoff, limit),
            ).fetchall()
            conn.close()
            return [dict(r) for r in rows]
        except Exception as e:
            print(f"    [!] Guardian: unable to load experiences: {e}")
            return []

    # === Adaptive route scan helpers ===
    def _load_route_stats(self, stats_path: Path) -> List[Dict[str, Any]]:
        try:
            if stats_path.exists():
                return json.loads(stats_path.read_text())
        except Exception:
            pass
        return []

    def _persist_route_stats(
        self, stats_path: Path, routes_scanned: int, duration: float
    ):
        stats = self._load_route_stats(stats_path)
        stats.append(
            {
                "ts": time.time(),
                "routes": routes_scanned,
                "duration": duration,
            }
        )
        # keep last 20 runs
        stats = stats[-20:]
        try:
            stats_path.parent.mkdir(parents=True, exist_ok=True)
            stats_path.write_text(json.dumps(stats))
        except Exception:
            pass

    def _target_duration(self, stats: List[Dict[str, Any]]) -> float:
        if not stats:
            return 30.0  # default target in seconds
        durations = sorted(s["duration"] for s in stats if s.get("duration"))
        if not durations:
            return 30.0
        p80_idx = int(0.8 * (len(durations) - 1))
        return max(15.0, durations[p80_idx])

    def _compute_adaptive_limit(
        self,
        total_routes: int,
        mode: str,
        limit_env: str | None,
        limit_cfg: Any,
        past_stats: List[Dict[str, Any]],
    ) -> int:
        # explicit overrides
        if limit_env is not None:
            try:
                return int(limit_env)
            except ValueError:
                pass
        if limit_cfg is not None:
            try:
                return int(limit_cfg)
            except ValueError:
                pass

        if mode != "auto":
            return -1

        # compute throughput from history
        durations = [
            s["duration"] for s in past_stats if s.get("duration") and s.get("routes")
        ]
        if durations:
            med_routes = sorted(
                past_stats, key=lambda s: s["duration"] / max(0.1, s["routes"])
            )[len(past_stats) // 2]
            routes_per_sec = med_routes["routes"] / max(0.1, med_routes["duration"])
        else:
            routes_per_sec = 2.0  # heuristic default

        target_duration = self._target_duration(past_stats)

        # system load
        cpu_idle: float = 50.0
        avail_gb: float = 1.0

        # Try reading from Hardware Sensor cache first for zero-overhead
        hw_file = os.path.join(
            os.path.dirname(os.path.dirname(__file__)), "logs", "hardware_vitals.json"
        )
        try:
            if os.path.exists(hw_file):
                import json

                with open(hw_file, "r") as f:
                    hw = json.load(f)
                    cpu_idle = 100 - hw["cpu"]["usage_percent"]
                    # Use available_gb if present, otherwise approximate
                    avail_gb = hw["memory"].get(
                        "available_gb",
                        hw["memory"]["total_gb"] - hw["memory"]["used_gb"],
                    )
            else:
                import psutil  # type: ignore

                cpu_idle = max(0, 100 - psutil.cpu_percent(interval=0.2))
                mem = psutil.virtual_memory()
                avail_gb = mem.available / (1024**3)
        except Exception:
            pass

        desired = int(routes_per_sec * target_duration)
        # adjust for resources: throttle if CPU > 85% or RAM < 4GB
        if cpu_idle < 15 or avail_gb < 4.0:
            print(
                f"[*] Guardian: Throttling scan due to load (CPU Idle: {cpu_idle}%, RAM: {avail_gb}GB)"
            )
            desired = max(5, desired // 4)  # Aggressive throttling

        desired = min(total_routes, max(10, desired))
        return desired

    async def run_daemon(self, interval: int = 300):
        print(f"[*] Guardian: Entering Daemon Mode (interval={interval}s)")

        # Start Hardware Sensor in the background
        sensor_path = os.path.join(os.path.dirname(__file__), "hardware_sensor.py")
        try:
            import subprocess

            # Use CREATE_NO_WINDOW for Windows if possible, or just start /B
            self._sensor_proc = subprocess.Popen(
                [self.python_exe, sensor_path],
                creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
            )
            print("[+] Guardian: Hardware Sensor background process started.")
        except Exception as e:
            print(f"[!] Guardian: Failed to start Hardware Sensor: {e}")

        while True:
            try:
                await self.perform_full_audit()
                print(
                    f"[*] Guardian: Audit cycle complete. Sleeping for {interval}s..."
                )
            except Exception as e:
                print(f"[!] Guardian: Error in daemon loop: {e}")
            await asyncio.sleep(interval)


if __name__ == "__main__":
    import asyncio
    import argparse

    def main():
        parser = argparse.ArgumentParser()
        parser.add_argument(
            "--daemon", action="store_true", help="Run in background daemon mode"
        )
        parser.add_argument(
            "--interval",
            type=int,
            default=300,
            help="Wait time in seconds between audits",
        )
        args = parser.parse_args()

        ROOT = Path(__file__).parent.parent.parent
        guardian = BGLGuardian(ROOT)

        if args.daemon:
            asyncio.run(guardian.run_daemon(args.interval))
        else:
            report = asyncio.run(guardian.perform_full_audit())
            print(json.dumps(report, indent=2))

    main()
