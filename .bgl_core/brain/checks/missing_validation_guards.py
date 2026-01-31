from pathlib import Path


def run(project_root: Path):
    evidence = []
    scope = ["api"]

    requests_dir = project_root / "app" / "Http" / "Requests"
    if requests_dir.exists() and any(requests_dir.glob("*.php")):
        return {"passed": True, "evidence": [], "scope": []}

    # fallback: ابحث عن كلمة Request في الكنترولرات
    controllers = list((project_root / "app" / "Http" / "Controllers").rglob("*.php"))
    found_request = False
    for c in controllers:
        try:
            txt = c.read_text(encoding="utf-8", errors="ignore")
            if "Request" in txt and "validate" in txt:
                found_request = True
                break
        except Exception:
            continue
    if found_request:
        return {"passed": True, "evidence": [], "scope": []}

    evidence.append("No FormRequest classes or validation calls detected in controllers")
    return {"passed": False, "evidence": evidence, "scope": scope}
