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
        "agent_runs": ["id", "run_id", "started_at"],
        "entities": ["id", "file_id", "name", "type"],
        "methods": ["id", "entity_id", "name"],
        "routes": ["id", "uri", "http_method"],
        "runtime_events": ["id", "timestamp", "event_type", "run_id", "source", "step_id"],
        "experiences": ["id", "created_at", "scenario", "summary"],
        "agent_proposals": ["id", "name", "action"],
        "decisions": ["id", "intent_id", "decision"],
        "outcomes": ["id", "decision_id", "result"],
        "env_snapshots": ["id", "created_at", "run_id", "kind"],
        "ui_semantic_snapshots": ["id", "created_at", "url"],
        "proposal_outcome_links": ["id", "proposal_id", "decision_id"],
        "learning_events": ["id", "fingerprint", "created_at"],
        "knowledge_items": ["id", "key", "source_path"],
        "knowledge_conflicts": ["id", "key", "created_at"],
        "learning_feedback": ["id", "created_at", "signal"],
        "long_term_goals": ["id", "goal_key", "priority", "status"],
        "long_term_goal_events": ["id", "goal_key", "created_at"],
        "canary_releases": ["id", "release_id", "status"],
        "canary_release_events": ["id", "release_id", "created_at"],
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
            # Soft-migrate runtime_events columns if missing
            if table == "runtime_events":
                try:
                    if "run_id" not in existing_cols:
                        conn.execute("ALTER TABLE runtime_events ADD COLUMN run_id TEXT")
                        existing_cols.add("run_id")
                    if "source" not in existing_cols:
                        conn.execute("ALTER TABLE runtime_events ADD COLUMN source TEXT")
                        existing_cols.add("source")
                    if "step_id" not in existing_cols:
                        conn.execute("ALTER TABLE runtime_events ADD COLUMN step_id TEXT")
                        existing_cols.add("step_id")
                except Exception:
                    pass
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
