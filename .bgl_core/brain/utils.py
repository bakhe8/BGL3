import sqlite3
from pathlib import Path
from typing import Dict


def load_route_usage(root: Path) -> Dict[str, float]:
    """
    Compute simple usage frequency from runtime_events in knowledge.db.
    Returns a map route -> normalized usage (0..1).
    """
    db = root / ".bgl_core" / "brain" / "knowledge.db"
    if not db.exists():
        return {}
    try:
        conn = sqlite3.connect(str(db))
        cursor = conn.cursor()
        cursor.execute(
            "SELECT route, COUNT(*) cnt FROM runtime_events WHERE route IS NOT NULL GROUP BY route"
        )
        rows = cursor.fetchall()
        conn.close()
    except Exception:
        return {}
    if not rows:
        return {}
    total = sum(r[1] for r in rows)
    if total == 0:
        return {}
    return {r[0]: r[1] / total for r in rows}
