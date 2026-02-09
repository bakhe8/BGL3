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
from typing import List, Dict, Any, Callable, Optional

from embeddings import add_text
try:
    from .db_utils import connect_db  # type: ignore
except Exception:
    from db_utils import connect_db  # type: ignore

try:
    from .config_loader import load_config  # type: ignore
except Exception:
    try:
        from config_loader import load_config  # type: ignore
    except Exception:
        load_config = None  # type: ignore

DB_PATH = Path(__file__).parent / "knowledge.db"
ROOT_DIR = Path(__file__).resolve().parents[2]
STATE_PATH = ROOT_DIR / ".bgl_core" / "logs" / "context_digest_state.json"
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


def _read_digest_state() -> Dict[str, Any]:
    if not STATE_PATH.exists():
        return {}
    try:
        return json.loads(STATE_PATH.read_text(encoding="utf-8"))
    except Exception:
        return {}


def _write_digest_state(state: Dict[str, Any]) -> None:
    try:
        STATE_PATH.parent.mkdir(parents=True, exist_ok=True)
        STATE_PATH.write_text(json.dumps(state, ensure_ascii=False, indent=2), encoding="utf-8")
    except Exception:
        pass


def _bucket_count(val: int) -> str:
    if val <= 0:
        return "0"
    if val <= 3:
        return "1-3"
    if val <= 9:
        return "4-9"
    if val <= 49:
        return "10-49"
    return "50+"


def _bucket_rate(val: float) -> str:
    if val <= 0:
        return "0"
    if val <= 0.1:
        return "0-0.1"
    if val <= 0.3:
        return "0.1-0.3"
    if val <= 0.6:
        return "0.3-0.6"
    return "0.6+"


def _bucket_latency(val: float) -> str:
    if val <= 0:
        return "0"
    if val < 500:
        return "low"
    if val < 1500:
        return "medium"
    if val < 3000:
        return "high"
    return "very_high"


def _load_code_contracts(root: Path) -> Dict[str, Any]:
    contracts_path = root / "analysis" / "code_contracts.json"
    if not contracts_path.exists():
        try:
            from .code_contracts import build_code_contracts  # type: ignore
        except Exception:
            try:
                from code_contracts import build_code_contracts  # type: ignore
            except Exception:
                build_code_contracts = None  # type: ignore
        if build_code_contracts:
            try:
                build_code_contracts(root)
            except Exception:
                pass
    try:
        return json.loads(contracts_path.read_text(encoding="utf-8"))
    except Exception:
        return {}


