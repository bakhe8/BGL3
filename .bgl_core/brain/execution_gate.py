from typing import Dict, Any
from pathlib import Path
import os

try:
    from .authority import Authority  # type: ignore
    from .config_loader import load_config  # type: ignore
except Exception:
    from authority import Authority
    from config_loader import load_config


def check(decision: Dict[str, Any], action: str) -> bool:
    """
    Central gate. For الآن يبقى غير مانع إلا في الحالات الواضحة.
    Returns True if action is allowed to proceed.
    """
    dec = decision.get("decision", "observe")
    requires_human = decision.get("requires_human", False)
    approvals_enabled = True
    force_no_human = False
    disable_fallback_blocks = False
    disable_fallback_require = False
    try:
        root = Path(__file__).resolve().parents[2]
        cfg = load_config(root) or {}
        force_val = cfg.get("force_no_human_approvals", 0)
        if isinstance(force_val, bool):
            force_no_human = force_val
        elif isinstance(force_val, (int, float)):
            force_no_human = float(force_val) != 0.0
        else:
            force_no_human = str(force_val).strip().lower() in ("1", "true", "yes", "on")
        env_force = os.getenv("BGL_FORCE_NO_HUMAN_APPROVALS")
        if env_force is not None:
            force_no_human = str(env_force).strip().lower() in ("1", "true", "yes", "on")
        env_flag = os.getenv("BGL_APPROVALS_ENABLED")
        if env_flag is not None:
            approvals_enabled = str(env_flag).strip().lower() in ("1", "true", "yes", "on")
        else:
            cfg_val = cfg.get("approvals_enabled", 1)
            if isinstance(cfg_val, bool):
                approvals_enabled = cfg_val
            elif isinstance(cfg_val, (int, float)):
                approvals_enabled = float(cfg_val) != 0.0
            else:
                approvals_enabled = str(cfg_val).strip().lower() in ("1", "true", "yes", "on")
        block_flag = os.getenv("BGL_DISABLE_FALLBACK_BLOCKS")
        if block_flag is not None:
            disable_fallback_blocks = str(block_flag).strip().lower() in ("1", "true", "yes", "on")
        else:
            cfg_val = cfg.get("fallback_block_disabled", 0)
            if isinstance(cfg_val, bool):
                disable_fallback_blocks = cfg_val
            elif isinstance(cfg_val, (int, float)):
                disable_fallback_blocks = float(cfg_val) != 0.0
            else:
                disable_fallback_blocks = str(cfg_val).strip().lower() in ("1", "true", "yes", "on")
        req_flag = os.getenv("BGL_DISABLE_FALLBACK_REQUIRE_HUMAN")
        if req_flag is not None:
            disable_fallback_require = str(req_flag).strip().lower() in ("1", "true", "yes", "on")
        else:
            cfg_val = cfg.get("fallback_require_human_disabled", 0)
            if isinstance(cfg_val, bool):
                disable_fallback_require = cfg_val
            elif isinstance(cfg_val, (int, float)):
                disable_fallback_require = float(cfg_val) != 0.0
            else:
                disable_fallback_require = str(cfg_val).strip().lower() in ("1", "true", "yes", "on")
    except Exception:
        approvals_enabled = True

    if dec == "block" and not disable_fallback_blocks:
        return False
    if dec == "defer":
        return False
    if force_no_human:
        requires_human = False
        approvals_enabled = False

    if requires_human and disable_fallback_require:
        requires_human = False

    if requires_human and approvals_enabled:
        # Compatibility: create/consult the approval queue instead of silently blocking forever.
        # This keeps old callers working while enabling the new approval workflow.
        try:
            root = Path(__file__).resolve().parents[2]
            auth = Authority(root)
            op = f"patch.{action}" if action else str(decision.get("intent") or "execution")
            cmd = str(decision.get("reason") or decision.get("command") or action or op)
            if auth.has_permission(op):
                return True
            auth.request_permission(op, cmd)
        except Exception:
            pass
        return False
    return True
