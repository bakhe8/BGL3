from __future__ import annotations

"""
volition.py
-----------
Capture the agent's "will" as a lightweight, non-executing preference.
This is NOT a policy, not a decision, and not a gate. It is a statement of
focus that can be observed over time.
"""

import json
import sqlite3
import time
from pathlib import Path
from typing import Any, Dict, Optional

try:
    from .llm_client import LLMClient  # type: ignore
except Exception:
    from llm_client import LLMClient


def _ensure_table(conn: sqlite3.Connection) -> None:
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS volitions (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          created_at REAL NOT NULL,
          run_id TEXT NOT NULL,
          source TEXT,
          volition TEXT NOT NULL,
          confidence REAL,
          payload_json TEXT
        )
        """
    )
    conn.execute(
        "CREATE INDEX IF NOT EXISTS idx_volitions_time ON volitions(created_at DESC)"
    )
    conn.commit()


def store_volition(
    db_path: Path,
    *,
    run_id: str,
    volition: str,
    confidence: float = 0.5,
    source: str = "llm",
    payload: Optional[Dict[str, Any]] = None,
    created_at: Optional[float] = None,
) -> None:
    created_at = float(created_at if created_at is not None else time.time())
    with sqlite3.connect(str(db_path)) as conn:
        _ensure_table(conn)
        conn.execute(
            """
            INSERT INTO volitions (created_at, run_id, source, volition, confidence, payload_json)
            VALUES (?, ?, ?, ?, ?, ?)
            """,
            (
                created_at,
                run_id,
                source,
                volition,
                float(confidence or 0),
                json.dumps(payload or {}, ensure_ascii=False),
            ),
        )
        conn.commit()


def latest_volition(db_path: Path) -> Optional[Dict[str, Any]]:
    with sqlite3.connect(str(db_path)) as conn:
        conn.row_factory = sqlite3.Row
        _ensure_table(conn)
        row = conn.execute(
            "SELECT * FROM volitions ORDER BY created_at DESC LIMIT 1"
        ).fetchone()
        if not row:
            return None
        payload = {}
        try:
            payload = json.loads(row["payload_json"] or "{}")
        except Exception:
            payload = {"raw": row["payload_json"]}
        return {
            "id": row["id"],
            "created_at": row["created_at"],
            "run_id": row["run_id"],
            "source": row["source"],
            "volition": row["volition"],
            "confidence": row["confidence"],
            "payload": payload,
        }


def derive_volition(diagnostic_map: Dict[str, Any]) -> Dict[str, Any]:
    """
    Produce a lightweight volition string from the current diagnostic context.
    This does NOT trigger execution; it's a statement of focus.
    """
    findings = diagnostic_map.get("findings") or {}
    vitals = diagnostic_map.get("vitals") or {}
    readiness = diagnostic_map.get("readiness") or {}
    signals = findings.get("signals") or {}
    route_health = {
        "failing": len(findings.get("failing_routes") or []),
        "worst": len(findings.get("worst_routes") or []),
    }
    context = {
        "vitals": vitals,
        "readiness": readiness,
        "signals": signals,
        "route_health": route_health,
        "execution_mode": diagnostic_map.get("execution_mode"),
    }

    prompt = f"""
You are the BGL3 agent. Choose ONE thing you *want* to focus on next.
This is a statement of will, not a decision or plan. Keep it short.
Output JSON only:
{{
  "volition": "short present-tense focus",
  "confidence": 0.0-1.0
}}

Context:
{json.dumps(context, ensure_ascii=False, indent=2)}
"""

    try:
        client = LLMClient()
        data = client.chat_json(prompt, temperature=0.2)
        vol = str(data.get("volition") or "").strip()
        conf = float(data.get("confidence") or 0.5)
        if not vol:
            raise ValueError("volition missing")
        return {"volition": vol, "confidence": conf, "source": "llm", "context": context}
    except Exception:
        # Minimal fallback: use signals to express a focus without heavy policy.
        counts = (signals.get("counts") or {}) if isinstance(signals, dict) else {}
        if counts.get("actionable_failures", 0) > 0:
            vol = "reduce actionable failures"
        elif counts.get("warnings", 0) > 0:
            vol = "stabilize warnings and observe changes"
        else:
            vol = "expand understanding of system behavior"
        return {"volition": vol, "confidence": 0.55, "source": "fallback", "context": context}

