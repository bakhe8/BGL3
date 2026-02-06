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
from pathlib import Path
from typing import List, Dict

from embeddings import add_text

DB_PATH = Path(__file__).parent / "knowledge.db"


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


def upsert_experiences(conn: sqlite3.Connection, experiences: List[Dict]):
    cur = conn.cursor()
    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS experiences (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at REAL NOT NULL,
            scenario TEXT,
            summary TEXT,
            related_files TEXT,
            confidence REAL,
            evidence_count INTEGER DEFAULT 0
        )
        """
    )
    now = time.time()
    inserted: List[Dict] = []
    for exp in experiences:
        cur.execute(
            """
            INSERT INTO experiences (created_at, scenario, summary, related_files, confidence, evidence_count)
            VALUES (?, ?, ?, ?, ?, ?)
            """,
            (
                now,
                exp["scenario"],
                exp["summary"],
                exp["related_files"],
                exp["confidence"],
                exp["evidence_count"],
            ),
        )
        exp_id = cur.lastrowid
        inserted.append(
            {
                "id": exp_id,
                "created_at": now,
                "scenario": exp["scenario"],
                "summary": exp["summary"],
                "related_files": exp["related_files"],
                "confidence": exp["confidence"],
                "evidence_count": exp["evidence_count"],
            }
        )

    conn.commit()

    # NEW: Index into semantic memory AFTER commit to avoid locking
    # Risk Mitigation: Confidence threshold + [Experience] prefix
    for exp in experiences:
        if exp["confidence"] >= 0.3:
            add_text(f"[Experience] {exp['scenario']}", exp["summary"])
    return inserted


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
    if os.getenv("BGL_AUTO_PROPOSE", "1") != "1":
        return []
    if not experiences:
        return []

    try:
        min_conf = float(os.getenv("BGL_AUTO_PROPOSE_MIN_CONF", "0.78") or 0.78)
    except Exception:
        min_conf = 0.78
    try:
        min_evidence = int(os.getenv("BGL_AUTO_PROPOSE_MIN_EVIDENCE", "4") or 4)
    except Exception:
        min_evidence = 4
    try:
        max_promote = int(os.getenv("BGL_AUTO_PROPOSE_LIMIT", "6") or 6)
    except Exception:
        max_promote = 6

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
        if conf < min_conf or evidence < min_evidence:
            continue

        # Determine action/impact heuristics
        text = summary.lower()
        if "failed" in text or "error" in text or "js error" in text or "network" in text:
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
    if os.getenv("BGL_AUTO_APPLY", "1") != "1":
        return
    try:
        max_apply = int(os.getenv("BGL_AUTO_APPLY_LIMIT", "3") or 3)
    except Exception:
        max_apply = 3
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
    events = fetch_events(conn, cutoff, args.limit)
    if not events:
        print("No events found in window.")
        return
    route_map = load_route_map(conn)
    experiences = summarize(events, route_map)
    inserted = upsert_experiences(conn, experiences)
    # Auto-promote experiences -> proposals and auto-apply in sandbox
    proposal_ids = auto_promote_experiences(conn, inserted)
    try:
        conn.close()
    except Exception:
        pass
    auto_apply_proposals(proposal_ids)
    print(
        f"Stored {len(experiences)} experience(s) from {len(events)} event(s). "
        f"Auto proposals: {len(proposal_ids)}"
    )


if __name__ == "__main__":
    main()
