from typing import Dict, Any, List
import time


def interpret(diagnostic: Dict[str, Any]) -> Dict[str, Any]:
    suggestions: List[str] = []

    failing = diagnostic["findings"].get("failing_routes", [])
    worst = diagnostic["findings"].get("worst_routes", [])
    perms = diagnostic["findings"].get("permission_issues", [])
    exp = diagnostic["findings"].get("experiences", [])

    if perms:
        suggestions.append(f"صلاحيات حرجة: {', '.join(perms[:3])}")

    if failing:
        uris = [f.get('uri') for f in failing[:3] if isinstance(f, dict)]
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
        "items_list": suggestions
    }
