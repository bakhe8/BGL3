import sqlite3
import json
import statistics
from pathlib import Path
from collections import defaultdict

DB_PATH = Path(".bgl_core/brain/knowledge.db")
OUTPUT_SUMMARY = Path("analysis/metrics_summary.json")
LEGACY_SUMMARY = Path("analysis/metrics_summary_enhanced.json")
OUTPUT_SUGGESTIONS = Path("analysis/suggestions.json")


def compute_stats(values):
    if not values:
        return {}
    return {
        "count": len(values),
        "min": min(values),
        "max": max(values),
        "avg": round(sum(values) / len(values), 2),
        "p50": statistics.median(values),
        "p90": round(statistics.quantiles(values, n=10)[8], 2)
        if len(values) >= 10
        else None,
    }


def analyze_traces():
    if not DB_PATH.exists():
        print(f"Database not found at {DB_PATH}")
        return

    conn = sqlite3.connect(str(DB_PATH))
    # Get all relevant events ordered by time
    cursor = conn.execute("""
        SELECT event_type, target, payload, timestamp 
        FROM runtime_events 
        WHERE event_type IN ('ui_click', 'mouse_metrics') 
        ORDER BY id ASC
    """)

    last_target = "Unknown"
    interaction_stats = defaultdict(lambda: {"move": [], "dom": []})
    move_all = []
    dom_all = []

    for event_type, target, payload, timestamp in cursor:
        if event_type == "ui_click":
            if target:
                last_target = target
        elif event_type == "mouse_metrics":
            # Parse payload
            parts = dict(p.split("=") for p in payload.split(";") if "=" in p)

            # Extract Move Time
            if "move_to_click_ms" in parts:
                try:
                    val = int(parts["move_to_click_ms"])
                    interaction_stats[last_target]["move"].append(val)
                    move_all.append(val)
                except ValueError:
                    pass

            # Extract DOM Change Time
            if "dom_change_ms" in parts and parts["dom_change_ms"] not in ("None", ""):
                try:
                    val = int(parts["dom_change_ms"])
                    interaction_stats[last_target]["dom"].append(val)
                    dom_all.append(val)
                except ValueError:
                    pass

    # Calculate aggregations
    final_report = []
    suggestions = []

    for target, measures in interaction_stats.items():
        move_times = measures["move"]
        dom_times = measures["dom"]

        entry = {
            "target": target,
            "count": len(move_times),
            "move_p50": statistics.median(move_times) if move_times else None,
            "dom_p50": statistics.median(dom_times) if dom_times else None,
            "dom_p90": None,
        }

        if dom_times:
            entry["dom_p90"] = (
                round(statistics.quantiles(dom_times, n=10)[8], 2)
                if len(dom_times) >= 10
                else max(dom_times)
            )

        final_report.append(entry)

        # Generate Suggestion if slow
        if entry["dom_p90"] and entry["dom_p90"] > 1000:
            suggestions.append(
                {
                    "type": "slow_interaction",
                    "priority": "High" if entry["dom_p90"] > 3000 else "Medium",
                    "target": target,
                    "metric": "click_to_dom_p90",
                    "value": entry["dom_p90"],
                    "reason": f"Interaction with {target} is taking > 1s to reflect changes.",
                }
            )

    # Save outputs
    OUTPUT_SUMMARY.parent.mkdir(exist_ok=True, parents=True)

    with open(OUTPUT_SUMMARY, "w", encoding="utf-8") as f:
        json.dump(
            {
                "overall": {
                    "move_to_click_ms": compute_stats(move_all),
                    "click_to_dom_ms": compute_stats(dom_all),
                },
                "per_target": final_report,
            },
            f,
            indent=2,
        )

    print(f"Summary saved to {OUTPUT_SUMMARY}")
    if LEGACY_SUMMARY.exists():
        try:
            LEGACY_SUMMARY.unlink()
        except Exception:
            pass

    # Analyze Backend Traces
    analyze_backend_traces(suggestions)

    with open(OUTPUT_SUGGESTIONS, "w", encoding="utf-8") as f:
        json.dump(suggestions, f, indent=2)

    generate_sql_patch(suggestions)
    print(f"Analysis complete. Found {len(suggestions)} suggestions.")
    print(f"Suggestions saved to {OUTPUT_SUGGESTIONS}")


def generate_sql_patch(suggestions):
    import re

    patch_file = Path("analysis/index_patch.sql")
    statements = []

    for item in suggestions:
        if item.get("type") == "slow_query":
            sql = item.get("statement", "")
            # Naive heuristic to find table and simple WHERE column
            # Matches: SELECT ... FROM table ... WHERE col = ?
            match = re.search(
                r"FROM\s+(\w+)\s+(?:.*?)WHERE\s+(\w+)\s*=", sql, re.IGNORECASE
            )
            if match:
                table, col = match.groups()
                index_name = f"idx_{table}_{col}_auto"
                stmt = f"-- Reason: {item['reason']}\nCREATE INDEX IF NOT EXISTS {index_name} ON {table}({col});"
                if stmt not in statements:
                    statements.append(stmt)

    if statements:
        with open(patch_file, "w", encoding="utf-8") as f:
            f.write("\n".join(statements))
        print(f"Generated SQL patch at {patch_file}")


def analyze_backend_traces(suggestions_list):
    trace_path = Path("storage/logs/traces.jsonl")
    if not trace_path.exists():
        return

    slow_queries = []

    with open(trace_path, "r", encoding="utf-8") as f:
        for line in f:
            if not line.strip():
                continue
            try:
                data = json.loads(line)
                duration = data.get("duration_ms", 0)
                if duration > 100:  # Threshold 100ms
                    slow_queries.append(data)
                    suggestions_list.append(
                        {
                            "type": "slow_query",
                            "priority": "High" if duration > 500 else "Medium",
                            "statement": data.get("statement"),
                            "duration": duration,
                            "route": data.get("route"),
                            "reason": f"Query took {duration}ms",
                        }
                    )
            except json.JSONDecodeError:
                pass

    if slow_queries:
        output_slow = Path("analysis/slow_queries.json")
        with open(output_slow, "w", encoding="utf-8") as f:
            json.dump(slow_queries, f, indent=2)
        print(f"Found {len(slow_queries)} slow queries. Saved to {output_slow}")


if __name__ == "__main__":
    analyze_traces()
