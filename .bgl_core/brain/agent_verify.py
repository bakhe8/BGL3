import importlib
import json
import importlib
from pathlib import Path
from typing import List, Dict, Any, Iterable, Set


def _normalize_path(path: str) -> str:
    return str(path or "").replace("\\", "/").lstrip("./")


def _infer_scopes_from_files(files: Iterable[str]) -> List[str]:
    scopes: Set[str] = set()
    for raw in files:
        path = _normalize_path(raw)
        if not path:
            continue
        if path.startswith(".bgl_core/") or "/.bgl_core/" in path:
            scopes.add("policy")
        if path.startswith("api/") or "/api/" in path:
            scopes.add("api")
        if path.startswith("views/") or "/views/" in path:
            scopes.add("ui")
        if path.startswith("agentfrontend/") or "/agentfrontend/" in path:
            scopes.add("ui")
        if path.endswith(".css") or path.endswith(".js"):
            scopes.add("ui")
        if path.startswith("reports/") or "/reports/" in path:
            scopes.add("reports")
        if "report" in path and path.endswith(".php"):
            scopes.add("reports")
        if path.startswith("ops/") or "/ops/" in path or "cron" in path:
            scopes.add("ops")
        if path.startswith("db/") or "/db/" in path or path.endswith(".sql"):
            scopes.add("db")
        if "schema" in path or "migration" in path:
            scopes.add("db")
        if path.endswith(".php") and "api/" not in path and "views/" not in path and "agentfrontend/" not in path:
            scopes.add("app")
    if not scopes:
        scopes.add("app")
    return sorted(scopes)


def _pattern_matches_scope(pat_scope: Any, scopes: Iterable[str]) -> bool:
    if pat_scope is None or pat_scope == "":
        return True
    if isinstance(pat_scope, list):
        return any(str(s).strip() in scopes for s in pat_scope if s)
    return str(pat_scope).strip() in scopes


def _load_patterns(project_root: Path) -> List[Dict[str, Any]]:
    patterns_file = project_root / ".bgl_core" / "brain" / "inference_patterns.json"
    if not patterns_file.exists():
        return []
    try:
        return json.loads(patterns_file.read_text(encoding="utf-8")) or []
    except Exception:
        return []


def run_all_checks(project_root: Path) -> Dict[str, Any]:
    """
    مصدر موحد لتشغيل كل checks المحددة في inference_patterns.json.
    يعيد JSON يحتوي على النتائج وتمرير/فشل إجمالي.
    """
    results: List[Dict[str, Any]] = []
    patterns = _load_patterns(project_root)
    if not patterns:
        return {"passed": True, "results": results}
    for pat in patterns:
        check_name = pat.get("check")
        if not check_name:
            continue
        try:
            module = importlib.import_module(f".checks.{check_name}", package="brain")
        except Exception:
            try:
                module = importlib.import_module(f"checks.{check_name}")
            except Exception as e:
                results.append(
                    {
                        "id": pat.get("id", check_name),
                        "check": check_name,
                        "passed": False,
                        "evidence": [f"import failed: {e}"],
                        "scope": pat.get("scope", []),
                    }
                )
                continue
        if not hasattr(module, "run"):
            results.append(
                {
                    "id": pat.get("id", check_name),
                    "check": check_name,
                    "passed": False,
                    "evidence": ["missing run()"],
                    "scope": pat.get("scope", []),
                }
            )
            continue
        try:
            res = module.run(project_root)
        except Exception as e:
            results.append(
                {
                    "id": pat.get("id", check_name),
                    "check": check_name,
                    "passed": False,
                    "evidence": [f"runtime error: {e}"],
                    "scope": pat.get("scope", []),
                }
            )
            continue
        results.append(
            {
                "id": pat.get("id", check_name),
                "check": check_name,
                "passed": bool(res.get("passed", False)),
                "evidence": res.get("evidence", []),
                "scope": res.get("scope", pat.get("scope", [])),
                "recommendation": pat.get("recommendation", ""),
            }
        )

    all_passed = all(r.get("passed") for r in results)
    return {"passed": all_passed, "results": results}


def run_contextual_checks(project_root: Path, changed_files: List[str]) -> Dict[str, Any]:
    """
    يشغّل checks ضمن scope مرتبط بالملفات المتغيّرة.
    عند عدم وجود scope محدد، يعود لتشغيل كل checks.
    """
    patterns = _load_patterns(project_root)
    if not patterns:
        return {"passed": True, "results": [], "context_scopes": []}
    scopes = _infer_scopes_from_files(changed_files)
    filtered = [p for p in patterns if _pattern_matches_scope(p.get("scope"), scopes)]
    if not filtered:
        filtered = patterns
    results: List[Dict[str, Any]] = []
    for pat in filtered:
        check_name = pat.get("check")
        if not check_name:
            continue
        try:
            module = importlib.import_module(f".checks.{check_name}", package="brain")
        except Exception:
            try:
                module = importlib.import_module(f"checks.{check_name}")
            except Exception as e:
                results.append(
                    {
                        "id": pat.get("id", check_name),
                        "check": check_name,
                        "passed": False,
                        "evidence": [f"import failed: {e}"],
                        "scope": pat.get("scope", []),
                    }
                )
                continue
        if not hasattr(module, "run"):
            results.append(
                {
                    "id": pat.get("id", check_name),
                    "check": check_name,
                    "passed": False,
                    "evidence": ["missing run()"],
                    "scope": pat.get("scope", []),
                }
            )
            continue
        try:
            res = module.run(project_root)
        except Exception as e:
            results.append(
                {
                    "id": pat.get("id", check_name),
                    "check": check_name,
                    "passed": False,
                    "evidence": [f"runtime error: {e}"],
                    "scope": pat.get("scope", []),
                }
            )
            continue
        results.append(
            {
                "id": pat.get("id", check_name),
                "check": check_name,
                "passed": bool(res.get("passed", False)),
                "evidence": res.get("evidence", []),
                "scope": res.get("scope", pat.get("scope", [])),
                "recommendation": pat.get("recommendation", ""),
            }
        )
    all_passed = all(r.get("passed") for r in results)
    return {
        "passed": all_passed,
        "results": results,
        "context_scopes": scopes,
    }


if __name__ == "__main__":
    root = Path(__file__).resolve().parents[2]
    summary = run_all_checks(root)
    print(json.dumps(summary, ensure_ascii=False, indent=2))
