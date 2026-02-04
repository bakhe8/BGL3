"""
Unified Observations
--------------------
Persist a compact, queryable snapshot of "what the agent observed" about the BGL3
environment per diagnostic run.

Goal:
- Keep the agent's perception/health/route signals in one canonical place
  (knowledge.db) so downstream reasoning can be consistent even when LLM is offline.
"""

from __future__ import annotations

import json
import sqlite3
import time
from pathlib import Path
from typing import Any, Dict, Optional


def _ensure_tables(conn: sqlite3.Connection) -> None:
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS env_snapshots (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          created_at REAL NOT NULL,
          run_id TEXT NOT NULL,
          kind TEXT NOT NULL,
          source TEXT,
          confidence REAL,
          payload_json TEXT NOT NULL
        )
        """
    )
    conn.execute(
        "CREATE INDEX IF NOT EXISTS idx_env_snapshots_kind_time ON env_snapshots(kind, created_at DESC)"
    )


def store_env_snapshot(
    db_path: Path,
    *,
    run_id: str,
    kind: str,
    payload: Dict[str, Any],
    source: str = "agency_core",
    confidence: Optional[float] = None,
    created_at: Optional[float] = None,
) -> None:
    created_at = float(created_at if created_at is not None else time.time())
    with sqlite3.connect(str(db_path)) as conn:
        _ensure_tables(conn)
        conn.execute(
            """
            INSERT INTO env_snapshots (created_at, run_id, kind, source, confidence, payload_json)
            VALUES (?, ?, ?, ?, ?, ?)
            """,
            (
                created_at,
                run_id,
                kind,
                source,
                confidence,
                json.dumps(payload, ensure_ascii=False),
            ),
        )
        conn.commit()


def latest_env_snapshot(
    db_path: Path, *, kind: Optional[str] = None
) -> Optional[Dict[str, Any]]:
    with sqlite3.connect(str(db_path)) as conn:
        conn.row_factory = sqlite3.Row
        _ensure_tables(conn)
        if kind:
            row = conn.execute(
                """
                SELECT * FROM env_snapshots
                WHERE kind = ?
                ORDER BY created_at DESC
                LIMIT 1
                """,
                (kind,),
            ).fetchone()
        else:
            row = conn.execute(
                """
                SELECT * FROM env_snapshots
                ORDER BY created_at DESC
                LIMIT 1
                """
            ).fetchone()
        if not row:
            return None
        payload = {}
        try:
            payload = json.loads(row["payload_json"] or "{}")
        except Exception:
            payload = {"raw": row["payload_json"]}
        return {
            "id": row["id"],
            "created_at": row["created_at"],
            "run_id": row["run_id"],
            "kind": row["kind"],
            "source": row["source"],
            "confidence": row["confidence"],
            "payload": payload,
        }


def _safe_get(d: Dict[str, Any], path: str, default: Any = None) -> Any:
    cur: Any = d
    for part in path.split("."):
        if not isinstance(cur, dict):
            return default
        cur = cur.get(part)
        if cur is None:
            return default
    return cur


def _diff_scalar(prev: Any, curr: Any) -> Optional[Dict[str, Any]]:
    if prev == curr:
        return None
    return {"from": prev, "to": curr}


def compute_diagnostic_delta(prev_payload: Dict[str, Any], curr_payload: Dict[str, Any]) -> Dict[str, Any]:
    """
    A compact, stable delta between two diagnostic snapshots.

    Keep it small and deterministic: only compare a curated set of keys plus some
    aggregate counts to avoid noisy diffs.
    """
    keys = [
        "health_score",
        "route_scan_mode",
        "route_scan_limit",
        "execution_mode",
        "vitals.infrastructure",
        "vitals.business_logic",
        "vitals.architecture",
        "route_health.failing_routes_count",
        "route_health.worst_routes_count",
        "runtime_events_meta.count",
        "scenario_deps.status",
    ]

    changes: Dict[str, Any] = {}
    for k in keys:
        p = _safe_get(prev_payload, k)
        c = _safe_get(curr_payload, k)
        d = _diff_scalar(p, c)
        if d is not None:
            changes[k] = d

    # Signals aggregation (avoid deep diff/noise).
    prev_signals = prev_payload.get("signals") or {}
    curr_signals = curr_payload.get("signals") or {}
    prev_counts = prev_signals.get("counts") if isinstance(prev_signals, dict) else None
    curr_counts = curr_signals.get("counts") if isinstance(curr_signals, dict) else None
    if isinstance(prev_counts, dict) and isinstance(curr_counts, dict):
        for ck in ("actionable_failures", "warnings", "scan_artifacts", "ok"):
            d = _diff_scalar(prev_counts.get(ck), curr_counts.get(ck))
            if d is not None:
                changes[f"signals.counts.{ck}"] = d

    highlights = []
    for k, v in changes.items():
        if k.startswith("vitals.") or k.startswith("route_health.") or k.startswith("signals.counts."):
            highlights.append({"key": k, **v})

    return {
        "changes": changes,
        "highlights": highlights[:20],
        "summary": {
            "changed_keys": len(changes),
        },
    }


def store_latest_diagnostic_delta(db_path: Path, *, run_id: str, curr_snapshot_payload: Dict[str, Any]) -> None:
    """
    Compute and store delta against the previous diagnostic snapshot (if any).
    """
    prev = latest_env_snapshot(db_path, kind="diagnostic")
    if not prev or not isinstance(prev.get("payload"), dict):
        return
    prev_payload = prev["payload"]
    delta = compute_diagnostic_delta(prev_payload, curr_snapshot_payload)
    store_env_snapshot(
        db_path,
        run_id=run_id,
        kind="diagnostic_delta",
        payload=delta,
        source="agency_core",
        confidence=None,
        created_at=time.time(),
    )


def diagnostic_to_snapshot(diagnostic_map: Dict[str, Any]) -> Dict[str, Any]:
    """
    Keep the snapshot compact and stable (schema-ish).
    """
    findings = diagnostic_map.get("findings") or {}
    vitals = diagnostic_map.get("vitals") or {}
    readiness = diagnostic_map.get("readiness") or {}

    return {
        "health_score": diagnostic_map.get("health_score"),
        "route_scan_mode": diagnostic_map.get("route_scan_mode"),
        "route_scan_limit": diagnostic_map.get("route_scan_limit"),
        "scan_duration_seconds": diagnostic_map.get("scan_duration_seconds"),
        "target_duration_seconds": diagnostic_map.get("target_duration_seconds"),
        "execution_mode": diagnostic_map.get("execution_mode"),
        "vitals": {
            "infrastructure": bool(vitals.get("infrastructure", False)),
            "business_logic": bool(vitals.get("business_logic", False)),
            "architecture": bool(vitals.get("architecture", False)),
        },
        "readiness": readiness,
        "route_health": {
            "failing_routes_count": len(findings.get("failing_routes") or []),
            "worst_routes_count": len(findings.get("worst_routes") or []),
        },
        "signals": findings.get("signals") or {},
        "signals_intent_hint": findings.get("signals_intent_hint") or {},
        "intent": findings.get("intent") or {},
        "decision": findings.get("decision") or {},
        "scenario_deps": findings.get("scenario_deps") or {},
        "runtime_events_meta": findings.get("runtime_events_meta") or {},
        "notes": {
            "llm_fallback_used": bool(
                (findings.get("intent") or {}).get("source") == "fallback"
            ),
        },
    }


def compute_skip_recommendation(
    prev_diag_payload: Optional[Dict[str, Any]],
    curr_diag_payload: Dict[str, Any],
    prev_fp_payload: Optional[Dict[str, Any]],
    curr_fp_payload: Dict[str, Any],
) -> Dict[str, Any]:
    """
    Decide what to skip next run when things look stable.
    This is advisory; callers can enforce policy.
    """
    # If we don't have history, don't skip.
    if not prev_diag_payload or not prev_fp_payload:
        return {"ok": False, "reasons": ["no_history"], "skip": {}}

    delta = compute_diagnostic_delta(prev_diag_payload, curr_diag_payload)
    changed_keys = int((delta.get("summary") or {}).get("changed_keys") or 0)
    fp_same = False
    try:
        fp_same = prev_fp_payload.get("sig") == curr_fp_payload.get("sig")
    except Exception:
        fp_same = False

    route_fail = int(((curr_diag_payload.get("route_health") or {}).get("failing_routes_count") or 0))
    readiness_ok = None
    try:
        readiness_ok = bool((curr_diag_payload.get("readiness") or {}).get("ok"))
    except Exception:
        readiness_ok = None

    stable = fp_same and changed_keys == 0 and route_fail == 0 and readiness_ok is not False
    if not stable:
        reasons = []
        if not fp_same:
            reasons.append("fingerprint_changed")
        if changed_keys != 0:
            reasons.append("diagnostic_changed")
        if route_fail != 0:
            reasons.append("failing_routes_present")
        if readiness_ok is False:
            reasons.append("readiness_failed")
        return {"ok": False, "reasons": reasons, "skip": {}}

    # Stable: suggest skipping the expensive parts.
    return {
        "ok": True,
        "reasons": ["stable_environment"],
        "skip": {
            "reindex": True,
            "deep_route_scan": True,
        },
    }
