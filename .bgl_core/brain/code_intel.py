import ast
import json
import os
import re
import sqlite3
import time
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple


ROOT_DIR = Path(__file__).resolve().parents[2]
DB_PATH = ROOT_DIR / ".bgl_core" / "brain" / "knowledge.db"
ANALYSIS_DIR = ROOT_DIR / "analysis"
DOCS_DIR = ROOT_DIR / "docs"


def _risk_tier(path: str) -> str:
    norm = path.replace("\\", "/").lower()
    if norm.startswith("api/"):
        return "high"
    if norm.startswith(".bgl_core/brain/"):
        return "high"
    if norm.startswith("app/") or norm.startswith("views/") or norm.startswith("partials/"):
        return "medium"
    if norm.startswith("agentfrontend/") or norm.startswith("public/js/"):
        return "medium"
    if norm.startswith("scripts/") or norm.startswith("tests/"):
        return "low"
    return "low"


def _list_files(root: Path, suffixes: Tuple[str, ...], skip_dirs: Optional[set[str]] = None) -> List[Path]:
    files: List[Path] = []
    skip_dirs = skip_dirs or set()
    for base, dirs, filenames in os.walk(root):
        dirs[:] = [d for d in dirs if d not in skip_dirs]
        for name in filenames:
            path = Path(base) / name
            if path.suffix.lower() in suffixes:
                files.append(path)
    return files


def _scan_python_tree(node: ast.AST) -> Dict[str, Any]:
    functions: List[str] = []
    classes: Dict[str, List[str]] = {}
    imports: List[str] = []
    for n in ast.walk(node):
        if isinstance(n, (ast.FunctionDef, ast.AsyncFunctionDef)):
            if isinstance(getattr(n, "parent", None), ast.Module):
                functions.append(n.name)
        elif isinstance(n, ast.ClassDef):
            methods = []
            for b in n.body:
                if isinstance(b, (ast.FunctionDef, ast.AsyncFunctionDef)):
                    methods.append(b.name)
            classes[n.name] = methods
        elif isinstance(n, ast.Import):
            for alias in n.names:
                imports.append(alias.name)
        elif isinstance(n, ast.ImportFrom):
            mod = n.module or ""
            imports.append(mod)
    return {
        "functions": sorted(set(functions)),
        "classes": {k: sorted(set(v)) for k, v in classes.items()},
        "imports": sorted(set([i for i in imports if i])),
    }


def _scan_js_file(path: Path) -> Dict[str, Any]:
    text = path.read_text(encoding="utf-8", errors="ignore")
    funcs = set(re.findall(r"function\\s+([A-Za-z0-9_$]+)\\s*\\(", text))
    classes = set(re.findall(r"class\\s+([A-Za-z0-9_$]+)", text))
    exports = set(re.findall(r"export\\s+(?:default\\s+)?(?:function|class)\\s+([A-Za-z0-9_$]+)", text))
    arrow_funcs = set(re.findall(r"const\\s+([A-Za-z0-9_$]+)\\s*=\\s*\\([^)]*\\)\\s*=>", text))
    return {
        "functions": sorted(funcs | arrow_funcs),
        "classes": sorted(classes),
        "exports": sorted(exports),
    }


def _attach_parents(tree: ast.AST) -> None:
    for node in ast.walk(tree):
        for child in ast.iter_child_nodes(node):
            child.parent = node  # type: ignore


def _scan_python(paths: List[Path]) -> Dict[str, Any]:
    files: Dict[str, Any] = {}
    for p in paths:
        rel = str(p.relative_to(ROOT_DIR))
        try:
            text = p.read_text(encoding="utf-8", errors="ignore")
            node = ast.parse(text)
            _attach_parents(node)
            files[rel] = _scan_python_tree(node)
        except Exception:
            files[rel] = {"error": "parse_failed"}
    return files


def _scan_js(paths: List[Path]) -> Dict[str, Any]:
    files: Dict[str, Any] = {}
    for p in paths:
        rel = str(p.relative_to(ROOT_DIR))
        try:
            files[rel] = _scan_js_file(p)
        except Exception:
            files[rel] = {"error": "parse_failed"}
    return files


def _scan_php_from_db(db_path: Path) -> Dict[str, Any]:
    if not db_path.exists():
        return {"error": "db_missing"}
    conn = sqlite3.connect(str(db_path))
    conn.row_factory = sqlite3.Row
    files: Dict[str, Any] = {}
    try:
        rows = conn.execute("SELECT id, path FROM files").fetchall()
        for r in rows:
            file_id = r["id"]
            path = r["path"]
            entities = conn.execute(
                "SELECT id, name, type, extends, line FROM entities WHERE file_id=?",
                (file_id,),
            ).fetchall()
            classes = {}
            functions = []
            for e in entities:
                methods = conn.execute(
                    "SELECT name, visibility, line FROM methods WHERE entity_id=?",
                    (e["id"],),
                ).fetchall()
                if e["type"] == "root":
                    functions.extend([m["name"] for m in methods if m["name"]])
                else:
                    classes[e["name"]] = [m["name"] for m in methods if m["name"]]
            files[path] = {
                "classes": classes,
                "functions": sorted(set(functions)),
            }
    except Exception as exc:
        files = {"error": str(exc)}
    finally:
        try:
            conn.close()
        except Exception:
            pass
    return files


def _collect_routes(db_path: Path) -> List[Dict[str, Any]]:
    if not db_path.exists():
        return []
    conn = sqlite3.connect(str(db_path))
    conn.row_factory = sqlite3.Row
    routes: List[Dict[str, Any]] = []
    try:
        rows = conn.execute("SELECT uri, http_method, controller, action, file_path FROM routes").fetchall()
        for r in rows:
            routes.append(dict(r))
    except Exception:
        routes = []
    finally:
        try:
            conn.close()
        except Exception:
            pass
    return routes


