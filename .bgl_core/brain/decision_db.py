import sqlite3
import os
from pathlib import Path


def init_db(db_path: Path, schema_path: Path):
    db_path.parent.mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(str(db_path))
    with conn:
        schema_sql = schema_path.read_text(encoding="utf-8")
        conn.executescript(schema_sql)
    conn.close()


def _connect(db_path: Path):
    return sqlite3.connect(str(db_path))


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
