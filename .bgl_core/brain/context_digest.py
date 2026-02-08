"""
Context Digest
--------------
Summarizes recent runtime_events into experiential memory for later analysis.
Usage:
    python .bgl_core/brain/context_digest.py --hours 24 --limit 500
"""

import argparse
import sqlite3
import time
import os
import sys
import hashlib
import json
import subprocess
import math
from pathlib import Path
from typing import List, Dict

from embeddings import add_text

try:
    from .config_loader import load_config  # type: ignore
except Exception:
    try:
        from config_loader import load_config  # type: ignore
    except Exception:
        load_config = None  # type: ignore

DB_PATH = Path(__file__).parent / "knowledge.db"
ROOT_DIR = Path(__file__).resolve().parents[2]
_CONFIG = {}
try:
    if load_config is not None:
        _CONFIG = load_config(ROOT_DIR) or {}
except Exception:
    _CONFIG = {}


def _cfg_flag(env_key: str, cfg_key: str, default: bool) -> bool:
    env_val = os.getenv(env_key)
    if env_val is not None:
        return str(env_val).strip() == "1"
    try:
        cfg_val = _CONFIG.get(cfg_key)
        if cfg_val is None:
            return bool(default)
        if isinstance(cfg_val, (int, float)):
            return float(cfg_val) != 0.0
        return str(cfg_val).strip() == "1"
    except Exception:
        return bool(default)


def _cfg_number(env_key: str, cfg_key: str, default):
    env_val = os.getenv(env_key)
    if env_val is not None:
        try:
            return type(default)(env_val)
        except Exception:
            return default
    try:
        cfg_val = _CONFIG.get(cfg_key, default)
        return type(default)(cfg_val)
    except Exception:
        return default


def fetch_events(conn: sqlite3.Connection, cutoff: float, limit: int):
    conn.row_factory = sqlite3.Row
    cur = conn.cursor()
    return cur.execute(
        """
        SELECT * FROM runtime_events
        WHERE timestamp >= ?
        ORDER BY timestamp DESC
        LIMIT ?
        """,
        (cutoff, limit),
    ).fetchall()


def fetch_prod_ops(conn: sqlite3.Connection, cutoff: float, limit: int):
    try:
        cur = conn.cursor()
        has_table = cur.execute(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='prod_operations'"
        ).fetchone()
        if not has_table:
            return []
        if limit is None or int(limit) <= 0:
            return cur.execute(
                """
                SELECT * FROM prod_operations
                WHERE timestamp >= ?
                ORDER BY timestamp DESC
                """,
                (cutoff,),
            ).fetchall()
        return cur.execute(
            """
            SELECT * FROM prod_operations
            WHERE timestamp >= ?
            ORDER BY timestamp DESC
            LIMIT ?
            """,
            (cutoff, limit),
        ).fetchall()
    except Exception:
        return []


def load_route_map(conn: sqlite3.Connection) -> Dict[str, Dict]:
    conn.row_factory = sqlite3.Row
    cur = conn.cursor()
    rows = cur.execute(
        "SELECT uri, controller, action, file_path FROM routes"
    ).fetchall()
    return {r["uri"]: dict(r) for r in rows}


