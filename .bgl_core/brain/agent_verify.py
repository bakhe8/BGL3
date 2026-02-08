import importlib
import json
import importlib
from pathlib import Path
from typing import List, Dict, Any


def run_all_checks(project_root: Path) -> Dict[str, Any]:
    """
    مصدر موحد لتشغيل كل checks المحددة في inference_patterns.json.
    يعيد JSON يحتوي على النتائج وتمرير/فشل إجمالي.
    """
    patterns_file = project_root / ".bgl_core" / "brain" / "inference_patterns.json"
    results: List[Dict[str, Any]] = []
    if not patterns_file.exists():
        return {"passed": True, "results": results}

    patterns = json.loads(patterns_file.read_text(encoding="utf-8"))
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


if __name__ == "__main__":
    root = Path(__file__).resolve().parents[2]
    summary = run_all_checks(root)
    print(json.dumps(summary, ensure_ascii=False, indent=2))
