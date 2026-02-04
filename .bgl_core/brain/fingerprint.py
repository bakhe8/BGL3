from __future__ import annotations

"""
fingerprint.py
--------------
Compute a stable "what changed?" fingerprint for BGL3 so the audit pipeline can
skip expensive work when nothing relevant changed.

We intentionally use file metadata (mtime + size) rather than hashing contents
to keep it fast on Windows for large projects.
"""

import json
import os
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple


def _stat_sig(p: Path) -> Optional[Tuple[int, int]]:
    try:
        st = p.stat()
        # mtime_ns, size
        return (int(getattr(st, "st_mtime_ns", int(st.st_mtime * 1e9))), int(st.st_size))
    except Exception:
        return None


def _walk_globs(root: Path, patterns: List[str]) -> List[Path]:
    out: List[Path] = []
    for pat in patterns:
        out.extend(root.glob(pat))
    # de-dupe
    uniq = []
    seen = set()
    for p in out:
        try:
            rp = str(p.resolve())
        except Exception:
            rp = str(p)
        if rp in seen:
            continue
        seen.add(rp)
        if p.is_file():
            uniq.append(p)
    return uniq


@dataclass
class Fingerprint:
    created_at: float
    file_count: int
    sig: Dict[str, Any]


def compute_fingerprint(root: Path) -> Fingerprint:
    """
    Focus on files that meaningfully affect routes/runtime behavior:
    - PHP app + api + views + public JS/CSS
    - agent brain configs and scenario definitions
    - OpenAPI specs
    """
    patterns = [
        "api/**/*.php",
        "app/**/*.php",
        "views/**/*.php",
        "partials/**/*.php",
        "public/js/**/*.js",
        "public/css/**/*.css",
        "docs/openapi*.yaml",
        ".bgl_core/brain/**/*.py",
        ".bgl_core/brain/**/*.yml",
        ".bgl_core/brain/**/*.yaml",
        "storage/settings.json",
    ]
    files = _walk_globs(root, patterns)

    # Aggregate signature: sums + max mtime + per-bucket quick stats
    mtimes = []
    total_size = 0
    missing = 0
    for f in files:
        s = _stat_sig(f)
        if not s:
            missing += 1
            continue
        mtimes.append(s[0])
        total_size += s[1]

    sig = {
        "max_mtime_ns": max(mtimes) if mtimes else 0,
        "sum_size": int(total_size),
        "missing": int(missing),
        "file_count": int(len(files)),
        # A coarse "version" knob to allow schema changes without breaking cache compatibility.
        "schema": 1,
    }
    return Fingerprint(created_at=time.time(), file_count=len(files), sig=sig)


def fingerprint_is_fresh(
    fp_payload: Optional[Dict[str, Any]],
    *,
    max_age_s: float = 600.0,
) -> bool:
    """
    Avoid thrashing on self-modifying repositories (logs, db, etc.) by allowing a
    short freshness window. If the last fingerprint is recent, treat it as "fresh"
    even if mtime moved due to verification artifacts.
    """
    if not isinstance(fp_payload, dict):
        return False
    ts = fp_payload.get("created_at")
    try:
        ts_f = float(ts)
    except Exception:
        return False
    return (time.time() - ts_f) <= float(max_age_s)


def fingerprint_equal(a: Optional[Dict[str, Any]], b: Optional[Dict[str, Any]]) -> bool:
    if not isinstance(a, dict) or not isinstance(b, dict):
        return False
    return a.get("sig") == b.get("sig")


def fingerprint_to_payload(fp: Fingerprint) -> Dict[str, Any]:
    return {"created_at": fp.created_at, "file_count": fp.file_count, "sig": fp.sig}
