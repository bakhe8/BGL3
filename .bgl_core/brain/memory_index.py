from __future__ import annotations

import hashlib
import json
import math
import time
import sqlite3
from pathlib import Path
from typing import Any, Dict, Optional


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
        CREATE TABLE IF NOT EXISTS memory_index (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kind TEXT NOT NULL,
            key_hash TEXT UNIQUE NOT NULL,
            key_text TEXT,
            summary TEXT,
            created_at REAL NOT NULL,
            updated_at REAL NOT NULL,
            last_seen REAL,
            seen_count INTEGER DEFAULT 0,
            evidence_count INTEGER DEFAULT 0,
            confidence REAL,
            value_score REAL,
            suppressed INTEGER DEFAULT 0,
            source_table TEXT,
            source_id INTEGER,
            meta_json TEXT
        )
        """
    )
    conn.execute(
        "CREATE INDEX IF NOT EXISTS idx_memory_index_kind ON memory_index(kind)"
    )
    conn.execute(
        "CREATE INDEX IF NOT EXISTS idx_memory_index_last_seen ON memory_index(last_seen DESC)"
    )
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS memory_relations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            parent_hash TEXT NOT NULL,
            child_hash TEXT NOT NULL,
            relation TEXT NOT NULL,
            created_at REAL NOT NULL,
            notes TEXT
        )
        """
    )
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS memory_actions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            key_hash TEXT NOT NULL,
            action TEXT NOT NULL,
            actor TEXT,
            created_at REAL NOT NULL,
            notes TEXT
        )
        """
    )
    conn.commit()


def _hash_key(kind: str, key_text: str, summary: str) -> str:
    raw = f"{kind.strip()}|{(key_text or '').strip()}|{(summary or '').strip()}"
    return hashlib.sha1(raw.encode("utf-8")).hexdigest()


def _merge_confidence(prev: Optional[float], new: Optional[float], weight: int) -> float:
    if new is None:
        return float(prev or 0.5)
    base = float(prev or 0.5)
    w = max(1, int(weight or 1))
    return (base + float(new) * w) / float(1 + w)


def _value_score(confidence: Optional[float], evidence_count: int) -> float:
    base = float(confidence or 0.5)
    boost = min(0.5, math.log1p(max(0, int(evidence_count))) / 4.0)
    return round(min(1.0, base + boost), 3)


def upsert_memory_item(
    db_path: Path,
    *,
    kind: str,
    key_text: str,
    summary: str,
    evidence_count: int = 1,
    confidence: Optional[float] = None,
    value_score: Optional[float] = None,
    meta: Optional[Dict[str, Any]] = None,
    source_table: Optional[str] = None,
    source_id: Optional[int] = None,
) -> Optional[Dict[str, Any]]:
    if not db_path or not db_path.exists():
        return None
    kind = (kind or "").strip()
    if not kind:
        return None
    key_text = (key_text or "").strip()
    summary = (summary or "").strip()
    key_hash = _hash_key(kind, key_text, summary)
    now = time.time()
    try:
        conn = _connect(db_path)
        _ensure_tables(conn)
        row = conn.execute(
            "SELECT id, seen_count, evidence_count, confidence, value_score, suppressed FROM memory_index WHERE key_hash=?",
            (key_hash,),
        ).fetchone()
        if row:
            seen_count = int(row["seen_count"] or 0) + 1
            evidence_total = int(row["evidence_count"] or 0) + int(
                evidence_count or 0
            )
            conf = _merge_confidence(row["confidence"], confidence, evidence_count or 1)
            score = (
                float(value_score)
                if value_score is not None
                else _value_score(conf, evidence_total)
            )
            meta_json = json.dumps(meta or {}, ensure_ascii=False)
            conn.execute(
                """
                UPDATE memory_index
                SET updated_at=?, last_seen=?, seen_count=?, evidence_count=?, confidence=?, value_score=?, meta_json=?
                WHERE id=?
                """,
                (
                    now,
                    now,
                    seen_count,
                    evidence_total,
                    conf,
                    score,
                    meta_json,
                    int(row["id"]),
                ),
            )
            conn.commit()
            conn.close()
            return {
                "id": int(row["id"]),
                "key_hash": key_hash,
                "seen_count": seen_count,
                "evidence_count": evidence_total,
                "confidence": conf,
                "value_score": score,
            }
        conf = float(confidence or 0.5)
        score = float(value_score) if value_score is not None else _value_score(conf, evidence_count)
        meta_json = json.dumps(meta or {}, ensure_ascii=False)
        cur = conn.execute(
            """
            INSERT INTO memory_index
            (kind, key_hash, key_text, summary, created_at, updated_at, last_seen, seen_count, evidence_count, confidence, value_score, suppressed, source_table, source_id, meta_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)
            """,
            (
                kind,
                key_hash,
                key_text,
                summary,
                now,
                now,
                now,
                1,
                int(evidence_count or 0),
                conf,
                score,
                source_table,
                int(source_id) if source_id else None,
                meta_json,
            ),
        )
        mem_id = cur.lastrowid
        conn.commit()
        conn.close()
        return {
            "id": int(mem_id or 0),
            "key_hash": key_hash,
            "seen_count": 1,
            "evidence_count": int(evidence_count or 0),
            "confidence": conf,
            "value_score": score,
        }
    except Exception:
        return None


def record_relation(
    db_path: Path,
    *,
    parent_hash: str,
    child_hash: str,
    relation: str,
    notes: str = "",
) -> None:
    if not db_path or not db_path.exists():
        return
    try:
        conn = _connect(db_path)
        _ensure_tables(conn)
        conn.execute(
            "INSERT INTO memory_relations (parent_hash, child_hash, relation, created_at, notes) VALUES (?, ?, ?, ?, ?)",
            (parent_hash, child_hash, relation, time.time(), notes),
        )
        conn.commit()
        conn.close()
    except Exception:
        return


def record_action(
    db_path: Path,
    *,
    key_hash: str,
    action: str,
    actor: str = "auto",
    notes: str = "",
) -> None:
    if not db_path or not db_path.exists():
        return
    try:
        conn = _connect(db_path)
        _ensure_tables(conn)
        conn.execute(
            "INSERT INTO memory_actions (key_hash, action, actor, created_at, notes) VALUES (?, ?, ?, ?, ?)",
            (key_hash, action, actor, time.time(), notes),
        )
        conn.commit()
        conn.close()
    except Exception:
        return


def suppress_memory_item(db_path: Path, key_hash: str, reason: str = "") -> None:
    if not db_path or not db_path.exists() or not key_hash:
        return
    try:
        conn = _connect(db_path)
        _ensure_tables(conn)
        conn.execute(
            "UPDATE memory_index SET suppressed=1, updated_at=? WHERE key_hash=?",
            (time.time(), key_hash),
        )
        conn.commit()
        conn.close()
        record_action(db_path, key_hash=key_hash, action="suppressed", actor="auto", notes=reason)
    except Exception:
        return


def unsuppress_memory_item(db_path: Path, key_hash: str) -> None:
    if not db_path or not db_path.exists() or not key_hash:
        return
    try:
        conn = _connect(db_path)
        _ensure_tables(conn)
        conn.execute(
            "UPDATE memory_index SET suppressed=0, updated_at=? WHERE key_hash=?",
            (time.time(), key_hash),
        )
        conn.commit()
        conn.close()
        record_action(db_path, key_hash=key_hash, action="unsuppressed", actor="auto", notes="")
    except Exception:
        return


def auto_curate_memory(
    db_path: Path,
    *,
    merge_limit: int = 240,
    split_limit: int = 80,
    min_group_size: int = 3,
    suppress_threshold: float = 0.35,
    split_min_len: int = 180,
    enable_split: bool = True,
    enable_merge: bool = True,
) -> Dict[str, int]:
    """
    Auto-curate memory index:
    - Merge near-duplicate items into group entries per (kind, key_text).
    - Split overly long summaries into smaller items (non-destructive).
    Returns counts of actions taken.
    """
    stats = {"merged_groups": 0, "merged_children": 0, "suppressed": 0, "split": 0}
    if not db_path or not db_path.exists():
        return stats
    try:
        conn = _connect(db_path)
        _ensure_tables(conn)
        cur = conn.cursor()
        # Avoid repeatedly splitting same items
        split_done = {
            r[0]
            for r in cur.execute(
                "SELECT key_hash FROM memory_actions WHERE action='split'"
            ).fetchall()
        }
        rows = cur.execute(
            """
            SELECT id, kind, key_hash, key_text, summary, confidence, evidence_count, value_score
            FROM memory_index
            WHERE suppressed=0 AND kind NOT LIKE '%_group'
            ORDER BY updated_at DESC
            LIMIT ?
            """,
            (int(merge_limit),),
        ).fetchall()
        conn.close()
    except Exception:
        return stats

    def _norm(s: str) -> str:
        return " ".join((s or "").strip().lower().split())

    # ---- Merge pass ----
    if enable_merge:
        groups: Dict[tuple, list] = {}
        for r in rows:
            kind = str(r[1] or "")
            key_text = str(r[3] or "")
            if not kind or not key_text:
                continue
            k = (kind, _norm(key_text))
            groups.setdefault(k, []).append(r)

        for (kind, key_norm), items in groups.items():
            if len(items) < int(min_group_size):
                continue
            # Aggregate group entry
            key_text = str(items[0][3] or "")
            total_evidence = sum(int(i[6] or 0) for i in items)
            max_conf = max(float(i[5] or 0.5) for i in items)
            # choose top summary by value_score
            top = sorted(items, key=lambda x: float(x[7] or 0.0), reverse=True)[0]
            top_summary = str(top[4] or "")
            group_summary = f"{kind} group for {key_text} ({len(items)} items). Top: {top_summary[:160]}"
            group = upsert_memory_item(
                db_path,
                kind=f"{kind}_group",
                key_text=key_text,
                summary=group_summary,
                evidence_count=total_evidence,
                confidence=max_conf,
                meta={"group_size": len(items), "top_summary": top_summary[:200]},
                source_table="memory_index",
                source_id=None,
            )
            if not group:
                continue
            group_hash = str(group.get("key_hash") or "")
            if not group_hash:
                continue
            stats["merged_groups"] += 1
            # Existing relations for this group
            try:
                conn = _connect(db_path)
                _ensure_tables(conn)
                existing = {
                    r[0]
                    for r in conn.execute(
                        "SELECT child_hash FROM memory_relations WHERE parent_hash=? AND relation='merged'",
                        (group_hash,),
                    ).fetchall()
                }
                conn.close()
            except Exception:
                existing = set()
            for it in items:
                child_hash = str(it[2] or "")
                if not child_hash or child_hash in existing:
                    continue
                record_relation(
                    db_path,
                    parent_hash=group_hash,
                    child_hash=child_hash,
                    relation="merged",
                    notes="auto_group",
                )
                stats["merged_children"] += 1
                if float(it[7] or 0.0) < float(suppress_threshold) and len(items) >= 4:
                    suppress_memory_item(
                        db_path,
                        child_hash,
                        reason=f"auto_group:{kind}",
                    )
                    stats["suppressed"] += 1

    # ---- Split pass ----
    if enable_split:
        split_rows = rows[: int(split_limit)]
        for r in split_rows:
            key_hash = str(r[2] or "")
            if not key_hash or key_hash in split_done:
                continue
            summary = str(r[4] or "")
            if len(summary) < int(split_min_len):
                continue
            # Attempt split by common separators
            parts: list[str] = []
            for sep in ["; ", " • ", " | ", ". "]:
                if sep in summary:
                    parts = [p.strip() for p in summary.split(sep) if p.strip()]
                    break
            if not parts:
                continue
            parts = parts[:3]
            for idx, part in enumerate(parts, start=1):
                if len(part) < 25:
                    continue
                child = upsert_memory_item(
                    db_path,
                    kind=str(r[1] or ""),
                    key_text=f"{str(r[3] or '')}::part{idx}",
                    summary=part,
                    evidence_count=max(1, int(r[6] or 1) // max(1, len(parts))),
                    confidence=float(r[5] or 0.5),
                    meta={"split_from": key_hash},
                    source_table="memory_index",
                    source_id=int(r[0] or 0),
                )
                if child and child.get("key_hash"):
                    record_relation(
                        db_path,
                        parent_hash=key_hash,
                        child_hash=str(child.get("key_hash")),
                        relation="split",
                        notes="auto_split",
                    )
                    stats["split"] += 1
            record_action(
                db_path,
                key_hash=key_hash,
                action="split",
                actor="auto",
                notes="auto_split",
            )
    return stats
