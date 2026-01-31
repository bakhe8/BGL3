import json
from pathlib import Path
import yaml  # type: ignore

def generate(root: Path) -> Path:
    """
    Generates a minimal OpenAPI spec from indexed routes (knowledge.db -> routes table).
    Merges manual seed if present.
    """
    out = root / "docs" / "openapi.generated.yaml"
    manual = root / "docs" / "openapi.manual.yaml"
    merged = root / "docs" / "openapi.yaml"

    routes_db = root / ".bgl_core" / "brain" / "knowledge.db"
    paths = {}
    if routes_db.exists():
        import sqlite3
        conn = sqlite3.connect(str(routes_db))
        conn.row_factory = sqlite3.Row
        rows = conn.execute("SELECT uri, http_method FROM routes").fetchall()
        for r in rows:
            uri = r["uri"]
            method = (r["http_method"] or "get").lower()
            if method == "any":
                methods = ["get", "post"]
            else:
                methods = [method]
            if uri not in paths:
                paths[uri] = {}
            for m in methods:
                paths[uri][m] = {
                    "responses": {"200": {"description": "OK"}},
                }
        conn.close()
    spec = {"openapi": "3.0.0", "info": {"title": "BGL3 API (Generated)", "version": "0.1"}, "paths": paths}
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(yaml.safe_dump(spec, sort_keys=False, allow_unicode=True), encoding="utf-8")

    # Merge manual + generated (manual overrides)
    base = {"openapi": "3.0.0", "info": {"title": "BGL3 API", "version": "0.1"}, "paths": {}}
    if manual.exists():
        try:
            base = yaml.safe_load(manual.read_text(encoding="utf-8")) or base
        except Exception:
            pass
    merged_paths = spec["paths"]
    merged_paths.update(base.get("paths", {}))
    base["paths"] = merged_paths
    merged.write_text(yaml.safe_dump(base, sort_keys=False, allow_unicode=True), encoding="utf-8")
    return merged


if __name__ == "__main__":
    print(generate(Path(__file__).parent.parent.parent))
