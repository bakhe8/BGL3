from __future__ import annotations

import hashlib
import json
import os
import sqlite3
import time
import shutil
import math
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

import yaml

from run_lock import acquire_lock, release_lock

ROOT = Path(__file__).parent.parent.parent
STATE_PATH = ROOT / ".bgl_core" / "logs" / "retention_state.json"
PREVIEW_PATH = ROOT / ".bgl_core" / "logs" / "retention_preview.json"
PRUNE_INDEX_PATH = ROOT / ".bgl_core" / "logs" / "prune_index.jsonl"
RETENTION_ACTIONS_PATH = ROOT / ".bgl_core" / "logs" / "retention_actions.json"
RETENTION_APPLY_PATH = ROOT / ".bgl_core" / "logs" / "retention_actions_applied.json"
RETENTION_ARCHIVE_DIR = ROOT / ".bgl_core" / "retention_archive"
LOCK_PATH = ROOT / ".bgl_core" / "logs" / "retention.lock"
DB_PATH = ROOT / ".bgl_core" / "brain" / "knowledge.db"


def _safe_yaml(path: Path) -> Dict[str, Any]:
    try:
        return yaml.safe_load(path.read_text(encoding="utf-8")) or {}
    except Exception:
        return {}


def _write_yaml(path: Path, payload: Dict[str, Any]) -> bool:
    try:
        path.parent.mkdir(parents=True, exist_ok=True)
        raw = yaml.safe_dump(payload, allow_unicode=True, sort_keys=False)
        path.write_text(raw, encoding="utf-8")
        return True
    except Exception:
        return False


def _safe_json(path: Path) -> Dict[str, Any]:
    try:
        if not path.exists():
            return {}
        return json.loads(path.read_text(encoding="utf-8")) or {}
    except Exception:
        return {}


def _write_json(path: Path, payload: Dict[str, Any]) -> None:
    try:
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    except Exception:
        pass


def _hash_text(text: str) -> str:
    return hashlib.sha1(text.encode("utf-8", errors="ignore")).hexdigest()


def _fingerprint_scenario(data: Dict[str, Any], path: Path) -> str:
    name = str(data.get("name") or path.stem)
    steps = data.get("steps") or []
    parts: List[str] = [name]
    if isinstance(steps, list):
        for step in steps:
            if not isinstance(step, dict):
                continue
            parts.append(str(step.get("action") or ""))
            parts.append(str(step.get("url") or ""))
            parts.append(str(step.get("route") or ""))
            parts.append(str(step.get("selector") or ""))
            parts.append(str(step.get("method") or ""))
    return _hash_text("|".join(parts))


def _step_fingerprint(step: Dict[str, Any]) -> str:
    parts = [
        str(step.get("action") or "").strip().lower(),
        str(step.get("url") or "").strip().lower(),
        str(step.get("route") or "").strip().lower(),
        str(step.get("selector") or "").strip().lower(),
        str(step.get("method") or "").strip().lower(),
        str(step.get("target") or "").strip().lower(),
    ]
    return _hash_text("|".join(parts))


def _fingerprint_generic(payload: Any) -> str:
    try:
        raw = json.dumps(payload, ensure_ascii=False, sort_keys=True)
    except Exception:
        raw = str(payload)
    return _hash_text(raw)


def _extract_routes_from_steps(steps: List[Dict[str, Any]]) -> Dict[str, Any]:
    routes: List[str] = []
    urls: List[str] = []
    selectors: List[str] = []
    actions: List[str] = []
    methods: List[str] = []
    has_ui = False
    has_api = False
    for step in steps:
        if not isinstance(step, dict):
            continue
        action = str(step.get("action") or "").strip().lower()
        if action:
            actions.append(action)
            if action in {"click", "fill", "select", "hover", "press", "type", "check", "uncheck", "scroll", "drag", "drop", "submit"}:
                has_ui = True
        selector = str(step.get("selector") or "").strip()
        if selector:
            selectors.append(selector)
            has_ui = True
        route = str(step.get("route") or "").strip()
        if route:
            routes.append(route)
        url = str(step.get("url") or "").strip()
        if url:
            urls.append(url)
            if "/api/" in url or url.endswith("/api") or "/api?" in url:
                has_api = True
        method = str(step.get("method") or "").strip().upper()
        if method:
            methods.append(method)
            if method in {"GET", "POST", "PUT", "PATCH", "DELETE"}:
                has_api = True
        if route.startswith("/api") or "/api/" in route:
            has_api = True
    return {
        "routes": sorted(set(routes)),
        "urls": sorted(set(urls)),
        "selectors": sorted(set(selectors)),
        "actions": sorted(set(actions)),
        "methods": sorted(set(methods)),
        "has_ui": has_ui,
        "has_api": has_api,
    }


def _load_report_signals() -> Dict[str, Any]:
    report = _safe_json(ROOT / ".bgl_core" / "logs" / "latest_report.json")
    diag_metrics = (report.get("diagnostic_comparison") or {}).get("metrics") or {}
    ui_delta = (diag_metrics.get("ui_action_coverage") or {}).get("delta")
    flow_delta = (diag_metrics.get("flow_coverage") or {}).get("delta")
    semantic_delta = (diag_metrics.get("semantic_change_count") or {}).get("delta")
    experiences = report.get("experiences") or []
    experience_keys: List[str] = []
    for exp in experiences:
        key = exp.get("scenario")
        if key:
            experience_keys.append(str(key))
    gap_scenarios = report.get("gap_scenarios") or []
    return {
        "ui_cov_delta": float(ui_delta or 0.0),
        "flow_cov_delta": float(flow_delta or 0.0),
        "semantic_delta": float(semantic_delta or 0.0),
        "semantic_changed": bool((report.get("ui_semantic_delta") or {}).get("changed")),
        "gap_scenarios": {str(name) for name in gap_scenarios if name},
        "experience_keys": {str(key) for key in experience_keys if key},
    }


