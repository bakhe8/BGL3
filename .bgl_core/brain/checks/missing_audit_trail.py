from pathlib import Path


def run(project_root: Path):
    evidence = []
    scope = []
    # وجود نموذج أو migration لـ audit
    if (project_root / "app" / "Models" / "AuditLog.php").exists():
        return {"passed": True, "evidence": [], "scope": []}
    migrations = list((project_root / "database" / "migrations").glob("*audit*.php"))
    if migrations:
        return {"passed": True, "evidence": [], "scope": []}
    evidence.append("No AuditLog model or audit_* migration found")
    scope.append("audit")
    return {"passed": False, "evidence": evidence, "scope": scope}
