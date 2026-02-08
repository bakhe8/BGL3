import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / ".bgl_core" / "brain"))

from decision_engine import (  # type: ignore
    _deterministic_decision,
    _apply_semantic_override,
    _apply_policy_overrides,
    _attach_explanation,
)


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


def test_policy_override_blocks_auto_fix_in_assisted_mode():
    payload = {
        "decision": "auto_fix",
        "risk_level": "low",
        "requires_human": False,
        "justification": [],
    }
    intent_payload = {"confidence": 0.9, "scope": []}
    policy = {"mode": "assisted", "auto_fix": {"min_confidence": 0.6, "max_risk": "medium"}}
    res = _apply_policy_overrides(payload, intent_payload, policy)
    assert res["decision"] == "propose_fix"
    assert res["requires_human"] is True


def test_policy_override_risk_threshold():
    payload = {
        "decision": "auto_fix",
        "risk_level": "high",
        "requires_human": False,
        "justification": [],
    }
    intent_payload = {"confidence": 0.95, "scope": []}
    policy = {"mode": "auto", "auto_fix": {"min_confidence": 0.6, "max_risk": "medium"}}
    res = _apply_policy_overrides(payload, intent_payload, policy)
    assert res["decision"] == "propose_fix"
    assert res["requires_human"] is True


def test_decision_explanation_attached():
    payload = {
        "decision": "propose_fix",
        "risk_level": "medium",
        "requires_human": True,
        "justification": ["needs review"],
    }
    intent_payload = {"intent": "evolve", "confidence": 0.55}
    res = _attach_explanation(payload, intent_payload, {"mode": "assisted"})
    assert "explanation" in res
    assert res["explanation"]["expected_outcomes"]


def test_domain_rules_force_human():
    payload = {
        "decision": "auto_fix",
        "risk_level": "low",
        "requires_human": False,
        "justification": [],
    }
    intent_payload = {
        "confidence": 0.9,
        "domain_rule_violations": [{"rule_id": "R001", "severity": "critical"}],
    }
    policy = {"mode": "auto", "auto_fix": {"min_confidence": 0.6, "max_risk": "medium"}}
    res = _apply_policy_overrides(payload, intent_payload, policy)
    assert res["decision"] == "propose_fix"
    assert res["requires_human"] is True
    assert res.get("force_requires_human") is True