def summarize_runtime_contracts(root: Path, limit: int = 60) -> List[Dict[str, Any]]:
    data = _load_code_contracts(root)
    contracts = data.get("contracts") or []
    if not isinstance(contracts, list):
        return []

    route_items: List[Dict[str, Any]] = []
    file_items: List[Dict[str, Any]] = []
    files_with_routes: set[str] = set()

    for c in contracts:
        if not isinstance(c, dict):
            continue
        kind = str(c.get("kind") or "")
        runtime = c.get("runtime") or {}
        if not runtime:
            continue
        try:
            event_count = int(runtime.get("event_count") or 0)
        except Exception:
            event_count = 0
        try:
            error_count = int(runtime.get("error_count") or 0)
        except Exception:
            error_count = 0
        try:
            error_rate = float(runtime.get("error_rate") or 0.0)
        except Exception:
            error_rate = 0.0
        try:
            avg_latency = float(runtime.get("avg_latency_ms") or 0.0)
        except Exception:
            avg_latency = 0.0
        last_error = str(runtime.get("last_error") or "").strip()

        if event_count <= 0:
            continue

        causality = c.get("runtime_causality") or {}
        if kind == "api":
            route = str(c.get("route") or "")
            file_path = str(c.get("file") or "")
            if file_path:
                files_with_routes.add(file_path.replace("\\", "/"))
            route_items.append(
                {
                    "target": route or "unknown_route",
                    "related": file_path or route,
                    "event_count": event_count,
                    "error_count": error_count,
                    "error_rate": error_rate,
                    "avg_latency": avg_latency,
                    "last_error": last_error,
                    "causality": causality,
                    "suspects": (causality.get("suspects") or []) if isinstance(causality, dict) else [],
                    "severity": "high" if (error_rate >= 0.2 or last_error) else "medium"
                    if avg_latency >= 2000
                    else "low",
                }
            )
        elif kind == "php_module":
            file_path = str(c.get("file") or "")
            file_items.append(
                {
                    "target": file_path or "unknown_file",
                    "related": file_path or "",
                    "event_count": event_count,
                    "error_count": error_count,
                    "error_rate": error_rate,
                    "avg_latency": avg_latency,
                    "last_error": last_error,
                    "causality": causality,
                    "suspects": (causality.get("suspects") or []) if isinstance(causality, dict) else [],
                    "severity": "high" if (error_rate >= 0.2 or last_error) else "medium"
                    if avg_latency >= 2000
                    else "low",
                }
            )

    # Prioritize route items; then include file items not already covered by routes.
    items: List[Dict[str, Any]] = []
    items.extend(route_items)
    for item in file_items:
        rel = str(item.get("related") or "").replace("\\", "/")
        if rel and rel in files_with_routes:
            continue
        items.append(item)

    def _score(it: Dict[str, Any]) -> float:
        return float(it.get("error_rate") or 0.0) * 10 + float(it.get("avg_latency") or 0.0) / 1000.0

    items = sorted(items, key=_score, reverse=True)
    summaries: List[Dict[str, Any]] = []
    for item in items:
        severity = item.get("severity") or "low"
        if severity == "low":
            continue
        target = str(item.get("target") or "unknown")
        scenario = f"runtime_contract:{target}"
        event_bucket = _bucket_count(int(item.get("event_count") or 0))
        error_bucket = _bucket_count(int(item.get("error_count") or 0))
        rate_bucket = _bucket_rate(float(item.get("error_rate") or 0.0))
        lat_bucket = _bucket_latency(float(item.get("avg_latency") or 0.0))
        has_error = bool(item.get("last_error"))
        summary = (
            f"Runtime evidence for {target}: events {event_bucket}, errors {error_bucket} "
            f"(rate {rate_bucket}), latency {lat_bucket}."
            + (" last_error_present." if has_error else "")
        )
        try:
            if "dependency_hotspot" in str(item.get("causality") or ""):
                suspects = item.get("suspects") or []
                if suspects:
                    summary += f" suspect_deps={','.join([str(s) for s in suspects[:4]])}."
        except Exception:
            pass
        confidence = 0.85 if severity == "high" else 0.7
        summaries.append(
            {
                "scenario": scenario,
                "summary": summary,
                "related_files": str(item.get("related") or target),
                "confidence": confidence,
                "evidence_count": int(item.get("event_count") or 0),
                "source_type": "runtime_contract",
            }
        )
        if len(summaries) >= limit:
            break

    return summaries


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
                "last_ts": 0.0,
                "ctx": {},
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
        try:
            ts = float(e["timestamp"] or 0)
        except Exception:
            ts = 0.0
        if ts >= float(g.get("last_ts") or 0):
            g["last_ts"] = ts
            g["ctx"] = _row_context(e)

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
                "run_id": (data.get("ctx") or {}).get("run_id"),
                "scenario_id": (data.get("ctx") or {}).get("scenario_id"),
                "goal_id": (data.get("ctx") or {}).get("goal_id"),
                "source_type": "runtime_event",
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


def _row_context(row: sqlite3.Row) -> Dict[str, str]:
    ctx: Dict[str, str] = {}
    try:
        keys = row.keys()
    except Exception:
        keys = []
    for key in ("run_id", "scenario_id", "goal_id"):
        try:
            if key in keys and row[key]:
                ctx[key] = str(row[key])
        except Exception:
            continue
    return ctx


def summarize_log_highlights(events: List[sqlite3.Row]) -> List[Dict]:
    summaries: List[Dict] = []
    for e in events:
        if e["event_type"] != "log_highlight":
            continue
        ctx = _row_context(e)
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
                "run_id": ctx.get("run_id"),
                "scenario_id": ctx.get("scenario_id"),
                "goal_id": ctx.get("goal_id"),
                "source_type": "log_highlight",
            }
        )
    return summaries


