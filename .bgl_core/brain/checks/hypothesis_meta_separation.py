from pathlib import Path
import os
from typing import Dict, Any, List

from config_loader import load_config


def run(project_root: Path) -> Dict[str, Any]:
    """
    Advisory check:
    Ensure hypothesis handling and meta-reasoning are not merged in the same module.
    Strict mode (BGL_POLICY_STRICT=1 or config policy_strict=1) turns this into a failing check.
    """
    cfg = load_config(project_root)
    strict = os.getenv("BGL_POLICY_STRICT")
    if strict is None:
        strict = str(cfg.get("policy_strict", 0))
    strict = str(strict) == "1"

    brain_dir = project_root / ".bgl_core" / "brain"
    if not brain_dir.exists():
        return {"passed": True, "evidence": ["brain dir missing"], "scope": ["policy"]}

    hypo_files = []
    meta_files = []
    for f in brain_dir.glob("*.py"):
        name = f.name.lower()
        if "hypothesis" in name:
            hypo_files.append(f)
        if "meta_reason" in name or "metareason" in name:
            meta_files.append(f)

    # No explicit modules yet
    if not hypo_files and not meta_files:
        return {
            "passed": True,
            "evidence": [
                "no hypothesis/meta modules detected (advisory only)"
            ],
            "scope": ["policy"],
        }

    mixed: List[str] = []
    for f in hypo_files:
        text = f.read_text(encoding="utf-8", errors="ignore").lower()
        if "meta_reason" in text or "metareason" in text:
            mixed.append(f"{f.name} references meta_reasoning")
    for f in meta_files:
        text = f.read_text(encoding="utf-8", errors="ignore").lower()
        if "hypothesis" in text:
            mixed.append(f"{f.name} references hypothesis")

    if mixed:
        return {
            "passed": False if strict else True,
            "evidence": mixed + ["advisory: keep hypothesis entities separate from meta reasoning"],
            "scope": ["policy"],
        }

    return {
        "passed": True,
        "evidence": [
            "hypothesis/meta modules present with separation intact"
        ],
        "scope": ["policy"],
    }
