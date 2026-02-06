import os
import time
import hashlib
from pathlib import Path
import sys


ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / ".bgl_core" / "brain"))

from auto_insights import should_include_insight  # type: ignore


def _hash_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(8192), b""):
            h.update(chunk)
    return h.hexdigest()


def test_auto_insight_ttl_expired(tmp_path: Path, monkeypatch):
    project_root = tmp_path / "proj"
    project_root.mkdir(parents=True, exist_ok=True)
    src = project_root / "app" / "Demo.php"
    src.parent.mkdir(parents=True, exist_ok=True)
    src.write_text("<?php echo 'demo'; ?>", encoding="utf-8")

    src_hash = _hash_file(src)
    insight = project_root / ".bgl_core" / "knowledge" / "auto_insights" / "Demo.php.insight.md"
    insight.parent.mkdir(parents=True, exist_ok=True)
    insight.write_text(
        f"**Path**: `{src.relative_to(project_root)}`\n**Source-Hash**: {src_hash}\n",
        encoding="utf-8",
    )

    # Make the insight old enough to expire
    old_time = time.time() - (10 * 86400)
    os.utime(insight, (old_time, old_time))

    monkeypatch.setenv("BGL_AUTO_INSIGHTS_TTL_DAYS", "1")
    ok, reason = should_include_insight(insight, project_root, allow_legacy=False)
    assert ok is False
    assert reason == "expired"
