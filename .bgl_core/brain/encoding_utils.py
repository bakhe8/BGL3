from __future__ import annotations

from pathlib import Path


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
    path.write_text(content, encoding="utf-8")

