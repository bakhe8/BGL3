import json
from pathlib import Path
from datetime import date


TEMPLATE = """---
id: {id}
type: {type}
risk_if_missing: {risk}
auto_applicable: {auto_applicable}
origin: auto_generated
confidence: {confidence}
conflicts_with: []
maturity:
  level: experimental
  first_seen: {first_seen}
  success_rate: 0.0
---
# Playbook: {title}

## الهدف
{goal}

## السياق
- مُولّد تلقائياً من فحص: {check}
- الدليل: {evidence}
- النطاق: {scope}

## الخطوات (مبدئية)
1. حدّد نقطة الحقن المناسبة.
2. طبّق قوالب التصحيح أو أضف منطقك الخاص.
3. شغّل اختبارات Gap ذات الصلة.

## معايير القبول
- لا توجد تحذيرات في Gap Tests المرتبطة.
- نجاح مسار العمل الأساسي دون أخطاء.
"""


def generate_from_proposed(project_root: Path):
    brain = project_root / ".bgl_core" / "brain"
    proposed_file = brain / "proposed_patterns.json"
    if not proposed_file.exists():
        return []

    proposed_dir = brain / "playbooks_proposed"
    proposed_dir.mkdir(exist_ok=True)

    with proposed_file.open("r", encoding="utf-8") as f:
        patterns = json.load(f) or []

    generated = []
    today = date.today().isoformat()

    for pat in patterns:
        pid = pat.get("id") or "PB_AUTO"
        # إذا كان الـ playbook معتمد مسبقاً، لا تنشئ مسودة جديدة
        approved = brain / "playbooks" / f"{pid}.md"
        if approved.exists():
            continue
        fname = proposed_dir / f"{pid}.md"
        if fname.exists():
            continue

        title = pid.replace("_", " ").title()
        goal = pat.get("recommendation", "تحسين تلقائي مقترح.")
        check = pat.get("check", "")
        evidence = "; ".join(pat.get("evidence", [])[:3]) or "N/A"
        scope = ", ".join(pat.get("scope", [])[:3]) or "global"
        content = TEMPLATE.format(
            id=pid,
            type="reliability",
            risk="medium",
            auto_applicable="false",
            confidence=pat.get("confidence", 0.65),
            first_seen=today,
            title=title,
            goal=goal,
            check=check,
            evidence=evidence,
            scope=scope,
        )
        fname.write_text(content, encoding="utf-8")
        generated.append(str(fname))
    return generated


if __name__ == "__main__":
    root = Path(__file__).resolve().parents[2]
    created = generate_from_proposed(root)
    if created:
        print("[+] Generated playbooks:", created)
