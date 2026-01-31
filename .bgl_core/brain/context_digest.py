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
from pathlib import Path
from typing import List, Dict

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
    rows = cur.execute("SELECT uri, controller, action, file_path FROM routes").fetchall()
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
        lat_avg = round(sum(data["latencies"]) / len(data["latencies"]), 2) if data["latencies"] else 0
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
    conn.commit()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--hours", type=float, default=24, help="Lookback window in hours")
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
    upsert_experiences(conn, experiences)
    print(f"Stored {len(experiences)} experience(s) from {len(events)} event(s).")


if __name__ == "__main__":
    main()
