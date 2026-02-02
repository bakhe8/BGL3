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

        self._initialized = True
        print(f"[*] AgencyCore: Intelligence engine initialized at {self.root_dir}")

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
            self.safety.validate(sample_file)
            if sample_file.exists()
            else {"valid": True}
        )

        # 3. Augment failing routes with diagnostic data
        failing_routes_details = []
        for route_item in health_report.get("failing_routes", []):
            if isinstance(route_item, dict):
                route_url = route_item.get("uri") or route_item.get("url") or route_item
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
            "worst_routes": health_report.get("worst_routes", []),
            "experiences": health_report.get("recent_experiences", []),
            "gap_tests": [],
        }
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
            "vitals": vitals,
            "findings": findings,
            "execution_mode": self.execution_mode,
            "execution_stats": self._execution_stats(),
            "tool_evidence": health_report.get("tool_evidence", {}),
        }

        # Decision layer (observe-only pipeline)
        intent_payload = resolve_intent({"vitals": vitals, "findings": findings})
        diagnostic_map["findings"]["intent"] = intent_payload

        policy = self.config.get("decision", {})
        decision_payload = decide(intent_payload, policy)
        diagnostic_map["findings"]["decision"] = decision_payload

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
        """Logs a risky operation for human approval."""
        conn = sqlite3.connect(str(self.db_path))
        cursor = conn.cursor()
        cursor.execute(
            "INSERT INTO agent_permissions (operation, command, timestamp) VALUES (?, ?, ?)",
            (operation, command, time.time()),
        )
        perm_id = int(cursor.lastrowid or 0)
        conn.commit()
        conn.close()
        print(f"[!] AgencyCore: Permission requested for {operation} (ID: {perm_id})")
        return perm_id

    def is_permission_granted(self, perm_id: int) -> bool:
        """Checks if the user has approved a specific operation."""
        conn = sqlite3.connect(str(self.db_path))
        cursor = conn.cursor()
        cursor.execute("SELECT status FROM agent_permissions WHERE id = ?", (perm_id,))
        row = cursor.fetchone()
        conn.close()
        return row and row[0] == "GRANTED"

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