def summarize_route_scan_meta(events: List[sqlite3.Row]) -> List[Dict]:
    summaries: List[Dict] = []
    for e in events:
        if e["event_type"] != "route_scan_meta":
            continue
        ctx = _row_context(e)
        payload = _safe_json(e["payload"])
        route = str(payload.get("route") or e["route"] or "/")
        sources = payload.get("sources") or []
        if isinstance(sources, str):
            sources = [sources]
        source_label = ", ".join([s for s in sources if s]) or "route_indexer"
        reason = str(payload.get("reason") or "health_scan")
        file_path = str(payload.get("file_path") or "")
        summary = f"Route {route} scanned during health audit. Sources: {source_label}. Reason: {reason}."
        summaries.append(
            {
                "scenario": f"route_scan_meta:{route}",
                "summary": summary,
                "related_files": file_path or route,
                "confidence": 0.65,
                "evidence_count": 1,
                "run_id": ctx.get("run_id"),
                "scenario_id": ctx.get("scenario_id"),
                "goal_id": ctx.get("goal_id"),
                "source_type": "route_scan_meta",
            }
        )
    return summaries


def _extract_failure_class(note: str) -> str:
    if not note:
        return ""
    try:
        import re

        m = re.search(r"failure_class=([A-Za-z0-9_-]+)", note)
        if m:
            return m.group(1)
    except Exception:
        pass
    return ""


def _snapshot_context(snapshot_raw: str) -> Dict[str, str]:
    try:
        snap = json.loads(snapshot_raw or "{}")
    except Exception:
        snap = {}
    meta = snap.get("metadata") or {}
    return {
        "run_id": str(meta.get("run_id") or snap.get("run_id") or ""),
        "scenario_id": str(meta.get("scenario_id") or snap.get("scenario_id") or ""),
        "goal_id": str(meta.get("goal_id") or snap.get("goal_id") or ""),
    }


def fetch_outcomes(conn: sqlite3.Connection, cutoff: float, limit: int):
    conn.row_factory = sqlite3.Row
    cols = {r[1] for r in conn.execute("PRAGMA table_info(outcomes)").fetchall()}
    has_ctx_cols = {"run_id", "scenario_id", "goal_id"}.issubset(cols)
    select_ctx = ", o.run_id, o.scenario_id, o.goal_id" if has_ctx_cols else ""
    cur = conn.cursor()
    return cur.execute(
        f"""
        SELECT o.id, o.result, o.notes, o.timestamp{select_ctx},
               d.decision, d.risk_level, i.intent, i.context_snapshot
        FROM outcomes o
        JOIN decisions d ON o.decision_id = d.id
        JOIN intents i ON d.intent_id = i.id
        WHERE strftime('%s', o.timestamp) >= ?
        ORDER BY o.id DESC
        LIMIT ?
        """,
        (int(cutoff), int(limit)),
    ).fetchall()


