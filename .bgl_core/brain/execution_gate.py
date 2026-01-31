from typing import Dict, Any


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
        return False
    return True
