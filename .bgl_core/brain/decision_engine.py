from typing import Dict, Any


def decide(intent_payload: Dict[str, Any], policy: Dict[str, Any]) -> Dict[str, Any]:
    decision = "observe"
    risk_level = "low"
    requires_human = False
    justification = []

    intent = intent_payload.get("intent", "observe")
    confidence = float(intent_payload.get("confidence", 0))
    maturity = (intent_payload.get("maturity") or {}).get("level", "experimental")
    suppressed = intent_payload.get("suppressed", False)
    conflicts = intent_payload.get("conflicts_with", [])

    # derive risk from intent
    if intent == "stabilize":
        risk_level = "medium"
    if intent == "investigate":
        risk_level = "low"

    mode = str(policy.get("mode", "assisted")).lower()
    auto_cfg = policy.get("auto_fix", {})
    min_conf = float(auto_cfg.get("min_confidence", 0.75))
    max_risk = auto_cfg.get("max_risk", "low")

    def risk_rank(r):
        return {"low": 1, "medium": 2, "high": 3}.get(str(r).lower(), 2)

    if suppressed:
        decision = "ignore"
        justification.append("Suppressed by policy/context.")
    elif intent == "observe":
        decision = "observe"
        justification.append("No actionable signals.")
    elif risk_rank(risk_level) > risk_rank(max_risk) or confidence < min_conf:
        decision = "propose_fix"
        justification.append("Below confidence/risk threshold for auto_fix.")
    else:
        decision = "auto_fix"
        justification.append("Meets confidence/risk thresholds.")

    if intent == "defer":
        decision = "defer"
        justification.append("Marked defer: important but not now.")

    if intent == "stabilize" and mode == "safe":
        decision = "block"
        justification.append("agent_mode=safe blocks fixes.")

    if intent == "refactor" or intent == "rename":
        requires_human = bool(policy.get("refactor", {}).get("requires_human", True))
        if requires_human:
            decision = "block" if mode == "safe" else "propose_fix"
            justification.append("Refactor requires human approval.")

    # maturity influence: enforced + high confidence & low risk => auto_fix
    if maturity == "enforced" and decision == "propose_fix" and risk_rank(risk_level) <= risk_rank(max_risk) and confidence >= min_conf:
        decision = "auto_fix"
        justification.append("Maturity enforced: allowing auto_fix.")

    return {
        "decision": decision,
        "risk_level": risk_level,
        "requires_human": requires_human,
        "justification": justification,
        "maturity": maturity,
        "conflicts_with": conflicts,
    }
