import json
import os
import shutil
from pathlib import Path
from datetime import date

try:
    from .config_loader import load_config  # type: ignore
except Exception:
    from config_loader import load_config

try:
    from .approve_playbook import append_rule  # type: ignore
except Exception:
    from approve_playbook import append_rule

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


def _approvals_disabled(cfg: dict) -> bool:
    """Return True when human approvals are globally disabled."""
    try:
        env_flag = os.getenv("BGL_APPROVALS_ENABLED")
        if env_flag is not None:
            if str(env_flag).strip().lower() in ("0", "false", "no", "off"):
                return True
    except Exception:
        pass
    try:
        force_no_human = cfg.get("force_no_human_approvals", 0)
        if str(force_no_human).strip().lower() in ("1", "true", "yes", "on"):
            return True
    except Exception:
        pass
    approvals = cfg.get("approvals_enabled", 1)
    if isinstance(approvals, bool):
        return not approvals
    if isinstance(approvals, (int, float)):
        return float(approvals) == 0.0
    if isinstance(approvals, str):
        return approvals.strip().lower() in ("0", "false", "no", "off")
    return False


def _auto_approve_pending(brain: Path) -> None:
    """Move proposed playbooks into playbooks and append runtime safety rules."""
    try:
        proposed_dir = brain / "playbooks_proposed"
        approved_dir = brain / "playbooks"
        approved_dir.mkdir(exist_ok=True)
        runtime_file = brain / "runtime_safety.yml"
        for src in proposed_dir.glob("*.md"):
            pid = src.stem
            dst = approved_dir / f"{pid}.md"
            if dst.exists():
                try:
                    src.unlink(missing_ok=True)
                except Exception:
                    pass
                continue
            try:
                shutil.move(str(src), str(dst))
                append_rule(runtime_file, pid, f"Ensure playbook {pid} is applied")
            except Exception:
                continue
    except Exception:
        return


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
    cfg = load_config(project_root) if project_root else {}
    auto_approve = _approvals_disabled(cfg)

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
        evidence_items = pat.get("evidence", []) or []
        evidence = "; ".join(str(item) for item in evidence_items[:3]) or "N/A"
        scope_items = pat.get("scope", []) or []
        scope = ", ".join(str(item) for item in scope_items[:3]) or "global"
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

    # If approvals are disabled, auto-approve all pending playbooks.
    if auto_approve:
        _auto_approve_pending(brain)
    return generated


if __name__ == "__main__":
    root = Path(__file__).resolve().parents[2]
    created = generate_from_proposed(root)
    if created:
        print("[+] Generated playbooks:", created)