def summarize_outcomes(outcomes: List[sqlite3.Row]) -> List[Dict]:
    summaries: List[Dict] = []
    grouped: Dict[str, Dict[str, Any]] = {}
    for row in outcomes:
        intent = str(row["intent"] or "unknown")
        result = str(row["result"] or "").lower()
        decision = str(row["decision"] or "")
        risk = str(row["risk_level"] or "")
        notes = str(row["notes"] or "")
        fclass = _extract_failure_class(notes)
        key = f"{intent}::{result}::{fclass}"
        g = grouped.setdefault(
            key,
            {
                "intent": intent,
                "result": result,
                "decision": decision,
                "risk": risk,
                "fclass": fclass,
                "count": 0,
                "last_note": "",
                "last_ts": 0.0,
                "ctx": {},
            },
        )
        g["count"] += 1
        try:
            ts = float(row["timestamp"] or 0)
        except Exception:
            ts = 0.0
        if ts >= float(g.get("last_ts") or 0):
            g["last_ts"] = ts
            g["last_note"] = notes[:220]
            ctx = {}
            try:
                ctx = {
                    "run_id": str(row["run_id"] or ""),
                    "scenario_id": str(row["scenario_id"] or ""),
                    "goal_id": str(row["goal_id"] or ""),
                }
            except Exception:
                ctx = {}
            if not any(ctx.values()):
                try:
                    ctx = _snapshot_context(str(row["context_snapshot"] or ""))
                except Exception:
                    ctx = _snapshot_context("")
            g["ctx"] = ctx

    for _, data in grouped.items():
        count = int(data.get("count") or 0)
        if count <= 0:
            continue
        result = data.get("result") or ""
        fclass = data.get("fclass") or ""
        label = fclass if fclass else result
        summary = (
            f"Outcome {result} for {data['intent']} ({data['decision']}/{data['risk']}) "
            f"x{count}. Last note: {data.get('last_note') or 'n/a'}"
        )
        confidence = 0.85 if result not in ("success", "success_sandbox", "success_direct") else 0.6
        summaries.append(
            {
                "scenario": f"outcome:{data['intent']}:{label}",
                "summary": summary,
                "related_files": data["intent"],
                "confidence": confidence,
                "evidence_count": count,
                "run_id": (data.get("ctx") or {}).get("run_id"),
                "scenario_id": (data.get("ctx") or {}).get("scenario_id"),
                "goal_id": (data.get("ctx") or {}).get("goal_id"),
                "source_type": "outcome",
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
        if "run_id" not in cols:
            conn.execute("ALTER TABLE experiences ADD COLUMN run_id TEXT")
        if "scenario_id" not in cols:
            conn.execute("ALTER TABLE experiences ADD COLUMN scenario_id TEXT")
        if "goal_id" not in cols:
            conn.execute("ALTER TABLE experiences ADD COLUMN goal_id TEXT")
        if "source_type" not in cols:
            conn.execute("ALTER TABLE experiences ADD COLUMN source_type TEXT")
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
                "source_type": "prod_ops",
            }
        )
    return summaries


