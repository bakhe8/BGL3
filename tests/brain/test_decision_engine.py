import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / ".bgl_core" / "brain"))

from decision_engine import _deterministic_decision, _apply_semantic_override  # type: ignore


def test_deterministic_decision_low_confidence_observe():
    payload = {"ui_semantic_delta": {"changed": False, "change_count": 0}}
    res = _deterministic_decision("evolve", 0.2, payload)
    assert res["decision"] == "observe"
    assert res["requires_human"] is False


def test_deterministic_decision_changes_propose_fix():
    payload = {"ui_semantic_delta": {"changed": True, "change_count": 8}}
    res = _deterministic_decision("evolve", 0.9, payload)
    assert res["decision"] == "propose_fix"
    assert res["requires_human"] is True


def test_semantic_override_promotes_observe_to_propose():
    intent_payload = {
        "ui_semantic_delta": {"changed": True, "change_count": 7},
        "ui_semantic": {"summary": {"text_keywords": ["change"]}},
        "self_policy": {"semantic_thresholds": {"propose_fix_change": 6, "auto_fix_change": 14}},
    }
    res = _apply_semantic_override({"decision": "observe"}, intent_payload)
    assert res["decision"] == "propose_fix"
    assert res["requires_human"] is True
