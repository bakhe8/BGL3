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
        CREATE TABLE IF NOT EXISTS hypotheses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            fingerprint TEXT UNIQUE,
            created_at REAL NOT NULL,
            updated_at REAL NOT NULL,
            status TEXT NOT NULL,
            source TEXT,
            title TEXT,
            statement TEXT NOT NULL,
            confidence REAL,
            priority REAL,
            evidence_json TEXT,
            tags_json TEXT,
            related_intent TEXT,
            related_goal TEXT,
            last_outcome_at REAL
        )
        """
    )
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS hypothesis_outcomes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            hypothesis_id INTEGER NOT NULL,
            outcome_table TEXT NOT NULL,
            outcome_id INTEGER NOT NULL,
            relation TEXT NOT NULL,
            score REAL,
            created_at REAL NOT NULL,
            notes TEXT,
            FOREIGN KEY (hypothesis_id) REFERENCES hypotheses(id)
        )
        """
    )
    conn.execute(
        "CREATE INDEX IF NOT EXISTS idx_hypotheses_status ON hypotheses(status, updated_at DESC)"
    )
    conn.execute(
        "CREATE INDEX IF NOT EXISTS idx_hypothesis_outcomes_h ON hypothesis_outcomes(hypothesis_id, created_at DESC)"
    )


def _fingerprint(statement: str, source: str = "") -> str:
    base = (source or "").strip().lower() + "|" + (statement or "").strip().lower()
    return hashlib.sha1(base.encode("utf-8")).hexdigest()


def _safe_json(obj: Any) -> str:
    try:
        return json.dumps(obj, ensure_ascii=False)
    except Exception:
        return "{}"


def _merge_evidence(existing: Dict[str, Any], incoming: Dict[str, Any]) -> Dict[str, Any]:
    out = dict(existing or {})
    for k, v in (incoming or {}).items():
        if k not in out:
            out[k] = v
            continue
        if isinstance(out[k], list) and isinstance(v, list):
            merged = list(out[k])
            for item in v:
                if item not in merged:
                    merged.append(item)
            out[k] = merged
        else:
            out[k] = out[k] if out[k] not in (None, "", []) else v
    return out


