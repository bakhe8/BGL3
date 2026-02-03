from typing import Dict, Any
from pathlib import Path

try:
    from .authority import Authority  # type: ignore
except Exception:
    from authority import Authority


def check(decision: Dict[str, Any], action: str) -> bool:
    """
    Central gate. For الآن يبقى غير مانع إلا في الحالات الواضحة.
    Returns True if action is allowed to proceed.
    """
    dec = decision.get("decision", "observe")
    requires_human = decision.get("requires_human", False)

    if dec == "block":
        return False
    if dec == "defer":
        return False
    if requires_human:
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
