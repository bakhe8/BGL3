from __future__ import annotations

import hashlib
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
        CREATE TABLE IF NOT EXISTS learning_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            fingerprint TEXT UNIQUE,
            created_at REAL NOT NULL,
            source TEXT,
            event_type TEXT,
            item_key TEXT,
            status TEXT,
            confidence REAL,
            detail_json TEXT
        )
        """
    )
    conn.execute(
        "CREATE INDEX IF NOT EXISTS idx_learning_events_time ON learning_events(created_at DESC)"
    )


def _fingerprint(*parts: str) -> str:
    base = "|".join([str(p or "").strip().lower() for p in parts])
    return hashlib.sha1(base.encode("utf-8")).hexdigest()


def _safe_json(obj: Any) -> str:
    try:
        return json.dumps(obj, ensure_ascii=False)
    except Exception:
        return "{}"


def _root_from_db(db_path: Path) -> Path:
    try:
        return db_path.parent.parent.parent
    except Exception:
        return Path(".").resolve()


def ingest_learned_events(db_path: Path, limit: int = 500) -> int:
    if not db_path.exists():
        return 0
    root = _root_from_db(db_path)
    log_path = root / "storage" / "logs" / "learned_events.tsv"
    if not log_path.exists():
        return 0
    try:
        lines = log_path.read_text(encoding="utf-8", errors="ignore").splitlines()
        if not lines:
            return 0
        lines = lines[-limit:]
        conn = _connect(db_path)
        _ensure_tables(conn)
        inserted = 0
        for line in lines:
            if "\t" not in line:
                continue
            parts = line.split("\t")
            if not parts:
                continue
            try:
                ts = float(parts[0])
            except Exception:
                ts = time.time()
            session = parts[1] if len(parts) > 1 else ""
            event_type = parts[2] if len(parts) > 2 else "learned"
            detail = parts[3] if len(parts) > 3 else ""
            item_key = f"{event_type}:{detail}" if detail else event_type
            fp = _fingerprint("learned_events_tsv", item_key, str(ts))
            payload = {"session": session, "detail": detail, "raw": line}
            try:
                conn.execute(
                    """
                    INSERT INTO learning_events
                    (fingerprint, created_at, source, event_type, item_key, status, confidence, detail_json)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    """,
                    (
                        fp,
                        ts,
                        "learned_events_tsv",
                        event_type,
                        item_key,
                        "observed",
                        None,
                        _safe_json(payload),
                    ),
                )
                inserted += 1
            except Exception:
                # likely duplicate
                continue
        conn.commit()
        conn.close()
        return inserted
    except Exception:
        return 0


def ingest_learning_confirmations(db_path: Path, limit: int = 200) -> int:
    if not db_path.exists():
        return 0
    try:
        conn = _connect(db_path)
        _ensure_tables(conn)
        rows = conn.execute(
            """
            SELECT item_key, item_type, action, notes, timestamp
            FROM learning_confirmations
            ORDER BY timestamp DESC
            LIMIT ?
            """,
            (int(limit),),
        ).fetchall()
        inserted = 0
        for r in rows:
            ts = float(r["timestamp"] or time.time())
            item_key = str(r["item_key"] or "")
            item_type = str(r["item_type"] or "")
            action = str(r["action"] or "")
            notes = str(r["notes"] or "")
            fp = _fingerprint("learning_confirmations", item_key, action, str(ts))
            payload = {"item_type": item_type, "notes": notes}
            try:
                conn.execute(
                    """
                    INSERT INTO learning_events
                    (fingerprint, created_at, source, event_type, item_key, status, confidence, detail_json)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    """,
                    (
                        fp,
                        ts,
                        "learning_confirmations",
                        "confirmation",
                        item_key,
                        action,
                        None,
                        _safe_json(payload),
                    ),
                )
                inserted += 1
            except Exception:
                continue
        conn.commit()
        conn.close()
        return inserted
    except Exception:
        return 0


def list_learning_events(db_path: Path, limit: int = 8) -> List[Dict[str, Any]]:
    if not db_path.exists():
        return []
    try:
        conn = _connect(db_path)
        _ensure_tables(conn)
        rows = conn.execute(
            "SELECT * FROM learning_events ORDER BY created_at DESC LIMIT ?",
            (int(limit),),
        ).fetchall()
        conn.close()
        out: List[Dict[str, Any]] = []
        for r in rows:
            item = dict(r)
            try:
                item["detail"] = json.loads(item.get("detail_json") or "{}")
            except Exception:
                item["detail"] = {}
            out.append(item)
        return out
    except Exception:
        return []
