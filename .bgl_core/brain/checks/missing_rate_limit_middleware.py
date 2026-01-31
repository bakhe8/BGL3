import os
from pathlib import Path
from typing import Dict, Any, List


def run(project_root: Path) -> Dict[str, Any]:
    """
    Check if any rate limiting middleware/guard is present.
    Heuristic: search for 'RateLimit' or 'rate limit' in autoload/router files.
    """
    targets: List[Path] = [
        project_root / "app" / "Support" / "autoload.php",
        project_root / "routes" / "web.php",
        project_root / "routes" / "api.php",
    ]
    found = False
    evidence: List[str] = []
    for t in targets:
        if t.exists():
            try:
                content = t.read_text(encoding="utf-8")
                if "RateLimit" in content or "rate limit" in content.lower():
                    found = True
                    evidence.append(str(t))
            except Exception:
                pass
    return {
        "passed": found,
        "evidence": evidence,
        "scope": ["api"] if not found else evidence,
    }
