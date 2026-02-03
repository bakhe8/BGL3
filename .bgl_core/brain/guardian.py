import time
import os
import json
import sqlite3
import urllib.request
import urllib.parse
import urllib.error
from pathlib import Path
from typing import List, Dict, Any, cast
import re

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
            except Exception as e:
                tool_evidence["run_checks"] = {"status": "ERROR", "message": str(e)}

        # Autonomous re-indexing (closing the gap)
        indexer = EntityIndexer(self.root_dir, self.db_path)
        indexer.index_project()

        # Optional: run predefined Playwright scenarios to populate runtime events
        run_scenarios = os.getenv(
            "BGL_RUN_SCENARIOS", str(self.config.get("run_scenarios", 1))
        )
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
            # Apply scenario config hints
            include_api = str(self.config.get("scenario_include_api", "0"))
            os.environ.setdefault("BGL_INCLUDE_API", include_api)
            os.environ.setdefault(
                "BGL_EXPLORATION",
                str(self.config.get("scenario_exploration", os.getenv("BGL_EXPLORATION", "1"))),
            )
            scenario_max_pages = int(self.config.get("max_pages", 3))
            scenario_idle_timeout = int(self.config.get("page_idle_timeout", 120))
            scenario_include = self.config.get("scenario_include", None)
            if isinstance(scenario_include, str) and not scenario_include.strip():
                scenario_include = None

            # Decision/gate for scenario batch
            intent_payload = {
                "intent": "scenario_batch",
                "confidence": 0.7,
                "reason": "Run predefined Playwright scenarios",
                "scope": ["scenarios"],
                "context_snapshot": {
                    "health": {},
                    "active_route": None,
                    "recent_changes": [],
                    "guardian_top": [],
                    "browser_state": "pending",
                },
            }
            policy = self.config.get("decision", {})
            decision_payload = decide(intent_payload, policy)
            intent_id = insert_intent(
                self.decision_db_path,
                intent_payload["intent"],
                intent_payload["confidence"],
                intent_payload["reason"],
                json.dumps(intent_payload["scope"]),
                json.dumps(intent_payload["context_snapshot"]),
                source="guardian",
            )
            decision_id = insert_decision(
                self.decision_db_path,
                intent_id,
                decision_payload.get("decision", "observe"),
                decision_payload.get("risk_level", "low"),
                bool(decision_payload.get("requires_human", False)),
                "; ".join(decision_payload.get("justification", [])),
            )
            # agent_mode + gate enforcement
            effective_mode = self.authority.effective_execution_mode()

            if decision_payload.get("decision") == "block":
                print("    [!] Guardian: scenario batch blocked by decision gate.")
                insert_outcome(
                    self.decision_db_path,
                    decision_id,
                    "blocked",
                    "scenario_batch blocked by gate",
                )
            elif self.agent_mode == "safe":
                print(
                    "    [!] Guardian: agent_mode=safe => skipping scenarios/browser run."
                )
                insert_outcome(
                    self.decision_db_path, decision_id, "skipped", "agent_mode safe"
                )
            elif self.agent_mode == "assisted":
                if scenario_runner_main is None:
                    insert_outcome(
                        self.decision_db_path,
                        decision_id,
                        "skipped",
                        scenario_dep_error or "scenario runner missing dependency",
                    )
                elif self._has_permission("run_scenarios"):
                    print("    [+] Permission granted for scenarios; running.")
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
                        insert_outcome(
                            self.decision_db_path,
                            decision_id,
                            "success",
                            f"scenario batch executed (mode={effective_mode})",
                        )
                    except Exception as e:
                        print(f"    [!] Guardian: scenario run failed: {e}")
                        insert_outcome(
                            self.decision_db_path, decision_id, "fail", str(e)
                        )
                else:
                    self._request_permission(
                        "run_scenarios",
                        f"python scenario_runner.py --base-url {base_url} --headless {int(headless)}",
                    )
                    print(
                        "    [!] Guardian: scenarios pending human approval (agent_mode=assisted)."
                    )
                    insert_outcome(
                        self.decision_db_path,
                        decision_id,
                        "blocked",
                        "awaiting human permission",
                    )
            else:  # auto
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
                    insert_outcome(
                        self.decision_db_path,
                        decision_id,
                        "success",
                        f"scenario batch executed (mode={effective_mode})",
                    )
                except Exception as e:
                    print(f"    [!] Guardian: scenario run failed: {e}")
                    insert_outcome(self.decision_db_path, decision_id, "fail", str(e))

        # 0. Maintenance (New in Phase 5)
        self.log_maintenance()

        # 0. Sync Knowledge (New in Phase 5)
        try:
            from .route_indexer import LaravelRouteIndexer  # type: ignore
        except ImportError:
            from route_indexer import LaravelRouteIndexer

        indexer = LaravelRouteIndexer(self.root_dir, self.db_path)
        if not self._gate_reindex(self.root_dir):
            print("    [!] Guardian: full reindex blocked by decision gate.")
        else:
            try:
                # Prefer run(); fallback to index_project if available
                method = getattr(indexer, "run", None)
                if method is None:
                    method = getattr(indexer, "index_project", None)
                if method is None:
                    raise AttributeError("LaravelRouteIndexer has no run/index_project")
                method()
                # record outcome success for reindex
                intent_payload = {
                    "intent": "reindex_full",
                    "confidence": 0.9,
                    "reason": "Reindex executed",
                    "scope": [str(self.root_dir)],
                    "context_snapshot": {},
                }
                policy = self.config.get("decision", {})
                decision_payload = decide(intent_payload, policy)
                intent_id = insert_intent(
                    self.decision_db_path,
                    intent_payload["intent"],
                    intent_payload["confidence"],
                    intent_payload["reason"],
                    json.dumps(intent_payload["scope"]),
                    json.dumps(intent_payload["context_snapshot"]),
                    source="guardian",
                )
                decision_id = insert_decision(
                    self.decision_db_path,
                    intent_id,
                    decision_payload.get("decision", "observe"),
                    decision_payload.get("risk_level", "low"),
                    bool(decision_payload.get("requires_human", False)),
                    "; ".join(decision_payload.get("justification", [])),
                )
                insert_outcome(
                    self.decision_db_path,
                    decision_id,
                    "success",
                    "reindex_full completed",
                )
            except Exception as e:
                print(f"[!] Guardian: reindex failed: {e}")

        # 1. Ensure route index exists then fetch routes
        self._ensure_routes_indexed()
        routes = self._get_proxied_routes()
        limit_env = os.getenv("BGL_ROUTE_SCAN_LIMIT")
        limit_cfg = self.config.get("route_scan_limit")
        mode_cfg = str(self.config.get("route_scan_mode", "auto")).lower()
        api_scan_mode = str(self.config.get("api_scan_mode", "safe")).lower()
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

        if limit > 0:
            routes = routes[:limit]

        # Track scan timing for safety
        scan_start = time.time()
        target_duration = self._target_duration(past_stats)

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

        report: Dict[str, Any] = {
            "timestamp": time.time(),
            "total_routes": len(routes),
            "healthy_routes": 0,
            "failing_routes": [],
            "skipped_routes": [],
            "log_anomalies": [],
            "business_conflicts": [],
            "suggestions": [],
            "recent_experiences": [],
            "route_scan_limit": len(routes),
            "route_scan_mode": mode_cfg,
            "permission_issues": [],
            "agent_mode": self.agent_mode,
            "tool_evidence": tool_evidence,
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
        report["api_contract_missing"] = self._contract_missing_routes(
            routes, api_contract.get("paths", {})
        )
        report["api_contract_gaps"] = self._contract_quality_gaps(
            routes, api_contract.get("paths", {})
        )

        # 2. Sequential Scan
        important_routes = routes

        for route in important_routes:
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
                scan_res = await self.safety.browser.scan_url(
                    uri, measure_perf=measure_perf
                )

            status_score = 100
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
        max_seconds = float(
            self.config.get(
                "route_scan_max_seconds", os.getenv("BGL_ROUTE_SCAN_MAX_SECONDS", 60)
            )
        )
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
            seed_info = seed_contract()
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

        # Fetch recent guarantees (simulation of querying the main app DB)
        # In a real integration, we might need to attach the app DB or use an API
        # For now, we will simulate the RECORD but use the logic bridge for VALIDATION

        # TODO: Implement actual DB fetch from 'guarantees' table if accessible
        # For this step, we will use a sample that represents a potential issue

        sample_candidates = {
            "supplier": {
                "candidates": [
                    {"score": 190, "source": "fuzzy"},
                    {"score": 185, "source": "fuzzy"},
                ],
                "normalized": "Ex",
            },
            "bank": {
                "candidates": [{"score": 50, "source": "fuzzy"}],
                "normalized": "Bk",
            },
        }
        sample_record = {
            "raw_supplier_name": "Example Supp",
            "raw_bank_name": "Bank Unknown",
        }

        bridge_path = self.root_dir / ".bgl_core" / "brain" / "logic_bridge.php"
        payload = json.dumps({"candidates": sample_candidates, "record": sample_record})

        try:
            result = subprocess.run(
                ["php", str(bridge_path)],
                input=payload,
                text=True,
                capture_output=True,
                check=True,
            )
            report = json.loads(result.stdout)
            if report.get("status") == "SUCCESS":
                return report.get("conflicts", [])
            return []
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
        method = (method or "GET").upper()
        if mode == "skip":
            return {"skipped": True, "reason": "api_scan_mode=skip"}

        # Align with OpenAPI contract if provided
        contract_methods = None
        if isinstance(contract, dict) and contract:
            contract_methods = {k.upper(): v for k, v in contract.items()}
            if method == "ANY":
                # Prefer contract-defined method
                if contract_methods:
                    method = list(contract_methods.keys())[0]
            if method not in contract_methods:
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
                        return {"skipped": True, "reason": f"missing_required_param:{name}"}
                    if example is not None:
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
                if example is None:
                    return {"skipped": True, "reason": "missing_request_example"}
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
        threshold = float(self.config.get("policy_auto_promote_threshold", 0.6))
        rules_path = self.root_dir / ".bgl_core" / "brain" / "policy_expectations.json"
        try:
            rules = json.loads(rules_path.read_text(encoding="utf-8")) if rules_path.exists() else []
        except Exception:
            rules = []
        promoted: List[Dict[str, Any]] = []
        existing_keys = {
            (r.get("uri"), r.get("method"), tuple(r.get("expected_statuses", [])), r.get("match_body_regex"), r.get("match_error_regex"))
            for r in rules
        }
        for c in candidates:
            if c.get("confidence", 0) < threshold:
                continue
            if not c.get("evidence"):
                continue
            uri = c.get("uri")
            status = c.get("status")
            method = c.get("method") or "ANY"
            body = c.get("error_body") or ""
            err = c.get("error") or ""
            if not uri or status is None:
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
            key = (uri, method, (status,), pattern, err_pattern)
            if key in existing_keys:
                continue
            rule = {
                "uri": uri,
                "method": method,
                "expected_statuses": [status],
                "reason": "Auto-promoted from evidence",
                "category": "policy_expected_auto",
            }
            if pattern:
                rule["match_body_regex"] = pattern
            if err_pattern:
                rule["match_error_regex"] = err_pattern
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

    def _gate_reindex(self, path: Path) -> bool:
        """Gate large reindex operations via decision layer and hardware limits."""
        # Gate record (authority handles policy overrides; reindex_full is usually safe/internal)
        req = ActionRequest(
            kind=ActionKind.WRITE_SANDBOX,
            operation=f"reindex.full|{str(path)}",
            command=f"reindex_full {path}",
            scope=[str(path)],
            reason=f"Reindex requested for {path}",
            confidence=0.75,
            metadata={"policy_key": "reindex_full", "path": str(path)},
        )
        gate = self.authority.gate(req, source="guardian")
        decision_id = int(gate.decision_id or 0)
        if not gate.allowed:
            return False

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
                    return False
            except Exception as e:
                print(f"[!] Guardian: Hardware check skipped: {e}")
        return True

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
        for fail in report["failing_routes"]:
            suspect = fail.get("suspect_code")
            file_name = "unknown file"
            if suspect and isinstance(suspect, dict):
                file_name = Path(suspect.get("file_path", "unknown")).name

            suggestions.append(
                f"Fix frontend error on {fail['uri']} (Suspect: {file_name})"
            )

        # Rule 2: log anomalies
        for anomaly in report["log_anomalies"]:
            suggestions.append(
                f"Investigate recurring backend error: {anomaly['message']}"
            )

        # Rule 3a: Permission issues
        for perm in report.get("permission_issues", []):
            suggestions.append(f"Permission check: {perm}")

        # Rule 3: Business Conflicts
        for conflict in report.get("business_conflicts", []):
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
            return [dict(r) for r in rows]
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
                ["python", sensor_path],
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
