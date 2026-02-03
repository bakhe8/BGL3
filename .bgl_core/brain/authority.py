from __future__ import annotations

"""
authority.py
------------
Single source of truth for:
- action taxonomy (side-effect classification),
- human approval queue (agent_permissions),
- decision logging (intents/decisions/outcomes in knowledge.db),
- deterministic gating for WRITE_* actions.

Design goals:
- Keep exploration flexible: OBSERVE/PROBE/PROPOSE should not be blocked.
- Make execution trustworthy: any WRITE_* requires explicit approval by default.
- Avoid drift: other modules should delegate here instead of re-implementing gates.
"""

import json
import os
import sqlite3
import time
from pathlib import Path
from typing import Any, Dict, Optional, List

try:
    from .brain_types import ActionRequest, ActionKind, GateResult  # type: ignore
    from .config_loader import load_config  # type: ignore
    from .decision_db import init_db, insert_intent, insert_decision, insert_outcome  # type: ignore
    from .decision_engine import decide  # type: ignore
except Exception:
    from brain_types import ActionRequest, ActionKind, GateResult
    from config_loader import load_config
    from decision_db import init_db, insert_intent, insert_decision, insert_outcome
    from decision_engine import decide


def _ensure_agent_permissions_table(conn: sqlite3.Connection):
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS agent_permissions (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          operation TEXT,
          command TEXT,
          status TEXT DEFAULT 'PENDING',
          timestamp REAL
        )
        """
    )
    conn.commit()


def _safe_json(val: Any) -> str:
    try:
        return json.dumps(val, ensure_ascii=False)
    except Exception:
        return "{}"


class Authority:
    def __init__(self, root_dir: Path):
        self.root_dir = root_dir
        self.config = load_config(root_dir) or {}
        self.db_path = root_dir / ".bgl_core" / "brain" / "knowledge.db"
        self.decision_schema = root_dir / ".bgl_core" / "brain" / "decision_schema.sql"
        if self.decision_schema.exists():
            init_db(self.db_path, self.decision_schema)
        # execution_mode: sandbox | direct | auto_trial (historical)
        self.execution_mode = str(self.config.get("execution_mode", "sandbox")).lower()

    def _eligible_for_direct(self, required_successes: int = 5) -> bool:
        """
        Auto-trial policy: allow "direct" mode only after N consecutive successes.
        Matches the historical behavior used by patcher/guardian.
        """
        if not self.db_path.exists():
            return False
        try:
            conn = sqlite3.connect(str(self.db_path))
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

    def effective_execution_mode(self) -> str:
        """
        Compute effective execution mode:
        - sandbox: never allow WRITE_PROD
        - direct: allow WRITE_PROD with explicit approval
        - auto_trial: becomes direct only when eligible; otherwise sandbox
        """
        if self.execution_mode == "auto_trial":
            return "direct" if self._eligible_for_direct() else "sandbox"
        return self.execution_mode

    # ---- permission queue (compatibility) ----

    def dedupe_permissions(self):
        """Keep only the latest PENDING permission per operation to avoid queue spam."""
        if not self.db_path.exists():
            return
        conn = sqlite3.connect(str(self.db_path))
        try:
            _ensure_agent_permissions_table(conn)
            cur = conn.cursor()
            cur.execute(
                """
                DELETE FROM agent_permissions
                WHERE status='PENDING'
                  AND id NOT IN (
                    SELECT MAX(id) FROM agent_permissions
                    WHERE status='PENDING'
                    GROUP BY operation
                  )
                """
            )
            conn.commit()
        finally:
            conn.close()

    def has_permission(self, operation: str) -> bool:
        if not self.db_path.exists():
            return False
        conn = sqlite3.connect(str(self.db_path))
        try:
            _ensure_agent_permissions_table(conn)
            cur = conn.cursor()
            row = cur.execute(
                "SELECT status FROM agent_permissions WHERE operation=? ORDER BY id DESC LIMIT 1",
                (operation,),
            ).fetchone()
            return bool(row and row[0] == "GRANTED")
        finally:
            conn.close()

    def request_permission(self, operation: str, command: str) -> int:
        """
        Upserts a PENDING permission request and returns its id.
        """
        if not self.db_path.exists():
            self.db_path.parent.mkdir(parents=True, exist_ok=True)
        conn = sqlite3.connect(str(self.db_path))
        try:
            _ensure_agent_permissions_table(conn)
            cur = conn.cursor()
            row = cur.execute(
                "SELECT id, status FROM agent_permissions WHERE operation=? ORDER BY id DESC LIMIT 1",
                (operation,),
            ).fetchone()
            if row and row[1] == "PENDING":
                cur.execute(
                    "UPDATE agent_permissions SET timestamp=? WHERE id=?",
                    (time.time(), row[0]),
                )
                perm_id = int(row[0])
            else:
                cur.execute(
                    "INSERT INTO agent_permissions (operation, command, status, timestamp) VALUES (?, ?, 'PENDING', ?)",
                    (operation, command, time.time()),
                )
                perm_id = int(cur.lastrowid or 0)
            conn.commit()
            return perm_id
        finally:
            conn.close()

    def permission_status(self, perm_id: int) -> Optional[str]:
        """Return the status for a permission id (GRANTED/PENDING/REJECTED/...)."""
        if not self.db_path.exists():
            return None
        conn = sqlite3.connect(str(self.db_path))
        try:
            _ensure_agent_permissions_table(conn)
            cur = conn.cursor()
            row = cur.execute(
                "SELECT status FROM agent_permissions WHERE id=?",
                (int(perm_id),),
            ).fetchone()
            return str(row[0]) if row else None
        finally:
            conn.close()

    def is_permission_granted(self, perm_id: int) -> bool:
        return self.permission_status(perm_id) == "GRANTED"

    # ---- decision logging ----

    def _log_decision(
        self,
        request: ActionRequest,
        decision_payload: Dict[str, Any],
        source: str,
    ) -> GateResult:
        """
        Log intent + decision rows and return a GateResult with linkage ids.
        """
        intent_payload: Dict[str, Any] = {
            "intent": request.operation,
            "confidence": float(request.confidence or 0.5),
            "reason": request.reason or request.command or request.operation,
            "scope": request.scope,
            "context_snapshot": {
                "action_kind": request.kind.value,
                "operation": request.operation,
                "command": request.command,
                "metadata": request.metadata,
                "execution_mode": self.execution_mode,
            },
        }

        intent_id = insert_intent(
            self.db_path,
            str(intent_payload["intent"]),
            float(intent_payload["confidence"]),
            str(intent_payload["reason"]),
            _safe_json(intent_payload.get("scope", [])),
            _safe_json(intent_payload.get("context_snapshot", {})),
            source=source,
        )

        decision_id = insert_decision(
            self.db_path,
            intent_id,
            str(decision_payload.get("decision", "observe")),
            str(decision_payload.get("risk_level", "low")),
            bool(decision_payload.get("requires_human", False)),
            "; ".join(list(decision_payload.get("justification", []))[:10]),
        )

        return GateResult(
            allowed=False,
            requires_human=bool(decision_payload.get("requires_human", False)),
            intent_id=int(intent_id or 0),
            decision_id=int(decision_id or 0),
            decision=str(decision_payload.get("decision", "")),
            risk_level=str(decision_payload.get("risk_level", "low")),
            justification=list(decision_payload.get("justification", []))[:10],
        )

    def record_outcome(
        self, decision_id: int, result: str, notes: str = "", backup_path: str = ""
    ):
        try:
            insert_outcome(self.db_path, decision_id, result, notes, backup_path=backup_path)
        except Exception:
            # Never crash the caller because of audit logging.
            pass

    # ---- main gate ----

    def gate(self, request: ActionRequest, source: str = "authority") -> GateResult:
        """
        Gate an action.
        - OBSERVE/PROBE/PROPOSE: allowed immediately.
        - WRITE_*: requires explicit approval unless explicitly overridden by env/config.
        """
        # Provide decision payload (risk/justification). We treat it as advisory for writes.
        policy = self.config.get("decision", {})
        try:
            decision_payload = decide(
                {
                    "intent": request.operation,
                    "confidence": request.confidence,
                    "reason": request.reason,
                    "scope": request.scope,
                    "metadata": request.metadata,
                },
                policy,
            )
        except Exception:
            decision_payload = {
                "decision": "observe",
                "risk_level": "medium",
                "requires_human": True if request.kind in (ActionKind.WRITE_SANDBOX, ActionKind.WRITE_PROD) else False,
                "justification": ["decision_engine failed; using deterministic gate"],
                "maturity": "experimental",
            }

        # Deterministic override: any WRITE_* requires human approval by default.
        write_action = request.kind in (ActionKind.WRITE_SANDBOX, ActionKind.WRITE_PROD)

        effective_mode = self.effective_execution_mode()

        if write_action:
            # Allow limited policy overrides for sandbox writes (internal maintenance).
            # WRITE_PROD stays strictly human-gated.
            requires_human = True
            if request.kind == ActionKind.WRITE_SANDBOX:
                policy_key = None
                try:
                    policy_key = request.metadata.get("policy_key")
                except Exception:
                    policy_key = None
                if policy_key:
                    cfg_block = policy.get(str(policy_key))
                    if isinstance(cfg_block, dict) and "requires_human" in cfg_block:
                        requires_human = bool(cfg_block.get("requires_human", True))

            decision_payload["requires_human"] = requires_human
            gate_res = self._log_decision(request, decision_payload, source=source)

            if requires_human:
                permitted = self.has_permission(request.operation)
                if not permitted:
                    perm_id = self.request_permission(
                        request.operation,
                        request.command or request.reason or request.operation,
                    )
                    gate_res.permission_id = perm_id
                    gate_res.allowed = False
                    gate_res.requires_human = True
                    gate_res.message = f"Pending human approval for {request.operation} (permission_id={perm_id})."
                    self.record_outcome(
                        gate_res.decision_id or 0,
                        "blocked",
                        "Awaiting human approval",
                    )
                    return gate_res

            # Permission granted: now decide if config/env allows this category.
            if request.kind == ActionKind.WRITE_PROD and effective_mode != "direct":
                gate_res.allowed = False
                gate_res.requires_human = True
                gate_res.message = f"WRITE_PROD blocked (execution_mode={effective_mode})."
                self.record_outcome(gate_res.decision_id or 0, "blocked", gate_res.message)
                return gate_res

            gate_res.allowed = True
            gate_res.requires_human = False
            gate_res.message = "Approved and allowed."
            return gate_res

        # Non-write actions: allow immediately.
        decision_payload["requires_human"] = False
        gate_res = self._log_decision(request, decision_payload, source=source)
        gate_res.allowed = True
        gate_res.requires_human = False
        gate_res.message = "Allowed (non-write action)."
        return gate_res
