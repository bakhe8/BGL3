import time
import sqlite3
import json
import os
from pathlib import Path
from typing import Dict, Any, List

# Core Service Imports (Internal)
try:
    from .safety import SafetyNet  # type: ignore
    from .guardian import BGLGuardian  # type: ignore
    from .browser_sensor import BrowserSensor  # type: ignore
    from .fault_locator import FaultLocator  # type: ignore
    from .inference import ReasoningEngine  # type: ignore
    from .interpretation import interpret  # type: ignore
    from .decision_db import init_db, insert_intent, insert_decision, insert_outcome  # type: ignore
    from .intent_resolver import resolve_intent  # type: ignore
    from .decision_engine import decide  # type: ignore
    from .config_loader import load_config  # type: ignore
    from .playbook_loader import load_playbooks_meta  # type: ignore
    from .authority import Authority  # type: ignore
    from .outcome_signals import compute_outcome_signals  # type: ignore
    from .learning_core import (
        ingest_learned_events,
        ingest_learning_confirmations,
        list_learning_events,
    )  # type: ignore
    from .hypothesis import derive_hypotheses_from_diagnostic, list_hypotheses  # type: ignore
    from .observations import (
        store_env_snapshot,
        diagnostic_to_snapshot,
        store_latest_diagnostic_delta,
        latest_env_snapshot,
        latest_ui_semantic_snapshot,
        previous_ui_semantic_snapshot,
        compute_ui_semantic_delta,
    )  # type: ignore
    from .fingerprint import compute_fingerprint, fingerprint_to_payload  # type: ignore
    from .volition import derive_volition, store_volition  # type: ignore
    from .autonomous_policy import apply_autonomous_policy_edit  # type: ignore
    from .self_policy import update_self_policy  # type: ignore
    from .self_rules import update_self_rules  # type: ignore
    from .schema_check import check_schema  # type: ignore
except (ImportError, ValueError):
    from safety import SafetyNet
    from guardian import BGLGuardian
    from browser_sensor import BrowserSensor
    from fault_locator import FaultLocator
    from inference import ReasoningEngine
    from interpretation import interpret
    from decision_db import init_db, insert_intent, insert_decision, insert_outcome
    from intent_resolver import resolve_intent
    from decision_engine import decide
    from config_loader import load_config
    from playbook_loader import load_playbooks_meta
    from authority import Authority
    from outcome_signals import compute_outcome_signals
    from learning_core import (
        ingest_learned_events,
        ingest_learning_confirmations,
        list_learning_events,
    )
    from hypothesis import derive_hypotheses_from_diagnostic, list_hypotheses
    from observations import (
        store_env_snapshot,
        diagnostic_to_snapshot,
        store_latest_diagnostic_delta,
        latest_env_snapshot,
        latest_ui_semantic_snapshot,
        previous_ui_semantic_snapshot,
        compute_ui_semantic_delta,
    )
    from fingerprint import compute_fingerprint, fingerprint_to_payload
    from volition import derive_volition, store_volition
    from autonomous_policy import apply_autonomous_policy_edit
    from self_policy import update_self_policy
    from self_rules import update_self_rules
    from schema_check import check_schema


