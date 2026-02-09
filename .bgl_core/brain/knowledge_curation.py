from __future__ import annotations

import hashlib
import json
import os
import sqlite3
import time
from pathlib import Path
from typing import Any, Dict, Iterable, List, Optional, Tuple

try:
    from .auto_insights import should_include_insight  # type: ignore
except Exception:
    from auto_insights import should_include_insight  # type: ignore


ALLOWED_EXTENSIONS = {".md", ".txt", ".json", ".yml", ".yaml"}


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
        CREATE TABLE IF NOT EXISTS knowledge_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            key TEXT NOT NULL,
            source_path TEXT NOT NULL,
            source_type TEXT,
            status TEXT,
            confidence REAL,
            mtime REAL,
            fingerprint TEXT UNIQUE,
            notes TEXT,
            created_at REAL,
            updated_at REAL
        )
        """
    )
    conn.execute(
        "CREATE INDEX IF NOT EXISTS idx_knowledge_items_key ON knowledge_items(key, status)"
    )
    conn.execute(
        "CREATE INDEX IF NOT EXISTS idx_knowledge_items_path ON knowledge_items(source_path)"
    )
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS knowledge_conflicts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            key TEXT NOT NULL,
            created_at REAL NOT NULL,
            winner_path TEXT,
            candidates_json TEXT,
            reason TEXT
        )
        """
    )
    conn.execute(
        "CREATE INDEX IF NOT EXISTS idx_knowledge_conflicts_key ON knowledge_conflicts(key, created_at DESC)"
    )


