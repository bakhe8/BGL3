from __future__ import annotations

import sqlite3
from pathlib import Path
from typing import Dict, List, Any


def _table_columns(conn: sqlite3.Connection, table: str) -> List[str]:
    try:
        rows = conn.execute(f"PRAGMA table_info({table})").fetchall()
        return [r[1] for r in rows] if rows else []
    except Exception:
        return []


def check_schema(db_path: Path) -> Dict[str, Any]:
    """
    Lightweight schema drift check for knowledge.db.
    Returns missing tables/columns for diagnostics.
    """
    required = {
        "entities": ["id", "file_id", "name", "type"],
        "methods": ["id", "entity_id", "name"],
        "routes": ["id", "uri", "http_method"],
        "runtime_events": ["id", "timestamp", "event_type"],
        "experiences": ["id", "created_at", "scenario", "summary"],
        "agent_proposals": ["id", "name", "action"],
        "decisions": ["id", "intent_id", "decision"],
        "outcomes": ["id", "decision_id", "result"],
        "env_snapshots": ["id", "created_at", "run_id", "kind"],
        "ui_semantic_snapshots": ["id", "created_at", "url"],
        "proposal_outcome_links": ["id", "proposal_id", "decision_id"],
        "learning_events": ["id", "fingerprint", "created_at"],
    }
    result: Dict[str, Any] = {"ok": True, "missing_tables": [], "missing_columns": {}}
    if not db_path.exists():
        return {
            "ok": False,
            "missing_tables": list(required.keys()),
            "missing_columns": {},
            "error": "db_missing",
        }
    try:
        conn = sqlite3.connect(str(db_path))
        conn.row_factory = sqlite3.Row
        existing = {
            row[0] for row in conn.execute("SELECT name FROM sqlite_master WHERE type='table'")
        }
        for table, cols in required.items():
            if table not in existing:
                result["missing_tables"].append(table)
                continue
            existing_cols = set(_table_columns(conn, table))
            missing = [c for c in cols if c not in existing_cols]
            if missing:
                result["missing_columns"][table] = missing
        conn.close()
    except Exception as exc:
        result["ok"] = False
        result["error"] = str(exc)
        return result
    if result["missing_tables"] or result["missing_columns"]:
        result["ok"] = False
    return result