def _read_decision_links(limit: int = 500) -> Dict[str, Dict[str, Any]]:
    links: Dict[str, Dict[str, Any]] = {}
    if not DB_PATH.exists():
        return links
    try:
        with sqlite3.connect(str(DB_PATH)) as conn:
            tables = {row[0] for row in conn.execute("SELECT name FROM sqlite_master WHERE type='table'").fetchall()}
            if "decision_traces" not in tables:
                return links
            rows = conn.execute(
                """
                SELECT
                    scenario_id,
                    COUNT(*) c,
                    SUM(
                        CASE
                            WHEN result IN ('fail', 'prevented_regression', 'regression') THEN 1
                            WHEN failure_class LIKE '%regress%' THEN 1
                            ELSE 0
                        END
                    ) regression_count,
                    MAX(created_at) last_ts
                FROM decision_traces
                WHERE scenario_id != ''
                GROUP BY scenario_id
                """
            ).fetchall()
            for scenario_id, count, regression_count, last_ts in rows:
                if not scenario_id:
                    continue
                links[str(scenario_id)] = {
                    "count": int(count or 0),
                    "regression_count": int(regression_count or 0),
                    "last_ts": float(last_ts or 0.0),
                }
            # Supplement links by scanning recent details_json for scenario references.
            rows = conn.execute(
                """
                SELECT scenario_id, details_json
                FROM decision_traces
                ORDER BY created_at DESC
                LIMIT ?
                """,
                (limit,),
            ).fetchall()
            for scenario_id, details_json in rows:
                if not scenario_id:
                    continue
                entry = links.setdefault(str(scenario_id), {"count": 0, "regression_count": 0, "last_ts": 0.0})
                entry["count"] = max(entry.get("count", 0), 1)
                if details_json and "regress" in str(details_json).lower():
                    entry["regression_count"] = max(entry.get("regression_count", 0), 1)
    except Exception:
        return links
    return links


def _read_runtime_stats() -> Dict[str, Dict[str, Any]]:
    stats: Dict[str, Dict[str, Any]] = {}
    if not DB_PATH.exists():
        return _read_runtime_fallback_stats()
    try:
        with sqlite3.connect(str(DB_PATH)) as conn:
            rows = conn.execute(
                """
                SELECT
                    scenario_id,
                    COUNT(*) c,
                    MAX(timestamp) last_ts,
                    SUM(CASE WHEN event_type LIKE '%timeout%' THEN 1 ELSE 0 END) timeout_count,
                    SUM(
                        CASE
                            WHEN event_type LIKE '%fail%' THEN 1
                            WHEN error IS NOT NULL AND error != '' THEN 1
                            WHEN COALESCE(status, 0) >= 400 THEN 1
                            ELSE 0
                        END
                    ) error_count
                FROM runtime_events
                WHERE scenario_id != ''
                GROUP BY scenario_id
                """
            ).fetchall()
        for scenario_id, count, last_ts, timeout_count, error_count in rows:
            if scenario_id:
                stats[str(scenario_id)] = {
                    "count": int(count or 0),
                    "last_ts": float(last_ts or 0.0),
                    "timeout_count": int(timeout_count or 0),
                    "error_count": int(error_count or 0),
                }
    except Exception:
        stats = {}

    fallback = _read_runtime_fallback_stats()
    if fallback:
        for scenario_id, fstat in fallback.items():
            if scenario_id in stats:
                merged = stats[scenario_id]
                merged["count"] = int(merged.get("count") or 0) + int(fstat.get("count") or 0)
                merged["last_ts"] = max(float(merged.get("last_ts") or 0.0), float(fstat.get("last_ts") or 0.0))
                merged["timeout_count"] = int(merged.get("timeout_count") or 0) + int(
                    fstat.get("timeout_count") or 0
                )
                merged["error_count"] = int(merged.get("error_count") or 0) + int(fstat.get("error_count") or 0)
            else:
                stats[scenario_id] = fstat
    return stats


def _read_runtime_fallback_stats(max_bytes: int = 2_000_000, max_lines: int = 5000) -> Dict[str, Dict[str, Any]]:
    stats: Dict[str, Dict[str, Any]] = {}
    fallback_path = ROOT / ".bgl_core" / "logs" / "runtime_events_fallback.jsonl"
    if not fallback_path.exists():
        return stats
    data = b""
    try:
        size = fallback_path.stat().st_size
        with fallback_path.open("rb") as fh:
            if size > max_bytes:
                fh.seek(max(0, size - max_bytes))
            data = fh.read(max_bytes)
    except Exception:
        return stats
    text = data.decode("utf-8", errors="ignore")
    lines = [ln for ln in text.splitlines() if ln.strip()]
    if len(lines) > max_lines:
        lines = lines[-max_lines:]
    for line in lines:
        try:
            payload = json.loads(line)
        except Exception:
            continue
        event = payload.get("event") or {}
        scenario_id = event.get("scenario_id") or event.get("scenario")
        if not scenario_id:
            continue
        scenario_id = str(scenario_id)
        ts = float(payload.get("timestamp") or event.get("timestamp") or 0.0)
        event_type = str(event.get("event_type") or "")
        error_flag = bool(event.get("error"))
        status = event.get("status")
        try:
            status_code = int(status) if status is not None else 0
        except Exception:
            status_code = 0
        timeout_flag = "timeout" in event_type
        fail_flag = "fail" in event_type or error_flag or status_code >= 400

        entry = stats.setdefault(
            scenario_id, {"count": 0, "last_ts": 0.0, "timeout_count": 0, "error_count": 0}
        )
        entry["count"] += 1
        entry["last_ts"] = max(float(entry.get("last_ts") or 0.0), ts)
        if timeout_flag:
            entry["timeout_count"] = int(entry.get("timeout_count") or 0) + 1
        if fail_flag:
            entry["error_count"] = int(entry.get("error_count") or 0) + 1
    return stats


