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


def _ensure_ui_semantic_tables(conn: sqlite3.Connection) -> None:
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS ui_semantic_snapshots (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          created_at REAL NOT NULL,
          url TEXT NOT NULL,
          source TEXT,
          digest TEXT,
          summary_json TEXT NOT NULL,
          payload_json TEXT
        )
        """
    )
    conn.execute(
        "CREATE INDEX IF NOT EXISTS idx_ui_semantic_url_time ON ui_semantic_snapshots(url, created_at DESC)"
    )


def _ensure_ui_action_tables(conn: sqlite3.Connection) -> None:
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS ui_action_snapshots (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          created_at REAL NOT NULL,
          url TEXT NOT NULL,
          source TEXT,
          digest TEXT,
          candidates_json TEXT NOT NULL
        )
        """
    )
    conn.execute(
        "CREATE INDEX IF NOT EXISTS idx_ui_action_url_time ON ui_action_snapshots(url, created_at DESC)"
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


def store_ui_semantic_snapshot(
    db_path: Path,
    *,
    url: str,
    summary: Dict[str, Any],
    payload: Optional[Dict[str, Any]] = None,
    source: str = "browser_sensor",
    created_at: Optional[float] = None,
) -> Optional[Dict[str, Any]]:
    if not url:
        return None
    created_at = float(created_at if created_at is not None else time.time())
    try:
        import hashlib

        digest = hashlib.sha1(
            json.dumps(summary or {}, ensure_ascii=False, sort_keys=True).encode("utf-8")
        ).hexdigest()
    except Exception:
        digest = ""
    with sqlite3.connect(str(db_path)) as conn:
        conn.row_factory = sqlite3.Row
        _ensure_ui_semantic_tables(conn)
        try:
            prev = conn.execute(
                "SELECT digest FROM ui_semantic_snapshots WHERE url = ? ORDER BY created_at DESC LIMIT 1",
                (url,),
            ).fetchone()
            if prev and prev.get("digest") == digest:
                return None
        except Exception:
            pass
        conn.execute(
            """
            INSERT INTO ui_semantic_snapshots (created_at, url, source, digest, summary_json, payload_json)
            VALUES (?, ?, ?, ?, ?, ?)
            """,
            (
                created_at,
                url,
                source,
                digest,
                json.dumps(summary or {}, ensure_ascii=False),
                json.dumps(payload or {}, ensure_ascii=False) if payload else None,
            ),
        )
        conn.commit()
    return {"created_at": created_at, "digest": digest}


def store_ui_action_snapshot(
    db_path: Path,
    *,
    url: str,
    candidates: Any,
    source: str = "scenario_runner",
    created_at: Optional[float] = None,
) -> Optional[Dict[str, Any]]:
    if not url:
        return None
    created_at = float(created_at if created_at is not None else time.time())
    try:
        import hashlib

        digest = hashlib.sha1(
            json.dumps(candidates or [], ensure_ascii=False, sort_keys=True).encode("utf-8")
        ).hexdigest()
    except Exception:
        digest = ""
    with sqlite3.connect(str(db_path)) as conn:
        conn.row_factory = sqlite3.Row
        _ensure_ui_action_tables(conn)
        try:
            prev = conn.execute(
                "SELECT digest FROM ui_action_snapshots WHERE url = ? ORDER BY created_at DESC LIMIT 1",
                (url,),
            ).fetchone()
            if prev and prev.get("digest") == digest:
                return None
        except Exception:
            pass
        conn.execute(
            """
            INSERT INTO ui_action_snapshots (created_at, url, source, digest, candidates_json)
            VALUES (?, ?, ?, ?, ?)
            """,
            (
                created_at,
                url,
                source,
                digest,
                json.dumps(candidates or [], ensure_ascii=False),
            ),
        )
        conn.commit()
    return {"created_at": created_at, "digest": digest}

def latest_ui_semantic_snapshot(
    db_path: Path, *, url: Optional[str] = None
) -> Optional[Dict[str, Any]]:
    with sqlite3.connect(str(db_path)) as conn:
        conn.row_factory = sqlite3.Row
        _ensure_ui_semantic_tables(conn)
        if url:
            row = conn.execute(
                """
                SELECT * FROM ui_semantic_snapshots
                WHERE url = ?
                ORDER BY created_at DESC
                LIMIT 1
                """,
                (url,),
            ).fetchone()
        else:
            row = conn.execute(
                """
                SELECT * FROM ui_semantic_snapshots
                ORDER BY created_at DESC
                LIMIT 1
                """
            ).fetchone()
        if not row:
            return None
        summary = {}
        payload = {}
        try:
            summary = json.loads(row["summary_json"] or "{}")
        except Exception:
            summary = {"raw": row["summary_json"]}
        try:
            payload = json.loads(row["payload_json"] or "{}") if row["payload_json"] else {}
        except Exception:
            payload = {"raw": row["payload_json"]}
        return {
            "id": row["id"],
            "created_at": row["created_at"],
            "url": row["url"],
            "source": row["source"],
            "digest": row["digest"],
            "summary": summary,
            "payload": payload,
        }


def previous_ui_semantic_snapshot(
    db_path: Path, *, url: str, before_ts: float
) -> Optional[Dict[str, Any]]:
    if not url:
        return None
    with sqlite3.connect(str(db_path)) as conn:
        conn.row_factory = sqlite3.Row
        _ensure_ui_semantic_tables(conn)
        row = conn.execute(
            """
            SELECT * FROM ui_semantic_snapshots
            WHERE url = ? AND created_at < ?
            ORDER BY created_at DESC
            LIMIT 1
            """,
            (url, float(before_ts)),
        ).fetchone()
        if not row:
            return None
        summary = {}
        payload = {}
        try:
            summary = json.loads(row["summary_json"] or "{}")
        except Exception:
            summary = {"raw": row["summary_json"]}
        try:
            payload = json.loads(row["payload_json"] or "{}") if row["payload_json"] else {}
        except Exception:
            payload = {"raw": row["payload_json"]}
        return {
            "id": row["id"],
            "created_at": row["created_at"],
            "url": row["url"],
            "source": row["source"],
            "digest": row["digest"],
            "summary": summary,
            "payload": payload,
        }


def compute_ui_semantic_delta(
    prev_summary: Optional[Dict[str, Any]], curr_summary: Optional[Dict[str, Any]]
) -> Dict[str, Any]:
    if not prev_summary or not curr_summary:
        return {"changed": False, "reason": "missing_prev_or_curr"}

    def _norm(s: Any) -> str:
        return str(s or "").strip().lower()

    def _list_set(items: Any) -> set:
        if not isinstance(items, list):
            return set()
        out = set()
        for item in items:
            if isinstance(item, dict):
                if "text" in item:
                    out.add(_norm(item.get("text")))
                else:
                    out.add(_norm(json.dumps(item, ensure_ascii=False, sort_keys=True)))
            else:
                out.add(_norm(item))
        return {i for i in out if i}

    def _diff(a: set, b: set, limit: int = 8) -> Dict[str, Any]:
        added = sorted(b - a)[:limit]
        removed = sorted(a - b)[:limit]
        return {"added": added, "removed": removed, "count_added": len(b - a), "count_removed": len(a - b)}

    delta = {
        "title_changed": _norm(prev_summary.get("title")) != _norm(curr_summary.get("title")),
        "headings": _diff(_list_set(prev_summary.get("headings")), _list_set(curr_summary.get("headings"))),
        "forms": _diff(_list_set(prev_summary.get("forms")), _list_set(curr_summary.get("forms"))),
        "tables": _diff(_list_set(prev_summary.get("tables")), _list_set(curr_summary.get("tables"))),
        "stats": _diff(_list_set(prev_summary.get("stats")), _list_set(curr_summary.get("stats"))),
        "nav_items": _diff(_list_set(prev_summary.get("nav_items")), _list_set(curr_summary.get("nav_items"))),
        "breadcrumbs": _diff(_list_set(prev_summary.get("breadcrumbs")), _list_set(curr_summary.get("breadcrumbs"))),
        "primary_actions": _diff(_list_set(prev_summary.get("primary_actions")), _list_set(curr_summary.get("primary_actions"))),
        "search_fields": _diff(_list_set(prev_summary.get("search_fields")), _list_set(curr_summary.get("search_fields"))),
        "text_blocks": _diff(_list_set(prev_summary.get("text_blocks")), _list_set(curr_summary.get("text_blocks"))),
        "text_keywords": _diff(_list_set(prev_summary.get("text_keywords")), _list_set(curr_summary.get("text_keywords"))),
        "page_type_changed": _norm(prev_summary.get("page_type")) != _norm(curr_summary.get("page_type")),
    }

    # Include UI state transitions (modals/loading/errors) as semantic deltas.
    try:
        prev_states = prev_summary.get("ui_states") if isinstance(prev_summary, dict) else {}
        curr_states = curr_summary.get("ui_states") if isinstance(curr_summary, dict) else {}
        if not isinstance(prev_states, dict):
            prev_states = {}
        if not isinstance(curr_states, dict):
            curr_states = {}
        state_keys = set(prev_states.keys()) | set(curr_states.keys())
        changed_keys = []
        for key in sorted(state_keys):
            if _norm(prev_states.get(key)) != _norm(curr_states.get(key)):
                changed_keys.append(key)
        delta["ui_states"] = {
            "changed": bool(changed_keys),
            "changed_keys": changed_keys[:8],
            "count_changed": len(changed_keys),
        }
    except Exception:
        delta["ui_states"] = {"changed": False, "changed_keys": [], "count_changed": 0}

    changed = (
        delta["title_changed"]
        or delta["headings"]["count_added"]
        or delta["headings"]["count_removed"]
        or delta["forms"]["count_added"]
        or delta["forms"]["count_removed"]
        or delta["tables"]["count_added"]
        or delta["tables"]["count_removed"]
        or delta["stats"]["count_added"]
        or delta["stats"]["count_removed"]
        or delta["nav_items"]["count_added"]
        or delta["nav_items"]["count_removed"]
        or delta["breadcrumbs"]["count_added"]
        or delta["breadcrumbs"]["count_removed"]
        or delta["primary_actions"]["count_added"]
        or delta["primary_actions"]["count_removed"]
        or delta["search_fields"]["count_added"]
        or delta["search_fields"]["count_removed"]
        or delta["text_blocks"]["count_added"]
        or delta["text_blocks"]["count_removed"]
        or delta["text_keywords"]["count_added"]
        or delta["text_keywords"]["count_removed"]
        or delta["page_type_changed"]
        or bool((delta.get("ui_states") or {}).get("changed"))
    )

    change_count = 0
    try:
        change_count = (
            int(delta["headings"]["count_added"]) + int(delta["headings"]["count_removed"])
            + int(delta["forms"]["count_added"]) + int(delta["forms"]["count_removed"])
            + int(delta["tables"]["count_added"]) + int(delta["tables"]["count_removed"])
            + int(delta["stats"]["count_added"]) + int(delta["stats"]["count_removed"])
            + int(delta["nav_items"]["count_added"]) + int(delta["nav_items"]["count_removed"])
            + int(delta["breadcrumbs"]["count_added"]) + int(delta["breadcrumbs"]["count_removed"])
            + int(delta["primary_actions"]["count_added"]) + int(delta["primary_actions"]["count_removed"])
            + int(delta["search_fields"]["count_added"]) + int(delta["search_fields"]["count_removed"])
            + int(delta["text_blocks"]["count_added"]) + int(delta["text_blocks"]["count_removed"])
            + int(delta["text_keywords"]["count_added"]) + int(delta["text_keywords"]["count_removed"])
        )
        if isinstance(delta.get("ui_states"), dict):
            change_count += int(delta["ui_states"].get("count_changed") or 0)
    except Exception:
        change_count = 0

    delta["changed"] = bool(changed)
    delta["change_count"] = change_count
    return delta


def _ensure_ui_flow_tables(conn: sqlite3.Connection) -> None:
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS ui_flow_transitions (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          created_at REAL NOT NULL,
          session TEXT,
          from_url TEXT,
          to_url TEXT,
          action TEXT,
          selector TEXT,
          semantic_delta_json TEXT,
          ui_states_json TEXT
        )
        """
    )
    conn.execute(
        "CREATE INDEX IF NOT EXISTS idx_ui_flow_from_to_time ON ui_flow_transitions(from_url, to_url, created_at DESC)"
    )
    conn.execute(
        "CREATE INDEX IF NOT EXISTS idx_ui_flow_session_time ON ui_flow_transitions(session, created_at DESC)"
    )


def record_ui_flow_transition(
    db_path: Path,
    *,
    session: str,
    from_url: str,
    to_url: str,
    action: str,
    selector: str = "",
    semantic_delta: Optional[Dict[str, Any]] = None,
    ui_states: Optional[Dict[str, Any]] = None,
    created_at: Optional[float] = None,
) -> None:
    if not (from_url or to_url):
        return
    created_at = float(created_at if created_at is not None else time.time())
    with sqlite3.connect(str(db_path)) as conn:
        _ensure_ui_flow_tables(conn)
        conn.execute(
            """
            INSERT INTO ui_flow_transitions (created_at, session, from_url, to_url, action, selector, semantic_delta_json, ui_states_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            """,
            (
                created_at,
                session,
                from_url,
                to_url,
                action,
                selector,
                json.dumps(semantic_delta or {}, ensure_ascii=False),
                json.dumps(ui_states or {}, ensure_ascii=False),
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


def _previous_env_snapshot(
    db_path: Path, *, kind: str, before_ts: float
) -> Optional[Dict[str, Any]]:
    with sqlite3.connect(str(db_path)) as conn:
        conn.row_factory = sqlite3.Row
        _ensure_tables(conn)
        row = conn.execute(
            """
            SELECT * FROM env_snapshots
            WHERE kind = ? AND created_at < ?
            ORDER BY created_at DESC
            LIMIT 1
            """,
            (kind, float(before_ts)),
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


def _count_runtime_events(
    db_path: Path, *, start_ts: float, end_ts: float
) -> int:
    try:
        with sqlite3.connect(str(db_path)) as conn:
            cur = conn.cursor()
            row = cur.execute(
                """
                SELECT COUNT(*) FROM runtime_events
                WHERE timestamp >= ? AND timestamp <= ?
                  AND (session LIKE 'run_%' OR event_type LIKE 'agent_run_%')
                """,
                (float(start_ts), float(end_ts)),
            ).fetchone()
            return int(row[0] or 0) if row else 0
    except Exception:
        return 0


def _count_internal_writes(
    db_path: Path, *, start_ts: float, end_ts: float
) -> int:
    try:
        with sqlite3.connect(str(db_path)) as conn:
            cur = conn.cursor()
            row = cur.execute(
                """
                SELECT COUNT(*)
                FROM outcomes o
                JOIN decisions d ON o.decision_id = d.id
                JOIN intents i ON d.intent_id = i.id
                WHERE (CAST(strftime('%s', o.timestamp) AS INTEGER) >= ? AND CAST(strftime('%s', o.timestamp) AS INTEGER) <= ?)
                  AND o.result IN ('success_direct','mode_direct','success_sandbox')
                """,
                (int(start_ts), int(end_ts)),
            ).fetchone()
            return int(row[0] or 0) if row else 0
    except Exception:
        return 0


def compute_change_attribution(
    db_path: Path,
    *,
    prev_snapshot: Dict[str, Any],
    curr_created_at: float,
    delta_payload: Dict[str, Any],
) -> Dict[str, Any]:
    prev_ts = float(prev_snapshot.get("created_at") or 0)
    curr_ts = float(curr_created_at or time.time())
    changed_keys = int((delta_payload.get("summary") or {}).get("changed_keys") or 0)
    test_events = _count_runtime_events(db_path, start_ts=prev_ts, end_ts=curr_ts)
    write_events = _count_internal_writes(db_path, start_ts=prev_ts, end_ts=curr_ts)

    classification = "no_change"
    confidence = 0.6
    if changed_keys > 0:
        if write_events > 0 and test_events > 0:
            classification = "mixed_internal"
            confidence = 0.7
        elif write_events > 0:
            classification = "internal_write"
            confidence = 0.85
        elif test_events > 0:
            classification = "internal_test"
            confidence = 0.8
        else:
            classification = "external_change"
            confidence = 0.75

    return {
        "classification": classification,
        "confidence": confidence,
        "window": {
            "prev_ts": prev_ts,
            "curr_ts": curr_ts,
            "seconds": max(0.0, curr_ts - prev_ts),
        },
        "signals": {
            "changed_keys": changed_keys,
            "test_events": test_events,
            "write_events": write_events,
        },
    }


def store_latest_diagnostic_delta(
    db_path: Path,
    *,
    run_id: str,
    curr_snapshot_payload: Dict[str, Any],
    created_at: Optional[float] = None,
) -> Optional[Dict[str, Any]]:
    """
    Compute and store delta against the previous diagnostic snapshot (if any).
    """
    curr_ts = float(created_at if created_at is not None else time.time())
    prev = _previous_env_snapshot(db_path, kind="diagnostic", before_ts=curr_ts)
    if not prev or not isinstance(prev.get("payload"), dict):
        return None
    prev_payload = prev["payload"]
    delta = compute_diagnostic_delta(prev_payload, curr_snapshot_payload)
    store_env_snapshot(
        db_path,
        run_id=run_id,
        kind="diagnostic_delta",
        payload=delta,
        source="agency_core",
        confidence=None,
        created_at=curr_ts,
    )
    attribution = compute_change_attribution(
        db_path,
        prev_snapshot=prev,
        curr_created_at=curr_ts,
        delta_payload=delta,
    )
    store_env_snapshot(
        db_path,
        run_id=run_id,
        kind="diagnostic_attribution",
        payload=attribution,
        source="agency_core",
        confidence=float(attribution.get("confidence") or 0.6),
        created_at=curr_ts,
    )
    return attribution


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
        "scenario_coverage": findings.get("scenario_coverage") or {},
        "flow_coverage": findings.get("flow_coverage") or {},
        "domain_rules": {
            "summary": findings.get("domain_rule_summary") or {},
            "violations_count": len(findings.get("domain_rule_violations") or []),
        },
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