def summarize(events: List[sqlite3.Row], route_map: Dict[str, Dict]) -> List[Dict]:
    summaries = []
    grouped: Dict[str, Dict] = {}
    for e in events:
        if e["event_type"] == "log_highlight":
            continue
        route = e["route"] or "/"
        g = grouped.setdefault(
            route,
            {
                "route": route,
                "count": 0,
                "http_calls": 0,
                "http_fail": 0,
                "js_errors": [],
                "network_errors": [],
                "latencies": [],
                "ui_events": 0,
            },
        )
        g["count"] += 1
        etype = e["event_type"]
        status = e["status"] or 0
        if etype in ("api_call", "http_error", "route"):
            g["http_calls"] += 1
            if status >= 400 or status == 0:
                g["http_fail"] += 1
            if e["latency_ms"] is not None:
                g["latencies"].append(e["latency_ms"])
        elif etype in ("ui_click", "ui_input"):
            g["ui_events"] += 1
        elif etype in ("js_error", "console_error"):
            if e["error"]:
                g["js_errors"].append(e["error"])
        elif etype in ("network_fail",):
            if e["error"]:
                g["network_errors"].append(e["error"])

    for route, data in grouped.items():
        lat_avg = (
            round(sum(data["latencies"]) / len(data["latencies"]), 2)
            if data["latencies"]
            else 0
        )
        lat_max = max(data["latencies"]) if data["latencies"] else 0
        controller = route_map.get(route, {}).get("controller") or "unknown"
        file_path = route_map.get(route, {}).get("file_path") or ""
        summary = (
            f"Route {route}: {data['count']} events, "
            f"{data['http_calls']} HTTP calls ({data['http_fail']} failed), "
            f"{len(data['js_errors'])} JS errors, "
            f"{len(data['network_errors'])} network errors, "
            f"avg latency {lat_avg} ms (max {lat_max} ms)."
        )
        related = file_path or controller
        has_fail = data["http_fail"] or data["js_errors"] or data["network_errors"]
        confidence = 0.85 if has_fail else 0.6
        summaries.append(
            {
                "scenario": route,
                "summary": summary,
                "related_files": related,
                "confidence": confidence,
                "evidence_count": data["count"],
            }
        )
    return summaries


def _safe_json(raw) -> Dict:
    if raw is None:
        return {}
    if isinstance(raw, dict):
        return raw
    try:
        return json.loads(raw)
    except Exception:
        return {"message": str(raw)}


def summarize_log_highlights(events: List[sqlite3.Row]) -> List[Dict]:
    summaries: List[Dict] = []
    for e in events:
        if e["event_type"] != "log_highlight":
            continue
        payload = _safe_json(e["payload"])
        message = str(payload.get("message") or "").strip()
        source = str(payload.get("source") or "log")
        if not message:
            continue
        scenario = f"log_error:{source}"
        related = str(payload.get("uri") or payload.get("route") or "")
        summaries.append(
            {
                "scenario": scenario,
                "summary": f"{source} log: {message}",
                "related_files": related,
                "confidence": 0.7,
                "evidence_count": 1,
            }
        )
    return summaries


def _ensure_experience_columns(conn: sqlite3.Connection) -> None:
    try:
        cols = {r[1] for r in conn.execute("PRAGMA table_info(experiences)").fetchall()}
    except Exception:
        cols = set()
    try:
        if "exp_hash" not in cols:
            conn.execute("ALTER TABLE experiences ADD COLUMN exp_hash TEXT")
        if "seen_count" not in cols:
            conn.execute(
                "ALTER TABLE experiences ADD COLUMN seen_count INTEGER DEFAULT 0"
            )
        if "last_seen" not in cols:
            conn.execute("ALTER TABLE experiences ADD COLUMN last_seen REAL")
        if "updated_at" not in cols:
            conn.execute("ALTER TABLE experiences ADD COLUMN updated_at REAL")
        if "value_score" not in cols:
            conn.execute("ALTER TABLE experiences ADD COLUMN value_score REAL")
        if "suppressed" not in cols:
            conn.execute(
                "ALTER TABLE experiences ADD COLUMN suppressed INTEGER DEFAULT 0"
            )
        conn.commit()
    except Exception:
        return


def _value_score(confidence: float, evidence_count: int) -> float:
    base = float(confidence or 0.5)
    boost = min(0.5, math.log1p(max(0, int(evidence_count))) / 4.0)
    return round(min(1.0, base + boost), 3)


def _normalize_scope(scope_val) -> str:
    if scope_val is None:
        return ""
    if isinstance(scope_val, (list, tuple)):
        return ",".join([str(s) for s in scope_val if s])
    if isinstance(scope_val, dict):
        return json.dumps(scope_val, ensure_ascii=False)
    s = str(scope_val)
    s = s.strip()
    if s.startswith("[") or s.startswith("{"):
        try:
            parsed = json.loads(s)
            if isinstance(parsed, list):
                return ",".join([str(p) for p in parsed if p])
            if isinstance(parsed, dict):
                return json.dumps(parsed, ensure_ascii=False)
        except Exception:
            return s
    return s


