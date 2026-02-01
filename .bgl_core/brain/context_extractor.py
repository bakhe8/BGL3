"""
Lightweight context extractor for LLM prompts.
- Gathers small code snippets around symbols/paths.
- Optionally uses call graph + AST indexes later; current version file/line based.
"""
from pathlib import Path
from typing import List, Dict

ROOT = Path(__file__).resolve().parents[2]


def _read_snippet(path: Path, max_lines: int = 60) -> str:
    if not path.exists():
        return ""
    lines = path.read_text(encoding="utf-8", errors="ignore").splitlines()
    return "\n".join(lines[:max_lines])


def extract(targets: List[str], max_files: int = 8) -> List[Dict[str, str]]:
    """
    targets: list of relative paths or symbols.
    Returns list of {path, snippet}.
    """
    results: List[Dict[str, str]] = []
    seen = set()
    for t in targets:
        if len(results) >= max_files:
            break
        p = (ROOT / t).resolve() if not t.startswith("/") else Path(t)
        if p.exists() and p.is_file():
            key = str(p)
            if key in seen:
                continue
            seen.add(key)
            results.append({"path": str(p.relative_to(ROOT)), "snippet": _read_snippet(p)})
            continue
        # fallback: search by filename
        for candidate in ROOT.rglob(Path(t).name):
            if candidate.is_file() and len(results) < max_files:
                key = str(candidate)
                if key in seen:
                    continue
                seen.add(key)
                results.append({"path": str(candidate.relative_to(ROOT)), "snippet": _read_snippet(candidate)})
    return results


if __name__ == "__main__":
    import json, sys
    req = sys.argv[1:]
    print(json.dumps(extract(req), ensure_ascii=False, indent=2))
