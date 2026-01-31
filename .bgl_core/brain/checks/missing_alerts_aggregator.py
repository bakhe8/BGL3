from pathlib import Path


def run(project_root: Path):
    scope = ["ops"]
    evidence = []

    # ابحث عن تنبيهات عبر slack/email أو تجميع أخطاء
    candidates = [
        project_root / "app" / "Services",
        project_root / "app" / "Support",
        project_root / "api",
    ]
    keywords = ("slack", "sendmail", "alert", "notification", "notify")

    for d in candidates:
        if not d.exists():
            continue
        for f in d.rglob("*.php"):
            try:
                txt = f.read_text(encoding="utf-8", errors="ignore").lower()
                if any(k in txt for k in keywords):
                    return {"passed": True, "evidence": [], "scope": []}
            except Exception:
                continue

    evidence.append("No alert/notification aggregator detected for repeated failures")
    return {"passed": False, "evidence": evidence, "scope": scope}
