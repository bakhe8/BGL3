from __future__ import annotations

import json
import os
import re
import sqlite3
import time
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

try:
    import yaml  # type: ignore
except Exception:
    yaml = None  # type: ignore


def _connect(db_path: Path) -> sqlite3.Connection:
    conn = sqlite3.connect(str(db_path), timeout=30.0)
    conn.row_factory = sqlite3.Row
    try:
        conn.execute("PRAGMA journal_mode=WAL;")
    except Exception:
        pass
    return conn


def _safe_float(val: Any) -> Optional[float]:
    try:
        if val is None:
            return None
        return float(val)
    except Exception:
        return None


def _parse_target(target: Any) -> Optional[Tuple[str, float]]:
    if target is None:
        return None
    s = str(target).strip()
    if not s:
        return None
    m = re.search(r"(<=|>=|<|>|=)\s*([0-9.]+)", s)
    if not m:
        return None
    try:
        return (m.group(1), float(m.group(2)))
    except Exception:
        return None


def _compare_target(op: str, current: float, target: float) -> bool:
    if op == "<":
        return current < target
    if op == "<=":
        return current <= target
    if op == ">":
        return current > target
    if op == ">=":
        return current >= target
    if op == "=":
        return current == target
    return False


def load_domain_kpis(root_dir: Path) -> List[Dict[str, Any]]:
    if yaml is None:
        return []
    try:
        path = root_dir / "docs" / "domain_map.yml"
        if not path.exists():
            return []
        raw = path.read_text(encoding="utf-8", errors="ignore")
        data = yaml.safe_load(raw) or {}
        kpis = data.get("operational_kpis") or []
        return kpis if isinstance(kpis, list) else []
    except Exception:
        return []


def _calc_data_quality_score(app_db_path: Path) -> Optional[float]:
    if not app_db_path.exists():
        return None
    try:
        conn = sqlite3.connect(str(app_db_path), timeout=30.0)
        conn.row_factory = sqlite3.Row
        banks_total = int(conn.execute("SELECT COUNT(*) FROM banks").fetchone()[0] or 0)
        sup_total = int(conn.execute("SELECT COUNT(*) FROM suppliers").fetchone()[0] or 0)

        banks = conn.execute(
            "SELECT arabic_name, english_name, short_name, normalized_name, contact_email FROM banks"
        ).fetchall()
        suppliers = conn.execute(
            "SELECT official_name, normalized_name FROM suppliers"
        ).fetchall()
        conn.close()

        valid_banks = 0
        for b in banks:
            has_name = bool(
                (b["arabic_name"] or "").strip()
                or (b["english_name"] or "").strip()
                or (b["short_name"] or "").strip()
            )
            has_norm = bool((b["normalized_name"] or "").strip())
            if not has_name or not has_norm:
                continue
            email = (b["contact_email"] or "").strip()
            if email and not re.match(r"^[^@\s]+@[^@\s]+\.[^@\s]+$", email):
                continue
            valid_banks += 1

        valid_suppliers = 0
        for s in suppliers:
            has_name = bool((s["official_name"] or "").strip())
            has_norm = bool((s["normalized_name"] or "").strip())
            if has_name and has_norm:
                valid_suppliers += 1

        total = banks_total + sup_total
        if total == 0:
            return None
        return round(((valid_banks + valid_suppliers) / float(total)) * 100.0, 2)
    except Exception:
        return None


