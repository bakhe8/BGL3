import json
import sqlite3
from pathlib import Path
from typing import Dict, Any, List, Optional


def _guess_layer(name: str) -> Optional[str]:
    if not name:
        return None
    if "Service" in name:
        return "service"
    if "Repository" in name or "Repo" in name:
        return "repo"
    if "Model" in name:
        return "model"
    return "other"


def _get_dependencies(conn: sqlite3.Connection, method_id: int) -> List[Dict[str, Any]]:
    query = """
        SELECT target_entity, target_method, type, confidence, evidence 
        FROM calls 
        WHERE source_method_id = ?
    """
    rows = conn.execute(query, (method_id,)).fetchall()
    deps = []
    for r in rows:
        ent = r["target_entity"]
        if ent:
            deps.append(
                {
                    "name": ent,
                    "layer": _guess_layer(ent),
                    "type": r["type"],
                    "method": r["target_method"],
                }
            )
    return deps


def _get_entity_method_id(
    conn: sqlite3.Connection, entity_name: str, method_name: str = "main"
) -> Optional[int]:
    query = """
        SELECT m.id 
        FROM methods m 
        JOIN entities e ON m.entity_id = e.id 
        WHERE e.name = ? AND m.name = ? 
        LIMIT 1
    """
    row = conn.execute(query, (entity_name, method_name)).fetchone()
    return row["id"] if row else None


def build_callgraph(root: Path) -> Dict[str, Any]:
    """
    Rich Callgraph: route -> controller -> service -> repo
    """
    db_path = root / ".bgl_core" / "brain" / "knowledge.db"
    out = root / "docs" / "api_callgraph.json"
    meta = {"total_routes": 0, "mapped_layers": 0, "output": str(out)}

    if not db_path.exists():
        return meta

    try:
        conn = sqlite3.connect(str(db_path))
        conn.row_factory = sqlite3.Row

        # 1. Get all routes
        routes = conn.execute(
            "SELECT uri, controller, action, file_path FROM routes"
        ).fetchall()
        graph = []

        for r in routes:
            route_data = dict(r)
            # uri = r["uri"]
            file_path = r["file_path"]

            # Use relative path for lookup
            try:
                rel_path = str(Path(file_path).relative_to(root))
            except ValueError:
                rel_path = file_path  # fallback

            # 2. Find "main" method for this file (root script)
            query = """
                SELECT m.id 
                FROM methods m 
                JOIN entities e ON m.entity_id = e.id 
                JOIN files f ON e.file_id = f.id 
                WHERE f.path = ? AND e.type = 'root' AND m.name = 'main'
            """
            # Try both backslash and forward slash
            main_method = conn.execute(query, (rel_path,)).fetchone()
            if not main_method:
                main_method = conn.execute(
                    query, (rel_path.replace("/", "\\"),)
                ).fetchone()

            dependencies = []
            if main_method:
                # 3. Get direct dependencies
                direct_deps = _get_dependencies(conn, main_method["id"])

                # 4. For each direct dep, try to find ITS dependencies (1 level deep for now)
                for dep in direct_deps:
                    dep_name = dep["name"]
                    # Check if it's a class we have indexed
                    construct_id = _get_entity_method_id(conn, dep_name, "__construct")
                    if construct_id:
                        dep["calls"] = _get_dependencies(conn, construct_id)
                    dependencies.append(dep)

            route_data["dependencies"] = dependencies
            graph.append(route_data)

        meta["total_routes"] = len(graph)
        meta["mapped_layers"] = sum(1 for r in graph if r.get("dependencies"))

        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_text(
            json.dumps(graph, ensure_ascii=False, indent=2), encoding="utf-8"
        )

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
