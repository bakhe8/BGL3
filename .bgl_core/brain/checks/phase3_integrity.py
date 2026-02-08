from pathlib import Path
import sqlite3
import time
from typing import Dict, Any, List


def run(project_root: Path) -> Dict[str, Any]:
    """
    Phase 3 integrity check:
    Ensure UI action snapshots + UI flow transitions + gap scenario execution are present.
    """
    db_path = project_root / ".bgl_core" / "brain" / "knowledge.db"
    if not db_path.exists():
        return {
            "passed": False,
            "evidence": ["missing knowledge.db"],
            "scope": ["ui", "phase3"],
        }

    cutoff = time.time() - (7 * 86400)
    snapshot_count = 0
    flow_count = 0
    gap_done = 0
    evidence: List[str] = []
    try:
        conn = sqlite3.connect(str(db_path))
        conn.row_factory = sqlite3.Row
        tables = {
            row[0]
            for row in conn.execute(
                "SELECT name FROM sqlite_master WHERE type='table'"
            ).fetchall()
        }
        if "ui_action_snapshots" in tables:
            row = conn.execute(
                "SELECT COUNT(*) FROM ui_action_snapshots WHERE created_at >= ?",
                (cutoff,),
            ).fetchone()
            snapshot_count = int(row[0] or 0) if row else 0
        else:
            evidence.append("missing table: ui_action_snapshots")

        if "ui_flow_transitions" in tables:
            row = conn.execute(
                "SELECT COUNT(*) FROM ui_flow_transitions WHERE created_at >= ?",
                (cutoff,),
            ).fetchone()
            flow_count = int(row[0] or 0) if row else 0
        else:
            evidence.append("missing table: ui_flow_transitions")

        if "runtime_events" in tables:
            row = conn.execute(
                "SELECT COUNT(*) FROM runtime_events WHERE event_type='gap_scenario_done' AND timestamp >= ?",
                (cutoff,),
            ).fetchone()
            gap_done = int(row[0] or 0) if row else 0
        else:
            evidence.append("missing table: runtime_events")
        conn.close()
    except Exception as exc:
        return {
            "passed": False,
            "evidence": [f"db error: {exc}"],
            "scope": ["ui", "phase3"],
        }

    evidence += [
        f"ui_action_snapshots_7d={snapshot_count}",
        f"ui_flow_transitions_7d={flow_count}",
        f"gap_scenario_done_7d={gap_done}",
    ]
    passed = snapshot_count > 0 and flow_count > 0 and gap_done > 0
    return {
        "passed": passed,
        "evidence": evidence,
        "scope": ["ui", "phase3"],
    }
