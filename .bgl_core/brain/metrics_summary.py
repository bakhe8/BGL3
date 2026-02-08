import sqlite3
import statistics
from pathlib import Path


def summarize(db_path: Path, write_json: bool = True):
    if not db_path.exists():
        print("runtime_events DB not found:", db_path)
        return
    conn = sqlite3.connect(str(db_path))
    cur = conn.cursor()
    cur.execute(
        "SELECT payload FROM runtime_events WHERE event_type='mouse_metrics'"
    )
    move_vals = []
    dom_vals = []
    for (payload,) in cur.fetchall():
        parts = dict(p.split("=") for p in payload.split(";") if "=" in p)
        if "move_to_click_ms" in parts:
            try:
                move_vals.append(int(parts["move_to_click_ms"]))
            except ValueError:
                pass
        if "dom_change_ms" in parts and parts["dom_change_ms"] not in ("None", ""):
            try:
                dom_vals.append(int(parts["dom_change_ms"]))
            except ValueError:
                pass
    def stats(arr):
        if not arr:
            return {}
        return {
            "count": len(arr),
            "min": min(arr),
            "max": max(arr),
            "avg": round(sum(arr)/len(arr), 2),
            "p50": statistics.median(arr),
            "p90": round(statistics.quantiles(arr, n=10)[8], 2) if len(arr) >= 10 else None,
        }
    move_stats = stats(move_vals)
    dom_stats = stats(dom_vals)
    if write_json:
        summary_path = Path("analysis/metrics_summary.json")
        summary_path.parent.mkdir(parents=True, exist_ok=True)
        summary_path.write_text(
            __import__("json").dumps(
                {
                    "overall": {
                        "move_to_click_ms": move_stats,
                        "click_to_dom_ms": dom_stats,
                    },
                    "per_target": [],
                },
                ensure_ascii=False,
                indent=2,
            ),
            encoding="utf-8",
        )
    print("Move→Click (ms):", move_stats)
    print("Click→DOM change (ms):", dom_stats)


if __name__ == "__main__":
    summarize(Path(".bgl_core/brain/knowledge.db"), write_json=True)
