from pathlib import Path


def run(project_root: Path):
    scope = ["api"]
    evidence = []

    import_files = list((project_root / "api").glob("import*.php"))
    patterns = ("filesize", "mime", "finfo", "max_size", "upload_max_filesize")
    has_guard = False
    for f in import_files:
        try:
            txt = f.read_text(encoding="utf-8", errors="ignore").lower()
            if any(p in txt for p in patterns):
                has_guard = True
                break
        except Exception:
            continue

    if has_guard:
        return {"passed": True, "evidence": [], "scope": []}

    evidence.append("Import endpoints missing size/type checks (filesize/mime not found)")
    return {"passed": False, "evidence": evidence, "scope": scope}