def upsert_experiences(
    conn: sqlite3.Connection,
    experiences: List[Dict],
    *,
    time_left_fn: Optional[Callable[[], float]] = None,
    min_embed_budget: float = 8.0,
):
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
            suppressed INTEGER DEFAULT 0,
            run_id TEXT,
            scenario_id TEXT,
            goal_id TEXT,
            source_type TEXT
        )
        """
    )
    _ensure_experience_columns(conn)
    now = time.time()
    try:
        max_items = int(
            _cfg_number(
                "BGL_CONTEXT_DIGEST_EXPERIENCE_LIMIT",
                "context_digest_experience_limit",
                len(experiences),
            )
        )
    except Exception:
        max_items = len(experiences)
    if max_items > 0 and len(experiences) > max_items:
        experiences = experiences[:max_items]
    upserted: List[Dict] = []
    processed: List[Dict] = []
    for exp in experiences:
        try:
            if time_left_fn is not None and time_left_fn() < 3.0:
                break
        except Exception:
            pass
        scenario = exp["scenario"]
        summary = exp["summary"]
        exp_run_id = exp.get("run_id") or None
        exp_scenario_id = exp.get("scenario_id") or None
        exp_goal_id = exp.get("goal_id") or None
        exp_source_type = exp.get("source_type") or None
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
                SET updated_at=?, last_seen=?, seen_count=?, evidence_count=?, confidence=?, value_score=?,
                    run_id=COALESCE(?, run_id),
                    scenario_id=COALESCE(?, scenario_id),
                    goal_id=COALESCE(?, goal_id),
                    source_type=COALESCE(?, source_type)
                WHERE id=?
                """,
                (
                    now,
                    now,
                    seen_count,
                    evidence_total,
                    new_conf,
                    value_score,
                    exp_run_id,
                    exp_scenario_id,
                    exp_goal_id,
                    exp_source_type,
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
                INSERT INTO experiences (created_at, updated_at, scenario, summary, related_files, exp_hash, seen_count, last_seen, confidence, evidence_count, value_score, suppressed, run_id, scenario_id, goal_id, source_type)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)
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
                    exp_run_id,
                    exp_scenario_id,
                    exp_goal_id,
                    exp_source_type,
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
                "run_id": exp_run_id,
                "scenario_id": exp_scenario_id,
                "goal_id": exp_goal_id,
                "source_type": exp_source_type,
            }
        )
        processed.append(exp)

    conn.commit()

    # Index into semantic memory AFTER commit to avoid locking
    skip_embeddings = False
    try:
        if time_left_fn is not None and time_left_fn() < float(min_embed_budget):
            skip_embeddings = True
    except Exception:
        skip_embeddings = False
    if not skip_embeddings:
        try:
            embed_limit = int(
                _cfg_number(
                    "BGL_CONTEXT_DIGEST_EMBED_LIMIT",
                    "context_digest_embed_limit",
                    160,
                )
            )
        except Exception:
            embed_limit = 160
        embed_count = 0
        for exp in processed:
            if embed_limit > 0 and embed_count >= embed_limit:
                break
            try:
                if time_left_fn is not None and time_left_fn() < float(min_embed_budget):
                    break
            except Exception:
                pass
            if exp["confidence"] >= 0.3:
                add_text(f"[Experience] {exp['scenario']}", exp["summary"])
                embed_count += 1

    # Unified memory index (best-effort, non-blocking)
    try:
        from .memory_index import upsert_memory_item  # type: ignore
    except Exception:
        try:
            from memory_index import upsert_memory_item  # type: ignore
        except Exception:
            upsert_memory_item = None  # type: ignore
    if upsert_memory_item:
        try:
            memory_limit = int(
                _cfg_number(
                    "BGL_CONTEXT_DIGEST_MEMORY_LIMIT",
                    "context_digest_memory_limit",
                    220,
                )
            )
        except Exception:
            memory_limit = 220
        mem_count = 0
        for exp in processed:
            if memory_limit > 0 and mem_count >= memory_limit:
                break
            try:
                if time_left_fn is not None and time_left_fn() < 5.0:
                    break
            except Exception:
                pass
            scenario = str(exp.get("scenario") or "")
            kind = "log" if scenario.startswith("log_error:") else "experience"
            upsert_memory_item(
                DB_PATH,
                kind=kind,
                key_text=scenario,
                summary=str(exp.get("summary") or ""),
                evidence_count=int(exp.get("evidence_count") or 0),
                confidence=float(exp.get("confidence") or 0.5),
                meta={
                    "related_files": exp.get("related_files"),
                    "run_id": exp.get("run_id"),
                    "scenario_id": exp.get("scenario_id"),
                    "goal_id": exp.get("goal_id"),
                    "source_type": exp.get("source_type"),
                },
                source_table="experiences",
                source_id=None,
            )
            mem_count += 1
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


