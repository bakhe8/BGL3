from typing import Dict, Any, List
import time


def interpret(diagnostic: Dict[str, Any]) -> Dict[str, Any]:
    suggestions: List[str] = []

    failing = diagnostic["findings"].get("failing_routes", [])
    worst = diagnostic["findings"].get("worst_routes", [])
    perms = diagnostic["findings"].get("permission_issues", [])
    exp = diagnostic["findings"].get("experiences", [])

    # Calculate Metrics
    stability_index = 1.0
    if failing:
        stability_index -= min(len(failing) * 0.1, 0.5)
    if worst:
        stability_index -= min(len(worst) * 0.05, 0.3)

    security_index = 1.0
    if perms:
        security_index -= min(len(perms) * 0.2, 0.6)

    compliance_index = 1.0
    viols = diagnostic["findings"].get("content_violations", [])
    if viols:
        titles = set(v["message"] for v in viols[:3])
        suggestions.append(f"مخالفات محتوى محظور: {', '.join(titles)}")
        compliance_index -= min(len(viols) * 0.2, 0.8)

    # Global Score (Weighted)
    global_score = (
        (stability_index * 0.5) + (security_index * 0.3) + (compliance_index * 0.2)
    )

    # Generate Suggestions
    if perms:
        suggestions.append(f"صلاحيات حرجة: {', '.join(perms[:3])}")
    if failing:
        uris = [
            str(f.get("uri")).strip()
            for f in failing[:3]
            if isinstance(f, dict) and f.get("uri")
        ]
        if uris:
            suggestions.append(f"مسارات تفشل حالياً: {', '.join(uris)}")
    if worst:
        wr = ", ".join([f"{w.get('uri')} (score {w.get('score')})" for w in worst[:3]])
        suggestions.append(f"مسارات ساخنة بحاجة فحص عميق: {wr}")
    if not suggestions and exp:
        suggestions.append("لا مشاكل حرجة؛ راقب الخبرات الحديثة.")
    if not suggestions:
        suggestions.append("النظام مستقر حالياً.")

    return {
        "generated_at": time.time(),
        "items_list": suggestions,
        "metrics": {
            "stability_index": round(stability_index, 2),
            "security_index": round(security_index, 2),
            "compliance_index": round(compliance_index, 2),
            "global_score": round(global_score, 2),
        },
    }
