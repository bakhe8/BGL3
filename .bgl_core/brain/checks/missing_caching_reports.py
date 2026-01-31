from pathlib import Path


def run(project_root: Path):
    scope = ["reports"]
    evidence = []

    reports = [
        project_root / "api" / "get_banks.php",
        project_root / "api" / "get_suppliers.php",
        project_root / "api" / "history.php",
    ]
    keywords = ("cache", "Cache", "remember", "cached")

    for f in reports:
        if not f.exists():
            continue
        try:
            txt = f.read_text(encoding="utf-8", errors="ignore")
            if any(k in txt for k in keywords):
                return {"passed": True, "evidence": [], "scope": []}
        except Exception:
            continue

    evidence.append("No caching hints found in main reporting endpoints")
    return {"passed": False, "evidence": evidence, "scope": scope}