def _ensure_conflict_history_table(conn: sqlite3.Connection) -> None:
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS knowledge_conflict_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            key TEXT NOT NULL,
            winner_path TEXT,
            created_at REAL NOT NULL,
            reason TEXT
        )
        """
    )
    conn.execute(
        "CREATE INDEX IF NOT EXISTS idx_knowledge_conflict_history_key ON knowledge_conflict_history(key, created_at DESC)"
    )


def _load_conflict_history(conn: sqlite3.Connection, cutoff: float) -> Dict[str, Dict[str, int]]:
    history: Dict[str, Dict[str, int]] = {}
    try:
        rows = conn.execute(
            "SELECT key, winner_path FROM knowledge_conflict_history WHERE created_at >= ?",
            (float(cutoff),),
        ).fetchall()
    except Exception:
        return history
    for row in rows:
        key = str(row[0] or "")
        winner = str(row[1] or "")
        if not key or not winner:
            continue
        history.setdefault(key, {})
        history[key][winner] = history[key].get(winner, 0) + 1
    return history


def _extract_meta(content: str) -> Tuple[Optional[str], Optional[str]]:
    try:
        import re

        path_match = re.search(r"\*\*Path\*\*: `(.+?)`", content or "")
        hash_match = re.search(r"\*\*Source-Hash\*\*: ([a-f0-9]{64})", content or "")
        source_rel = path_match.group(1) if path_match else None
        stored_hash = hash_match.group(1) if hash_match else None
        return source_rel, stored_hash
    except Exception:
        return None, None


def _fingerprint(*parts: str) -> str:
    base = "|".join([str(p or "").strip().lower() for p in parts])
    return hashlib.sha1(base.encode("utf-8")).hexdigest()


def _rel_path(root_dir: Path, path: Path) -> str:
    try:
        return path.resolve().relative_to(root_dir.resolve()).as_posix()
    except Exception:
        return path.as_posix()


def _iter_files(root_dir: Path) -> Iterable[Tuple[Path, str]]:
    bases = [root_dir / ".bgl_core" / "knowledge", root_dir / "docs"]
    for base in bases:
        if not base.exists():
            continue
        for p in base.rglob("*"):
            if not p.is_file():
                continue
            if p.suffix.lower() not in ALLOWED_EXTENSIONS:
                continue
            yield p, ("knowledge" if base.name == "knowledge" else "docs")


def _item_from_path(
    root_dir: Path, path: Path, bucket: str
) -> Dict[str, Any]:
    rel = _rel_path(root_dir, path)
    source_type = "docs" if bucket == "docs" else "knowledge"
    status = "active"
    confidence = 0.6 if source_type == "docs" else 0.7
    notes = ""

    if "auto_insights" in rel.replace("\\", "/") and path.name.endswith(".insight.md"):
        source_type = "auto_insight"
        try:
            ok, reason = should_include_insight(path, root_dir, allow_legacy=True)
        except Exception:
            ok, reason = True, "ok"
        meta_path = None
        meta_hash = None
        try:
            content = path.read_text(encoding="utf-8", errors="ignore")
            meta_path, meta_hash = _extract_meta(content)
        except Exception:
            meta_path, meta_hash = None, None
        if meta_path:
            key = meta_path
        else:
            key = rel
        if ok and not meta_path:
            status = "legacy"
            confidence = 0.6
        elif ok:
            status = "active"
            confidence = 0.9
        else:
            status = reason
            confidence = 0.2 if reason in ("stale", "missing_source", "expired") else 0.3
        notes = f"meta_path={meta_path or ''} meta_hash={'yes' if meta_hash else 'no'}"
    else:
        key = rel

    try:
        mtime = float(path.stat().st_mtime)
    except Exception:
        mtime = 0.0

    # Age-based decay (applies to all knowledge sources)
    try:
        max_age_days = int(os.getenv("BGL_KNOWLEDGE_MAX_AGE_DAYS", "120") or 120)
    except Exception:
        max_age_days = 120
    if max_age_days > 0 and mtime > 0:
        age_days = (time.time() - mtime) / 86400.0
        if age_days > max_age_days and status in ("active", "legacy"):
            status = "stale_age"
            confidence = max(0.1, confidence - 0.2)
            notes = f"{notes} age_days={int(age_days)}".strip()
        try:
            decay_days = int(os.getenv("BGL_KNOWLEDGE_DECAY_DAYS", "45") or 45)
        except Exception:
            decay_days = 45
        if decay_days > 0 and age_days > decay_days:
            decay = min(0.35, (age_days / float(decay_days)) * 0.05)
            confidence = max(0.1, confidence - decay)
            notes = f"{notes} decay={round(decay, 2)}".strip()
        try:
            boost_days = int(os.getenv("BGL_KNOWLEDGE_RECENT_BOOST_DAYS", "2") or 2)
        except Exception:
            boost_days = 2
        if boost_days > 0 and age_days <= boost_days:
            confidence = min(0.98, confidence + 0.05)

    return {
        "key": str(key),
        "source_path": rel,
        "source_type": source_type,
        "status": status,
        "confidence": float(confidence),
        "mtime": float(mtime or 0),
        "notes": notes.strip(),
    }


def _rank_item(item: Dict[str, Any]) -> Tuple[int, float, int, float]:
    status = str(item.get("status") or "")
    active_flag = 1 if status in ("active", "legacy") else 0
    conf = float(item.get("confidence") or 0)
    src = str(item.get("source_type") or "")
    src_rank = 1
    if src == "auto_insight":
        src_rank = 3
    elif src == "knowledge":
        src_rank = 2
    try:
        mtime = float(item.get("mtime") or 0)
    except Exception:
        mtime = 0.0
    return (active_flag, conf, src_rank, mtime)


def curate_knowledge(root_dir: Path, db_path: Path) -> Dict[str, Any]:
    if not db_path.exists():
        return {"ok": False, "error": "db_missing"}
    items: List[Dict[str, Any]] = []
    for path, bucket in _iter_files(root_dir):
        items.append(_item_from_path(root_dir, path, bucket))

    grouped: Dict[str, List[Dict[str, Any]]] = {}
    for item in items:
        grouped.setdefault(str(item.get("key") or ""), []).append(item)

    history_counts: Dict[str, Dict[str, int]] = {}
    try:
        history_days = int(os.getenv("BGL_KNOWLEDGE_CONFLICT_HISTORY_DAYS", "30") or 30)
    except Exception:
        history_days = 30
    history_cutoff = time.time() - (history_days * 86400.0)
    try:
        stable_count = int(os.getenv("BGL_KNOWLEDGE_CONFLICT_STABLE_COUNT", "2") or 2)
    except Exception:
        stable_count = 2

    conn = None
    try:
        conn = _connect(db_path)
        _ensure_tables(conn)
        _ensure_conflict_history_table(conn)
        history_counts = _load_conflict_history(conn, history_cutoff)
    except Exception:
        history_counts = {}

    conflicts: List[Dict[str, Any]] = []
    for key, group in grouped.items():
        if len(group) <= 1:
            continue
        ranked = sorted(group, key=_rank_item, reverse=True)
        winner = ranked[0]
        stable_winner = ""
        if history_counts and key in history_counts:
            winners = history_counts.get(key) or {}
            if winners:
                try:
                    stable_winner = max(winners.items(), key=lambda kv: kv[1])[0]
                except Exception:
                    stable_winner = ""
                if stable_winner and winners.get(stable_winner, 0) >= stable_count:
                    for item in ranked:
                        if str(item.get("source_path") or "") == stable_winner:
                            winner = item
                            break
        active = [g for g in ranked if str(g.get("status") or "") in ("active", "legacy")]
        if active:
            # Mark non-winners as superseded when multiple active entries exist.
            for item in active:
                if item is winner:
                    continue
                item["status"] = "superseded"
        # Record conflict when top entries are close in confidence
        if len(active) > 1:
            try:
                conf_a = float(active[0].get("confidence") or 0)
                conf_b = float(active[1].get("confidence") or 0)
            except Exception:
                conf_a, conf_b = 0.0, 0.0
            if abs(conf_a - conf_b) <= 0.08:
                conflicts.append(
                    {
                        "key": key,
                        "winner_path": winner.get("source_path"),
                        "candidates": [
                            {
                                "path": i.get("source_path"),
                                "status": i.get("status"),
                                "confidence": i.get("confidence"),
                                "source_type": i.get("source_type"),
                            }
                            for i in ranked[:4]
                        ],
                        "reason": "stable_history" if stable_winner else "close_confidence",
                    }
                )

    # Persist registry
    try:
        if conn is None:
            conn = _connect(db_path)
            _ensure_tables(conn)
            _ensure_conflict_history_table(conn)
        conn.execute("DELETE FROM knowledge_items")
        conn.execute("DELETE FROM knowledge_conflicts")
        now = time.time()
        for item in items:
            fp = _fingerprint(item.get("key", ""), item.get("source_path", ""), str(item.get("mtime", "")))
            conn.execute(
                """
                INSERT INTO knowledge_items
                (key, source_path, source_type, status, confidence, mtime, fingerprint, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    str(item.get("key") or ""),
                    str(item.get("source_path") or ""),
                    str(item.get("source_type") or ""),
                    str(item.get("status") or ""),
                    float(item.get("confidence") or 0),
                    float(item.get("mtime") or 0),
                    fp,
                    str(item.get("notes") or ""),
                    now,
                    now,
                ),
            )
        # prune old history to keep DB small
        try:
            conn.execute(
                "DELETE FROM knowledge_conflict_history WHERE created_at < ?",
                (float(history_cutoff),),
            )
        except Exception:
            pass
        for conflict in conflicts:
            conn.execute(
                """
                INSERT INTO knowledge_conflicts (key, created_at, winner_path, candidates_json, reason)
                VALUES (?, ?, ?, ?, ?)
                """,
                (
                    str(conflict.get("key") or ""),
                    now,
                    str(conflict.get("winner_path") or ""),
                    json.dumps(conflict.get("candidates") or [], ensure_ascii=False),
                    str(conflict.get("reason") or ""),
                ),
            )
            try:
                conn.execute(
                    """
                    INSERT INTO knowledge_conflict_history (key, winner_path, created_at, reason)
                    VALUES (?, ?, ?, ?)
                    """,
                    (
                        str(conflict.get("key") or ""),
                        str(conflict.get("winner_path") or ""),
                        now,
                        str(conflict.get("reason") or ""),
                    ),
                )
            except Exception:
                pass
        conn.commit()
        conn.close()
    except Exception:
        try:
            conn.close()
        except Exception:
            pass

    # Summaries
    counts: Dict[str, int] = {
        "total": len(items),
        "active": 0,
        "legacy": 0,
        "stale": 0,
        "stale_age": 0,
        "superseded": 0,
        "conflicts": len(conflicts),
    }
    by_type: Dict[str, int] = {}
    for item in items:
        status = str(item.get("status") or "")
        if status in counts:
            counts[status] += 1
        elif status:
            counts["stale"] += 1
        src = str(item.get("source_type") or "")
        by_type[src] = by_type.get(src, 0) + 1

    return {
        "ok": True,
        "counts": counts,
        "by_type": by_type,
        "conflicts": conflicts[:8],
        "conflict_keys": [c.get("key") for c in conflicts[:20]],
    }


def load_knowledge_status(db_path: Path) -> Dict[str, Dict[str, Any]]:
    if not db_path.exists():
        return {}
    try:
        conn = _connect(db_path)
        rows = conn.execute(
            "SELECT source_path, status, confidence FROM knowledge_items"
        ).fetchall()
        conn.close()
        out: Dict[str, Dict[str, Any]] = {}
        for r in rows:
            out[str(r[0])] = {
                "status": str(r[1] or ""),
                "confidence": float(r[2] or 0),
            }
        return out
    except Exception:
        return {}