def compute_kpi_metrics(root_dir: Path, db_path: Path) -> Dict[str, Any]:
    current: Dict[str, Any] = {}
    scopes: Dict[str, List[str]] = {}
    targets: Dict[str, Any] = {}

    if not db_path.exists():
        return {"current": current, "scopes": scopes, "targets": targets, "status": {}, "summary": {}}

    try:
        conn = _connect(db_path)
        cur = conn.cursor()

        write_routes = [
            "/api/create-guarantee.php",
            "/api/update_bank.php",
            "/api/update_supplier.php",
            "/api/import_suppliers.php",
            "/api/import_banks.php",
            "/api/create-bank.php",
            "/api/create-supplier.php",
        ]
        placeholders = ",".join(["?"] * len(write_routes))
        total_writes = cur.execute(
            f"SELECT COUNT(*) FROM runtime_events WHERE route IN ({placeholders})",
            write_routes,
        ).fetchone()[0] or 0
        error_writes = cur.execute(
            f"SELECT COUNT(*) FROM runtime_events WHERE route IN ({placeholders}) AND status >= 400",
            write_routes,
        ).fetchone()[0] or 0
        val_fails = cur.execute(
            f"SELECT COUNT(*) FROM runtime_events WHERE route IN ({placeholders}) AND status = 422",
            write_routes,
        ).fetchone()[0] or 0

        if total_writes:
            current["api_error_rate"] = round((float(error_writes) / float(total_writes)) * 100.0, 2)
            current["validation_failure_rate"] = round((float(val_fails) / float(total_writes)) * 100.0, 2)

        lat = cur.execute(
            f"SELECT AVG(latency_ms) FROM runtime_events WHERE route IN ({placeholders}) AND latency_ms IS NOT NULL",
            write_routes,
        ).fetchone()[0]
        if lat is not None:
            current["contract_latency_ms"] = round(float(lat), 1)

        imp_total = cur.execute(
            "SELECT COUNT(*) FROM runtime_events WHERE event_type IN ('import_suppliers','import_banks')"
        ).fetchone()[0] or 0
        imp_fail = cur.execute(
            "SELECT COUNT(*) FROM runtime_events WHERE event_type IN ('import_suppliers','import_banks') AND (status >= 400 OR error IS NOT NULL)"
        ).fetchone()[0] or 0
        if imp_total:
            current["import_success_rate"] = round((1.0 - (float(imp_fail) / float(imp_total))) * 100.0, 2)

        scope_rows = cur.execute(
            "SELECT DISTINCT event_type FROM runtime_events WHERE event_type IN ('import_suppliers','import_banks')"
        ).fetchall()
        scopes["import_success_rate"] = [str(r[0]) for r in scope_rows if r and r[0]]

        scope_routes = cur.execute(
            f"SELECT DISTINCT route FROM runtime_events WHERE route IN ({placeholders}) AND route IS NOT NULL",
            write_routes,
        ).fetchall()
        present_routes = [str(r[0]) for r in scope_routes if r and r[0]]
        scopes["api_error_rate"] = present_routes
        scopes["validation_failure_rate"] = present_routes
        scopes["contract_latency_ms"] = present_routes

        conn.close()
    except Exception:
        pass

    app_db_path = root_dir / "storage" / "database" / "app.sqlite"
    quality_score = _calc_data_quality_score(app_db_path)
    if quality_score is not None:
        current["data_quality_score"] = quality_score
        scopes["data_quality_score"] = ["banks", "suppliers"]

    for k in load_domain_kpis(root_dir):
        name = str(k.get("name") or "").strip()
        if name:
            targets[name] = k.get("target")

    status: Dict[str, str] = {}
    bad = warn = ok = unknown = 0
    violations: List[Dict[str, Any]] = []
    for name, val in current.items():
        cur_val = _safe_float(val)
        target = targets.get(name)
        parsed = _parse_target(target)
        if cur_val is None or parsed is None:
            status[name] = "warn"
            warn += 1
            continue
        op, tgt = parsed
        meets = _compare_target(op, float(cur_val), float(tgt))
        status[name] = "ok" if meets else "bad"
        if meets:
            ok += 1
        else:
            bad += 1
            violations.append(
                {
                    "name": name,
                    "current": cur_val,
                    "target": str(target),
                }
            )

    unknown = max(0, len(current) - (ok + warn + bad))

    return {
        "current": current,
        "scopes": scopes,
        "targets": targets,
        "status": status,
        "summary": {
            "ok": ok,
            "warn": warn,
            "bad": bad,
            "unknown": unknown,
        },
        "violations": violations[:10],
    }


def summarize_agent_activity(
    db_path: Path, *, lookback_hours: int = 6, limit: int = 50
) -> Dict[str, Any]:
    if not db_path.exists():
        return {"total": 0, "recent": 0, "stale": True}
    try:
        conn = _connect(db_path)
        cutoff = time.time() - (float(lookback_hours) * 3600.0)
        rows = conn.execute(
            """
            SELECT timestamp, activity, type, message
            FROM agent_activity
            WHERE timestamp >= ?
            ORDER BY timestamp DESC
            LIMIT ?
            """,
            (float(cutoff), int(limit)),
        ).fetchall()
        conn.close()
    except Exception:
        rows = []

    total_recent = len(rows)
    last_ts = 0.0
    last_name = ""
    unique: Dict[str, int] = {}
    for r in rows:
        ts = float(r["timestamp"] or 0)
        if ts >= last_ts:
            last_ts = ts
            raw = r["activity"] or r["type"] or r["message"] or ""
            last_name = str(raw)
        raw = r["activity"] or r["type"] or r["message"] or ""
        key = str(raw or "").strip() or "unknown"
        unique[key] = unique.get(key, 0) + 1

    try:
        stale_minutes = int(os.getenv("BGL_ACTIVITY_STALE_MIN", "30") or 30)
    except Exception:
        stale_minutes = 30
    stale = True
    if last_ts > 0:
        stale = (time.time() - last_ts) >= float(stale_minutes) * 60.0

    top = sorted(unique.items(), key=lambda kv: kv[1], reverse=True)[:6]
    return {
        "total": total_recent,
        "recent": total_recent,
        "last_ts": last_ts,
        "last_activity": last_name[:120],
        "top_activity": [{"name": k, "count": v} for k, v in top],
        "stale": stale,
    }