def summarize_prod_ops(ops: List[sqlite3.Row]) -> List[Dict]:
    summaries: List[Dict] = []
    grouped: Dict[str, Dict] = {}
    for op in ops:
        operation = str(op["operation"] or "")
        scope_val = None
        try:
            if "scope" in op.keys():
                scope_val = op["scope"]
        except Exception:
            scope_val = None
        if scope_val is None:
            try:
                payload = op["payload_json"] if "payload_json" in op.keys() else None
                if payload:
                    scope_val = json.loads(payload).get("scope")
            except Exception:
                scope_val = None
        scope_raw = _normalize_scope(scope_val)
        status = str(op["status"] or "")
        key = f"{operation}::{scope_raw}"
        g = grouped.setdefault(
            key,
            {
                "operation": operation,
                "scope": scope_raw,
                "count": 0,
                "allowed": 0,
                "blocked": 0,
                "last_status": "",
                "last_mode": "",
                "last_source": "",
                "last_ts": 0,
            },
        )
        g["count"] += 1
        if status == "allowed":
            g["allowed"] += 1
        elif status.startswith("blocked"):
            g["blocked"] += 1
        ts = float(op["timestamp"] or 0)
        if ts >= g["last_ts"]:
            g["last_ts"] = ts
            g["last_status"] = status
            g["last_mode"] = str(op["execution_mode"] or "")
            g["last_source"] = str(op["source"] or "")

    for _, data in grouped.items():
        count = int(data["count"] or 0)
        if count <= 0:
            continue
        allowed = int(data["allowed"] or 0)
        blocked = int(data["blocked"] or 0)
        blocked_rate = round((blocked / max(1, count)) * 100, 1)
        summary = (
            f"Prod op {data['operation']} on {data['scope'] or 'unknown'}: "
            f"{count} ops, allowed {allowed}, blocked {blocked} ({blocked_rate}%). "
            f"Last={data['last_status']} mode={data['last_mode']} source={data['last_source']}."
        )
        related = data["scope"] or data["operation"]
        confidence = 0.88 if blocked_rate >= 30 else 0.7
        summaries.append(
            {
                "scenario": f"prod_op:{data['operation']}:{data['scope'] or 'unknown'}",
                "summary": summary,
                "related_files": related,
                "confidence": confidence,
                "evidence_count": count,
            }
        )
    return summaries


