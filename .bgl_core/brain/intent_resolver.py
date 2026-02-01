from typing import Dict, Any


def resolve_intent(diagnostic: Dict[str, Any]) -> Dict[str, Any]:
    """
    Observe-only resolver.
    Produces a single intent snapshot without influencing execution.
    """
    findings = diagnostic.get("findings", {})
    failing = findings.get("failing_routes", []) or []
    permission = findings.get("permission_issues", []) or []
    worst_routes = findings.get("worst_routes", []) or []
    experiences = findings.get("experiences", []) or []

    route_usage = diagnostic.get("route_usage", {}) or {}
    feature_flags = diagnostic.get("feature_flags", {}) or {}
    suppressed = False

    if failing:
        intent = "stabilize"
        reason = f"{len(failing)} failing routes detected"
        confidence = 0.85
        scope = [f.get("uri") if isinstance(f, dict) else str(f) for f in failing][:3]
    elif worst_routes:
        intent = "investigate"
        reason = "Hot routes with recent issues"
        confidence = 0.6
        scope = [w.get("uri") for w in worst_routes[:3]]
    elif permission:
        intent = "unblock"
        reason = "Permission issues detected"
        confidence = 0.65
        scope = permission[:3]
    else:
        intent = "observe"
        reason = "System nominal"
        confidence = 0.4
        scope = []

    context_snapshot = {
        "health": diagnostic.get("vitals", {}),
        "active_route": scope[0] if scope else None,
        "recent_changes": [],
        "guardian_top": scope[:3],
        "browser_state": diagnostic.get("browser_state", "unknown"),
    }

    # suppression rules: keep them conservative but never suppress failing/hot routes
    primary_route = context_snapshot.get("active_route")
    if intent == "observe":
        # only apply suppression heuristics when we're already in observe mode
        if primary_route:
            usage = float(route_usage.get(primary_route, 0))
            if usage < 0.01:
                suppressed = True
            deprecated = feature_flags.get("deprecated_routes", [])
            if primary_route in deprecated:
                suppressed = True
    else:
        suppressed = False

    return {
        "intent": intent,
        "confidence": confidence,
        "reason": reason,
        "scope": scope,
        "context_snapshot": context_snapshot,
        "suppressed": suppressed,
    }