class AgencyCore:
    """
    BGL3 Agency Core (The Brain) - Singleton
    Coordinates all sensors, actors, and knowledge.
    """

    _instance = None

    def __new__(cls, *args, **kwargs):
        if not cls._instance:
            cls._instance = super(AgencyCore, cls).__new__(cls)
        return cls._instance

    def __init__(self, root_dir: Path, base_url: str = "http://localhost:8000"):
        if hasattr(self, "_initialized"):
            return

        self.root_dir = root_dir
        self.base_url = base_url
        self.db_path = root_dir / ".bgl_core" / "brain" / "knowledge.db"
        # توحيد التخزين: القرارات داخل knowledge.db مباشرة
        self.decision_db_path = root_dir / ".bgl_core" / "brain" / "knowledge.db"
        self.decision_schema = root_dir / ".bgl_core" / "brain" / "decision_schema.sql"
        self.config = load_config(root_dir)
        self.authority = Authority(root_dir)

        # Initialize Core Components
        self.sensor_browser = BrowserSensor(
            base_url=base_url, headless=True, project_root=root_dir
        )
        self.locator = FaultLocator(self.db_path, self.root_dir)
        self.safety = SafetyNet(root_dir, base_url)
        self.guardian = BGLGuardian(root_dir, base_url)
        self.inference = ReasoningEngine(
            self.db_path, browser_sensor=self.sensor_browser
        )
        self.playbook_meta = load_playbooks_meta(root_dir)
        # Init decision db (idempotent)
        if self.decision_schema.exists():
            init_db(self.decision_db_path, self.decision_schema)
        self.execution_mode = str(self.config.get("execution_mode", "sandbox")).lower()

        # Schema drift check (best-effort, non-fatal)
        try:
            drift = check_schema(self.db_path)
            if isinstance(drift, dict) and not drift.get("ok", True):
                self.log_activity("SCHEMA_DRIFT", json.dumps(drift), "WARN")
        except Exception:
            pass

        self._initialized = True
        print(f"[*] AgencyCore: Intelligence engine initialized at {self.root_dir}")

    def _write_autonomy_goal(
        self, goal: str, payload: Dict[str, Any], source: str, ttl_days: int = 3
    ) -> None:
        try:
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
            # avoid duplicates
            payload_json = json.dumps(payload or {}, ensure_ascii=False)
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

    async def run_full_diagnostic(self) -> Dict[str, Any]:
        """
        Orchestrates a complete project-wide diagnostic.
        Standardizes output to a DiagnosticMap.
        """
        print("[*] AgencyCore: Initiating Full System Diagnostic...")

        # 1. Guardian Audit
        health_report = await self.guardian.perform_full_audit()

        # 2. Safety Audit (Architecture & Integrity)
        # We pick a sample file for architectural validation (usually a controller)
        sample_file = (
            self.root_dir / "app" / "Http" / "Controllers" / "GuaranteesController.php"
        )
        integrity_check = (
            await self.safety.validate_async(sample_file)
            if sample_file.exists()
            else {"valid": True}
        )

        # 3. Augment failing routes with diagnostic data
        failing_routes_details = []
        for route_item in health_report.get("failing_routes", []):
            if isinstance(route_item, dict):
                route_url = route_item.get("uri") or route_item.get("url") or route_item
                detail = self.locator.diagnose_fault(route_url)
                detail["uri"] = route_url
                if route_item.get("errors") is not None:
                    detail["errors"] = route_item.get("errors")
                if route_item.get("status") is not None:
                    detail["status"] = route_item.get("status")
                if route_item.get("latency_ms") is not None:
                    detail["latency_ms"] = route_item.get("latency_ms")
                failing_routes_details.append(detail)
            else:
                route_url = route_item
                failing_routes_details.append(self.locator.diagnose_fault(route_url))

        # 4. Unify into DiagnosticMap
        vitals = {
            "infrastructure": integrity_check.get("valid", False),
            "business_logic": (
                len(health_report.get("business_conflicts", [])) == 0
                and len(health_report.get("permission_issues", [])) == 0
                and len(health_report.get("failing_routes", [])) == 0
            ),
            "architecture": integrity_check.get("valid", True),
        }

        findings = {
            "failing_routes": failing_routes_details,
            "blockers": self.get_active_blockers(),
            "proposals": [],  # Legacy, replaced by dynamic reasoning
            "permission_issues": health_report.get("permission_issues", []),
            "pending_approvals": health_report.get("pending_approvals", []),
            "recent_outcomes": health_report.get("recent_outcomes", []),
            "worst_routes": health_report.get("worst_routes", []),
            "experiences": health_report.get("recent_experiences", []),
            "learning_confirmations": health_report.get("learning_confirmations", []),
            "scenario_deps": health_report.get("scenario_deps", {}),
            "api_scan": health_report.get("api_scan", {}),
            "api_contract_missing": health_report.get("api_contract_missing", []),
            "api_contract_gaps": health_report.get("api_contract_gaps", []),
            "expected_failures": health_report.get("expected_failures", []),
            "policy_candidates": health_report.get("policy_candidates", []),
            "policy_auto_promoted": health_report.get("policy_auto_promoted", []),
            "gap_tests": [],
        }
        # Latest UI semantic snapshot (if available)
        try:
            ui_sem = latest_ui_semantic_snapshot(self.db_path)
            if ui_sem:
                findings["ui_semantic"] = ui_sem
                prev_sem = previous_ui_semantic_snapshot(
                    self.db_path,
                    url=str(ui_sem.get("url") or ""),
                    before_ts=float(ui_sem.get("created_at") or time.time()),
                )
                if prev_sem and prev_sem.get("summary"):
                    findings["ui_semantic_delta"] = compute_ui_semantic_delta(
                        prev_sem.get("summary"), ui_sem.get("summary")
                    )
        except Exception:
            findings.setdefault("ui_semantic", None)
        findings["external_checks"] = []  # Legacy
        # Convert failing external checks into actionable proposals so they share one channel
        for check in findings["external_checks"]:
            if check.get("passed"):
                continue
            findings["proposals"].append(
                {
                    "id": check.get("id", "external_check"),
                    "source": "external_check",
                    "recommendation": check.get(
                        "recommendation", "Apply related playbook"
                    ),
                    "evidence": check.get("evidence", []),
                    "scope": check.get("scope", []),
                    "severity": check.get("severity", "medium"),
                }
            )
        findings["playbooks_meta"] = self.playbook_meta

        findings["interpretation"] = interpret(
            {
                "vitals": vitals,
                "findings": {
                    "failing_routes": failing_routes_details,
                    "permission_issues": findings["permission_issues"],
                    "worst_routes": findings["worst_routes"],
                    "experiences": findings["experiences"],
                },
            }
        )

        diagnostic_map: Dict[str, Any] = {
            "version": "1.0.0-gold",
            "timestamp": time.time(),
            "health_score": health_report.get("healthy_routes", 0)
            / max(1, health_report.get("total_routes", 1))
            * 100,
            "route_scan_limit": health_report.get("route_scan_limit", 0),
            "route_scan_mode": health_report.get("route_scan_mode", "auto"),
            "scan_duration_seconds": health_report.get("scan_duration_seconds", 0),
            "target_duration_seconds": health_report.get("target_duration_seconds", 0),
            "vitals": vitals,
            "findings": findings,
            "readiness": health_report.get("readiness", {}),
            "api_contract": health_report.get("api_contract", {}),
            "execution_mode": self.execution_mode,
            "execution_stats": self._execution_stats(),
            "tool_evidence": health_report.get("tool_evidence", {}),
        }

        # Learning ingestion (unifies learned events into knowledge.db)
        try:
            ingest_learned_events(self.db_path)
            ingest_learning_confirmations(self.db_path)
            findings["learning_recent"] = list_learning_events(self.db_path, limit=8)
        except Exception:
            findings.setdefault("learning_recent", [])

        # Deterministic signals layer (Outcome -> Signals + intent hint for robust fallback)
        try:
            signals_pack = compute_outcome_signals(
                {
                    "vitals": vitals,
                    "findings": findings,
                    "readiness": diagnostic_map.get("readiness", {}),
                }
            )
            findings["signals"] = signals_pack.get("signals", {})
            findings["signals_intent_hint"] = signals_pack.get("intent_hint", {})
        except Exception:
            findings.setdefault("signals", {})
            findings.setdefault("signals_intent_hint", {})

        # Self policy auto-update (no human approval; agent self-tuning)
        try:
            self_policy = update_self_policy(self.root_dir, findings)
            findings["self_policy"] = self_policy
        except Exception:
            findings.setdefault("self_policy", {})

        # Self rules auto-update (safe subset only)
        try:
            self_rules = update_self_rules(self.root_dir, findings)
            findings["self_rules"] = self_rules
        except Exception:
            findings.setdefault("self_rules", {})

        # Optional Reasoning layer (connects ReasoningEngine into the main pipeline)
        reasoning_enabled = False
        try:
            reasoning_enabled = (
                os.getenv("BGL_REASONING", "0") == "1"
                or bool(self.config.get("reasoning_enabled", 0))
            )
        except Exception:
            reasoning_enabled = False

        if reasoning_enabled:
            try:
                try:
                    from .brain_types import Context  # type: ignore
                except Exception:
                    from brain_types import Context  # type: ignore

                # Provide compact context to avoid huge prompts.
                reason_ctx = Context(
                    query_text="system_diagnostic",
                    env_state={
                        "vitals": vitals,
                        "readiness": diagnostic_map.get("readiness", {}),
                        "signals": findings.get("signals", {}),
                        "counts": (findings.get("signals") or {}).get("counts", {}),
                        "route_health": {
                            "failing": len(findings.get("failing_routes") or []),
                            "worst": len(findings.get("worst_routes") or []),
                        },
                        "ui_semantic_changed": bool((findings.get("ui_semantic_delta") or {}).get("changed")),
                        "ui_semantic_change_count": int((findings.get("ui_semantic_delta") or {}).get("change_count") or 0),
                    },
                )
                reasoning_plan = await self.inference.reason(reason_ctx)
                findings["reasoning"] = reasoning_plan

                # Map reasoning action -> intent hint (lightweight, deterministic).
                try:
                    action = str((reasoning_plan or {}).get("action", "")).upper()
                except Exception:
                    action = ""
                hint_intent = None
                if action in ("WRITE_FILE", "RENAME_CLASS", "ADD_METHOD", "SEARCH_GITHUB"):
                    hint_intent = "evolve"
                elif action in ("OBSERVE", "WAIT"):
                    hint_intent = "observe"
                if hint_intent:
                    findings["reasoning_hint"] = {
                        "intent": hint_intent,
                        "confidence": 0.65,
                        "reason": f"reasoning_action:{action}",
                        "scope": [],
                    }

                # Link reasoning output into decision/outcome audit trail (non-executing).
                try:
                    from .brain_types import ActionRequest, ActionKind  # type: ignore
                except Exception:
                    from brain_types import ActionRequest, ActionKind  # type: ignore
                try:
                    req = ActionRequest(
                        kind=ActionKind.PROPOSE,
                        operation="reasoning.plan",
                        command=str((reasoning_plan or {}).get("objective", "reasoning_plan")),
                        scope=[],
                        reason="reasoning_engine_output",
                        confidence=0.6,
                        metadata={"reasoning_action": action},
                    )
                    gate = self.authority.gate(req, source="reasoning")
                    self.authority.record_outcome(
                        int(gate.decision_id or 0),
                        "proposed",
                        "reasoning_plan_logged",
                    )
                except Exception:
                    pass
            except Exception as e:
                findings["reasoning"] = {"error": str(e)}

        # Hypothesis layer (bridges exploration outcomes with decision intent)
        try:
            derive_hypotheses_from_diagnostic(self.db_path, diagnostic_map)
            findings["hypotheses"] = list_hypotheses(self.db_path, status="open", limit=8)
        except Exception:
            findings.setdefault("hypotheses", [])

        # Include recent confirmed/contradicted summaries for transparency.
        try:
            findings["hypotheses_confirmed"] = list_hypotheses(self.db_path, status="confirmed", limit=4)
            findings["hypotheses_contradicted"] = list_hypotheses(self.db_path, status="contradicted", limit=4)
        except Exception:
            findings.setdefault("hypotheses_confirmed", [])
            findings.setdefault("hypotheses_contradicted", [])

        # Decision layer (observe-only pipeline)
        intent_payload = resolve_intent(
            {"vitals": vitals, "findings": findings, "readiness": diagnostic_map.get("readiness", {})}
        )
        # Attach UI semantic context to intent payload so decision engine can react.
        try:
            if findings.get("ui_semantic"):
                intent_payload["ui_semantic"] = findings.get("ui_semantic")
            if findings.get("ui_semantic_delta"):
                intent_payload["ui_semantic_delta"] = findings.get("ui_semantic_delta")
            if findings.get("self_policy"):
                intent_payload["self_policy"] = findings.get("self_policy")
        except Exception:
            pass
        diagnostic_map["findings"]["intent"] = intent_payload

        policy = self.config.get("decision", {})
        decision_payload = decide(intent_payload, policy)
        diagnostic_map["findings"]["decision"] = decision_payload

        # Seed exploration goals from decision intent (lightweight unification).
        try:
            intent_val = str(intent_payload.get("intent", "")).strip()
            reason = str(intent_payload.get("reason", "")).strip()
            scope = intent_payload.get("scope", []) or []
            payload = {
                "intent": intent_val,
                "decision": str(decision_payload.get("decision", "")).strip(),
                "reason": reason,
                "scope": scope,
            }
            uri = ""
            if isinstance(scope, list):
                for s in scope:
                    sv = str(s or "").strip()
                    if sv.startswith("http") or "/" in sv:
                        uri = sv
                        break
            if uri:
                payload["uri"] = uri
            if intent_val:
                self._write_autonomy_goal("decision_focus", payload, "decision", ttl_days=3)
        except Exception:
            pass

        # Persist intent + decision (context snapshot as JSON)
        try:
            context_snapshot = json.dumps(intent_payload.get("context_snapshot", {}))
            intent_id = insert_intent(
                self.decision_db_path,
                intent_payload.get("intent", "observe"),
                float(intent_payload.get("confidence", 0)),
                intent_payload.get("reason", ""),
                json.dumps(intent_payload.get("scope", [])),
                context_snapshot,
                source="agency_core",
            )
            decision_id = insert_decision(
                self.decision_db_path,
                intent_id,
                decision_payload.get("decision", "observe"),
                decision_payload.get("risk_level", "low"),
                bool(decision_payload.get("requires_human", False)),
                "; ".join(decision_payload.get("justification", [])),
            )
            # سجل outcome مرتبط بالحالة الفعلية للأنظمة
            if not vitals.get("business_logic", False):
                outcome_result = "fail"
            elif decision_payload.get("decision") in ("block", "observe"):
                # النظام سليم حالياً ولم يُنفذ شيء
                outcome_result = "false_positive"
            else:
                outcome_result = "success"
            insert_outcome(
                self.decision_db_path,
                decision_id,
                outcome_result,
                notes="auto-log from master_verify pipeline",
            )
        except Exception as e:
            print(f"[!] AgencyCore: failed to persist decision layer: {e}")

        # Log discovery if new proposals exist
        if diagnostic_map["findings"]["proposals"]:
            self.log_activity(
                "INFERENCE",
                f"Detected {len(diagnostic_map['findings']['proposals'])} potential architectural improvements.",
                "INFO",
            )

        # Persist a compact environment snapshot in knowledge.db (unifies observations across subsystems).
        try:
            run_id = str(int(diagnostic_map.get("timestamp") or time.time()))
            snapshot = diagnostic_to_snapshot(diagnostic_map)
            store_env_snapshot(
                self.db_path,
                run_id=run_id,
                kind="diagnostic",
                payload=snapshot,
                source="agency_core",
                confidence=float(intent_payload.get("confidence", 0) or 0),
                created_at=float(diagnostic_map.get("timestamp") or time.time()),
            )
            # Also store a delta snapshot vs the previous run (if any) to make change detection cheap.
            attribution = store_latest_diagnostic_delta(
                self.db_path,
                run_id=run_id,
                curr_snapshot_payload=snapshot,
                created_at=float(diagnostic_map.get("timestamp") or time.time()),
            )
            if isinstance(attribution, dict):
                diagnostic_map["findings"]["diagnostic_attribution"] = attribution
                diagnostic_map["diagnostic_attribution"] = attribution
            # If changes are detected without internal test/write activity, prompt exploration.
            try:
                if (
                    isinstance(attribution, dict)
                    and attribution.get("classification") == "external_change"
                    and int((attribution.get("signals") or {}).get("changed_keys") or 0) > 0
                ):
                    self._write_autonomy_goal(
                        "external_change_probe",
                        {
                            "changed_keys": int(
                                (attribution.get("signals") or {}).get("changed_keys") or 0
                            ),
                            "window_s": float(
                                (attribution.get("window") or {}).get("seconds") or 0
                            ),
                            "classification": attribution.get("classification"),
                        },
                        "attribution",
                        ttl_days=2,
                    )
            except Exception:
                pass
        except Exception:
            pass

        # Persist a project fingerprint for change detection (fast, file-metadata based).
        try:
            fp = compute_fingerprint(self.root_dir)
            store_env_snapshot(
                self.db_path,
                run_id=str(int(diagnostic_map.get("timestamp") or time.time())),
                kind="project_fingerprint",
                payload=fingerprint_to_payload(fp),
                source="agency_core",
                confidence=None,
                created_at=float(diagnostic_map.get("timestamp") or time.time()),
            )
        except Exception:
            pass

        # Derive and store volition (agent "will" statement).
        try:
            vol = derive_volition(diagnostic_map)
            store_volition(
                self.db_path,
                run_id=str(int(diagnostic_map.get("timestamp") or time.time())),
                volition=str(vol.get("volition", "")),
                confidence=float(vol.get("confidence", 0.5)),
                source=str(vol.get("source", "llm")),
                payload={"context": vol.get("context", {})},
                created_at=float(diagnostic_map.get("timestamp") or time.time()),
            )
            diagnostic_map["findings"]["volition"] = vol
            diagnostic_map["purpose"] = vol.get("volition", "")
            diagnostic_map["findings"]["purpose"] = vol.get("volition", "")
            try:
                purpose_text = str(vol.get("volition", "") or "").strip()
                if purpose_text:
                    self._write_autonomy_goal(
                        "purpose_focus",
                        {"term": purpose_text, "purpose": purpose_text},
                        "purpose",
                        ttl_days=2,
                    )
            except Exception:
                pass
        except Exception:
            pass

        # Autonomous policy self-amendment (direct write to policy_expectations.json)
        try:
            auto_enabled = (
                os.getenv("BGL_AUTONOMOUS", "0") == "1"
                or os.getenv("BGL_FORCE_AUTONOMOUS_POLICY", "0") == "1"
                or bool(self.config.get("autonomous_policy", 0))
                or bool(self.config.get("force_autonomous_policy", 0))
                or self.authority.effective_execution_mode() == "autonomous"
            )
        except Exception:
            auto_enabled = os.getenv("BGL_AUTONOMOUS", "0") == "1"

        if auto_enabled:
            try:
                auto_policy = apply_autonomous_policy_edit(self.root_dir, diagnostic_map)
                diagnostic_map["findings"]["autonomous_policy"] = auto_policy
                if isinstance(auto_policy, dict) and auto_policy.get("status") == "applied":
                    self.log_activity(
                        "AUTONOMOUS_POLICY",
                        f"Applied policy patch: {auto_policy.get('action')}",
                    )
            except Exception:
                pass

        return diagnostic_map

    def _execution_stats(self) -> Dict[str, Any]:
        stats = {"direct_attempts": 0, "success_rate": None, "total_outcomes": 0}
        try:
            conn = sqlite3.connect(str(self.decision_db_path))
            cur = conn.cursor()
            cur.execute("SELECT COUNT(*) FROM outcomes WHERE result = 'mode_direct'")
            stats["direct_attempts"] = int(cur.fetchone()[0])
            cur.execute("SELECT result, COUNT(*) FROM outcomes GROUP BY result")
            rows = cur.fetchall()
            total = sum(r[1] for r in rows)
            stats["total_outcomes"] = total
            if total > 0:
                successes = sum(
                    count
                    for res, count in rows
                    if res in ("success", "success_with_override")
                )
                stats["success_rate"] = round(successes / total, 3)
            conn.close()
        except Exception:
            pass
        return stats

    def get_active_blockers(self) -> List[Dict[str, Any]]:
        """Retrieves unresolved agent struggles from cognitive memory."""
        try:
            conn = sqlite3.connect(str(self.db_path))
            conn.row_factory = sqlite3.Row
            cursor = conn.cursor()
            cursor.execute("SELECT * FROM agent_blockers WHERE status = 'PENDING'")
            rows = cursor.fetchall()
            conn.close()
            return [dict(r) for r in rows]
        except Exception as e:
            print(f"[!] AgencyCore: Memory error: {e}")
            return []

    def solve_blocker(self, blocker_id: int):
        """Marks a cognitive struggle as resolved."""
        conn = sqlite3.connect(str(self.db_path))
        conn.execute(
            "UPDATE agent_blockers SET status = 'RESOLVED' WHERE id = ?", (blocker_id,)
        )
        conn.commit()
        conn.close()
        print(f"[*] AgencyCore: Blocker {blocker_id} resolved by context update.")

    def commit_proposed_rule(self, rule_id: str):
        """Appends a proposed rule to domain_rules.yml."""
        import yaml  # Assume yaml is available for core edit

        rules_path = self.root_dir / ".bgl_core" / "brain" / "domain_rules.yml"
        proposals = self.inference.analyze_patterns()

        target_rule = next((p for p in proposals if str(p["id"]) == str(rule_id)), None)
        if not target_rule:
            return False

        # Prepare YAML entry
        new_entry = {
            "id": target_rule["id"],
            "name": target_rule["name"],
            "description": target_rule["description"],
            "action": target_rule["action"],
        }

        try:
            # Note: We use a simple append or full write depending on complexity
            with open(rules_path, "r") as f:
                content = yaml.safe_load(f) or {"rules": []}

            # Prevent duplicates
            if not any(r["id"] == new_entry["id"] for r in content.get("rules", [])):
                content.setdefault("rules", []).append(new_entry)
                with open(rules_path, "w") as f:
                    yaml.dump(content, f, sort_keys=False)
                print(
                    f"[*] AgencyCore: Evolved rule {rule_id} committed to domain_rules.yml"
                )
                return True
        except Exception as e:
            print(f"[!] AgencyCore: Failed to commit rule: {e}")
            return False
        return False

    def request_permission(self, operation: str, command: str) -> int:
        """Logs a risky operation for human approval (delegates to Authority)."""
        perm_id = self.authority.request_permission(operation, command)
        print(f"[!] AgencyCore: Permission requested for {operation} (ID: {perm_id})")
        return int(perm_id or 0)

    def is_permission_granted(self, perm_id: int) -> bool:
        """Checks if the user has approved a specific operation (delegates to Authority)."""
        return bool(self.authority.is_permission_granted(perm_id))

    def log_activity(self, activity_type: str, message: str, status: str = "INFO"):
        """Persistent activity logging for dashboard visibility."""
        try:
            conn = sqlite3.connect(str(self.db_path))
            conn.execute(
                "INSERT INTO agent_activity (timestamp, type, message, status) VALUES (?, ?, ?, ?)",
                (time.time(), activity_type, message, status),
            )
            conn.commit()
            conn.close()
        except Exception as e:
            print(f"[!] AgencyCore: Activity log error: {e}")

    def log_trace(self, message: str, level: str = "INFO"):
        """Systemic tracing for audit trails."""
        # Future: write to system_traces table
        print(f"[{level}] AgencyCore Trace: {message}")


if __name__ == "__main__":
    # Test Initialization
    core = AgencyCore(Path("c:/Users/Bakheet/Documents/Projects/BGL3"))
    print(
        f"[+] Singleton Test: {core is AgencyCore(Path('c:/Users/Bakheet/Documents/Projects/BGL3'))}"
    )
