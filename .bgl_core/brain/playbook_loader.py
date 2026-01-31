import re
from pathlib import Path
from typing import Dict, Any


def load_playbooks_meta(root: Path) -> Dict[str, Any]:
    """
    Load front-matter metadata from playbooks/*.md
    Supports simple YAML-like header bounded by --- ... --- with key: value.
    """
    base = root / ".bgl_core" / "brain" / "playbooks"
    meta: Dict[str, Any] = {}
    if not base.exists():
        return meta
    for md in base.glob("*.md"):
        try:
            text = md.read_text(encoding="utf-8")
        except Exception:
            continue
        if not text.startswith("---"):
            continue
        parts = text.split("---", 2)
        if len(parts) < 3:
            continue
        header = parts[1].strip().splitlines()
        entry: Dict[str, Any] = {}
        key = None
        for line in header:
            if re.match(r"^\s*-", line):
                # list item for last key
                if key:
                    # normalize existing scalar to list then append
                    if not isinstance(entry.get(key), list):
                        entry[key] = [entry.get(key)] if entry.get(key) else []
                    entry.setdefault(key, []).append(line.strip("- ").strip())
                continue
            m = re.match(r"^(\w+):\s*(.*)$", line)
            if m:
                key = m.group(1)
                val = m.group(2)
                # booleans/numbers
                if val.lower() in ("true", "false"):
                    entry[key] = val.lower() == "true"
                else:
                    try:
                        entry[key] = float(val) if "." in val else int(val)
                    except Exception:
                        entry[key] = val
        if "id" in entry:
            meta[entry["id"]] = entry
        else:
            meta[md.name] = entry
    return meta