def upsert_experiences(conn: sqlite3.Connection, experiences: List[Dict]):
    cur = conn.cursor()
    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS experiences (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at REAL NOT NULL,
            updated_at REAL,
            scenario TEXT,
            summary TEXT,
            related_files TEXT,
            exp_hash TEXT UNIQUE,
            seen_count INTEGER DEFAULT 0,
            last_seen REAL,
            confidence REAL,
            evidence_count INTEGER DEFAULT 0,
            value_score REAL,
            suppressed INTEGER DEFAULT 0
        )
        """
    )
    _ensure_experience_columns(conn)
    now = time.time()
    upserted: List[Dict] = []
    for exp in experiences:
        scenario = exp["scenario"]
        summary = exp["summary"]
        exp_hash = _exp_hash(scenario, summary)
        row = cur.execute(
            "SELECT id, seen_count, evidence_count, confidence, value_score FROM experiences WHERE exp_hash=?",
            (exp_hash,),
        ).fetchone()
        if row:
            seen_count = int(row[1] or 0) + 1
            evidence_total = int(row[2] or 0) + int(exp["evidence_count"] or 0)
            prev_conf = float(row[3] or 0.5)
            new_conf = (
                (prev_conf + float(exp["confidence"] or 0.5) * max(1, int(exp["evidence_count"] or 1)))
                / float(1 + max(1, int(exp["evidence_count"] or 1)))
            )
            value_score = _value_score(new_conf, evidence_total)
            cur.execute(
                """
                UPDATE experiences
                SET updated_at=?, last_seen=?, seen_count=?, evidence_count=?, confidence=?, value_score=?
                WHERE id=?
                """,
                (
                    now,
                    now,
                    seen_count,
                    evidence_total,
                    new_conf,
                    value_score,
                    row[0],
                ),
            )
            exp_id = row[0]
        else:
            seen_count = 1
            evidence_total = int(exp["evidence_count"] or 0)
            conf = float(exp["confidence"] or 0.5)
            value_score = _value_score(conf, evidence_total)
            cur.execute(
                """
                INSERT INTO experiences (created_at, updated_at, scenario, summary, related_files, exp_hash, seen_count, last_seen, confidence, evidence_count, value_score, suppressed)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
                """,
                (
                    now,
                    now,
                    scenario,
                    summary,
                    exp["related_files"],
                    exp_hash,
                    seen_count,
                    now,
                    conf,
                    evidence_total,
                    value_score,
                ),
            )
            exp_id = cur.lastrowid
        upserted.append(
            {
                "id": exp_id,
                "created_at": now,
                "scenario": scenario,
                "summary": summary,
                "related_files": exp["related_files"],
                "confidence": exp["confidence"],
                "evidence_count": exp["evidence_count"],
                "exp_hash": exp_hash,
            }
        )

    conn.commit()

    # Index into semantic memory AFTER commit to avoid locking
    for exp in experiences:
        if exp["confidence"] >= 0.3:
            add_text(f"[Experience] {exp['scenario']}", exp["summary"])

    # Unified memory index (best-effort, non-blocking)
    try:
        from .memory_index import upsert_memory_item  # type: ignore
    except Exception:
        try:
            from memory_index import upsert_memory_item  # type: ignore
        except Exception:
            upsert_memory_item = None  # type: ignore
    if upsert_memory_item:
        for exp in experiences:
            scenario = str(exp.get("scenario") or "")
            kind = "log" if scenario.startswith("log_error:") else "experience"
            upsert_memory_item(
                DB_PATH,
                kind=kind,
                key_text=scenario,
                summary=str(exp.get("summary") or ""),
                evidence_count=int(exp.get("evidence_count") or 0),
                confidence=float(exp.get("confidence") or 0.5),
                meta={"related_files": exp.get("related_files")},
                source_table="experiences",
                source_id=None,
            )
    return upserted


def _exp_hash(scenario: str, summary: str) -> str:
    raw = f"{scenario.strip()}|{summary.strip()}"
    return hashlib.sha1(raw.encode("utf-8")).hexdigest()


def _ensure_experience_actions(conn: sqlite3.Connection) -> None:
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS experience_actions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            exp_hash TEXT UNIQUE,
            action TEXT,
            created_at REAL
        )
        """
    )
    conn.commit()


