from __future__ import annotations

import json
import re
import os
import time
from pathlib import Path
from typing import Dict, Tuple, Optional


def _extract_meta(content: str) -> Tuple[Optional[str], Optional[str]]:
    try:
        path_match = re.search(r"\*\*Path\*\*: `(.+?)`", content or "")
        hash_match = re.search(r"\*\*Source-Hash\*\*: ([a-f0-9]{64})", content or "")
        source_rel = path_match.group(1) if path_match else None
        stored_hash = hash_match.group(1) if hash_match else None
        return source_rel, stored_hash
    except Exception:
        return None, None


def should_include_insight(
    doc_file: Path,
    project_root: Path,
    *,
    allow_legacy: bool = False,
) -> Tuple[bool, str]:
    """
    Decide whether an auto-insight should be included.
    Returns (include, reason_code).
    reason_code: ok | duplicate | missing_meta | nested | missing_source | stale | expired
    """
    name = doc_file.name
    if name.endswith(".insight.md.insight.md"):
        return False, "duplicate"
    if not name.endswith(".insight.md"):
        return False, "missing_meta"
    try:
        content = doc_file.read_text(encoding="utf-8")
    except Exception:
        return False, "missing_meta"
    source_rel, stored_hash = _extract_meta(content)
    if not source_rel or not stored_hash:
        return (True, "ok") if allow_legacy else (False, "missing_meta")
    # Skip insights about other insights
    if "auto_insights" in source_rel.replace("\\", "/") or source_rel.endswith(".insight.md"):
        return False, "nested"
    source_full = project_root / source_rel
    if not source_full.exists():
        return False, "missing_source"
    try:
        current_hash = _hash_file(source_full)
    except Exception:
        current_hash = ""
    if current_hash and current_hash != stored_hash:
        return False, "stale"
    try:
        ttl_days = int(os.getenv("BGL_AUTO_INSIGHTS_TTL_DAYS", "0") or 0)
    except Exception:
        ttl_days = 0
    if ttl_days > 0:
        try:
            age_days = (time.time() - doc_file.stat().st_mtime) / 86400.0
            if age_days > float(ttl_days):
                return False, "expired"
        except Exception:
            pass
    return True, "ok"


def _hash_file(path: Path) -> str:
    import hashlib
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(8192), b""):
            h.update(chunk)
    return h.hexdigest()


def audit_auto_insights(
    project_root: Path,
    *,
    allow_legacy: bool = False,
    max_insights: int = 0,
) -> Dict[str, int]:
    """
    Audit auto_insights health. Returns counts.
    """
    folder = project_root / ".bgl_core" / "knowledge" / "auto_insights"
    counts = {
        "total": 0,
        "loaded": 0,
        "duplicate": 0,
        "missing_meta": 0,
        "nested": 0,
        "missing_source": 0,
        "stale": 0,
        "expired": 0,
        "skipped_limit": 0,
    }
    if not folder.exists():
        return counts
    loaded = 0
    for doc_file in folder.rglob("*.md"):
        if ".insight.md" not in doc_file.name:
            continue
        counts["total"] += 1
        ok, reason = should_include_insight(
            doc_file, project_root, allow_legacy=allow_legacy
        )
        if ok:
            if max_insights and loaded >= max_insights:
                counts["skipped_limit"] += 1
                continue
            counts["loaded"] += 1
            loaded += 1
        else:
            counts[reason] = counts.get(reason, 0) + 1
    return counts


def write_auto_insights_status(project_root: Path, data: Dict[str, int]) -> None:
    try:
        logs = project_root / ".bgl_core" / "logs"
        logs.mkdir(parents=True, exist_ok=True)
        out_path = logs / "auto_insights_status.json"
        out_path.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")
    except Exception:
        return