def build_code_intel(root_dir: Path = ROOT_DIR, db_path: Path = DB_PATH) -> Dict[str, Any]:
    start = time.time()
    meta: Dict[str, Any] = {
        "started_at": start,
        "root": str(root_dir),
        "db_path": str(db_path),
        "ok": True,
    }

    # Optional reindex (PHP AST) if DB looks empty or forced.
    try:
        force_reindex = str(os.getenv("BGL_CODE_INTEL_REINDEX", "0")) == "1"
    except Exception:
        force_reindex = False
    try:
        if force_reindex or not db_path.exists():
            try:
                from .indexer import EntityIndexer  # type: ignore
            except Exception:
                from indexer import EntityIndexer  # type: ignore
            indexer = EntityIndexer(root_dir, db_path)
            indexer.index_project()
            indexer.close()
        else:
            conn = sqlite3.connect(str(db_path))
            row = conn.execute("SELECT COUNT(*) FROM files").fetchone()
            conn.close()
            if row and int(row[0] or 0) == 0:
                try:
                    from .indexer import EntityIndexer  # type: ignore
                except Exception:
                    from indexer import EntityIndexer  # type: ignore
                indexer = EntityIndexer(root_dir, db_path)
                indexer.index_project()
                indexer.close()
    except Exception as exc:
        meta["php_reindex_error"] = str(exc)

    # PHP index (backend + views)
    php_index = _scan_php_from_db(db_path)
    routes = _collect_routes(db_path)

    # Python index (agent core + scripts/tests)
    py_paths = []
    skip_dirs = {"node_modules", "dist", ".git", "vendor", "__pycache__"}
    for base in (".bgl_core/brain", "scripts", "tests"):
        base_path = root_dir / base
        if base_path.exists():
            py_paths.extend(_list_files(base_path, (".py",), skip_dirs=skip_dirs))
    python_index = _scan_python(py_paths)

    # JS/Frontend index
    js_paths = []
    for base in ("public/js", "agentfrontend/src", "agentfrontend/app"):
        base_path = root_dir / base
        if base_path.exists():
            js_paths.extend(_list_files(base_path, (".js", ".jsx"), skip_dirs=skip_dirs))
    js_index = _scan_js(js_paths)

    # Risk summary
    risk_summary: Dict[str, int] = {"high": 0, "medium": 0, "low": 0}
    for path in list(php_index.keys()) + list(python_index.keys()) + list(js_index.keys()):
        tier = _risk_tier(str(path))
        risk_summary[tier] = risk_summary.get(tier, 0) + 1

    code_index = {
        "meta": meta,
        "routes": routes,
        "php": php_index,
        "python": python_index,
        "js": js_index,
        "risk_summary": risk_summary,
    }

    ANALYSIS_DIR.mkdir(parents=True, exist_ok=True)
    DOCS_DIR.mkdir(parents=True, exist_ok=True)
    (ANALYSIS_DIR / "code_index.json").write_text(
        json.dumps(code_index, ensure_ascii=False, indent=2), encoding="utf-8"
    )

    # Write a human-readable summary
    total_php = len(php_index) if isinstance(php_index, dict) else 0
    total_py = len(python_index)
    total_js = len(js_index)
    report_lines = [
        "# تقرير فهم الكود (Code Understanding)",
        "",
        f"- PHP files indexed: {total_php}",
        f"- Python files indexed: {total_py}",
        f"- JS files indexed: {total_js}",
        f"- Routes indexed: {len(routes)}",
        f"- Risk summary: high={risk_summary.get('high',0)}, medium={risk_summary.get('medium',0)}, low={risk_summary.get('low',0)}",
        "",
        "## مخرجات أساسية",
        f"- `analysis/code_index.json` يحتوي الخريطة الكاملة.",
        "",
        "## تفسير",
        "- الملفات عالية المخاطر تتطلب مراجعة واختبارات قبل أي تعديل.",
        "- ربط المخرجات في التقرير مع التعديلات يعطي ثقة أعلى.",
    ]
    (DOCS_DIR / "code_understanding_report.md").write_text(
        "\n".join(report_lines), encoding="utf-8"
    )

    # Best-effort: store a compact memory index entry for agent reasoning.
    try:
        from .memory_index import upsert_memory_item  # type: ignore
    except Exception:
        try:
            from memory_index import upsert_memory_item  # type: ignore
        except Exception:
            upsert_memory_item = None  # type: ignore
    if upsert_memory_item:
        try:
            upsert_memory_item(
                db_path=db_path,
                kind="code_index",
                key_text="code_index_snapshot",
                summary=f"Code index updated: php={total_php}, py={total_py}, js={total_js}, routes={len(routes)}",
                evidence_count=1,
                confidence=0.7,
                meta={
                    "code_index": str(ANALYSIS_DIR / "code_index.json"),
                    "report": str(DOCS_DIR / "code_understanding_report.md"),
                    "risk_summary": risk_summary,
                },
                source_table="code_intel",
                source_id=None,
            )
        except Exception:
            pass

    meta["duration_sec"] = round(time.time() - start, 3)
    meta["output"] = {
        "code_index": str(ANALYSIS_DIR / "code_index.json"),
        "report": str(DOCS_DIR / "code_understanding_report.md"),
    }
    return meta


if __name__ == "__main__":
    result = build_code_intel()
    print(json.dumps(result, ensure_ascii=False, indent=2))
