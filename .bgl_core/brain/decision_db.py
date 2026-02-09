import sqlite3
import os
import json
import time
from pathlib import Path
from typing import Any, Dict, Optional
try:
    from .db_utils import connect_db  # type: ignore
except Exception:
    from db_utils import connect_db  # type: ignore


def init_db(db_path: Path, schema_path: Path):
    db_path.parent.mkdir(parents=True, exist_ok=True)
    conn = connect_db(db_path, timeout=30.0)
    with conn:
        schema_sql = schema_path.read_text(encoding="utf-8")
        conn.executescript(schema_sql)
    conn.close()


def _connect(db_path: Path):
    return connect_db(db_path, timeout=30.0)


def _ensure_outcome_columns(conn: sqlite3.Connection) -> None:
    try:
        cols = {r[1] for r in conn.execute("PRAGMA table_info(outcomes)").fetchall()}
    except Exception:
        cols = set()
    try:
        if "run_id" not in cols:
            conn.execute("ALTER TABLE outcomes ADD COLUMN run_id TEXT")
        if "scenario_id" not in cols:
            conn.execute("ALTER TABLE outcomes ADD COLUMN scenario_id TEXT")
        if "goal_id" not in cols:
            conn.execute("ALTER TABLE outcomes ADD COLUMN goal_id TEXT")
    except Exception:
        pass


def _ensure_decision_trace(conn: sqlite3.Connection) -> None:
    try:
        conn.execute(
            """
            CREATE TABLE IF NOT EXISTS decision_traces (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at REAL NOT NULL,
                kind TEXT,
                decision_id INTEGER,
                outcome_id INTEGER,
                intent_id INTEGER,
                operation TEXT,
                risk_level TEXT,
                result TEXT,
                failure_class TEXT,
                source TEXT,
                run_id TEXT,
                scenario_id TEXT,
                goal_id TEXT,
                details_json TEXT
            )
            """
        )
        conn.execute(
            "CREATE INDEX IF NOT EXISTS idx_decision_traces_decision ON decision_traces(decision_id, created_at DESC)"
        )
        conn.execute(
            "CREATE INDEX IF NOT EXISTS idx_decision_traces_outcome ON decision_traces(outcome_id, created_at DESC)"
        )
    except Exception:
        pass


def _safe_json(obj: Any) -> str:
    try:
        return json.dumps(obj, ensure_ascii=False)
    except Exception:
        return "{}"


def record_decision_trace(
    db_path: Path,
    *,
    kind: str,
    decision_id: int,
    outcome_id: Optional[int] = None,
    intent_id: Optional[int] = None,
    operation: str = "",
    risk_level: str = "",
    result: str = "",
    failure_class: str = "",
    source: str = "agent",
    details: Optional[Dict[str, Any]] = None,
    run_id: Optional[str] = None,
    scenario_id: Optional[str] = None,
    goal_id: Optional[str] = None,
) -> None:
    if not db_path:
        return
    conn = _connect(db_path)
    with conn:
        _ensure_decision_trace(conn)
        try:
            if decision_id and (not operation or not risk_level or not intent_id):
                row = conn.execute(
                    """
                    SELECT d.intent_id, d.risk_level, i.intent
                    FROM decisions d
                    JOIN intents i ON i.id = d.intent_id
                    WHERE d.id = ?
                    """,
                    (int(decision_id),),
                ).fetchone()
                if row:
                    if not intent_id:
                        intent_id = int(row[0] or 0)
                    if not risk_level:
                        risk_level = str(row[1] or "")
                    if not operation:
                        operation = str(row[2] or "")
        except Exception:
            pass
        if run_id is None:
            run_id = os.getenv("BGL_RUN_ID") or ""
        if scenario_id is None:
            scenario_id = os.getenv("BGL_SCENARIO_ID") or ""
        if goal_id is None:
            goal_id = os.getenv("BGL_GOAL_ID") or ""
        try:
            conn.execute(
                """
                INSERT INTO decision_traces
                (created_at, kind, decision_id, outcome_id, intent_id, operation, risk_level, result, failure_class, source, run_id, scenario_id, goal_id, details_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    time.time(),
                    str(kind or ""),
                    int(decision_id or 0),
                    int(outcome_id or 0),
                    int(intent_id or 0),
                    str(operation or ""),
                    str(risk_level or ""),
                    str(result or ""),
                    str(failure_class or ""),
                    str(source or "agent"),
                    str(run_id or ""),
                    str(scenario_id or ""),
                    str(goal_id or ""),
                    _safe_json(details or {}),
                ),
            )
        except Exception:
            pass


def insert_intent(
    db_path: Path,
    intent: str,
    confidence: float,
    reason: str,
    scope: str,
    context_snapshot: str,
    source: str = "agent",
) -> int:
    conn = _connect(db_path)
    with conn:
        cur = conn.execute(
            """
            INSERT INTO intents (timestamp, intent, confidence, reason, scope, context_snapshot, source)
            VALUES (datetime('now'), ?, ?, ?, ?, ?, ?)
            """,
            (intent, confidence, reason, scope, context_snapshot, source),
        )
        intent_id = cur.lastrowid
    return intent_id


def insert_decision(
    db_path: Path,
    intent_id: int,
    decision: str,
    risk_level: str,
    requires_human: bool,
    justification: str,
):
    conn = _connect(db_path)
    with conn:
        cur = conn.execute(
            """
            INSERT INTO decisions (intent_id, decision, risk_level, requires_human, justification, created_at)
            VALUES (?, ?, ?, ?, ?, datetime('now'))
            """,
            (intent_id, decision, risk_level, int(requires_human), justification),
        )
        decision_id = cur.lastrowid
    return decision_id


def insert_outcome(
    db_path: Path, decision_id: int, result: str, notes: str = "", backup_path: str = ""
):
    conn = _connect(db_path)
    with conn:
        _ensure_outcome_columns(conn)
        cur = conn.execute(
            """
            INSERT INTO outcomes (decision_id, result, notes, backup_path, timestamp)
            VALUES (?, ?, ?, ?, datetime('now'))
            """,
            (decision_id, result, notes, backup_path),
        )
        outcome_id = cur.lastrowid
        try:
            run_id = os.getenv("BGL_RUN_ID") or ""
            scenario_id = os.getenv("BGL_SCENARIO_ID") or ""
            goal_id = os.getenv("BGL_GOAL_ID") or ""
            if run_id or scenario_id or goal_id:
                conn.execute(
                    "UPDATE outcomes SET run_id=?, scenario_id=?, goal_id=? WHERE id=?",
                    (run_id, scenario_id, goal_id, int(outcome_id or 0)),
                )
        except Exception:
            pass
        return outcome_id
