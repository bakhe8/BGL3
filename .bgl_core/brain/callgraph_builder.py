import json
import sqlite3
from pathlib import Path
from typing import Dict, Any, Optional


def _guess_entity(uri: str) -> Optional[str]:
    if "guarantee" in uri or "extend" in uri or "release" in uri:
        return "guarantee"
    if "bank" in uri:
        return "bank"
    if "supplier" in uri:
        return "supplier"
    if "import" in uri or "export" in uri:
        return "import_export"
    return None


def build_callgraph(root: Path) -> Dict[str, Any]:
    """
    Reads routes table from knowledge.db and writes a lightweight callgraph file:
    uri -> controller/action/file_path
    """
    db = root / ".bgl_core" / "brain" / "knowledge.db"
    out = root / "docs" / "api_callgraph.json"
    meta = {"total_routes": 0, "mapped_controllers": 0, "output": str(out)}

    if not db.exists():
        return meta

    try:
        conn = sqlite3.connect(str(db))
        conn.row_factory = sqlite3.Row
        rows = conn.execute("SELECT uri, controller, action, file_path FROM routes").fetchall()
        data = [dict(r) for r in rows]
        meta["total_routes"] = len(data)
        for r in data:
            r["entity"] = _guess_entity(r["uri"])
        meta["mapped_controllers"] = sum(1 for r in data if r.get("controller"))
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")
    except Exception as e:
        meta["error"] = str(e)
    finally:
        try:
            conn.close()
        except Exception:
            pass
    return meta


if __name__ == "__main__":
    root = Path(__file__).parent.parent.parent
    print(build_callgraph(root))
