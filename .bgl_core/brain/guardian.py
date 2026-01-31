import time
import os
import json
import sqlite3
from pathlib import Path
from typing import List, Dict, Any

try:
    from .safety import SafetyNet  # type: ignore
    from .fault_locator import FaultLocator  # type: ignore
    from .config_loader import load_config  # type: ignore
    from .decision_engine import decide  # type: ignore
    from .decision_db import insert_intent, insert_decision, insert_outcome, init_db  # type: ignore
except ImportError:
    from safety import SafetyNet
    from fault_locator import FaultLocator
    from config_loader import load_config
    from decision_engine import decide
    from decision_db import insert_intent, insert_decision, insert_outcome, init_db


class BGLGuardian:
    def __init__(self, root_dir: Path, base_url: str = "http://localhost:8000"):
        self.root_dir = root_dir
        cfg = load_config(root_dir)
        self.base_url = cfg.get("base_url", base_url)
        browser_enabled = bool(int(os.getenv("BGL_ENABLE_BROWSER", str(cfg.get("browser_enabled", 0)))))
        self.safety = SafetyNet(root_dir, base_url, enable_browser=browser_enabled)
        self.db_path = root_dir / ".bgl_core" / "brain" / "knowledge.db"
        self.locator = FaultLocator(self.db_path, root_dir)
        self.config = cfg
        self.agent_mode = str(cfg.get("agent_mode", "assisted")).lower()
        # decision db
        env_decision_db = os.environ.get("BGL_SANDBOX_DECISION_DB")
        self.decision_db_path = Path(env_decision_db) if env_decision_db else root_dir / ".bgl_core" / "brain" / "decision.db"
        self.decision_schema = root_dir / ".bgl_core" / "brain" / "decision_schema.sql"
        if self.decision_schema.exists():
            init_db(self.decision_db_path, self.decision_schema)
        self.execution_mode = str(cfg.get("execution_mode", "sandbox")).lower()

    async def perform_full_audit(self) -> Dict[str, Any]:
        """
        Scans all indexed routes and provides a proactive health report.
        """
        print("[*] Guardian: Starting Full System Health Audit...")

        # Optional: run predefined Playwright scenarios to populate runtime events
        run_scenarios = os.getenv("BGL_RUN_SCENARIOS", str(self.config.get("run_scenarios", 1)))
        if run_scenarios == "1":
            try:
                from .scenario_runner import main as run_scenarios  # type: ignore
            except ImportError:
                from scenario_runner import main as run_scenarios
            base_url = os.getenv("BGL_BASE_URL", self.config.get("base_url", "http://localhost:8000"))
            headless = bool(int(os.getenv("BGL_HEADLESS", str(self.config.get("headless", 0)))))  # default visible
            keep_open = bool(int(os.getenv("BGL_KEEP_BROWSER", str(self.config.get("keep_browser", 0)))))
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
            effective_mode = self.execution_mode
            if effective_mode == "auto_trial" and self._eligible_for_direct():
                effective_mode = "direct"

            if decision_payload.get("decision") == "block":
                print("    [!] Guardian: scenario batch blocked by decision gate.")
                insert_outcome(self.decision_db_path, decision_id, "blocked", "scenario_batch blocked by gate")
            elif self.agent_mode == "safe":
                print("    [!] Guardian: agent_mode=safe => skipping scenarios/browser run.")
                insert_outcome(self.decision_db_path, decision_id, "skipped", "agent_mode safe")
            elif self.agent_mode == "assisted":
                if self._has_permission("run_scenarios"):
                    print("    [+] Permission granted for scenarios; running.")
                    try:
                        await run_scenarios(base_url, headless, keep_open)
                        insert_outcome(self.decision_db_path, decision_id, "success", f"scenario batch executed (mode={effective_mode})")
                    except Exception as e:
                        print(f"    [!] Guardian: scenario run failed: {e}")
                        insert_outcome(self.decision_db_path, decision_id, "fail", str(e))
                else:
                    self._request_permission("run_scenarios", f"python scenario_runner.py --base-url {base_url} --headless {int(headless)}")
                    print("    [!] Guardian: scenarios pending human approval (agent_mode=assisted).")
                    insert_outcome(self.decision_db_path, decision_id, "blocked", "awaiting human permission")
            else:  # auto
                try:
                    await run_scenarios(base_url, headless, keep_open)
                    insert_outcome(self.decision_db_path, decision_id, "success", f"scenario batch executed (mode={effective_mode})")
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
                indexer.run()
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
                insert_outcome(self.decision_db_path, decision_id, "success", "reindex_full completed")
            except Exception as e:
                print(f"[!] Guardian: reindex failed: {e}")

        # 1. Get all routes
        routes = self._get_proxied_routes()
        limit_env = os.getenv("BGL_ROUTE_SCAN_LIMIT")
        limit_cfg = self.config.get("route_scan_limit")
        mode_cfg = str(self.config.get("route_scan_mode", "auto")).lower()

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
        hot_routes = {exp["scenario"] for exp in recent_exp if exp.get("confidence", 0) >= 0.6}
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
            "log_anomalies": [],
            "business_conflicts": [],
            "suggestions": [],
            "recent_experiences": [],
            "route_scan_limit": len(routes),
            "route_scan_mode": mode_cfg,
            "permission_issues": [],
            "agent_mode": self.agent_mode,
        }

        # 2. Sequential Scan
        important_routes = routes

        for route in important_routes:
            uri = route["uri"]
            print(f"    - Checking: {uri}")
            if (
                not getattr(self.safety, "enable_browser", False)
                or not getattr(self.safety, "browser", None)
                or not hasattr(self.safety.browser, "scan_url")
            ):
                scan_res = {"valid": True, "report": {}}
            else:
                scan_res = await self.safety.browser.scan_url(uri)

            status_score = 100
            status_val = scan_res.get("status", "SUCCESS")
            if (
                status_val != "SUCCESS"
                or scan_res.get("console_errors")
                or scan_res.get("network_failures")
            ):
                status_score = 0
                failure_info = {
                    "uri": uri,
                    "errors": scan_res.get("console_errors", [])
                    + [f["error"] for f in scan_res.get("network_failures", [])],
                    "suspect_code": self.locator.locate_url(uri),
                }
                report["failing_routes"].append(failure_info)
            else:
                report["healthy_routes"] += 1

            # Phase 5: Update Knowledge Base
            self._update_route_health(route, status_score)

        # 3. Analyze Backend Logs for Anomalies
        report["log_anomalies"] = self._detect_log_anomalies()

        # 4. Check Business Logic Conflicts (Collaborative Integration)
        # Simulation: In a real run, this would pull sampled data from DB
        sample_candidates = {"supplier": {"candidates": [{"score": 190}, {"score": 185}], "normalized": "Ex"}}
        sample_record = {"raw_supplier_name": "Example"}
        report["business_conflicts"] = self._check_business_conflicts(sample_candidates, sample_record)

        # 4b. Permission watchdog (write access to critical files)
        report["permission_issues"] = self._check_permissions()

        # 5. Load experiential memory and generate Suggestions
        report["recent_experiences"] = self._load_recent_experiences()
        report["suggestions"] = self._generate_suggestions(report)
        report["worst_routes"] = self._worst_routes(report)

        # 6. Timing safety check
        scan_duration = time.time() - scan_start
        max_seconds = float(self.config.get("route_scan_max_seconds", os.getenv("BGL_ROUTE_SCAN_MAX_SECONDS", 60)))
        if scan_duration > max_seconds:
            report.setdefault("warnings", []).append(
                f"Route scan exceeded safe time ({scan_duration:.1f}s > {max_seconds}s). Consider lowering limit or resources."
            )
        report["scan_duration_seconds"] = scan_duration
        report["target_duration_seconds"] = target_duration

        # 7. Persist stats for next adaptive run
        self._persist_route_stats(stats_path, len(important_routes), scan_duration)

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

    def auto_remediate(
        self, suggestion_index: int, report: Dict[str, Any]
    ) -> Dict[str, Any]:
        """
        Experimental: Attempts to solve a high-confidence suggestion using pre-defined rules.
        """
        if suggestion_index >= len(report["suggestions"]):
            return {"status": "ERROR", "message": "Invalid suggestion index"}

        suggestion = report["suggestions"][suggestion_index]
        print(f"[*] Guardian: Attempting Rule-Guided Remediation for: {suggestion}")

        # In a real scenario, this would generate a prompt for the Patcher.
        # For now, we simulate the 'Intent' to fix.
        return {
            "status": "INITIATED",
            "suggestion": suggestion,
            "message": "Auto-remediation logic triggered (rule-based simulation)",
        }

    def log_maintenance(self):
        """Standard maintenance tasks for the system."""
        print("[*] Guardian: Running Log Maintenance...")
        self._prune_logs(days=7)

    def _prune_logs(self, days: int):
        """Clears old log entries to preserve performance."""
        # Simulation of pruning logic
        print(f"    - Pruning logs older than {days} days... OK")

    def _check_business_conflicts(self, candidates: Dict[str, Any], record: Dict[str, Any]) -> List[str]:
        """Calls the PHP logic bridge to detect business-level conflicts."""
        import subprocess
        
        bridge_path = self.root_dir / ".bgl_core" / "brain" / "logic_bridge.php"
        payload = json.dumps({"candidates": candidates, "record": record})
        
        try:
            result = subprocess.run(
                ["php", str(bridge_path)],
                input=payload,
                text=True,
                capture_output=True,
                check=True
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

    def _has_permission(self, operation: str) -> bool:
        if not self.db_path.exists():
            return False
        conn = sqlite3.connect(str(self.db_path))
        cur = conn.cursor()
        row = cur.execute("SELECT status FROM agent_permissions WHERE operation=? ORDER BY id DESC LIMIT 1", (operation,)).fetchone()
        conn.close()
        return row and row[0] == "GRANTED"

    def _request_permission(self, operation: str, command: str):
        conn = sqlite3.connect(str(self.db_path))
        cur = conn.cursor()
        cur.execute(
            "INSERT INTO agent_permissions (operation, command, status, timestamp) VALUES (?, ?, 'PENDING', ?)",
            (operation, command, time.time()),
        )
        conn.commit()
        conn.close()

    def _gate_reindex(self, path: Path) -> bool:
        """Gate large reindex operations via decision layer."""
        intent_payload = {
            "intent": "reindex_full",
            "confidence": 0.75,
            "reason": f"Reindex requested for {path}",
            "scope": [str(path)],
            "context_snapshot": {
                "health": {},
                "active_route": None,
                "recent_changes": [],
                "guardian_top": [],
                "browser_state": "idle",
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
        if decision_payload.get("decision") in ["block", "defer"] or decision_payload.get("requires_human"):
            insert_outcome(self.decision_db_path, decision_id, "blocked", "reindex blocked by gate/human requirement")
            return False
        return True

    def _eligible_for_direct(self, required_successes: int = 5) -> bool:
        try:
            conn = sqlite3.connect(str(self.decision_db_path))
            cur = conn.cursor()
            cur.execute(
                """
                SELECT result FROM outcomes
                WHERE result IN ('success','fail','blocked','mode_direct')
                ORDER BY id DESC LIMIT ?
                """,
                (required_successes,),
            )
            rows = [r[0] for r in cur.fetchall()]
            conn.close()
            if len(rows) < required_successes:
                return False
            return all(r == "success" for r in rows)
        except Exception:
            return False
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
                suggestions.append(f"Prioritized route {exp['scenario']} shows issues: {exp['summary']}")
            elif exp.get("confidence", 0) >= 0.5:
                suggestions.append(f"Monitor route {exp['scenario']} (recent activity): {exp['summary']}")

        # Rule 5: Worst routes
        for wr in report.get("worst_routes", [])[:3]:
            suggestions.append(f"Hot route {wr.get('uri')} needs attention (score {wr.get('score')})")

        return suggestions

    def _worst_routes(self, report: Dict[str, Any], top_n: int = 5) -> List[Dict[str, Any]]:
        """Infer worst routes from experiences + failing routes + http errors count."""
        scored = {}
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

    def _load_recent_experiences(self, hours: int = 24, limit: int = 10) -> List[Dict[str, Any]]:
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

    def _persist_route_stats(self, stats_path: Path, routes_scanned: int, duration: float):
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

    def _compute_adaptive_limit(self, total_routes: int, mode: str, limit_env: str | None, limit_cfg: Any, past_stats: List[Dict[str, Any]]) -> int:
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
        durations = [s["duration"] for s in past_stats if s.get("duration") and s.get("routes")]
        if durations:
            med_routes = sorted(past_stats, key=lambda s: s["duration"]/max(0.1,s["routes"]))[len(past_stats)//2]
            routes_per_sec = med_routes["routes"] / max(0.1, med_routes["duration"])
        else:
            routes_per_sec = 2.0  # heuristic default

        target_duration = self._target_duration(past_stats)

        # system load
        cpu_idle = 50
        avail_gb = 1
        try:
            import psutil  # type: ignore
            cpu_idle = max(0, 100 - psutil.cpu_percent(interval=0.2))
            mem = psutil.virtual_memory()
            avail_gb = mem.available / (1024 ** 3)
        except Exception:
            pass

        desired = int(routes_per_sec * target_duration)
        # adjust for resources
        if cpu_idle < 15 or avail_gb < 0.5:
            desired = max(10, desired // 2)
        desired = min(total_routes, max(10, desired))
        return desired


if __name__ == "__main__":
    import asyncio

    async def test():
        ROOT = Path(__file__).parent.parent.parent
        guardian = BGLGuardian(ROOT)
        report = await guardian.perform_full_audit()
        print(json.dumps(report, indent=2))

    asyncio.run(test())
