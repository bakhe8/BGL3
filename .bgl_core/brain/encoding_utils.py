from __future__ import annotations

from pathlib import Path
import os


def _atomic_write_text(path: Path, content: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp_path = path.with_suffix(f"{path.suffix}.tmp.{os.getpid()}")
    try:
        tmp_path.write_text(content, encoding="utf-8")
        os.replace(tmp_path, path)
    finally:
        try:
            if tmp_path.exists():
                tmp_path.unlink()
        except Exception:
            pass


def read_text_utf8(path: Path) -> str:
    """
    Read UTF-8 text and strip BOM if present.
    """
    return path.read_text(encoding="utf-8-sig")


def write_text_utf8(path: Path, content: str) -> None:
    """
    Write UTF-8 text without BOM.
    """
    # Guard against accidental BOM in content
    if content.startswith("\ufeff"):
        content = content.lstrip("\ufeff")
    _atomic_write_text(path, content)