def _ensure_experience_links(conn: sqlite3.Connection) -> None:
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS experience_proposal_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            exp_hash TEXT,
            experience_id INTEGER,
            proposal_id INTEGER,
            created_at REAL,
            source TEXT
        )
        """
    )
    conn.commit()


def _log_runtime_event(conn: sqlite3.Connection, event_type: str, payload: Dict) -> None:
    try:
        conn.execute(
            """
            INSERT INTO runtime_events (timestamp, event_type, payload)
            VALUES (?, ?, ?)
            """,
            (time.time(), event_type, json.dumps(payload, ensure_ascii=False)),
        )
        conn.commit()
    except Exception:
        pass


def auto_promote_experiences(conn: sqlite3.Connection, experiences: List[Dict]) -> List[int]:
    """
    Convert high-signal experiences into proposals automatically.
    Returns list of created proposal IDs.
    """
    if not _cfg_flag("BGL_AUTO_PROPOSE", "auto_propose", True):
        return []
    if not experiences:
        return []

    min_conf = _cfg_number("BGL_AUTO_PROPOSE_MIN_CONF", "auto_propose_min_conf", 0.78)
    min_evidence = _cfg_number("BGL_AUTO_PROPOSE_MIN_EVIDENCE", "auto_propose_min_evidence", 4)
    min_conf_high = _cfg_number("BGL_AUTO_PROPOSE_MIN_CONF_HIGH", "auto_propose_min_conf_high", 0.7)
    min_evidence_high = _cfg_number("BGL_AUTO_PROPOSE_MIN_EVIDENCE_HIGH", "auto_propose_min_evidence_high", 2)
    max_promote = _cfg_number("BGL_AUTO_PROPOSE_LIMIT", "auto_propose_limit", 6)

    _ensure_experience_actions(conn)
    _ensure_experience_links(conn)

    cur = conn.cursor()
    existing_actions = {}
    try:
        rows = cur.execute("SELECT exp_hash, action FROM experience_actions").fetchall()
        for r in rows:
            existing_actions[r[0]] = r[1]
    except Exception:
        existing_actions = {}

    created: List[int] = []
    promoted = 0
    for exp in experiences:
        if promoted >= max_promote:
            break
        scenario = str(exp.get("scenario") or "")
        summary = str(exp.get("summary") or "")
        if not scenario or not summary:
            continue
        exp_hash = _exp_hash(scenario, summary)
        if exp_hash in existing_actions:
            continue
        try:
            conf = float(exp.get("confidence") or 0)
        except Exception:
            conf = 0.0
        try:
            evidence = int(exp.get("evidence_count") or 0)
        except Exception:
            evidence = 0

        # Boost high-signal experiences (errors, failures, API) to avoid missing proposals.
        text = summary.lower()
        scenario_norm = scenario.lower()
        high_signal = any(
            k in text
            for k in (
                "failed",
                "error",
                "exception",
                "http_error",
                "network_fail",
                "js error",
                "console_error",
                "blocked",
            )
        ) or "/api/" in scenario_norm
        min_conf_eff = min_conf_high if high_signal else min_conf
        min_evidence_eff = min_evidence_high if high_signal else min_evidence
        if high_signal:
            conf = max(conf, 0.85)
        if conf < min_conf_eff or evidence < min_evidence_eff:
            continue

        # Determine action/impact heuristics
        text = summary.lower()
        if (
            "failed" in text
            or "error" in text
            or "js error" in text
            or "network" in text
            or "blocked" in text
        ):
            action = "stabilize"
            impact = "medium"
        else:
            action = "investigate"
            impact = "low" if conf < 0.85 else "medium"

        base_name = f"تحسين تلقائي من خبرة: {scenario}"
        name = base_name
        # ensure unique name
        try:
            exists = cur.execute(
                "SELECT id FROM agent_proposals WHERE name = ?",
                (name,),
            ).fetchone()
            if exists:
                name = f"{base_name} #{int(time.time())}"
        except Exception:
            name = f"{base_name} #{int(time.time())}"

        evidence_payload = {
            "experience_id": exp.get("id"),
            "exp_hash": exp_hash,
            "scenario": scenario,
            "summary": summary,
            "confidence": conf,
            "evidence_count": evidence,
            "related_files": exp.get("related_files"),
            "source": "auto_experience",
        }

        try:
            cur.execute(
                """
                INSERT INTO agent_proposals
                (name, description, action, count, evidence, impact, solution, expectation)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    name,
                    summary[:400],
                    action,
                    1,
                    json.dumps(evidence_payload, ensure_ascii=False),
                    impact,
                    "",
                    "",
                ),
            )
            proposal_id = cur.lastrowid
            conn.commit()
            created.append(int(proposal_id))
            promoted += 1

            # Mark experience as auto-promoted
            cur.execute(
                "INSERT OR REPLACE INTO experience_actions (exp_hash, action, created_at) VALUES (?, ?, ?)",
                (exp_hash, "auto_promoted", time.time()),
            )
            conn.commit()

            # Link table
            cur.execute(
                """
                INSERT INTO experience_proposal_links (exp_hash, experience_id, proposal_id, created_at, source)
                VALUES (?, ?, ?, ?, ?)
                """,
                (exp_hash, exp.get("id"), proposal_id, time.time(), "auto"),
            )
            conn.commit()

            _log_runtime_event(
                conn,
                "auto_proposal",
                {"proposal_id": proposal_id, "exp_hash": exp_hash, "scenario": scenario},
            )
        except Exception:
            continue

    return created


