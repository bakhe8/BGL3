"""
CLI gateway for tools that the local LLM أو أي عميل آخر يمكنه استدعاؤها.
Usage:
    echo '{"tool": "run_checks"}' | python scripts/tool_gateway.py
    echo '{"tool": "logic_bridge", "payload": {...}}' | python scripts/tool_gateway.py
"""
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.append(str(ROOT / ".bgl_core" / "brain"))

from llm_tools import dispatch  # type: ignore


def main():
    raw = sys.stdin.buffer.read()
    req = json.loads(raw.decode("utf-8-sig"))
    resp = dispatch(req)
    print(json.dumps(resp, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
