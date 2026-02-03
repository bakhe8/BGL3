from pathlib import Path
import os
from typing import Dict, Any, List

from config_loader import load_config


def run(project_root: Path) -> Dict[str, Any]:
    """
    Advisory check:
    Ensure self-regulation layers are tied to runtime sensing signals.
    Strict mode (BGL_POLICY_STRICT=1 or config policy_strict=1) turns this into a failing check.
    """
    cfg = load_config(project_root)
    strict = os.getenv("BGL_POLICY_STRICT")
    if strict is None:
        strict = str(cfg.get("policy_strict", 0))
    strict = str(strict) == "1"

    files = [
        project_root / ".bgl_core" / "brain" / "decision_engine.py",
        project_root / ".bgl_core" / "brain" / "execution_gate.py",
    ]
    markers = [
        "runtime_events",
        "runtime_events_meta",
        "metrics_summary",
        "metrics_guard",
        "experiences",
        "browser_sensor",
        "hardware_vitals",
    ]

    found: List[str] = []
    missing_files: List[str] = []
    for f in files:
        if not f.exists():
            missing_files.append(str(f))
            continue
        text = f.read_text(encoding="utf-8", errors="ignore").lower()
        hits = [m for m in markers if m in text]
        if hits:
            found.append(f"{f.name}: {', '.join(hits)}")

    if found:
        return {
            "passed": True,
            "evidence": ["runtime linkage detected"] + found,
            "scope": ["policy", "runtime"],
        }

    # No linkage found
    evidence = []
    if missing_files:
        evidence.append("missing files: " + ", ".join(missing_files))
    evidence.append(
        "no runtime sensing linkage found in decision_engine/execution_gate"
    )
    evidence.append(
        "advisory: connect self-regulation to runtime events/metrics when ready"
    )

    return {
        "passed": False if strict else True,
        "evidence": evidence,
        "scope": ["policy", "runtime"],
    }
