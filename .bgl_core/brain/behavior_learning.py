from __future__ import annotations

import json
import time
import sqlite3
from pathlib import Path
from typing import Any, Dict, List, Optional


def _connect(db_path: Path) -> sqlite3.Connection:
    conn = sqlite3.connect(str(db_path), timeout=30.0)
    conn.row_factory = sqlite3.Row
    try:
        conn.execute("PRAGMA journal_mode=WAL;")
    except Exception:
        pass
    return conn


def _ensure_tables(conn: sqlite3.Connection) -> None:
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS behavior_hints (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          created_at REAL NOT NULL,
          updated_at REAL NOT NULL,
          last_seen REAL,
          page_url TEXT,
          action TEXT,
          selector TEXT,
          hint TEXT,
          confidence REAL DEFAULT 0.5,
          success_count INTEGER DEFAULT 0,
          fail_count INTEGER DEFAULT 0,
          notes TEXT
        )
        """
    )
    conn.execute(
        "CREATE INDEX IF NOT EXISTS idx_behavior_hints_lookup ON behavior_hints(page_url, action, selector, hint)"
    )


def record_behavior_hint(
    db_path: Path,
    *,
    page_url: str,
    action: str,
    selector: str,
    hint: str,
    confidence: float = 0.5,
    notes: str = "",
) -> Optional[int]:
    if not db_path or not db_path.exists():
        return None
    page_url = (page_url or "").strip()
    action = (action or "").strip().lower()
    selector = (selector or "").strip()
    hint = (hint or "").strip().lower()
    if not action or not hint:
        return None
    now = time.time()
    try:
        conn = _connect(db_path)
        _ensure_tables(conn)
        row = conn.execute(
            """
            SELECT id, confidence, fail_count
            FROM behavior_hints
            WHERE page_url=? AND action=? AND selector=? AND hint=?
            """,
            (page_url, action, selector, hint),
        ).fetchone()
        if row:
            new_conf = float(row["confidence"] or 0.5)
            # decay slightly when re-logging same hint as "needed"
            new_conf = max(0.1, new_conf - 0.02)
            conn.execute(
                """
                UPDATE behavior_hints
                SET updated_at=?, last_seen=?, fail_count=?, confidence=?, notes=?
                WHERE id=?
                """,
                (
                    now,
                    now,
                    int(row["fail_count"] or 0) + 1,
                    new_conf,
                    notes,
                    int(row["id"]),
                ),
            )
            conn.commit()
            conn.close()
            return int(row["id"])
        conn.execute(
            """
            INSERT INTO behavior_hints
            (created_at, updated_at, last_seen, page_url, action, selector, hint, confidence, success_count, fail_count, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 1, ?)
            """,
            (
                now,
                now,
                now,
                page_url,
                action,
                selector,
                hint,
                float(confidence),
                notes,
            ),
        )
        hint_id = conn.execute("SELECT last_insert_rowid()").fetchone()[0]
        conn.commit()
        conn.close()
        return int(hint_id or 0)
    except Exception:
        return None


def get_behavior_hints(
    db_path: Path,
    *,
    page_url: str,
    action: str,
    selector: str,
    limit: int = 5,
) -> List[Dict[str, Any]]:
    if not db_path or not db_path.exists():
        return []
    page_url = (page_url or "").strip()
    action = (action or "").strip().lower()
    selector = (selector or "").strip()
    try:
        conn = _connect(db_path)
        _ensure_tables(conn)
        rows = conn.execute(
            """
            SELECT *
            FROM behavior_hints
            WHERE page_url=? AND action=? AND selector=?
            ORDER BY confidence DESC, updated_at DESC
            LIMIT ?
            """,
            (page_url, action, selector, int(limit)),
        ).fetchall()
        conn.close()
        return [dict(r) for r in rows]
    except Exception:
        return []


def mark_hint_result(db_path: Path, hint_id: int, success: bool) -> None:
    if not db_path or not db_path.exists() or not hint_id:
        return
    try:
        conn = _connect(db_path)
        _ensure_tables(conn)
        row = conn.execute(
            "SELECT confidence, success_count, fail_count FROM behavior_hints WHERE id=?",
            (int(hint_id),),
        ).fetchone()
        if not row:
            conn.close()
            return
        conf = float(row["confidence"] or 0.5)
        if success:
            conf = min(0.95, conf + 0.05)
            succ = int(row["success_count"] or 0) + 1
            fail = int(row["fail_count"] or 0)
        else:
            conf = max(0.1, conf - 0.03)
            succ = int(row["success_count"] or 0)
            fail = int(row["fail_count"] or 0) + 1
        conn.execute(
            """
            UPDATE behavior_hints
            SET updated_at=?, last_seen=?, confidence=?, success_count=?, fail_count=?
            WHERE id=?
            """,
            (time.time(), time.time(), conf, succ, fail, int(hint_id)),
        )
        conn.commit()
        conn.close()
    except Exception:
        return