def _log_runtime_event(event_type: str, payload: Dict[str, Any]) -> None:
    if not DB_PATH.exists():
        return
    try:
        with sqlite3.connect(str(DB_PATH), timeout=5.0) as conn:
            conn.execute("PRAGMA journal_mode=WAL;")
            conn.execute(
                """
                CREATE TABLE IF NOT EXISTS runtime_events (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    timestamp REAL NOT NULL,
                    session TEXT,
                    run_id TEXT,
                    scenario_id TEXT,
                    goal_id TEXT,
                    source TEXT,
                    event_type TEXT NOT NULL,
                    route TEXT,
                    method TEXT,
                    target TEXT,
                    step_id TEXT,
                    payload TEXT,
                    status INTEGER,
                    latency_ms REAL,
                    error TEXT
                )
                """
            )
            conn.execute(
                """
                INSERT INTO runtime_events (timestamp, session, run_id, scenario_id, goal_id, source, event_type, payload)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    time.time(),
                    "retention_engine",
                    "",
                    "",
                    "",
                    "retention_engine",
                    event_type,
                    json.dumps(payload, ensure_ascii=False),
                ),
            )
            conn.commit()
    except Exception:
        return


def _collect_scenarios() -> List[Dict[str, Any]]:
    out: List[Dict[str, Any]] = []
    base = ROOT / ".bgl_core" / "brain" / "scenarios"
    for path in base.rglob("*.yaml"):
        data = _safe_yaml(path)
        name = str(data.get("name") or path.stem)
        scenario_id = str(data.get("id") or "")
        steps = data.get("steps") if isinstance(data.get("steps"), list) else []
        step_summaries: List[Dict[str, Any]] = []
        for step in steps:
            if not isinstance(step, dict):
                continue
            summary = {
                "action": step.get("action"),
                "url": step.get("url"),
                "route": step.get("route"),
                "selector": step.get("selector"),
                "method": step.get("method"),
                "target": step.get("target"),
            }
            step_summaries.append(summary)
        step_fps = [_step_fingerprint(s) for s in step_summaries]
        route_info = _extract_routes_from_steps(step_summaries)
        try:
            mtime = float(path.stat().st_mtime)
        except Exception:
            mtime = 0.0
        fp = _fingerprint_scenario(data, path)
        out.append(
            {
                "kind": "scenario",
                "name": name,
                "scenario_id": scenario_id,
                "path": str(path),
                "fingerprint": fp,
                "steps": step_summaries,
                "step_fps": step_fps,
                "routes": route_info.get("routes"),
                "urls": route_info.get("urls"),
                "selectors": route_info.get("selectors"),
                "actions": route_info.get("actions"),
                "methods": route_info.get("methods"),
                "flags": {"has_ui": route_info.get("has_ui"), "has_api": route_info.get("has_api")},
                "meta": {"origin": data.get("meta", {}).get("origin")},
                "mtime": mtime,
            }
        )
    return out


def _collect_patch_plans() -> List[Dict[str, Any]]:
    out: List[Dict[str, Any]] = []
    base = ROOT / ".bgl_core" / "patch_plans"
    if not base.exists():
        return out
    for path in base.rglob("*.json"):
        data = _safe_json(path)
        try:
            mtime = float(path.stat().st_mtime)
        except Exception:
            mtime = 0.0
        fp = _fingerprint_generic({"changes": data.get("changes"), "id": data.get("id")})
        out.append(
            {
                "kind": "patch_plan",
                "name": str(data.get("id") or path.stem),
                "path": str(path),
                "fingerprint": fp,
                "meta": {"changes": len(data.get("changes") or [])},
                "mtime": mtime,
            }
        )
    return out


def _collect_backups() -> List[Dict[str, Any]]:
    out: List[Dict[str, Any]] = []
    base = ROOT / ".bgl_core" / "backups"
    if not base.exists():
        return out
    for path in base.iterdir():
        if not path.is_dir():
            continue
        # Limit fingerprinting to directory name + count to avoid heavy scans.
        file_count = 0
        try:
            for _ in path.rglob("*"):
                file_count += 1
                if file_count >= 2000:
                    break
        except Exception:
            file_count = 0
        try:
            mtime = float(path.stat().st_mtime)
        except Exception:
            mtime = 0.0
        fp = _fingerprint_generic({"name": path.name, "count": file_count})
        out.append(
            {
                "kind": "backup",
                "name": path.name,
                "path": str(path),
                "fingerprint": fp,
                "meta": {"files": file_count},
                "mtime": mtime,
            }
        )
    return out


def _collect_playbooks() -> List[Dict[str, Any]]:
    out: List[Dict[str, Any]] = []
    base = ROOT / ".bgl_core" / "brain" / "playbooks"
    if not base.exists():
        return out
    for path in base.rglob("*.md"):
        try:
            content = path.read_text(encoding="utf-8")
        except Exception:
            content = ""
        try:
            mtime = float(path.stat().st_mtime)
        except Exception:
            mtime = 0.0
        fp = _hash_text(content[:2000] + path.name)
        out.append(
            {
                "kind": "playbook",
                "name": path.stem,
                "path": str(path),
                "fingerprint": fp,
                "meta": {"size": len(content)},
                "mtime": mtime,
            }
        )
    return out


def _collect_logs() -> List[Dict[str, Any]]:
    out: List[Dict[str, Any]] = []
    base = ROOT / ".bgl_core" / "logs"
    if not base.exists():
        return out
    for path in base.glob("*.json*"):
        if path.name.startswith("latest_report") or path.name.startswith("diagnostic_baseline"):
            continue
        try:
            size = path.stat().st_size
        except Exception:
            size = 0
        try:
            mtime = float(path.stat().st_mtime)
        except Exception:
            mtime = 0.0
        fp = _fingerprint_generic({"path": path.name, "size": size})
        out.append(
            {
                "kind": "log",
                "name": path.name,
                "path": str(path),
                "fingerprint": fp,
                "meta": {"size": size},
                "mtime": mtime,
            }
        )
    return out


def _collect_insights() -> List[Dict[str, Any]]:
    out: List[Dict[str, Any]] = []
    base = ROOT / ".bgl_core" / "knowledge" / "auto_insights"
    if not base.exists():
        return out
    for path in base.glob("*.insight.md"):
        try:
            content = path.read_text(encoding="utf-8", errors="ignore")
        except Exception:
            content = ""
        source_path = ""
        for line in content.splitlines()[:12]:
            if line.strip().lower().startswith("**path**"):
                parts = line.split("`")
                if len(parts) >= 2:
                    source_path = parts[1]
                break
        try:
            mtime = float(path.stat().st_mtime)
        except Exception:
            mtime = 0.0
        fp = _hash_text(content[:4000] + path.name)
        out.append(
            {
                "kind": "insight",
                "name": path.stem,
                "path": str(path),
                "fingerprint": fp,
                "meta": {"size": len(content), "source_path": source_path},
                "mtime": mtime,
            }
        )
    return out


def _collect_snapshots(limit_per_kind: int) -> List[Dict[str, Any]]:
    out: List[Dict[str, Any]] = []
    if not DB_PATH.exists():
        return out
    try:
        with sqlite3.connect(str(DB_PATH)) as conn:
            conn.row_factory = sqlite3.Row
            env_rows = conn.execute(
                """
                SELECT id, created_at, run_id, kind, source, payload_json
                FROM env_snapshots
                ORDER BY created_at DESC
                LIMIT ?
                """,
                (limit_per_kind,),
            ).fetchall()
            for row in env_rows:
                payload = str(row["payload_json"] or "")
                fp = _hash_text(f"{row['kind']}|{payload[:4000]}")
                out.append(
                    {
                        "kind": "snapshot_env",
                        "name": str(row["kind"] or "env"),
                        "path": f"db:env_snapshots/{row['id']}",
                        "fingerprint": fp,
                        "meta": {"source": row["source"], "run_id": row["run_id"], "size": len(payload)},
                        "mtime": float(row["created_at"] or 0.0),
                    }
                )
            sem_rows = conn.execute(
                """
                SELECT id, created_at, url, source, digest, summary_json
                FROM ui_semantic_snapshots
                ORDER BY created_at DESC
                LIMIT ?
                """,
                (limit_per_kind,),
            ).fetchall()
            for row in sem_rows:
                digest = str(row["digest"] or "")
                fp = _hash_text(f"{row['url']}|{digest}")
                summary = str(row["summary_json"] or "")
                out.append(
                    {
                        "kind": "snapshot_ui_semantic",
                        "name": str(row["url"] or "ui_semantic"),
                        "path": f"db:ui_semantic_snapshots/{row['id']}",
                        "fingerprint": fp,
                        "meta": {"source": row["source"], "url": row["url"], "size": len(summary)},
                        "mtime": float(row["created_at"] or 0.0),
                    }
                )
            act_rows = conn.execute(
                """
                SELECT id, created_at, url, source, digest, candidates_json
                FROM ui_action_snapshots
                ORDER BY created_at DESC
                LIMIT ?
                """,
                (limit_per_kind,),
            ).fetchall()
            for row in act_rows:
                digest = str(row["digest"] or "")
                fp = _hash_text(f"{row['url']}|{digest}")
                candidates = str(row["candidates_json"] or "")
                out.append(
                    {
                        "kind": "snapshot_ui_action",
                        "name": str(row["url"] or "ui_action"),
                        "path": f"db:ui_action_snapshots/{row['id']}",
                        "fingerprint": fp,
                        "meta": {"source": row["source"], "url": row["url"], "size": len(candidates)},
                        "mtime": float(row["created_at"] or 0.0),
                    }
                )
    except Exception:
        return out
    return out


def _summarize_text_file(path: Path, *, max_bytes: int = 20000, max_lines: int = 120) -> str:
    try:
        size = path.stat().st_size
    except Exception:
        size = 0
    data = b""
    try:
        with path.open("rb") as fh:
            if size > max_bytes:
                fh.seek(max(0, size - max_bytes))
            data = fh.read(max_bytes)
    except Exception:
        data = b""
    text = data.decode("utf-8", errors="ignore")
    lines = [ln.strip() for ln in text.splitlines() if ln.strip()]
    if not lines:
        return ""
    if len(lines) > max_lines:
        lines = lines[-max_lines:]
    summary = " | ".join(lines)
    summary = summary.replace("\t", " ")
    if len(summary) > 1200:
        summary = summary[-1200:]
    return summary


def _log_retention_highlight(item: Dict[str, Any], summary: str, run_seq: int) -> None:
    kind = str(item.get("kind") or "")
    name = str(item.get("name") or Path(item.get("path", "")).stem or "")
    message = f"Retention archive {kind}: {name}."
    if summary:
        message += f" Summary: {summary}"
    payload = {
        "message": message,
        "source": f"retention_{kind}",
        "uri": str(item.get("path") or ""),
        "run_seq": run_seq,
        "utility_score": item.get("utility_score"),
    }
    _log_runtime_event("log_highlight", payload)


def _archive_item(path_str: str, run_seq: int) -> Tuple[bool, str]:
    if not path_str:
        return False, "missing_path"
    src = Path(path_str)
    if not src.exists():
        return False, "not_found"
    try:
        rel = src.resolve().relative_to(ROOT.resolve())
    except Exception:
        rel = Path(src.name)
    dest = RETENTION_ARCHIVE_DIR / f"run_{run_seq}" / rel
    try:
        dest.parent.mkdir(parents=True, exist_ok=True)
        shutil.move(str(src), str(dest))
        return True, str(dest)
    except Exception as exc:
        return False, str(exc)


def _utility_score(
    item: Dict[str, Any],
    stats: Dict[str, Dict[str, Any]],
    *,
    report_signals: Dict[str, Any],
    decision_links: Dict[str, Dict[str, Any]],
    step_stats: Dict[str, int],
    weights: Dict[str, float],
    uniqueness_rarity: int,
) -> float:
    kind = str(item.get("kind") or "")
    if kind == "scenario":
        keys = {item.get("scenario_id"), item.get("name"), Path(item.get("path", "")).stem}
        event_count = 0
        best_stat: Dict[str, Any] = {}
        for key in keys:
            if key and key in stats:
                stat = stats.get(key, {})
                count = int(stat.get("count") or 0)
                if count >= event_count:
                    event_count = count
                    best_stat = stat
        score = min(20.0, event_count * 0.1)
        name = str(item.get("name") or "")
        if name.startswith("gap_"):
            score += 10.0
        if name.startswith("goal_"):
            score += 5.0
        flags = item.get("flags") or {}
        has_ui = bool(flags.get("has_ui"))
        has_api = bool(flags.get("has_api"))
        scenario_path = str(item.get("path") or "")
        scenario_file = Path(scenario_path).name if scenario_path else ""
        gap_set = report_signals.get("gap_scenarios") or set()

        coverage_delta = 0.0
        if scenario_file in gap_set or name in gap_set:
            coverage_delta = 1.0
        else:
            if report_signals.get("ui_cov_delta", 0.0) > 0 and has_ui:
                coverage_delta = 0.5
            if report_signals.get("flow_cov_delta", 0.0) > 0 and ("flow" in name or "flow" in scenario_path):
                coverage_delta = max(coverage_delta, 0.4)

        semantic_delta = 1.0 if report_signals.get("semantic_changed") and has_ui else 0.0

        decision_link = 0.0
        regression_signal = 0.0
        for key in keys:
            if key and key in decision_links:
                link = decision_links.get(key, {})
                decision_link = max(decision_link, 1.0)
                if int(link.get("regression_count") or 0) > 0:
                    regression_signal = 1.0
        experience_keys = report_signals.get("experience_keys") or set()
        if experience_keys and decision_link == 0.0:
            scenario_keys = {scenario_path, scenario_file, name, str(item.get("scenario_id") or "")}
            for extra in (item.get("routes") or []):
                scenario_keys.add(str(extra))
            for extra in (item.get("urls") or []):
                scenario_keys.add(str(extra))
            for extra in (item.get("selectors") or []):
                scenario_keys.add(str(extra))
            if any(key in experience_keys for key in scenario_keys if key):
                decision_link = 0.6

        if regression_signal == 0.0:
            error_count = int(best_stat.get("error_count") or 0)
            timeout_count = int(best_stat.get("timeout_count") or 0)
            if error_count + timeout_count > 0:
                regression_signal = 0.4

        name_lower = name.lower()
        path_lower = scenario_path.lower()
        repro_tokens = ("repro", "regress", "bug", "issue", "fail", "error", "crash")
        repro_value = 1.0 if any(tok in name_lower or tok in path_lower for tok in repro_tokens) else 0.0
        if regression_signal > 0.0:
            repro_value = max(repro_value, 0.5)

        step_fps = item.get("step_fps") or []
        rare_steps = 0
        for fp in step_fps:
            if step_stats.get(fp, 0) <= uniqueness_rarity:
                rare_steps += 1
        uniqueness = (rare_steps / len(step_fps)) if step_fps else 0.0

        total_events = int(best_stat.get("count") or 0)
        error_count = int(best_stat.get("error_count") or 0)
        timeout_count = int(best_stat.get("timeout_count") or 0)
        run_quality = 0.0
        if total_events > 0:
            run_quality = max(0.0, (total_events - error_count - timeout_count) / float(total_events))

        signals = {
            "coverage_delta": coverage_delta,
            "semantic_delta": semantic_delta,
            "regression_signal": regression_signal,
            "decision_link": decision_link,
            "repro_value": repro_value,
            "uniqueness": uniqueness,
            "run_quality": run_quality,
        }
        if decision_link >= 1.0 or regression_signal >= 1.0:
            item["protected"] = True
        signal_score = 0.0
        signal_score += signals["coverage_delta"] * float(weights.get("coverage_delta", 0.0))
        signal_score += signals["semantic_delta"] * float(weights.get("semantic_delta", 0.0))
        signal_score += signals["regression_signal"] * float(weights.get("regression_signal", 0.0))
        signal_score += signals["decision_link"] * float(weights.get("decision_link", 0.0))
        signal_score += signals["repro_value"] * float(weights.get("repro_value", 0.0))
        signal_score += signals["uniqueness"] * float(weights.get("uniqueness", 0.0))
        signal_score += signals["run_quality"] * float(weights.get("run_quality", 0.0))

        item["utility_signals"] = {k: round(v, 4) for k, v in signals.items()}
        item["utility_signal_score"] = round(signal_score, 4)
        return score + signal_score
    if kind == "patch_plan":
        changes = int((item.get("meta") or {}).get("changes") or 0)
        return min(6.0, changes * 0.5)
    if kind == "backup":
        files = int((item.get("meta") or {}).get("files") or 0)
        return min(4.0, max(0.5, files / 500.0))
    if kind == "playbook":
        size = int((item.get("meta") or {}).get("size") or 0)
        return min(4.0, max(0.3, size / 8000.0))
    if kind == "log":
        size = int((item.get("meta") or {}).get("size") or 0)
        return min(1.5, max(0.1, size / 20000.0))
    if kind == "snapshot_env":
        size = int((item.get("meta") or {}).get("size") or 0)
        return min(1.5, max(0.5, size / 10000.0))
    if kind == "snapshot_ui_semantic":
        return 2.0
    if kind == "snapshot_ui_action":
        return 2.0
    return 0.0


def _last_used_ts(item: Dict[str, Any], stats: Dict[str, Dict[str, Any]]) -> float:
    kind = str(item.get("kind") or "")
    if kind == "scenario":
        keys = {item.get("scenario_id"), item.get("name"), Path(item.get("path", "")).stem}
        last_ts = 0.0
        for key in keys:
            if key and key in stats:
                last_ts = max(last_ts, float(stats.get(key, {}).get("last_ts") or 0.0))
        return last_ts
    return 0.0


def _compute_decay_factor(
    age_hours: float,
    *,
    grace_hours: float,
    decay_hours: float,
    min_factor: float,
) -> float:
    if age_hours <= grace_hours:
        return 1.0
    if decay_hours <= 0:
        return min_factor
    # Exponential decay to model utility fading with time.
    factor = math.exp(-max(0.0, age_hours - grace_hours) / decay_hours)
    return max(min_factor, min(1.0, factor))


def _collect_step_stats(items: List[Dict[str, Any]]) -> Dict[str, int]:
    stats: Dict[str, int] = {}
    for item in items:
        if item.get("kind") != "scenario":
            continue
        for fp in item.get("step_fps") or []:
            if not fp:
                continue
            stats[fp] = stats.get(fp, 0) + 1
    return stats


def _collect_merge_groups(items: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    groups: Dict[str, List[Dict[str, Any]]] = {}
    for item in items:
        if item.get("kind") != "scenario":
            continue
        fp = str(item.get("fingerprint") or "")
        if not fp:
            continue
        groups.setdefault(fp, []).append(item)
    out: List[Dict[str, Any]] = []
    for fp, members in groups.items():
        if len(members) < 2:
            continue
        members_sorted = sorted(members, key=lambda m: str(m.get("path") or ""))
        canonical = members_sorted[0]
        out.append(
            {
                "fingerprint": fp,
                "canonical": {"name": canonical.get("name"), "path": canonical.get("path")},
                "members": [{"name": m.get("name"), "path": m.get("path")} for m in members_sorted[1:]],
            }
        )
    return out


def _route_key(item: Dict[str, Any]) -> str:
    if item.get("kind") != "scenario":
        return ""
    routes = item.get("routes") or []
    if routes:
        return str(routes[0] or "")
    urls = item.get("urls") or []
    if urls:
        return str(urls[0] or "")
    name = str(item.get("name") or "")
    if name:
        return name
    return str(Path(item.get("path", "")).stem or "unknown")


def _collect_rewrite_candidates(
    items: List[Dict[str, Any]],
    step_stats: Dict[str, int],
    *,
    min_utility: float,
    rarity_threshold: int,
    min_keep_steps: int,
    max_keep_steps: int,
) -> List[Dict[str, Any]]:
    candidates: List[Dict[str, Any]] = []
    for item in items:
        if item.get("kind") != "scenario":
            continue
        if float(item.get("utility_score") or 0.0) > min_utility:
            continue
        steps = item.get("steps") or []
        step_fps = item.get("step_fps") or []
        keep: List[Dict[str, Any]] = []
        for idx, fp in enumerate(step_fps):
            freq = int(step_stats.get(fp) or 0)
            if freq <= rarity_threshold:
                step = steps[idx] if idx < len(steps) else {}
                keep.append(
                    {
                        "index": idx,
                        "fingerprint": fp,
                        "reason": f"rare_step<= {rarity_threshold}",
                        "action": step.get("action"),
                        "url": step.get("url"),
                        "route": step.get("route"),
                        "selector": step.get("selector"),
                    }
                )
        if len(keep) < min_keep_steps:
            continue
        keep = keep[:max_keep_steps]
        candidates.append(
            {
                "name": item.get("name"),
                "path": item.get("path"),
                "scenario_id": item.get("scenario_id"),
                "utility_score": item.get("utility_score"),
                "keep_steps": keep,
                "suggested_action": "rewrite_compact",
            }
        )
    return candidates


def _should_skip_path(path_str: str, allowlist: List[str]) -> bool:
    if not path_str:
        return False
    for token in allowlist or []:
        if token and token in path_str:
            return True
    return False


def _backup_file(path: Path, run_seq: int) -> Optional[str]:
    try:
        rel = path.resolve().relative_to(ROOT.resolve())
    except Exception:
        rel = Path(path.name)
    backup_dir = ROOT / ".bgl_core" / "backups" / "retention" / f"run_{run_seq}"
    dest = backup_dir / rel
    try:
        dest.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(path, dest)
        return str(dest)
    except Exception:
        return None


def _apply_rewrite(candidate: Dict[str, Any], run_seq: int, allowlist: List[str]) -> Tuple[bool, str]:
    path_str = str(candidate.get("path") or "")
    if not path_str:
        return False, "missing_path"
    if _should_skip_path(path_str, allowlist):
        return False, "allowlist"
    path = Path(path_str)
    data = _safe_yaml(path)
    if not data:
        return False, "unreadable_yaml"
    meta = data.get("meta") or {}
    retention = meta.get("retention") or {}
    try:
        prior_seq = int(retention.get("rewrite_run_seq") or 0)
    except Exception:
        prior_seq = 0
    if prior_seq >= run_seq:
        return False, "already_rewritten"
    keep_steps = candidate.get("keep_steps") or []
    if not keep_steps:
        return False, "no_keep_steps"
    steps = data.get("steps") or []
    new_steps: List[Dict[str, Any]] = []
    for entry in keep_steps:
        try:
            idx = int(entry.get("index"))
        except Exception:
            continue
        if idx < 0 or idx >= len(steps):
            continue
        step = steps[idx]
        if isinstance(step, dict):
            new_steps.append(step)
    if not new_steps:
        return False, "no_steps_retained"
    backup_path = _backup_file(path, run_seq)
    retention.update(
        {
            "rewrite_run_seq": run_seq,
            "rewrite_ts": time.time(),
            "rewrite_reason": "low_utility_compact",
            "rewrite_backup": backup_path or "",
        }
    )
    meta["retention"] = retention
    data["meta"] = meta
    data["steps"] = new_steps
    ok = _write_yaml(path, data)
    if ok:
        _log_runtime_event(
            "retention_rewrite",
            {
                "path": str(path),
                "scenario_id": candidate.get("scenario_id"),
                "kept_steps": len(new_steps),
                "run_seq": run_seq,
            },
        )
    return (ok, "rewritten" if ok else "write_failed")


def _apply_merge(group: Dict[str, Any], run_seq: int, allowlist: List[str]) -> Tuple[int, List[str]]:
    moved = 0
    errors: List[str] = []
    members = group.get("members") or []
    for member in members:
        path_str = str(member.get("path") or "")
        if not path_str:
            continue
        if _should_skip_path(path_str, allowlist):
            continue
        src = Path(path_str)
        if not src.exists():
            continue
        try:
            rel = src.resolve().relative_to(ROOT.resolve())
        except Exception:
            rel = Path(src.name)
        dest = RETENTION_ARCHIVE_DIR / f"run_{run_seq}" / rel
        try:
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.move(str(src), str(dest))
            moved += 1
        except Exception as exc:
            errors.append(f"{src}: {exc}")
    return moved, errors


def run_retention(root_dir: Path, cfg: Dict[str, Any], run_id: Optional[str] = None) -> Dict[str, Any]:
    enabled = bool(cfg.get("retention_enabled", 0))
    dry_run = bool(cfg.get("retention_dry_run", 1))
    if not enabled:
        return {"enabled": False}

    ttl_sec = int(cfg.get("retention_lock_ttl_sec", 1800) or 1800)
    ok, reason = acquire_lock(LOCK_PATH, ttl_sec=ttl_sec, label="retention")
    if not ok:
        return {"enabled": True, "status": "locked", "reason": reason}

    try:
        state = _safe_json(STATE_PATH)
        run_seq = int(state.get("run_seq") or 0) + 1
        allowlist = cfg.get("retention_allowlist") or []
        min_utility = float(cfg.get("retention_min_utility", 0.0) or 0.0)
        quota = int(cfg.get("retention_quota_per_run", 0) or 0)
        pause_on_low_cov = bool(cfg.get("retention_pause_on_low_coverage", 1))
        disable_time_prune = bool(cfg.get("retention_disable_time_prune", 0))

        report = _safe_json(ROOT / ".bgl_core" / "logs" / "latest_report.json")
        pause = False
        try:
            ui_cov = (report.get("ui_action_coverage") or {}).get("coverage_ratio")
            min_ui = float(cfg.get("min_ui_action_coverage", 30) or 30)
            if pause_on_low_cov and ui_cov is not None and float(ui_cov) < min_ui:
                pause = True
        except Exception:
            pause = False

        catalog: List[Dict[str, Any]] = []
        snapshot_limit = int(cfg.get("retention_snapshot_limit_per_kind", 500) or 500)
        catalog.extend(_collect_scenarios())
        catalog.extend(_collect_patch_plans())
        catalog.extend(_collect_backups())
        catalog.extend(_collect_playbooks())
        catalog.extend(_collect_logs())
        catalog.extend(_collect_insights())
        catalog.extend(_collect_snapshots(snapshot_limit))

        stats = _read_runtime_stats()
        report_signals = _load_report_signals()
        decision_links = _read_decision_links()
        prunable: List[Dict[str, Any]] = []
        kept: List[Dict[str, Any]] = []
        action_items: List[Dict[str, Any]] = []

        rewrite_rarity = int(cfg.get("retention_rewrite_rarity", 1) or 1)
        rewrite_min_steps = int(cfg.get("retention_rewrite_min_steps", 1) or 1)
        rewrite_max_steps = int(cfg.get("retention_rewrite_max_steps", 6) or 6)
        uniqueness_rarity = int(cfg.get("retention_uniqueness_rarity", rewrite_rarity) or rewrite_rarity)
        step_stats = _collect_step_stats(catalog)
        grace_hours = float(cfg.get("retention_grace_hours", 6) or 6)
        decay_hours = float(cfg.get("retention_decay_hours", 168) or 168)
        min_decay = float(cfg.get("retention_min_decay_factor", 0.0) or 0.0)
        weights = {
            "coverage_delta": float(cfg.get("retention_weight_coverage_delta", 4.0) or 4.0),
            "semantic_delta": float(cfg.get("retention_weight_semantic_delta", 3.0) or 3.0),
            "regression_signal": float(cfg.get("retention_weight_regression_signal", 5.0) or 5.0),
            "decision_link": float(cfg.get("retention_weight_decision_link", 2.5) or 2.5),
            "repro_value": float(cfg.get("retention_weight_repro_value", 3.5) or 3.5),
            "uniqueness": float(cfg.get("retention_weight_uniqueness", 2.0) or 2.0),
            "run_quality": float(cfg.get("retention_weight_run_quality", 1.5) or 1.5),
        }

        for item in catalog:
            raw_score = _utility_score(
                item,
                stats,
                report_signals=report_signals,
                decision_links=decision_links,
                step_stats=step_stats,
                weights=weights,
                uniqueness_rarity=uniqueness_rarity,
            )
            last_used = _last_used_ts(item, stats)
            mtime = float(item.get("mtime") or 0.0)
            last_signal = last_used or mtime
            age_hours = (time.time() - last_signal) / 3600.0 if last_signal > 0 else 0.0
            if disable_time_prune:
                decay_factor = 1.0
                age_hours = 0.0
            else:
                decay_factor = _compute_decay_factor(
                    age_hours,
                    grace_hours=grace_hours,
                    decay_hours=decay_hours,
                    min_factor=min_decay,
                )
            utility = raw_score * decay_factor
            item["utility_score_raw"] = round(raw_score, 4)
            item["utility_score"] = round(utility, 4)
            item["last_used_ts"] = last_used
            item["age_hours"] = round(age_hours, 2)
            item["decay_factor"] = round(decay_factor, 4)
            item["run_seq"] = run_seq
            path = str(item.get("path") or "")
            if any(a and a in path for a in allowlist):
                item["action"] = "keep_allowlist"
                kept.append(item)
                continue
            if item.get("protected"):
                item["action"] = "keep_protected"
                kept.append(item)
                continue
            if pause:
                item["action"] = "keep_paused"
                kept.append(item)
                continue
            if float(item.get("utility_score") or 0.0) <= min_utility:
                item["action"] = "prune_candidate"
                prunable.append(item)
            else:
                item["action"] = "keep"
                kept.append(item)

        top_k = int(cfg.get("retention_top_k_per_route", 0) or 0)
        if top_k > 0:
            groups: Dict[str, List[Dict[str, Any]]] = {}
            for item in catalog:
                if item.get("kind") != "scenario":
                    continue
                key = _route_key(item)
                if not key:
                    continue
                groups.setdefault(key, []).append(item)
            keep_paths = set()
            for _, members in groups.items():
                members_sorted = sorted(
                    members, key=lambda m: float(m.get("utility_score") or 0.0), reverse=True
                )
                for m in members_sorted[:top_k]:
                    path = str(m.get("path") or "")
                    if path:
                        keep_paths.add(path)
            if keep_paths:
                new_prunable: List[Dict[str, Any]] = []
                kept_paths = {str(k.get("path") or "") for k in kept}
                for item in prunable:
                    path = str(item.get("path") or "")
                    if path and path in keep_paths:
                        if path not in kept_paths:
                            kept.append(item)
                            kept_paths.add(path)
                        item["action"] = "keep_top_k"
                    else:
                        new_prunable.append(item)
                prunable = new_prunable
                for item in kept:
                    path = str(item.get("path") or "")
                    if path and path in keep_paths and item.get("action") == "keep":
                        item["action"] = "keep_top_k"

        if quota > 0 and len(prunable) > quota:
            prunable.sort(key=lambda i: (i.get("utility_score") or 0.0, str(i.get("name") or i.get("path") or "")))
            prunable = prunable[:quota]

        merge_groups = _collect_merge_groups([i for i in catalog if float(i.get("utility_score") or 0.0) <= min_utility])
        rewrite_candidates = _collect_rewrite_candidates(
            catalog,
            step_stats,
            min_utility=min_utility,
            rarity_threshold=rewrite_rarity,
            min_keep_steps=rewrite_min_steps,
            max_keep_steps=rewrite_max_steps,
        )
        if merge_groups:
            action_items.append({"type": "merge", "groups": merge_groups})
        if rewrite_candidates:
            action_items.append({"type": "rewrite", "candidates": rewrite_candidates})

        summary = {
            "enabled": True,
            "dry_run": dry_run,
            "run_seq": run_seq,
            "catalog_total": len(catalog),
            "prune_candidates": len(prunable),
            "kept": len(kept),
            "paused": pause,
            "prune_quota": quota,
            "rewrite_candidates": len(rewrite_candidates),
            "merge_groups": len(merge_groups),
            "top_k_per_route": top_k,
        }

        archived_count = 0
        archive_errors: List[str] = []
        archive_kinds = cfg.get("retention_archive_kinds") or [
            "log",
            "patch_plan",
            "playbook",
            "backup",
            "insight",
        ]
        if not dry_run and not pause:
            try:
                RETENTION_ARCHIVE_DIR.mkdir(parents=True, exist_ok=True)
            except Exception:
                pass

        if dry_run or pause:
            _write_json(PREVIEW_PATH, {"summary": summary, "candidates": prunable[:200]})
            _write_json(RETENTION_ACTIONS_PATH, {"summary": summary, "actions": action_items})
            _log_runtime_event("retention_preview", summary)
        else:
            with PRUNE_INDEX_PATH.open("a", encoding="utf-8") as fh:
                for item in prunable:
                    tombstone = {
                        "fingerprint": item.get("fingerprint"),
                        "kind": item.get("kind"),
                        "scenario_key": item.get("name"),
                        "reason": "low_utility",
                        "utility_score": item.get("utility_score"),
                        "evidence": "auto_retention",
                        "run_seq": run_seq,
                        "block_budget": int(cfg.get("retention_block_budget_default", 3) or 3),
                    }
                    fh.write(json.dumps(tombstone, ensure_ascii=False) + "\n")
            _write_json(RETENTION_ACTIONS_PATH, {"summary": summary, "actions": action_items})
            _log_runtime_event("retention_pruned", summary)
            if not pause:
                for item in prunable:
                    kind = str(item.get("kind") or "")
                    if kind not in archive_kinds:
                        continue
                    path = str(item.get("path") or "")
                    if _should_skip_path(path, allowlist):
                        continue
                    summary_text = _summarize_text_file(Path(path)) if path else ""
                    _log_retention_highlight(item, summary_text, run_seq)
                    ok, reason = _archive_item(path, run_seq)
                    if ok:
                        archived_count += 1
                    else:
                        archive_errors.append(f"{path}: {reason}")

        auto_apply = bool(cfg.get("retention_auto_apply", 0))
        apply_results: Dict[str, Any] = {
            "run_seq": run_seq,
            "auto_apply": auto_apply,
            "dry_run": dry_run,
            "paused": pause,
            "rewrite_applied": 0,
            "merge_applied": 0,
            "archived": archived_count,
            "errors": [],
        }
        if auto_apply and (not dry_run) and (not pause) and action_items:
            for action in action_items:
                if action.get("type") == "rewrite":
                    for cand in action.get("candidates") or []:
                        ok, reason = _apply_rewrite(cand, run_seq, allowlist)
                        if ok:
                            apply_results["rewrite_applied"] += 1
                        else:
                            apply_results["errors"].append(
                                {"type": "rewrite", "path": cand.get("path"), "reason": reason}
                            )
                if action.get("type") == "merge":
                    for group in action.get("groups") or []:
                        moved, errors = _apply_merge(group, run_seq, allowlist)
                        apply_results["merge_applied"] += moved
                        for err in errors:
                            apply_results["errors"].append({"type": "merge", "error": err})
            _log_runtime_event("retention_apply", apply_results)
        _write_json(RETENTION_APPLY_PATH, apply_results)

        summary["archived"] = archived_count
        if archive_errors:
            summary["archive_errors"] = archive_errors[:20]
        _write_json(STATE_PATH, summary)
        return summary
    finally:
        release_lock(LOCK_PATH)
