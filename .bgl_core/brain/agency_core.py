import time
import asyncio
import sqlite3
import json
import os
import sys
import subprocess
from pathlib import Path
from typing import Dict, Any, List
try:
    from .db_utils import connect_db  # type: ignore
except Exception:
    from db_utils import connect_db  # type: ignore

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
        ingest_runtime_contract_experiences,
        list_learning_events,
        update_fallback_rules_from_prod_ops,
        update_fallback_rules_from_outcomes,
        apply_external_dependency_fallback,
        auto_propose_outcome_failures,
        update_fallback_rules_from_runtime_contracts,
        update_fallback_rules_from_code_intent,
    )  # type: ignore
    from .code_intel import build_code_intel  # type: ignore
    from .code_contracts import build_code_contracts  # type: ignore
    from .failure_classifier import summarize_failure_taxonomy  # type: ignore
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
    from .knowledge_curation import curate_knowledge  # type: ignore
    from .learning_feedback import apply_learning_feedback  # type: ignore
    from .long_term_goals import refresh_long_term_goals, summarize_long_term_goals  # type: ignore
    from .canary_release import evaluate_canary_releases, summarize_canary_status  # type: ignore
    from .metrics_enrichment import compute_kpi_metrics, summarize_agent_activity  # type: ignore
    from .generate_playbooks import generate_from_proposed  # type: ignore
    from .schema_check import check_schema  # type: ignore
    from .agent_verify import run_all_checks  # type: ignore
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
        ingest_runtime_contract_experiences,
        list_learning_events,
        update_fallback_rules_from_prod_ops,
        update_fallback_rules_from_outcomes,
        apply_external_dependency_fallback,
        auto_propose_outcome_failures,
        update_fallback_rules_from_runtime_contracts,
        update_fallback_rules_from_code_intent,
    )
    from code_intel import build_code_intel
    from code_contracts import build_code_contracts
    from failure_classifier import summarize_failure_taxonomy
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
    from knowledge_curation import curate_knowledge
    from learning_feedback import apply_learning_feedback
    from long_term_goals import refresh_long_term_goals, summarize_long_term_goals
    from canary_release import evaluate_canary_releases, summarize_canary_status
    from metrics_enrichment import compute_kpi_metrics, summarize_agent_activity
    from generate_playbooks import generate_from_proposed
    from schema_check import check_schema
    from agent_verify import run_all_checks


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

    def _summarize_code_intent_signals(self) -> Dict[str, Any]:
        path = self.root_dir / "analysis" / "code_contracts.json"
        if not path.exists():
            return {}
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
        except Exception:
            return {}
        counts = {"stabilize": 0, "unblock": 0, "evolve": 0, "observe": 0}
        token_freq: Dict[str, int] = {}
        total = 0

        def _ingest(item: Dict[str, Any]) -> None:
            nonlocal total
            sig = item.get("intent_signals") or {}
            hint = sig.get("intent_hint") or {}
            suggested = str(hint.get("suggested_intent") or "").lower()
            if suggested in counts:
                counts[suggested] += 1
            for t in sig.get("tokens") or []:
                token = str(t).lower()
                if len(token) < 2:
                    continue
                token_freq[token] = token_freq.get(token, 0) + 1
            total += 1

        for c in data.get("contracts") or []:
            if isinstance(c, dict) and c.get("intent_signals"):
                _ingest(c)
        for c in data.get("function_contracts") or []:
            if isinstance(c, dict) and c.get("intent_signals"):
                _ingest(c)

        if total == 0:
            return {}
        top_tokens = sorted(token_freq.items(), key=lambda kv: kv[1], reverse=True)[:12]
        top_intents = sorted(
            [{"intent": k, "count": v} for k, v in counts.items() if v > 0],
            key=lambda item: item["count"],
            reverse=True,
        )
        suggested = top_intents[0]["intent"] if top_intents else ""
        return {
            "intent_counts": counts,
            "top_intents": top_intents,
            "suggested_intent": suggested,
            "top_tokens": [t for t, _ in top_tokens],
            "total": total,
        }

    def _summarize_code_temporal_signals(self) -> Dict[str, Any]:
        path = self.root_dir / "analysis" / "code_contracts.json"
        if not path.exists():
            return {}
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
        except Exception:
            return {}
        total = 0
        counts = {"stateful": 0, "startup_exec": 0, "accumulates": 0, "first_request_writes": 0}
        markers: Dict[str, int] = {}

        def _ingest(item: Dict[str, Any]) -> None:
            nonlocal total
            prof = item.get("temporal_profile") or {}
            if not isinstance(prof, dict):
                return
            total += 1
            if prof.get("stateful"):
                counts["stateful"] += 1
            if prof.get("startup_exec"):
                counts["startup_exec"] += 1
            if prof.get("accumulates"):
                counts["accumulates"] += 1
            if prof.get("first_request_writes"):
                counts["first_request_writes"] += 1
            for m in prof.get("markers") or []:
                mk = str(m).lower()
                if not mk:
                    continue
                markers[mk] = markers.get(mk, 0) + 1

        for c in data.get("contracts") or []:
            if isinstance(c, dict) and c.get("temporal_profile"):
                _ingest(c)
        for c in data.get("function_contracts") or []:
            if isinstance(c, dict) and c.get("temporal_profile"):
                _ingest(c)

        if total == 0:
            return {}
        top_markers = sorted(markers.items(), key=lambda kv: kv[1], reverse=True)[:10]
        return {
            "counts": counts,
            "total": total,
            "stateful_ratio": round(counts["stateful"] / max(1, total), 3),
            "startup_ratio": round(counts["startup_exec"] / max(1, total), 3),
            "top_markers": [m for m, _ in top_markers],
        }

    def _auto_patch_error_experiences(self, experiences: List[Dict[str, Any]]) -> None:
        if not experiences:
            return
        try:
            enabled = os.getenv(
                "BGL_AUTO_PATCH_ON_ERRORS",
                str(self.config.get("auto_patch_on_errors", 0)),
            )
            if str(enabled) != "1":
                return
        except Exception:
            return
        try:
            limit = int(
                os.getenv(
                    "BGL_AUTO_PATCH_LIMIT",
                    str(self.config.get("auto_patch_limit", 2)),
                )
                or 2
            )
        except Exception:
            limit = 2
        try:
            min_conf = float(
                os.getenv(
                    "BGL_AUTO_PATCH_MIN_CONF",
                    str(self.config.get("auto_patch_min_conf", 0.6)),
                )
                or 0.6
            )
        except Exception:
            min_conf = 0.6
        try:
            min_evidence = int(
                os.getenv(
                    "BGL_AUTO_PATCH_MIN_EVIDENCE",
                    str(self.config.get("auto_patch_min_evidence", 3)),
                )
                or 3
            )
        except Exception:
            min_evidence = 3

        keywords = (
            "failed",
            "error",
            "network error",
            "http calls",
            "500",
            "400",
            "exception",
        )
        exe = sys.executable or "python"
        script = self.root_dir / ".bgl_core" / "brain" / "apply_proposal.py"
        if not script.exists():
            return

        try:
            import hashlib
        except Exception:
            hashlib = None  # type: ignore

        created = 0
        try:
            conn = connect_db(self.db_path)
            conn.execute(
                """
                CREATE TABLE IF NOT EXISTS agent_proposals (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT UNIQUE,
                    description TEXT,
                    action TEXT,
                    count INTEGER,
                    evidence TEXT,
                    impact TEXT,
                    solution TEXT,
                    expectation TEXT
                )
                """
            )
            cur = conn.cursor()
            for exp in experiences:
                if created >= limit:
                    break
                summary = str(exp.get("summary") or "")
                scenario = str(exp.get("scenario") or "")
                if not summary or not scenario:
                    continue
                text = summary.lower()
                if not any(k in text for k in keywords):
                    continue
                try:
                    conf = float(exp.get("confidence") or 0)
                except Exception:
                    conf = 0.0
                try:
                    evidence = int(exp.get("evidence_count") or 0)
                except Exception:
                    evidence = 0
                if conf < min_conf or evidence < min_evidence:
                    continue

                base = f"{scenario}|{summary}"
                if hashlib:
                    exp_hash = hashlib.sha1(base.encode("utf-8")).hexdigest()[:10]
                else:
                    exp_hash = str(abs(hash(base)))[:10]
                name = f"Auto patch {exp_hash}: {scenario}"
                try:
                    exists = cur.execute(
                        "SELECT id FROM agent_proposals WHERE name = ?",
                        (name,),
                    ).fetchone()
                    if exists:
                        continue
                except Exception:
                    pass
                evidence_payload = {
                    "scenario": scenario,
                    "summary": summary,
                    "confidence": conf,
                    "evidence_count": evidence,
                    "related_files": exp.get("related_files"),
                    "source": "auto_patch_errors",
                }
                try:
                    cur.execute(
                        """
                        INSERT INTO agent_proposals
                        (name, description, action, count, evidence, impact, solution, expectation)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        """,
                        (
                            name,
                            summary[:400],
                            "stabilize",
                            1,
                            json.dumps(evidence_payload, ensure_ascii=False),
                            "medium",
                            "",
                            "",
                        ),
                    )
                    proposal_id = cur.lastrowid
                    conn.commit()
                except Exception:
                    continue

                try:
                    subprocess.run(
                        [exe, str(script), "--proposal", str(proposal_id)],
                        cwd=str(self.root_dir),
                        capture_output=True,
                        text=True,
                        check=False,
                    )
                except Exception:
                    pass
                created += 1
        except Exception:
            pass
        finally:
            try:
                conn.close()
            except Exception:
                pass

    def _auto_propose_ui_action_gaps(
        self, ui_action_cov: Dict[str, Any]
    ) -> List[Dict[str, Any]]:
        if not ui_action_cov:
            return []
        if ui_action_cov.get("reliable") is False:
            return []
        try:
            ratio = float(
                ui_action_cov.get("operational_coverage_ratio")
                or ui_action_cov.get("coverage_ratio")
                or 0.0
            )
        except Exception:
            ratio = 0.0
        try:
            min_ratio = float(
                os.getenv(
                    "BGL_MIN_UI_ACTION_COVERAGE",
                    str(self.config.get("min_ui_action_coverage", "30")),
                )
                or "30"
            )
        except Exception:
            min_ratio = 30.0
        if ratio >= min_ratio:
            return []

        gaps = ui_action_cov.get("gaps") or []
        if not gaps:
            return []

        try:
            import hashlib
        except Exception:
            hashlib = None  # type: ignore

        proposals: List[Dict[str, Any]] = []
        try:
            conn = connect_db(self.db_path)
            conn.execute(
                """
                CREATE TABLE IF NOT EXISTS agent_proposals (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT UNIQUE,
                    description TEXT,
                    action TEXT,
                    count INTEGER,
                    evidence TEXT,
                    impact TEXT,
                    solution TEXT,
                    expectation TEXT
                )
                """
            )
            cur = conn.cursor()

            sample = gaps[:6]
            sample_text = []
            for g in sample:
                label = g.get("text") or g.get("href") or g.get("selector")
                if label:
                    sample_text.append(str(label))
            evidence_payload = {
                "coverage_ratio": ratio,
                "window_days": ui_action_cov.get("window_days"),
                "sample_gaps": sample,
                "source": "ui_action_coverage",
            }
            base = json.dumps(evidence_payload, ensure_ascii=False)
            if hashlib:
                suffix = hashlib.sha1(base.encode("utf-8")).hexdigest()[:8]
            else:
                suffix = str(abs(hash(base)))[:8]
            name = f"تحسين تغطية تفاعل UI ({ratio:.1f}%) #{suffix}"
            description = (
                "تغطية تفاعل UI منخفضة. نحتاج توسيع الاستكشاف ليشمل عناصر لم يتم التفاعل معها.\n"
                f"نماذج العناصر: {', '.join(sample_text[:6])}"
            ).strip()
            try:
                exists = cur.execute(
                    "SELECT id FROM agent_proposals WHERE name = ?",
                    (name,),
                ).fetchone()
                if not exists:
                    cur.execute(
                        """
                        INSERT INTO agent_proposals
                        (name, description, action, count, evidence, impact, solution, expectation)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        """,
                        (
                            name,
                            description[:400],
                            "improve_exploration",
                            1,
                            json.dumps(evidence_payload, ensure_ascii=False),
                            "medium",
                            "",
                            "",
                        ),
                    )
                    proposal_id = cur.lastrowid
                    conn.commit()
                    proposals.append(
                        {
                            "id": proposal_id,
                            "source": "ui_action_gap",
                            "recommendation": "توسيع الاستكشاف ليشمل الأزرار/التبويبات غير المُجرّبة",
                            "evidence": sample,
                            "severity": "medium",
                        }
                    )
            except Exception:
                pass
        except Exception:
            return proposals
        finally:
            try:
                conn.close()
            except Exception:
                pass
        return proposals

    def _auto_propose_coverage_gaps(
        self, scenario_cov: Dict[str, Any], flow_cov: Dict[str, Any]
    ) -> List[Dict[str, Any]]:
        proposals: List[Dict[str, Any]] = []
        try:
            conn = connect_db(self.db_path)
            conn.execute(
                """
                CREATE TABLE IF NOT EXISTS agent_proposals (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT UNIQUE,
                    description TEXT,
                    action TEXT,
                    count INTEGER,
                    evidence TEXT,
                    impact TEXT,
                    solution TEXT,
                    expectation TEXT
                )
                """
            )
            cur = conn.cursor()

            # Scenario coverage gaps
            if scenario_cov and scenario_cov.get("reliable") is not False:
                try:
                    ratio = float(scenario_cov.get("coverage_ratio") or 0.0)
                except Exception:
                    ratio = 0.0
                gaps = scenario_cov.get("uncovered_sample") or []
                try:
                    min_ratio = float(
                        os.getenv(
                            "BGL_COVERAGE_MIN_RATIO",
                            str(self.config.get("coverage_min_ratio", 35)),
                        )
                        or "35"
                    )
                except Exception:
                    min_ratio = 35.0
                if gaps and ratio < min_ratio:
                    sample = gaps[:6]
                    evidence_payload = {
                        "coverage_ratio": ratio,
                        "window_days": scenario_cov.get("window_days"),
                        "sample_gaps": sample,
                        "source": "scenario_coverage",
                    }
                    base = json.dumps(evidence_payload, ensure_ascii=False)
                    try:
                        import hashlib

                        suffix = hashlib.sha1(base.encode("utf-8")).hexdigest()[:8]
                    except Exception:
                        suffix = str(abs(hash(base)))[:8]
                    name = f"تغطية مسارات غير مُجرّبة ({ratio:.1f}%) #{suffix}"
                    description = "تغطية المسارات أقل من الحد المطلوب. نحتاج تشغيل سيناريوهات للمسارات غير المغطاة."
                    exists = cur.execute(
                        "SELECT id FROM agent_proposals WHERE name = ?",
                        (name,),
                    ).fetchone()
                    if not exists:
                        cur.execute(
                            """
                            INSERT INTO agent_proposals
                            (name, description, action, count, evidence, impact, solution, expectation)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                            """,
                            (
                                name,
                                description[:400],
                                "improve_coverage",
                                1,
                                json.dumps(evidence_payload, ensure_ascii=False),
                                "medium",
                                "",
                                "",
                            ),
                        )
                        proposal_id = cur.lastrowid
                        conn.commit()
                        proposals.append(
                            {
                                "id": proposal_id,
                                "source": "scenario_coverage_gap",
                                "recommendation": "توسيع التغطية للمسارات غير المُجرّبة",
                                "evidence": sample,
                                "severity": "medium",
                            }
                        )

            # Flow coverage gaps
            if flow_cov and flow_cov.get("reliable") is not False:
                try:
                    ratio = float(
                        flow_cov.get("operational_coverage_ratio")
                        or flow_cov.get("coverage_ratio")
                        or 0.0
                    )
                except Exception:
                    ratio = 0.0
                gaps = flow_cov.get("uncovered_sample") or []
                try:
                    min_ratio = float(
                        os.getenv(
                            "BGL_MIN_FLOW_COVERAGE",
                            str(self.config.get("min_flow_coverage", 35)),
                        )
                        or "35"
                    )
                except Exception:
                    min_ratio = 35.0
                if gaps and ratio < min_ratio:
                    sample = gaps[:4]
                    evidence_payload = {
                        "coverage_ratio": ratio,
                        "window_days": flow_cov.get("window_days"),
                        "sample_gaps": sample,
                        "source": "flow_coverage",
                    }
                    base = json.dumps(evidence_payload, ensure_ascii=False)
                    try:
                        import hashlib

                        suffix = hashlib.sha1(base.encode("utf-8")).hexdigest()[:8]
                    except Exception:
                        suffix = str(abs(hash(base)))[:8]
                    name = f"تغطية تدفقات غير مكتملة ({ratio:.1f}%) #{suffix}"
                    description = "تغطية التدفقات تعتمد على مسارات بدون أحداث فعلية؛ نحتاج تشغيل تدفقات كاملة."
                    exists = cur.execute(
                        "SELECT id FROM agent_proposals WHERE name = ?",
                        (name,),
                    ).fetchone()
                    if not exists:
                        cur.execute(
                            """
                            INSERT INTO agent_proposals
                            (name, description, action, count, evidence, impact, solution, expectation)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                            """,
                            (
                                name,
                                description[:400],
                                "improve_flow_coverage",
                                1,
                                json.dumps(evidence_payload, ensure_ascii=False),
                                "medium",
                                "",
                                "",
                            ),
                        )
                        proposal_id = cur.lastrowid
                        conn.commit()
                        proposals.append(
                            {
                                "id": proposal_id,
                                "source": "flow_coverage_gap",
                                "recommendation": "تشغيل التدفقات غير المكتملة بشكل فعلي",
                                "evidence": sample,
                                "severity": "medium",
                            }
                        )
        except Exception:
            pass
        finally:
            try:
                conn.close()
            except Exception:
                pass
        return proposals
    def _auto_context_digest(self) -> Dict[str, Any]:
        """
        Run context_digest to unify runtime/prod logs into experiences -> proposals -> sandbox apply.
        """
        try:
            enabled = os.getenv(
                "BGL_AUTO_CONTEXT_DIGEST",
                str(self.config.get("auto_digest", 1)),
            )
            if str(enabled).strip().lower() not in ("1", "true", "yes", "on"):
                return {"ok": False, "skipped": True, "reason": "disabled"}
        except Exception:
            return {"ok": False, "skipped": True, "reason": "disabled"}

        try:
            hours = float(
                os.getenv(
                    "BGL_AUTO_DIGEST_HOURS",
                    str(self.config.get("auto_digest_hours", 12)),
                )
                or 12
            )
        except Exception:
            hours = 12.0
        try:
            limit = int(
                os.getenv(
                    "BGL_AUTO_DIGEST_LIMIT",
                    str(self.config.get("auto_digest_limit", 400)),
                )
                or 400
            )
        except Exception:
            limit = 400

        # If we recently timed out, reduce scope automatically (based on prior runs).
        state_path = self.root_dir / ".bgl_core" / "logs" / "context_digest_state.json"
        try:
            state = json.loads(state_path.read_text(encoding="utf-8")) if state_path.exists() else {}
        except Exception:
            state = {}
        try:
            last_timeout_at = float(state.get("last_timeout_at") or 0)
        except Exception:
            last_timeout_at = 0.0
        if last_timeout_at and (time.time() - last_timeout_at) < 6 * 3600:
            # Back off to avoid repeated timeouts.
            hours = min(hours, float(state.get("fallback_hours") or max(1.0, hours / 2)))
            limit = min(limit, int(state.get("fallback_limit") or max(100, int(limit / 2))))

        script = self.root_dir / ".bgl_core" / "brain" / "context_digest.py"
        if not script.exists():
            return {"ok": False, "error": "context_digest_missing"}
        try:
            exe = sys.executable or "python"
        except Exception:
            exe = "python"

        try:
            timeout_sec = float(
                os.getenv(
                    "BGL_AUTO_DIGEST_TIMEOUT_SEC",
                    str(self.config.get("auto_digest_timeout_sec", 120)),
                )
                or 120
            )
        except Exception:
            timeout_sec = 120.0
        try:
            proc = subprocess.run(
                [exe, str(script), "--hours", str(hours), "--limit", str(limit)],
                cwd=str(self.root_dir),
                capture_output=True,
                text=True,
                check=False,
                timeout=timeout_sec,
            )
            out = (proc.stdout or "").strip()
            err = (proc.stderr or "").strip()
            # Keep the payload compact for the report.
            try:
                state_path.parent.mkdir(parents=True, exist_ok=True)
                state = state if isinstance(state, dict) else {}
                state.update(
                    {
                        "last_success_at": time.time(),
                        "last_timeout_at": state.get("last_timeout_at"),
                        "last_hours": hours,
                        "last_limit": limit,
                        "returncode": proc.returncode,
                    }
                )
                state_path.write_text(json.dumps(state, ensure_ascii=False, indent=2), encoding="utf-8")
            except Exception:
                pass
            return {
                "ok": proc.returncode == 0,
                "returncode": proc.returncode,
                "stdout": out[-500:] if out else "",
                "stderr": err[-500:] if err else "",
                "hours": hours,
                "limit": limit,
                "timeout_sec": timeout_sec,
            }
        except Exception as e:
            # Mark timeout and suggest fallback scope for next run.
            try:
                state_path.parent.mkdir(parents=True, exist_ok=True)
                state = state if isinstance(state, dict) else {}
                state.update(
                    {
                        "last_timeout_at": time.time(),
                        "last_hours": hours,
                        "last_limit": limit,
                        "timeout_sec": timeout_sec,
                        "fallback_hours": max(1.0, hours / 2),
                        "fallback_limit": max(100, int(limit / 2)),
                    }
                )
                state_path.write_text(json.dumps(state, ensure_ascii=False, indent=2), encoding="utf-8")
            except Exception:
                pass
            return {
                "ok": False,
                "error": str(e),
                "hours": hours,
                "limit": limit,
                "timeout_sec": timeout_sec,
                "timed_out": "TimeoutExpired" in str(e),
            }

    def _auto_generate_patch_plans(self) -> Dict[str, Any]:
        """
        Auto-generate patch plans for proposals lacking a plan.
        Uses existing generate_patch_plan.py to avoid custom logic.
        """
        try:
            enabled = os.getenv(
                "BGL_AUTO_PLAN",
                str(self.config.get("auto_plan", self.config.get("auto_propose", 1))),
            )
            if str(enabled).strip().lower() not in ("1", "true", "yes", "on"):
                return {"ok": False, "skipped": True, "reason": "disabled"}
        except Exception:
            return {"ok": False, "skipped": True, "reason": "disabled"}

        try:
            limit = int(
                os.getenv(
                    "BGL_AUTO_PLAN_LIMIT",
                    str(self.config.get("auto_plan_limit", 3)),
                )
                or 3
            )
        except Exception:
            limit = 3

        if limit <= 0:
            return {"ok": False, "skipped": True, "reason": "limit_zero"}

        if not self.db_path.exists():
            return {"ok": False, "error": "db_missing"}

        script = self.root_dir / ".bgl_core" / "brain" / "generate_patch_plan.py"
        if not script.exists():
            return {"ok": False, "error": "generate_patch_plan_missing"}

        try:
            exe = sys.executable or "python"
        except Exception:
            exe = "python"

        generated = []
        failed = []
        skipped = 0
        try:
            conn = connect_db(self.db_path)
            conn.row_factory = sqlite3.Row
            rows = conn.execute(
                """
                SELECT id, name, impact, action, solution
                FROM agent_proposals
                WHERE solution IS NULL OR TRIM(solution) = ''
                ORDER BY id DESC
                LIMIT ?
                """,
                (limit,),
            ).fetchall()
            conn.close()
        except Exception as e:
            return {"ok": False, "error": f"db_query_failed:{e}"}

        if not rows:
            return {"ok": True, "generated": 0, "failed": 0, "skipped": 0, "reason": "no_candidates"}

        for row in rows:
            pid = row["id"]
            try:
                proc = subprocess.run(
                    [exe, str(script), "--proposal", str(pid)],
                    cwd=str(self.root_dir),
                    capture_output=True,
                    text=True,
                    check=False,
                    timeout=120,
                )
                if proc.returncode == 0:
                    generated.append(int(pid))
                else:
                    failed.append({"id": int(pid), "error": (proc.stderr or proc.stdout or "").strip()[-200:]})
            except Exception as e:
                failed.append({"id": int(pid), "error": str(e)})

        return {
            "ok": len(failed) == 0,
            "generated": len(generated),
            "failed": len(failed),
            "skipped": skipped,
            "generated_ids": generated[:10],
            "failed_samples": failed[:5],
        }

    def _auto_run_checks(self, findings: Dict[str, Any]) -> Dict[str, Any]:
        """
        Run agent_verify checks only when triggered by signals (coverage/quality).
        """
        try:
            enabled = os.getenv(
                "BGL_AUTO_VERIFY",
                str(self.config.get("auto_verify", 1)),
            )
            if str(enabled).strip().lower() not in ("1", "true", "yes", "on"):
                return {"ok": False, "skipped": True, "reason": "disabled"}
        except Exception:
            return {"ok": False, "skipped": True, "reason": "disabled"}

        triggers: Dict[str, Any] = {}
        # Trigger on low success rate
        try:
            if str(self.config.get("auto_verify_on_low_success", "1")) == "1":
                threshold = float(
                    os.getenv(
                        "BGL_AUTO_VERIFY_SUCCESS_THRESHOLD",
                        str(self.config.get("auto_verify_success_threshold", 0.6)),
                    )
                    or 0.6
                )
                stats = self._execution_stats()
                sr = stats.get("success_rate")
                if sr is not None and float(sr) < threshold:
                    triggers["low_success_rate"] = {
                        "success_rate": sr,
                        "threshold": threshold,
                    }
        except Exception:
            pass

        # Trigger on UI action coverage gap
        try:
            if str(self.config.get("auto_verify_on_ui_gap", "1")) == "1":
                ui_cov = findings.get("ui_action_coverage") or {}
                ratio = float(ui_cov.get("coverage_ratio") or 0.0)
                threshold = float(
                    os.getenv(
                        "BGL_AUTO_VERIFY_UI_GAP_THRESHOLD",
                        str(self.config.get("auto_verify_ui_gap_threshold", 30)),
                    )
                    or 30
                )
                if ui_cov and ratio < threshold:
                    triggers["ui_action_gap"] = {"ratio": ratio, "threshold": threshold}
        except Exception:
            pass

        # Trigger on route coverage gap
        try:
            if str(self.config.get("auto_verify_on_route_gap", "1")) == "1":
                scov = findings.get("scenario_coverage") or {}
                ratio = float(scov.get("coverage_ratio") or 0.0)
                threshold = float(
                    os.getenv(
                        "BGL_AUTO_VERIFY_ROUTE_COVERAGE_THRESHOLD",
                        str(self.config.get("auto_verify_route_coverage_threshold", 60)),
                    )
                    or 60
                )
                if scov and ratio < threshold:
                    triggers["route_gap"] = {"ratio": ratio, "threshold": threshold}
        except Exception:
            pass

        # Trigger on API contract gaps
        try:
            if str(self.config.get("auto_verify_on_contract_gap", "1")) == "1":
                missing = findings.get("api_contract_missing") or []
                gaps = findings.get("api_contract_gaps") or []
                if missing or gaps:
                    triggers["contract_gap"] = {
                        "missing": len(missing),
                        "gaps": len(gaps),
                    }
        except Exception:
            pass

        if not triggers:
            return {"ok": False, "skipped": True, "reason": "no_triggers"}

        try:
            checks = run_all_checks(self.root_dir)
        except Exception as e:
            return {"ok": False, "error": str(e), "triggers": triggers}
        auto_actions: List[Dict[str, Any]] = []
        try:
            results = (checks or {}).get("results") or []
            # Optional: auto-apply DB fixes if db checks fail and auto-apply is enabled.
            allow_db_fixes = os.getenv("BGL_AUTO_APPLY_DB_FIXES")
            if allow_db_fixes is None:
                allow_db_fixes = str(self.config.get("auto_apply", 0))
            allow_db_fixes = str(allow_db_fixes).strip().lower() in ("1", "true", "yes", "on")
            if allow_db_fixes:
                db_fail = [
                    r for r in results
                    if not r.get("passed")
                    and str(r.get("check") or r.get("id") or "").strip().lower()
                    in ("db_index_missing", "db_fk_missing")
                ]
                if db_fail:
                    exe = sys.executable or "python"
                    script = self.root_dir / ".bgl_core" / "brain" / "apply_db_fixes.py"
                    if script.exists():
                        proc = subprocess.run(
                            [exe, str(script), "--db", "storage/database/app.sqlite"],
                            cwd=str(self.root_dir),
                            capture_output=True,
                            text=True,
                            check=False,
                            timeout=120,
                        )
                        auto_actions.append(
                            {
                                "action": "apply_db_fixes",
                                "ok": proc.returncode == 0,
                                "returncode": proc.returncode,
                                "stdout": (proc.stdout or "")[-400:],
                                "stderr": (proc.stderr or "")[-400:],
                                "checks": [r.get("check") or r.get("id") for r in db_fail],
                            }
                        )
        except Exception as e:
            auto_actions.append({"action": "apply_db_fixes", "ok": False, "error": str(e)})
        return {"ok": True, "triggers": triggers, "checks": checks, "auto_actions": auto_actions}

    def _sync_playbooks_from_proposals(self, proposals: List[Dict[str, Any]]) -> Dict[str, Any]:
        """
        Reuse generate_playbooks.py by exporting proposals into proposed_patterns.json.
        This avoids new logic and keeps playbook generation in one place.
        """
        if not proposals:
            return {"ok": False, "skipped": True, "reason": "no_proposals"}
        try:
            import re
        except Exception:
            re = None  # type: ignore

        def _slug(text: str) -> str:
            raw = str(text or "").strip()
            if not raw:
                return "PB_AUTO"
            if re is None:
                return raw.replace(" ", "_")[:64]
            return re.sub(r"[^a-zA-Z0-9_]+", "_", raw)[:64]

        patterns: List[Dict[str, Any]] = []
        for p in proposals:
            if not isinstance(p, dict):
                continue
            pid = p.get("id") or p.get("name") or p.get("source") or "PB_AUTO"
            pid = _slug(str(pid))
            recommendation = (
                p.get("recommendation")
                or p.get("description")
                or p.get("summary")
                or "تحسين تلقائي مقترح."
            )
            evidence = p.get("evidence") or []
            scope = p.get("scope") or []
            try:
                confidence = float(p.get("confidence") or p.get("score") or 0.6)
            except Exception:
                confidence = 0.6
            patterns.append(
                {
                    "id": pid,
                    "check": p.get("source") or p.get("check") or "proposal",
                    "recommendation": recommendation,
                    "evidence": evidence,
                    "scope": scope,
                    "confidence": confidence,
                }
            )
        if not patterns:
            return {"ok": False, "skipped": True, "reason": "no_patterns"}

        proposed_path = self.root_dir / ".bgl_core" / "brain" / "proposed_patterns.json"
        try:
            proposed_path.write_text(
                json.dumps(patterns, ensure_ascii=False, indent=2), encoding="utf-8"
            )
        except Exception as e:
            return {"ok": False, "error": f"write_failed:{e}"}

        try:
            generated = generate_from_proposed(self.root_dir) or []
        except Exception as e:
            return {"ok": False, "error": f"generate_failed:{e}"}
        return {"ok": True, "generated": generated, "count": len(generated)}

    def _write_autonomy_goal(
        self, goal: str, payload: Dict[str, Any], source: str, ttl_days: int = 3
    ) -> None:
        try:
            db = connect_db(self.db_path, timeout=30.0)
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
        fast_profile = (
            str(os.getenv("BGL_DIAGNOSTIC_PROFILE", "")).lower() == "fast"
            or os.getenv("BGL_FAST_VERIFY", "0") == "1"
        )

        # 1. Guardian Audit
        guardian_timeout = 0.0
        try:
            guardian_timeout = float(os.getenv("BGL_GUARDIAN_TIMEOUT_SEC", "0") or 0)
        except Exception:
            guardian_timeout = 0.0
        if guardian_timeout <= 0:
            try:
                diag_timeout = float(os.getenv("BGL_DIAGNOSTIC_TIMEOUT_SEC", "0") or 0)
            except Exception:
                diag_timeout = 0.0
            if diag_timeout > 0:
                guardian_timeout = max(60.0, diag_timeout * 0.7)
        if guardian_timeout > 0:
            try:
                health_report = await asyncio.wait_for(
                    self.guardian.perform_full_audit(), timeout=guardian_timeout
                )
            except asyncio.TimeoutError:
                health_report = {
                    "audit_status": "timeout",
                    "audit_reason": "guardian_timeout",
                    "route_scan_stats": {
                        "attempted": 0,
                        "checked": 0,
                        "skipped": 0,
                        "errors": 0,
                        "unknown": 0,
                    },
                    "scan_duration_seconds": guardian_timeout,
                    "target_duration_seconds": guardian_timeout,
                    "route_scan_limit": 0,
                    "route_scan_mode": "auto",
                    "total_routes": 0,
                    "healthy_routes": 0,
                    "failing_routes": [],
                    "permission_issues": [],
                    "business_conflicts": [],
                    "worst_routes": [],
                    "recent_experiences": [],
                    "learning_confirmations": [],
                    "scenario_deps": {},
                    "api_scan": {},
                    "api_contract_missing": [],
                    "api_contract_gaps": [],
                    "expected_failures": [],
                    "policy_candidates": [],
                    "policy_auto_promoted": [],
                    "domain_rule_violations": [],
                    "domain_rule_summary": {},
                    "scenario_coverage": {},
                    "flow_coverage": {},
                    "ui_action_coverage": {},
                    "gap_scenarios": [],
                    "gap_scenarios_existing": [],
                    "scenario_run_stats": {"status": "timeout", "attempted": False},
                    "coverage_reliability": {},
                    "external_checks": [],
                    "tool_evidence": {},
                    "readiness": {"ok": False, "duration_ms": 0},
                }
        else:
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
        if not fast_profile:
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
                len(health_report.get("business_conflicts") or []) == 0
                and len(health_report.get("permission_issues") or []) == 0
                and len(health_report.get("failing_routes") or []) == 0
            ),
            "architecture": integrity_check.get("valid", True),
        }

        findings = {
            "failing_routes": failing_routes_details,
            "blockers": self.get_active_blockers(),
            "proposals": [],  # Legacy, replaced by dynamic reasoning
            "permission_issues": health_report.get("permission_issues") or [],
            "pending_approvals": health_report.get("pending_approvals") or [],
            "recent_outcomes": health_report.get("recent_outcomes") or [],
            "worst_routes": health_report.get("worst_routes") or [],
            "experiences": health_report.get("recent_experiences") or [],
            "learning_confirmations": health_report.get("learning_confirmations") or [],
            "scenario_deps": health_report.get("scenario_deps", {}),
            "api_scan": health_report.get("api_scan", {}),
            "api_contract_missing": health_report.get("api_contract_missing") or [],
            "api_contract_gaps": health_report.get("api_contract_gaps") or [],
            "expected_failures": health_report.get("expected_failures") or [],
            "policy_candidates": health_report.get("policy_candidates") or [],
            "policy_auto_promoted": health_report.get("policy_auto_promoted") or [],
            "domain_rule_violations": health_report.get("domain_rule_violations") or [],
            "domain_rule_summary": health_report.get("domain_rule_summary", {}),
            "scenario_coverage": health_report.get("scenario_coverage", {}),
            "flow_coverage": health_report.get("flow_coverage", {}),
            "ui_action_coverage": health_report.get("ui_action_coverage", {}),
            "gap_scenarios": health_report.get("gap_scenarios") or [],
            "gap_scenarios_existing": health_report.get("gap_scenarios_existing") or [],
            "scenario_run_stats": health_report.get("scenario_run_stats", {}),
            "coverage_reliability": health_report.get("coverage_reliability", {}),
            "gap_tests": [],
            "external_checks": health_report.get("external_checks") or [],
            "tool_evidence": health_report.get("tool_evidence") or {},
        }
        # KPI metrics (operational signals)
        try:
            if not fast_profile:
                findings["kpi_metrics"] = compute_kpi_metrics(self.root_dir, self.db_path)
        except Exception:
            findings.setdefault("kpi_metrics", {})
        # Agent activity summary (stability/stall hints)
        try:
            if not fast_profile:
                findings["activity_summary"] = summarize_agent_activity(self.db_path)
        except Exception:
            findings.setdefault("activity_summary", {})
        # Code understanding snapshot (PHP + Python + JS) for safer edits
        try:
            if not fast_profile and str(os.getenv("BGL_CODE_INTEL", "1")) == "1":
                findings["code_intel"] = build_code_intel(self.root_dir, self.db_path)
        except Exception:
            findings.setdefault("code_intel", {})
        # Code contracts + test linkage (trust for safe edits)
        try:
            if not fast_profile and str(os.getenv("BGL_CODE_CONTRACTS", "1")) == "1":
                findings["code_contracts"] = build_code_contracts(self.root_dir)
        except Exception:
            findings.setdefault("code_contracts", {})
        # Code intent signals (variable/comment/test-driven intent hints)
        try:
            if not fast_profile and str(os.getenv("BGL_CODE_CONTRACTS", "1")) == "1":
                findings["code_intent_signals"] = self._summarize_code_intent_signals()
        except Exception:
            findings.setdefault("code_intent_signals", {})
        # Code temporal signals (startup/first-request/state accumulation)
        try:
            if not fast_profile and str(os.getenv("BGL_CODE_CONTRACTS", "1")) == "1":
                findings["code_temporal_signals"] = self._summarize_code_temporal_signals()
        except Exception:
            findings.setdefault("code_temporal_signals", {})
        # Diagnostic delta snapshot (recent change context)
        try:
            delta_snap = latest_env_snapshot(self.db_path, kind="diagnostic_delta")
            if delta_snap and isinstance(delta_snap, dict):
                findings["diagnostic_delta"] = delta_snap.get("payload") or {}
        except Exception:
            findings.setdefault("diagnostic_delta", {})
        # Latest UI semantic snapshot (if available)
        try:
            ui_sem = latest_ui_semantic_snapshot(self.db_path)
            if ui_sem:
                findings["ui_semantic"] = ui_sem
                current_url = str(ui_sem.get("url") or "")
                prev_sem = previous_ui_semantic_snapshot(
                    self.db_path,
                    url=current_url,
                    before_ts=float(ui_sem.get("created_at") or time.time()),
                )
                if not (prev_sem and prev_sem.get("summary")):
                    prev_sem = previous_ui_semantic_snapshot(
                        self.db_path,
                        url=None,
                        before_ts=float(ui_sem.get("created_at") or time.time()),
                    )
                if prev_sem and prev_sem.get("summary"):
                    delta = compute_ui_semantic_delta(
                        prev_sem.get("summary"), ui_sem.get("summary")
                    )
                    if not delta.get("changed") and str(prev_sem.get("url") or "") and str(prev_sem.get("url") or "") != current_url:
                        delta["changed"] = True
                        delta["reason"] = "new_url"
                        delta["change_count"] = max(1, int(delta.get("change_count") or 0))
                        delta["prev_url"] = str(prev_sem.get("url") or "")
                    findings["ui_semantic_delta"] = delta
        except Exception:
            findings.setdefault("ui_semantic", None)
        # Merge auto-run checks (if any) into external_checks for unified proposal channel.
        try:
            if not fast_profile:
                auto_checks = self._auto_run_checks(findings)
                findings["auto_checks"] = auto_checks
                auto_results = (auto_checks.get("checks") or {}).get("results") or []
                if auto_results:
                    findings["external_checks"].extend(auto_results)
        except Exception:
            findings.setdefault("auto_checks", {})
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
        try:
            ui_action_proposals = self._auto_propose_ui_action_gaps(
                findings.get("ui_action_coverage") or {}
            )
            if ui_action_proposals:
                findings["proposals"].extend(ui_action_proposals)
        except Exception:
            pass
        try:
            coverage_proposals = self._auto_propose_coverage_gaps(
                findings.get("scenario_coverage") or {},
                findings.get("flow_coverage") or {},
            )
            if coverage_proposals:
                findings["proposals"].extend(coverage_proposals)
        except Exception:
            pass
        try:
            findings["playbooks_generated"] = self._sync_playbooks_from_proposals(
                findings.get("proposals") or []
            )
        except Exception:
            findings.setdefault("playbooks_generated", {})
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

        route_stats = health_report.get("route_scan_stats") or {}
        checked = 0
        try:
            checked = int(route_stats.get("checked") or 0)
        except Exception:
            checked = 0
        total_routes = int(health_report.get("total_routes", 0) or 0)
        healthy_routes = int(health_report.get("healthy_routes", 0) or 0)
        if checked <= 0:
            health_score = None
            health_score_status = "unknown_no_checked_routes"
        else:
            health_score = healthy_routes / max(1, checked) * 100
            health_score_status = "ok"
        audit_status = str(health_report.get("audit_status") or "ok")
        audit_reason = str(health_report.get("audit_reason") or "")
        audit_budget_seconds = float(health_report.get("audit_budget_seconds") or 0.0)
        audit_elapsed_seconds = float(health_report.get("audit_elapsed_seconds") or 0.0)

        diagnostic_map: Dict[str, Any] = {
            "version": "1.0.0-gold",
            "timestamp": time.time(),
            "health_score": health_score,
            "health_score_status": health_score_status,
            "route_scan_limit": health_report.get("route_scan_limit", 0),
            "route_scan_mode": health_report.get("route_scan_mode", "auto"),
            "route_scan_stats": route_stats,
            "scan_duration_seconds": health_report.get("scan_duration_seconds", 0),
            "target_duration_seconds": health_report.get("target_duration_seconds", 0),
            "audit_status": audit_status,
            "audit_reason": audit_reason,
            "audit_budget_seconds": audit_budget_seconds,
            "audit_elapsed_seconds": audit_elapsed_seconds,
            "vitals": vitals,
            "findings": findings,
            "readiness": health_report.get("readiness", {}),
            "api_contract": health_report.get("api_contract", {}),
            "execution_mode": self.execution_mode,
            "execution_stats": self._execution_stats(),
            "tool_evidence": health_report.get("tool_evidence", {}),
        }
        findings["audit_status"] = audit_status
        findings["audit_reason"] = audit_reason
        findings["audit_budget_seconds"] = audit_budget_seconds
        findings["audit_elapsed_seconds"] = audit_elapsed_seconds

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

        # Knowledge curation (conflict detection + weighting)
        try:
            knowledge_status = curate_knowledge(self.root_dir, self.db_path)
            findings["knowledge_status"] = knowledge_status
        except Exception:
            findings.setdefault("knowledge_status", {})

        # Learning feedback loop (intent bias updates based on outcomes)
        try:
            learning_feedback = apply_learning_feedback(self.root_dir, self.db_path)
            findings["learning_feedback"] = learning_feedback
            if isinstance(learning_feedback, dict) and learning_feedback.get("policy"):
                findings["self_policy"] = learning_feedback.get("policy")
        except Exception:
            findings.setdefault("learning_feedback", {})

        # Fallback rules from prod operation outcomes (central log -> learning -> fallback)
        try:
            fallback_status = update_fallback_rules_from_prod_ops(
                self.root_dir,
                self.db_path,
            )
            findings["fallback_rules"] = fallback_status
            if isinstance(fallback_status, dict) and fallback_status.get("ok"):
                try:
                    findings.setdefault("self_policy", {})
                    if isinstance(findings["self_policy"], dict):
                        findings["self_policy"].setdefault("fallback_rules", fallback_status.get("total_rules"))
                except Exception:
                    pass
        except Exception:
            findings.setdefault("fallback_rules", {})

        # Fallback rules from execution outcomes (improve success rate)
        try:
            fb_outcomes = update_fallback_rules_from_outcomes(
                self.root_dir,
                self.db_path,
            )
            findings["fallback_rules_outcomes"] = fb_outcomes
        except Exception:
            findings.setdefault("fallback_rules_outcomes", {})
        # Fallback rules from runtime contract evidence (route/file stability)
        try:
            fb_runtime = update_fallback_rules_from_runtime_contracts(
                self.root_dir,
                self.db_path,
            )
            findings["fallback_rules_runtime"] = fb_runtime
        except Exception:
            findings.setdefault("fallback_rules_runtime", {})
        # Fallback rules from code intent signals (variables/comments/tests/logs)
        try:
            fb_code_intent = update_fallback_rules_from_code_intent(
                self.root_dir,
                self.db_path,
            )
            findings["fallback_rules_code_intent"] = fb_code_intent
        except Exception:
            findings.setdefault("fallback_rules_code_intent", {})
        # Auto-propose from repeated outcome failures (bridges outcomes -> proposals).
        try:
            outcome_props = auto_propose_outcome_failures(self.root_dir, self.db_path)
            findings["outcome_proposals"] = outcome_props
            created = (outcome_props or {}).get("created") or []
            if created:
                findings["proposals"].extend(created)
        except Exception:
            findings.setdefault("outcome_proposals", {})
        # Failure taxonomy (classified failures -> signals for rollback/fallback)
        try:
            if summarize_failure_taxonomy:
                findings["failure_taxonomy"] = summarize_failure_taxonomy(self.db_path)
        except Exception:
            findings.setdefault("failure_taxonomy", {})

        # External dependency fallback (DB/network outages -> short-lived block + rollback signal)
        external_dependency_signal = None
        try:
            ext_status = apply_external_dependency_fallback(self.root_dir, self.db_path)
            findings["external_dependency"] = ext_status
            try:
                detected = (ext_status or {}).get("detected") or {}
                if int(detected.get("count") or 0) > 0:
                    external_dependency_signal = detected
            except Exception:
                external_dependency_signal = None
        except Exception:
            findings.setdefault("external_dependency", {})
            external_dependency_signal = None

        # Auto context digest: unify runtime/prod logs into experiences -> proposals -> sandbox apply.
        try:
            findings["context_digest"] = self._auto_context_digest()
        except Exception:
            findings.setdefault("context_digest", {})
        # Promote runtime contract experiences into learning events (scoring signal).
        try:
            findings["runtime_contract_learning"] = ingest_runtime_contract_experiences(
                self.db_path, limit=160
            )
        except Exception:
            findings.setdefault("runtime_contract_learning", 0)
        try:
            findings["auto_plan"] = self._auto_generate_patch_plans()
        except Exception:
            findings.setdefault("auto_plan", {})

        # Auto-patch confirmed error experiences (sandbox) before canary evaluation.
        try:
            self._auto_patch_error_experiences(findings.get("experiences") or [])
        except Exception:
            pass

        # Long-term goals (schedule + priority policy)
        try:
            lt_status = refresh_long_term_goals(self.db_path)
        except Exception:
            lt_status = {"ok": False}
        try:
            lt_status["summary"] = summarize_long_term_goals(self.db_path, limit=6)
        except Exception:
            lt_status.setdefault("summary", {})
        findings["long_term_goals"] = lt_status

        # Canary release evaluation (safe production promotion / rollback)
        try:
            auto_rb_env = os.getenv("BGL_CANARY_AUTO_ROLLBACK")
            if auto_rb_env is None:
                cfg_val = self.config.get("canary_auto_rollback", 0)
                if isinstance(cfg_val, bool):
                    auto_rb = cfg_val
                elif isinstance(cfg_val, (int, float)):
                    auto_rb = float(cfg_val) != 0.0
                else:
                    auto_rb = str(cfg_val).strip().lower() in ("1", "true", "yes", "on")
            else:
                auto_rb = bool(int(auto_rb_env or "0"))
        except Exception:
            auto_rb = False
        try:
            cfg_min_age = self.config.get("canary_min_age_sec", 300)
            min_age = int(os.getenv("BGL_CANARY_MIN_AGE_SEC", str(cfg_min_age) or "300") or "300")
        except Exception:
            min_age = 300
        try:
            cfg_recheck = self.config.get("canary_recheck_sec", 0)
            recheck_sec = int(os.getenv("BGL_CANARY_RECHECK_SEC", str(cfg_recheck) or "0") or "0")
        except Exception:
            recheck_sec = 0
        try:
            if os.getenv("BGL_CANARY_EXTERNAL_DEPENDENCY_ROLLBACK") is None:
                cfg_val = self.config.get("canary_external_dependency_rollback", 1)
                if isinstance(cfg_val, bool):
                    os.environ["BGL_CANARY_EXTERNAL_DEPENDENCY_ROLLBACK"] = "1" if cfg_val else "0"
                elif isinstance(cfg_val, (int, float)):
                    os.environ["BGL_CANARY_EXTERNAL_DEPENDENCY_ROLLBACK"] = "1" if float(cfg_val) != 0.0 else "0"
                else:
                    os.environ["BGL_CANARY_EXTERNAL_DEPENDENCY_ROLLBACK"] = (
                        "1" if str(cfg_val).strip().lower() in ("1", "true", "yes", "on") else "0"
                    )
        except Exception:
            pass
        try:
            canary_eval = evaluate_canary_releases(
                self.root_dir,
                self.db_path,
                min_age_sec=min_age,
                auto_rollback=auto_rb,
                recheck_sec=recheck_sec,
                external_dependency=external_dependency_signal,
            )
        except Exception:
            canary_eval = {"ok": False}
        try:
            canary_eval["summary"] = summarize_canary_status(self.db_path, limit=4)
        except Exception:
            canary_eval.setdefault("summary", {})
        findings["canary_status"] = canary_eval

        # Auto-run checks already executed earlier to feed proposals/external checks.

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
            if findings.get("domain_rule_violations"):
                intent_payload["domain_rule_violations"] = findings.get("domain_rule_violations")
            if findings.get("domain_rule_summary"):
                intent_payload["domain_rule_summary"] = findings.get("domain_rule_summary")
            if findings.get("code_intent_signals"):
                intent_payload["code_intent_signals"] = findings.get("code_intent_signals")
            if findings.get("code_temporal_signals"):
                intent_payload["code_temporal_signals"] = findings.get("code_temporal_signals")
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

        run_id = str(
            os.getenv("BGL_DIAGNOSTIC_RUN_ID")
            or int(diagnostic_map.get("timestamp") or time.time())
        )

        # Persist a compact environment snapshot in knowledge.db (unifies observations across subsystems).
        try:
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
                try:
                    self.guardian._log_runtime_event(
                        {
                            "timestamp": time.time(),
                            "run_id": run_id,
                            "event_type": "diagnostic_delta",
                            "source": "agency_core",
                            "payload": {
                                "classification": attribution.get("classification"),
                                "confidence": attribution.get("confidence"),
                                "signals": attribution.get("signals"),
                                "window": attribution.get("window"),
                            },
                        }
                    )
                except Exception:
                    pass
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

        # Safe config tuning via agent_flags.json (no direct config.yml writes)
        try:
            from .config_tuner import apply_safe_config_tuning  # type: ignore
        except Exception:
            try:
                from config_tuner import apply_safe_config_tuning  # type: ignore
            except Exception:
                apply_safe_config_tuning = None  # type: ignore
        if apply_safe_config_tuning:
            try:
                tune_res = apply_safe_config_tuning(self.root_dir, diagnostic_map)
                diagnostic_map["findings"]["config_tuning"] = tune_res
                if isinstance(tune_res, dict) and tune_res.get("status") == "applied":
                    self.log_activity(
                        "CONFIG_TUNING",
                        f"Applied safe config updates: {list(tune_res.get('updates', {}).keys())}",
                    )
            except Exception:
                pass

        try:
            scenario_stats = diagnostic_map.get("findings", {}).get("scenario_run_stats", {})
            self.guardian._log_runtime_event(
                {
                    "timestamp": time.time(),
                    "run_id": run_id,
                    "event_type": "diagnostic_run_complete",
                    "source": "agency_core",
                    "payload": {
                        "health_score": diagnostic_map.get("health_score"),
                        "health_score_status": diagnostic_map.get("health_score_status"),
                        "route_scan_stats": diagnostic_map.get("route_scan_stats", {}),
                        "scan_duration_seconds": diagnostic_map.get("scan_duration_seconds"),
                        "target_duration_seconds": diagnostic_map.get("target_duration_seconds"),
                        "scenario_run_stats": scenario_stats,
                        "diagnostic_confidence": diagnostic_map.get("diagnostic_confidence")
                        or (diagnostic_map.get("findings") or {}).get("diagnostic_confidence"),
                        "diagnostic_faults": diagnostic_map.get("diagnostic_faults")
                        or (diagnostic_map.get("findings") or {}).get("diagnostic_faults"),
                        "execution_mode": diagnostic_map.get("execution_mode"),
                    },
                    "status": 1 if diagnostic_map.get("health_score") is not None else 0,
                }
            )
        except Exception:
            pass

        return diagnostic_map

    def _execution_stats(self) -> Dict[str, Any]:
        stats = {"direct_attempts": 0, "success_rate": None, "total_outcomes": 0}
        try:
            conn = connect_db(self.decision_db_path, timeout=30.0)
            cur = conn.cursor()
            cur.execute("SELECT COUNT(*) FROM outcomes WHERE result = 'mode_direct'")
            stats["direct_attempts"] = int(cur.fetchone()[0])
            cur.execute("SELECT result, COUNT(*) FROM outcomes GROUP BY result")
            rows = cur.fetchall()
            ignore = {"proposed", "skipped", "deferred", "blocked"}
            total = sum(count for res, count in rows if str(res or "") not in ignore)
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
            conn = connect_db(self.db_path)
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
        conn = connect_db(self.db_path)
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
        for attempt in range(3):
            try:
                conn = connect_db(self.db_path)
                conn.execute(
                    "INSERT INTO agent_activity (timestamp, type, message, status) VALUES (?, ?, ?, ?)",
                    (time.time(), activity_type, message, status),
                )
                conn.commit()
                conn.close()
                return
            except sqlite3.OperationalError as e:
                try:
                    conn.close()
                except Exception:
                    pass
                if "locked" in str(e).lower() and attempt < 2:
                    time.sleep(0.1 * (attempt + 1))
                    continue
                print(f"[!] AgencyCore: Activity log error: {e}")
                return
            except Exception as e:
                print(f"[!] AgencyCore: Activity log error: {e}")
                return

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
