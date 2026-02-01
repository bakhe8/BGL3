import sqlite3
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
        return cur.lastrowid


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
        return cur.lastrowid


def insert_outcome(
    db_path: Path, decision_id: int, result: str, notes: str = "", backup_path: str = ""
):
    conn = _connect(db_path)
    with conn:
        conn.execute(
            """
            INSERT INTO outcomes (decision_id, result, notes, backup_path, timestamp)
            VALUES (?, ?, ?, ?, datetime('now'))
            """,
            (decision_id, result, notes, backup_path),
        )