def auto_apply_proposals(proposal_ids: List[int], time_budget_sec: float | None = None) -> None:
    if not proposal_ids:
        return
    if not _cfg_flag("BGL_AUTO_APPLY", "auto_apply", True):
        return
    max_apply = _cfg_number("BGL_AUTO_APPLY_LIMIT", "auto_apply_limit", 3)
    timeout_sec = _cfg_number("BGL_AUTO_APPLY_TIMEOUT_SEC", "auto_apply_timeout_sec", 300)
    exe = sys.executable or "python"
    root = Path(__file__).resolve().parents[2]
    script = root / ".bgl_core" / "brain" / "apply_proposal.py"
    if not script.exists():
        return
    conn = None
    try:
        conn = connect_db(DB_PATH, timeout=30.0)
    except Exception:
        conn = None
    time_budget = float(time_budget_sec) if time_budget_sec is not None else None
    for pid in proposal_ids[:max_apply]:
        if time_budget is not None and time_budget < 8:
            break
        started = time.time()
        try:
            eff_timeout = float(timeout_sec)
            if time_budget is not None:
                eff_timeout = max(5.0, min(eff_timeout, time_budget - 2))
            result = subprocess.run(
                [exe, str(script), "--proposal", str(pid)],
                cwd=str(root),
                capture_output=True,
                text=True,
                check=False,
                timeout=float(eff_timeout),
            )
            duration = round(time.time() - started, 2)
            if time_budget is not None:
                time_budget = max(0.0, time_budget - duration)
            if conn is not None:
                payload = {
                    "proposal_id": int(pid),
                    "returncode": int(result.returncode or 0),
                    "duration_s": duration,
                }
                if result.returncode != 0:
                    err = (result.stderr or result.stdout or "").strip()
                    if err:
                        payload["error"] = err[:400]
                    _log_runtime_event(conn, "auto_apply_failed", payload)
                else:
                    _log_runtime_event(conn, "auto_apply_done", payload)
        except subprocess.TimeoutExpired:
            if time_budget is not None:
                time_budget = max(0.0, time_budget - float(timeout_sec))
            if conn is not None:
                _log_runtime_event(
                    conn,
                    "auto_apply_timeout",
                    {
                        "proposal_id": int(pid),
                        "timeout_sec": float(timeout_sec),
                        "duration_s": round(time.time() - started, 2),
                    },
                )
        except Exception as e:
            if conn is not None:
                _log_runtime_event(
                    conn,
                    "auto_apply_error",
                    {"proposal_id": int(pid), "error": str(e)},
                )
    try:
        if conn is not None:
            conn.close()
    except Exception:
        pass


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--hours", type=float, default=24, help="Lookback window in hours"
    )
    parser.add_argument("--limit", type=int, default=500, help="Max events to process")
    parser.add_argument(
        "--timeout",
        type=float,
        default=None,
        help="Max runtime seconds for this digest (0 disables limit)",
    )
    args = parser.parse_args()

    start_ts = time.time()
    stale_minutes = _cfg_number(
        "BGL_CONTEXT_DIGEST_STALE_MINUTES",
        "context_digest_stale_minutes",
        30,
    )
    prev_state = _read_digest_state()
    if prev_state.get("status") == "running":
        try:
            started_at = float(prev_state.get("started_at") or 0)
        except Exception:
            started_at = 0.0
        if started_at and (time.time() - started_at) < (float(stale_minutes) * 60.0):
            print("Context digest: already running (state guard).")
            return

    digest_id = f"{int(start_ts)}-{os.getpid()}"
    state: Dict[str, Any] = {
        "digest_id": digest_id,
        "status": "running",
        "started_at": start_ts,
        "pid": os.getpid(),
        "hours": float(args.hours),
        "limit": int(args.limit),
    }
    _write_digest_state(state)
    timeout_cfg = _cfg_number(
        "BGL_CONTEXT_DIGEST_TIMEOUT_SEC", "context_digest_timeout_sec", 110
    )
    timeout_sec = (
        float(args.timeout)
        if args.timeout is not None
        else float(timeout_cfg or 0)
    )
    deadline = (start_ts + timeout_sec) if timeout_sec and timeout_sec > 0 else None
    time_budget_left = lambda: 1e9
    if deadline is not None:
        time_budget_left = lambda: max(0.0, float(deadline - time.time()))

    def _time_left() -> float:
        return time_budget_left()

    def _expired() -> bool:
        return bool(deadline is not None and time.time() >= deadline)

    stats: Dict[str, Any] = {
        "events": 0,
        "outcomes": 0,
        "prod_ops": 0,
        "experiences": 0,
        "auto_proposals": 0,
    }

    def _finalize_state(status: str, **extra: Any) -> None:
        state.update(
            {
                "status": status,
                "ended_at": time.time(),
                "duration_sec": round(time.time() - start_ts, 2),
                "stats": stats,
            }
        )
        if extra:
            state.update(extra)
        _write_digest_state(state)

    cutoff = time.time() - args.hours * 3600

    conn = connect_db(DB_PATH, timeout=30.0)
    try:
        conn.execute("PRAGMA journal_mode=WAL;")
        conn.execute("PRAGMA busy_timeout=3000;")
    except Exception:
        pass

    def _budget_guard(stage: str) -> bool:
        if not _expired():
            return False
        try:
            _log_runtime_event(
                conn,
                "context_digest_timeout",
                {"stage": stage, "remaining_sec": round(_time_left(), 2)},
            )
        except Exception:
            pass
        _finalize_state("timeout", stage=stage, remaining_sec=round(_time_left(), 2))
        try:
            conn.close()
        except Exception:
            pass
        print(f"Context digest: time budget exhausted at {stage}.")
        return True

    # Runtime events (respect remaining time budget)
    event_limit = int(args.limit)
    try:
        if _time_left() < 25:
            event_limit = max(120, min(event_limit, int(_time_left() * 6)))
    except Exception:
        event_limit = int(args.limit)
    events = fetch_events(conn, cutoff, event_limit)
    stats["events"] = len(events or [])
    event_summaries: List[Dict] = []
    log_summaries: List[Dict] = []
    route_meta_summaries: List[Dict] = []
    if events:
        route_map = load_route_map(conn)
        event_summaries = summarize(events, route_map)
        log_summaries = summarize_log_highlights(events)
        route_meta_summaries = summarize_route_scan_meta(events)
    if _budget_guard("events"):
        return

    # Decision outcomes (intent/decision/outcome -> unified summaries)
    outcome_limit = int(args.limit)
    try:
        if _time_left() < 20:
            outcome_limit = max(80, min(outcome_limit, int(_time_left() * 4)))
    except Exception:
        outcome_limit = int(args.limit)
    outcomes = fetch_outcomes(conn, cutoff, outcome_limit)
    stats["outcomes"] = len(outcomes or [])
    outcome_summaries = summarize_outcomes(outcomes) if outcomes else []
    if _budget_guard("outcomes"):
        return

    # Prod operations (central log)
    prod_ops_full = _cfg_flag("BGL_PROD_OPS_FULL", "prod_ops_full", False)
    prod_ops_hours = _cfg_number("BGL_PROD_OPS_HOURS", "prod_ops_hours", args.hours)
    prod_ops_limit = _cfg_number("BGL_PROD_OPS_LIMIT", "prod_ops_limit", args.limit)
    try:
        if _time_left() < 18:
            prod_ops_limit = max(80, min(int(prod_ops_limit), int(_time_left() * 4)))
    except Exception:
        pass
    prod_cutoff = 0.0 if prod_ops_full or prod_ops_hours <= 0 else (time.time() - prod_ops_hours * 3600)
    prod_ops = fetch_prod_ops(conn, prod_cutoff, prod_ops_limit)
    stats["prod_ops"] = len(prod_ops or [])
    prod_summaries = summarize_prod_ops(prod_ops) if prod_ops else []
    if _budget_guard("prod_ops"):
        return

    runtime_contract_summaries: List[Dict[str, Any]] = []
    if _cfg_flag(
        "BGL_RUNTIME_CONTRACT_EXPERIENCES", "runtime_contract_experiences", True
    ):
        # Skip heavy contract build when nearing timeout budget.
        if _time_left() >= 25:
            runtime_contract_summaries = summarize_runtime_contracts(ROOT_DIR, limit=120)
    if _budget_guard("runtime_contracts"):
        return

    experiences = (
        event_summaries
        + log_summaries
        + route_meta_summaries
        + prod_summaries
        + outcome_summaries
        + runtime_contract_summaries
    )
    if not experiences:
        print("No events/prod operations/outcomes found in window.")
        _finalize_state("no_data")
        return

    inserted = upsert_experiences(conn, experiences, time_left_fn=_time_left)
    stats["experiences"] = len(inserted or [])
    if _budget_guard("experiences"):
        return

    # Auto-promote experiences -> proposals and auto-apply in sandbox
    proposal_ids = auto_promote_experiences(conn, inserted)
    stats["auto_proposals"] = len(proposal_ids or [])
    try:
        conn.close()
    except Exception:
        pass
    # Only auto-apply when sufficient time budget remains.
    if _time_left() >= 20:
        auto_apply_proposals(
            proposal_ids,
            time_budget_sec=_time_left(),
        )
    else:
        print("Context digest: skipping auto-apply due to time budget.")
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
            if _time_left() >= 15:
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
            else:
                print("Context digest: skipping auto memory curation due to time budget.")
        except Exception:
            pass
    print(
        f"Stored {len(experiences)} experience(s) from {len(events)} event(s) "
        f"+ {len(outcomes)} outcome(s) and {len(prod_ops)} prod op(s). "
        f"Auto proposals: {len(proposal_ids)}"
    )
    _finalize_state("ok")


if __name__ == "__main__":
    main()