def auto_apply_proposals(proposal_ids: List[int]) -> None:
    if not proposal_ids:
        return
    if not _cfg_flag("BGL_AUTO_APPLY", "auto_apply", True):
        return
    max_apply = _cfg_number("BGL_AUTO_APPLY_LIMIT", "auto_apply_limit", 3)
    exe = sys.executable or "python"
    root = Path(__file__).resolve().parents[2]
    script = root / ".bgl_core" / "brain" / "apply_proposal.py"
    if not script.exists():
        return
    for pid in proposal_ids[:max_apply]:
        try:
            subprocess.run(
                [exe, str(script), "--proposal", str(pid)],
                cwd=str(root),
                capture_output=True,
                text=True,
                check=False,
            )
        except Exception:
            continue


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--hours", type=float, default=24, help="Lookback window in hours"
    )
    parser.add_argument("--limit", type=int, default=500, help="Max events to process")
    args = parser.parse_args()

    cutoff = time.time() - args.hours * 3600

    conn = sqlite3.connect(DB_PATH)

    # Runtime events
    events = fetch_events(conn, cutoff, args.limit)
    event_summaries: List[Dict] = []
    log_summaries: List[Dict] = []
    if events:
        route_map = load_route_map(conn)
        event_summaries = summarize(events, route_map)
        log_summaries = summarize_log_highlights(events)

    # Prod operations (central log)
    prod_ops_full = _cfg_flag("BGL_PROD_OPS_FULL", "prod_ops_full", False)
    prod_ops_hours = _cfg_number("BGL_PROD_OPS_HOURS", "prod_ops_hours", args.hours)
    prod_ops_limit = _cfg_number("BGL_PROD_OPS_LIMIT", "prod_ops_limit", args.limit)
    prod_cutoff = 0.0 if prod_ops_full or prod_ops_hours <= 0 else (time.time() - prod_ops_hours * 3600)
    prod_ops = fetch_prod_ops(conn, prod_cutoff, prod_ops_limit)
    prod_summaries = summarize_prod_ops(prod_ops) if prod_ops else []

    experiences = event_summaries + log_summaries + prod_summaries
    if not experiences:
        print("No events/prod operations found in window.")
        return

    inserted = upsert_experiences(conn, experiences)

    # Auto-promote experiences -> proposals and auto-apply in sandbox
    proposal_ids = auto_promote_experiences(conn, inserted)
    try:
        conn.close()
    except Exception:
        pass
    auto_apply_proposals(proposal_ids)
    # Auto memory curation (merge/split/suppress) across all memory kinds
    try:
        from .memory_index import auto_curate_memory  # type: ignore
    except Exception:
        try:
            from memory_index import auto_curate_memory  # type: ignore
        except Exception:
            auto_curate_memory = None  # type: ignore
    if auto_curate_memory and _cfg_flag("BGL_MEMORY_AUTO_CURATE", "memory_auto_curate", True):
        try:
            stats = auto_curate_memory(
                DB_PATH,
                merge_limit=_cfg_number("BGL_MEMORY_MERGE_LIMIT", "memory_merge_limit", 240),
                split_limit=_cfg_number("BGL_MEMORY_SPLIT_LIMIT", "memory_split_limit", 80),
                min_group_size=_cfg_number("BGL_MEMORY_GROUP_MIN", "memory_group_min", 3),
                suppress_threshold=_cfg_number(
                    "BGL_MEMORY_SUPPRESS_THRESHOLD", "memory_suppress_threshold", 0.35
                ),
                split_min_len=_cfg_number("BGL_MEMORY_SPLIT_MIN_LEN", "memory_split_min_len", 180),
                enable_split=_cfg_flag("BGL_MEMORY_AUTO_SPLIT", "memory_auto_split", True),
                enable_merge=_cfg_flag("BGL_MEMORY_AUTO_MERGE", "memory_auto_merge", True),
            )
            print(f"Auto memory curation: {stats}")
        except Exception:
            pass
    print(
        f"Stored {len(experiences)} experience(s) from {len(events)} event(s) "
        f"and {len(prod_ops)} prod op(s). "
        f"Auto proposals: {len(proposal_ids)}"
    )


if __name__ == "__main__":
    main()
