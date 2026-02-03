from pathlib import Path
from typing import Dict, Any, List


def _read_text(path: Path) -> str:
    try:
        return path.read_text(encoding="utf-8", errors="ignore")
    except Exception:
        return ""


def run(project_root: Path) -> Dict[str, Any]:
    """
    Hard check:
    Prevent gate/approval drift by ensuring there is a *single* writer of the approval queue
    and a single patch actuator entrypoint.
    """
    brain = project_root / ".bgl_core" / "brain"
    if not brain.exists():
        return {"passed": True, "evidence": ["brain folder missing"], "scope": ["policy"]}

    violations: List[str] = []

    # 1) Only authority.py may write/update the approval queue (agent_permissions).
    perm_markers = [
        "INSERT INTO agent_permissions",
        "UPDATE agent_permissions",
        "DELETE FROM agent_permissions",
    ]
    for py in brain.rglob("*.py"):
        if py.name in ("authority.py", "authority_drift.py"):
            continue
        text = _read_text(py)
        for m in perm_markers:
            if m in text:
                violations.append(f"{py.relative_to(project_root)}: {m}")

    # 2) Only patcher.py may reference the PHP patch actuator.
    for py in brain.rglob("*.py"):
        if py.name in ("patcher.py", "authority_drift.py"):
            continue
        text = _read_text(py)
        if "patcher.php" in text:
            violations.append(f"{py.relative_to(project_root)}: patcher.php reference")

    # 3) Prevent raw SQL inserts into decision tables outside decision_db.py/authority.py.
    raw_sql_markers = [
        "INSERT INTO intents",
        "INSERT INTO decisions",
        "INSERT INTO outcomes",
    ]
    allow_raw = {"decision_db.py", "authority.py", "authority_drift.py"}
    for py in brain.rglob("*.py"):
        if py.name in allow_raw:
            continue
        text = _read_text(py)
        for m in raw_sql_markers:
            if m in text:
                violations.append(f"{py.relative_to(project_root)}: {m}")

    if violations:
        return {
            "passed": False,
            "evidence": ["authority drift detected"] + violations[:50],
            "scope": ["policy", "execution"],
        }

    return {
        "passed": True,
        "evidence": ["authority drift check passed"],
        "scope": ["policy", "execution"],
    }