def upsert_hypothesis(
    db_path: Path,
    *,
    statement: str,
    title: str = "",
    source: str = "signals",
    confidence: float = 0.5,
    priority: float = 0.5,
    status: str = "open",
    evidence: Optional[Dict[str, Any]] = None,
    tags: Optional[List[str]] = None,
    related_intent: str = "",
    related_goal: str = "",
) -> Optional[int]:
    if not statement:
        return None
    now = time.time()
    fp = _fingerprint(statement, source)
    try:
        conn = _connect(db_path)
        _ensure_tables(conn)
        row = conn.execute(
            "SELECT id, confidence, priority, evidence_json, tags_json, status FROM hypotheses WHERE fingerprint=?",
            (fp,),
        ).fetchone()
        if row:
            existing_evidence = {}
            try:
                existing_evidence = json.loads(row["evidence_json"] or "{}")
            except Exception:
                existing_evidence = {}
            merged_evidence = _merge_evidence(existing_evidence, evidence or {})
            existing_tags = []
            try:
                existing_tags = json.loads(row["tags_json"] or "[]")
            except Exception:
                existing_tags = []
            merged_tags = list(existing_tags)
            for t in tags or []:
                if t not in merged_tags:
                    merged_tags.append(t)
            new_conf = max(float(row["confidence"] or 0), float(confidence or 0))
            new_pri = max(float(row["priority"] or 0), float(priority or 0))
            conn.execute(
                """
                UPDATE hypotheses
                SET updated_at=?, confidence=?, priority=?, evidence_json=?, tags_json=?
                WHERE id=?
                """,
                (
                    now,
                    new_conf,
                    new_pri,
                    _safe_json(merged_evidence),
                    _safe_json(merged_tags),
                    int(row["id"]),
                ),
            )
            conn.commit()
            conn.close()
            return int(row["id"])
        else:
            conn.execute(
                """
                INSERT INTO hypotheses (
                    fingerprint, created_at, updated_at, status, source, title, statement,
                    confidence, priority, evidence_json, tags_json, related_intent, related_goal
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    fp,
                    now,
                    now,
                    status,
                    source,
                    title,
                    statement,
                    float(confidence or 0),
                    float(priority or 0),
                    _safe_json(evidence or {}),
                    _safe_json(tags or []),
                    related_intent,
                    related_goal,
                ),
            )
            hid = conn.execute("SELECT last_insert_rowid()").fetchone()[0]
            conn.commit()
            conn.close()
            return int(hid or 0)
    except Exception:
        return None


def list_hypotheses(db_path: Path, status: str = "open", limit: int = 8) -> List[Dict[str, Any]]:
    if not db_path.exists():
        return []
    try:
        conn = _connect(db_path)
        _ensure_tables(conn)
        rows = conn.execute(
            "SELECT * FROM hypotheses WHERE status=? ORDER BY priority DESC, updated_at DESC LIMIT ?",
            (status, int(limit)),
        ).fetchall()
        conn.close()
        out: List[Dict[str, Any]] = []
        for r in rows:
            item = dict(r)
            try:
                item["evidence"] = json.loads(item.get("evidence_json") or "{}")
            except Exception:
                item["evidence"] = {}
            try:
                item["tags"] = json.loads(item.get("tags_json") or "[]")
            except Exception:
                item["tags"] = []
            out.append(item)
        return out
    except Exception:
        return []


def derive_hypotheses_from_diagnostic(db_path: Path, diagnostic_map: Dict[str, Any]) -> List[int]:
    findings = diagnostic_map.get("findings") or {}
    signals = findings.get("signals") or {}
    counts = (signals.get("counts") or {}) if isinstance(signals, dict) else {}
    top = (signals.get("top") or {}) if isinstance(signals, dict) else {}
    scenario_deps = findings.get("scenario_deps") or {}
    ids: List[int] = []

    # Actionable failing routes
    try:
        actionable = int(counts.get("actionable_failures") or 0)
    except Exception:
        actionable = 0
    if actionable > 0:
        routes = top.get("actionable_failures") or []
        statement = "Actionable failing routes indicate defects in handlers."
        if routes:
            statement += " Routes: " + ", ".join([str(r) for r in routes[:6]])
        hid = upsert_hypothesis(
            db_path,
            statement=statement,
            title="Actionable Failures",
            source="signals",
            confidence=0.7,
            priority=0.8,
            tags=["stabilize", "routes"],
            evidence={"routes": routes, "count": actionable},
            related_intent="stabilize",
        )
        if hid:
            ids.append(hid)

    # Pending approvals
    try:
        pending = int(counts.get("pending_approvals") or 0)
    except Exception:
        pending = 0
    if pending > 0:
        ops = top.get("pending_approvals") or []
        statement = "Progress is blocked by pending approvals."
        if ops:
            statement += " Ops: " + ", ".join([str(o) for o in ops[:6]])
        hid = upsert_hypothesis(
            db_path,
            statement=statement,
            title="Pending Approvals Block",
            source="signals",
            confidence=0.8,
            priority=0.75,
            tags=["unblock", "approvals"],
            evidence={"operations": ops, "count": pending},
            related_intent="unblock",
        )
        if hid:
            ids.append(hid)

    # Scenario deps missing
    if isinstance(scenario_deps, dict) and not scenario_deps.get("ok", True):
        missing = scenario_deps.get("missing") or []
        statement = "Scenario dependencies are missing; exploration may be stalled."
        if missing:
            statement += " Missing: " + ", ".join([str(m) for m in missing[:6]])
        hid = upsert_hypothesis(
            db_path,
            statement=statement,
            title="Scenario Dependencies Missing",
            source="signals",
            confidence=0.7,
            priority=0.6,
            tags=["unblock", "scenarios"],
            evidence={"missing": missing},
            related_intent="unblock",
        )
        if hid:
            ids.append(hid)

    # API contract gaps
    api_missing = findings.get("api_contract_missing") or []
    api_gaps = findings.get("api_contract_gaps") or []
    if api_missing or api_gaps:
        statement = "API contracts are incomplete; contract seeding likely required."
        hid = upsert_hypothesis(
            db_path,
            statement=statement,
            title="API Contract Coverage",
            source="signals",
            confidence=0.65,
            priority=0.55,
            tags=["evolve", "api"],
            evidence={"missing": api_missing, "gaps": api_gaps},
            related_intent="evolve",
        )
        if hid:
            ids.append(hid)

    # Policy candidates
    policy_candidates = findings.get("policy_candidates") or []
    if policy_candidates:
        statement = "Policy candidates suggest missing rules or controls."
        hid = upsert_hypothesis(
            db_path,
            statement=statement,
            title="Policy Gaps",
            source="signals",
            confidence=0.6,
            priority=0.5,
            tags=["evolve", "policy"],
            evidence={"candidates": policy_candidates[:6]},
            related_intent="evolve",
        )
        if hid:
            ids.append(hid)

    # UI semantic change (page meaning drift)
    ui_sem = findings.get("ui_semantic") or {}
    ui_delta = findings.get("ui_semantic_delta") or {}
    try:
        if isinstance(ui_delta, dict) and ui_delta.get("changed"):
            url = str(ui_sem.get("url") or "").strip()
            change_count = int(ui_delta.get("change_count") or 0)
            keywords = []
            try:
                keywords = (ui_sem.get("summary") or {}).get("text_keywords", []) or []
            except Exception:
                keywords = []
            statement = "UI semantic structure changed; knowledge may be stale."
            if url:
                statement += f" URL: {url}"
            if keywords:
                statement += " Keywords: " + ", ".join([str(k) for k in keywords[:6]])
            rel_intent = "evolve" if change_count >= 6 else "observe"
            hid = upsert_hypothesis(
                db_path,
                statement=statement,
                title="UI Semantic Change",
                source="ui_semantic",
                confidence=0.55,
                priority=0.45,
                tags=["ui", "semantic", rel_intent],
                evidence={"delta": ui_delta, "keywords": keywords[:10], "url": url},
                related_intent=rel_intent,
            )
            if hid:
                ids.append(hid)
    except Exception:
        pass

    # Learning confirmations (cumulative)
    learning = findings.get("learning_confirmations") or []
    if learning:
        keys = [str(l.get("item_key") or "") for l in learning if isinstance(l, dict)]
        keys = [k for k in keys if k]
        statement = "Confirmed anomalies indicate recurring issues."
        if keys:
            statement += " Items: " + ", ".join(keys[:6])
        hid = upsert_hypothesis(
            db_path,
            statement=statement,
            title="Confirmed Anomalies",
            source="learning",
            confidence=0.6,
            priority=0.55,
            tags=["learning"],
            evidence={"items": keys},
            related_intent="observe",
        )
        if hid:
            ids.append(hid)

    return ids


def _relation_from_outcome(kind: str, value: str) -> str:
    k = (kind or "").lower()
    v = (value or "").strip()
    if k == "error":
        return "supports"
    if k == "gap":
        return "supports"
    if k == "api_result":
        try:
            code = int(v)
        except Exception:
            code = 0
        if code >= 400:
            return "supports"
        if code > 0:
            return "contradicts"
    if k == "goal_result":
        try:
            code = int(v)
        except Exception:
            code = 0
        if code >= 400:
            return "supports"
        if code > 0:
            return "contradicts"
    return "neutral"


def _confidence_delta(relation: str) -> float:
    if relation == "supports":
        return 0.05
    if relation == "contradicts":
        return -0.05
    return 0.0


def ingest_exploration_outcomes(db_path: Path, since_ts: float, limit: int = 200) -> None:
    if not db_path.exists():
        return
    try:
        conn = _connect(db_path)
        _ensure_tables(conn)
        outcomes = conn.execute(
            """
            SELECT id, timestamp, kind, value, route
            FROM exploration_outcomes
            WHERE timestamp >= ?
            ORDER BY id DESC
            LIMIT ?
            """,
            (float(since_ts), int(limit)),
        ).fetchall()
        if not outcomes:
            conn.close()
            return
        hypotheses = conn.execute(
            "SELECT id, evidence_json, confidence FROM hypotheses WHERE status='open' ORDER BY updated_at DESC LIMIT 50"
        ).fetchall()
        for h in hypotheses:
            try:
                evidence = json.loads(h["evidence_json"] or "{}")
            except Exception:
                evidence = {}
            routes = evidence.get("routes") or []
            if not routes:
                continue
            for row in outcomes:
                route = str(row["route"] or "")
                if not route:
                    continue
                if route not in routes:
                    continue
                relation = _relation_from_outcome(str(row["kind"] or ""), str(row["value"] or ""))
                score = _confidence_delta(relation)
                conn.execute(
                    """
                    INSERT INTO hypothesis_outcomes (hypothesis_id, outcome_table, outcome_id, relation, score, created_at, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    """,
                    (
                        int(h["id"]),
                        "exploration_outcomes",
                        int(row["id"]),
                        relation,
                        float(score),
                        time.time(),
                        f"route={route}",
                    ),
                )
                # Update hypothesis confidence and last_outcome_at
                try:
                    new_conf = float(h["confidence"] or 0) + score
                except Exception:
                    new_conf = float(h["confidence"] or 0) + score
                if new_conf < 0:
                    new_conf = 0.0
                if new_conf > 1:
                    new_conf = 1.0
                conn.execute(
                    "UPDATE hypotheses SET confidence=?, updated_at=?, last_outcome_at=? WHERE id=?",
                    (new_conf, time.time(), float(row["timestamp"] or time.time()), int(h["id"])),
                )
        conn.commit()
        conn.close()
    except Exception:
        return


def refresh_hypothesis_status(
    db_path: Path,
    *,
    confirm_threshold: float = 0.85,
    contradict_threshold: float = 0.2,
    confirm_min_support: int = 3,
    contradict_min_support: int = 2,
    stale_days: int = 7,
) -> None:
    if not db_path.exists():
        return
    try:
        conn = _connect(db_path)
        _ensure_tables(conn)
        rows = conn.execute(
            "SELECT id, confidence, updated_at, last_outcome_at, status FROM hypotheses WHERE status='open'"
        ).fetchall()
        now = time.time()
        stale_sec = float(stale_days) * 86400.0
        for r in rows:
            hid = int(r["id"])
            conf = float(r["confidence"] or 0)
            last_outcome = float(r["last_outcome_at"] or 0)
            updated_at = float(r["updated_at"] or 0)

            supports = conn.execute(
                "SELECT COUNT(*) FROM hypothesis_outcomes WHERE hypothesis_id=? AND relation='supports'",
                (hid,),
            ).fetchone()[0]
            contradicts = conn.execute(
                "SELECT COUNT(*) FROM hypothesis_outcomes WHERE hypothesis_id=? AND relation='contradicts'",
                (hid,),
            ).fetchone()[0]

            new_status = None
            if supports >= confirm_min_support and conf >= confirm_threshold:
                new_status = "confirmed"
            elif contradicts >= contradict_min_support and conf <= contradict_threshold:
                new_status = "contradicted"
            else:
                # Archive stale hypotheses with no recent outcomes
                last_activity = max(updated_at, last_outcome)
                if last_activity and (now - last_activity) >= stale_sec:
                    new_status = "archived"

            if new_status:
                conn.execute(
                    "UPDATE hypotheses SET status=?, updated_at=? WHERE id=?",
                    (new_status, now, hid),
                )

        conn.commit()
        conn.close()
    except Exception:
        return
