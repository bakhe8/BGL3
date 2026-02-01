import json
from pathlib import Path

import sys
sys.path.append(str(Path(__file__).resolve().parents[1] / ".bgl_core" / "brain"))

from llm_tools import dispatch  # type: ignore


def test_tool_schema():
    resp = dispatch({"tool": "tool_schema"})
    assert resp.get("tools"), "tools list should not be empty"


def test_unknown_tool():
    resp = dispatch({"tool": "nope"})
    assert resp.get("status") == "ERROR"


def test_context_pack():
    resp = dispatch({"tool": "context_pack"})
    assert resp.get("status") == "SUCCESS"
    assert "context" in resp


def test_score_response_records():
    text = "safe response"
    resp = dispatch({"tool": "score_response", "payload": {"text": text}})
    assert resp.get("status") == "SUCCESS"
    assert "score" in resp
