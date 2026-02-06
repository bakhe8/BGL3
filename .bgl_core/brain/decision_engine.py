from typing import Dict, Any
import json

try:
    from .llm_client import LLMClient  # type: ignore
except Exception:
    from llm_client import LLMClient


def decide(intent_payload: Dict[str, Any], policy: Dict[str, Any]) -> Dict[str, Any]:
    """
    Smart Decision Engine.
    Uses LLM to evaluate risk and maturity before authorizing actions.
    """
    print("[*] SmartDecisionEngine: Evaluating risk-benefit ratio...")

    intent = intent_payload.get("intent", "observe")
    confidence = float(intent_payload.get("confidence", 0))
    semantic_hint = _semantic_decision_hint(intent_payload)

    prompt = f"""
    Evaluate the risk of this proposed autonomous action.
    Intent: {json.dumps(intent_payload, indent=2)}
    Policy Context: {json.dumps(policy, indent=2)}
    UI Semantic Hint: {json.dumps(semantic_hint, indent=2)}
    
    Output JSON:
    {{
        "decision": "auto_fix" | "propose_fix" | "block" | "observe",
        "risk_level": "low" | "medium" | "high",
        "requires_human": bool,
        "justification": ["reasons"],
        "maturity": "experimental" | "stable" | "enforced"
    }}
    """

    # 1. Try local LLM first (with timeout protection)
    try:
        client = LLMClient()
        payload = client.chat_json(prompt, temperature=0.0)
        return _apply_semantic_override(payload, intent_payload)
    except (TimeoutError, Exception) as e:
        print(
            f"[!] SmartDecisionEngine: LLM unavailable ({type(e).__name__}). Using deterministic fallback."
        )
        return _deterministic_decision(intent, confidence, intent_payload)


def _deterministic_decision(intent: str, confidence: float, payload: Dict) -> Dict:
    """
    Rule-based decision when LLM is unavailable.
    Conservative approach: require human approval for uncertain tasks.
    """
    semantic_hint = _semantic_decision_hint(payload)
    if (
        semantic_hint.get("changed")
        and intent in ("evolve", "observe")
        and semantic_hint.get("change_count", 0) >= 6
    ):
        change_count = int(semantic_hint.get("change_count") or 0)
        risk_level = "medium" if change_count >= 12 else "low"
        justification = [
            f"UI semantic change detected (count={change_count})",
            "Proposing review due to potential workflow/content drift",
        ]
        keywords = semantic_hint.get("keywords") or []
        if keywords:
            justification.append(f"keywords: {', '.join([str(k) for k in keywords[:6]])}")
        return {
            "decision": "propose_fix",
            "risk_level": risk_level,
            "requires_human": True,
            "justification": justification,
            "maturity": "experimental",
        }

    # Safe operations (observe-only or very low confidence)
    if intent == "observe" or confidence < 0.3:
        return {
            "decision": "observe",
            "risk_level": "low",
            "requires_human": False,
            "justification": ["Low-risk observation mode"],
            "maturity": "stable",
        }

    # Medium confidence: propose but don't execute
    if confidence < 0.7:
        return {
            "decision": "propose_fix",
            "risk_level": "medium",
            "requires_human": True,
            "justification": [
                f"Confidence {confidence:.2f} below auto-fix threshold (0.7)",
                "Human review required for safety",
            ],
            "maturity": "experimental",
        }

    # High confidence but no LLM: still be conservative
    return {
        "decision": "propose_fix",  # Don't auto-fix without LLM validation
        "risk_level": "low",
        "requires_human": True,
        "justification": [
            f"High confidence ({confidence:.2f}) but LLM unavailable",
            "Requesting human approval as safety measure",
        ],
        "maturity": "stable",
    }


def _semantic_decision_hint(intent_payload: Dict[str, Any]) -> Dict[str, Any]:
    delta = intent_payload.get("ui_semantic_delta") or {}
    summary = (intent_payload.get("ui_semantic") or {}).get("summary") or {}
    self_policy = intent_payload.get("self_policy") or {}
    thresholds = (self_policy.get("semantic_thresholds") or {}) if isinstance(self_policy, dict) else {}
    try:
        changed = bool(delta.get("changed"))
    except Exception:
        changed = False
    try:
        change_count = int(delta.get("change_count") or 0)
    except Exception:
        change_count = 0
    propose_thr = 6
    auto_thr = 14
    try:
        propose_thr = int(thresholds.get("propose_fix_change", propose_thr) or propose_thr)
        auto_thr = int(thresholds.get("auto_fix_change", auto_thr) or auto_thr)
    except Exception:
        pass
    keywords = summary.get("text_keywords") if isinstance(summary, dict) else []
    if not isinstance(keywords, list):
        keywords = []
    return {
        "changed": changed,
        "change_count": change_count,
        "keywords": keywords[:10],
        "propose_fix_change": propose_thr,
        "auto_fix_change": auto_thr,
    }


def _apply_semantic_override(payload: Dict[str, Any], intent_payload: Dict[str, Any]) -> Dict[str, Any]:
    try:
        semantic_hint = _semantic_decision_hint(intent_payload)
        if (
            semantic_hint.get("changed")
            and semantic_hint.get("change_count", 0) >= int(semantic_hint.get("propose_fix_change") or 6)
            and str(payload.get("decision", "")).lower() == "observe"
        ):
            payload["decision"] = "propose_fix"
            payload.setdefault(
                "risk_level",
                "medium"
                if int(semantic_hint.get("change_count") or 0)
                >= int(semantic_hint.get("auto_fix_change") or 14)
                else "low",
            )
            payload["requires_human"] = True
            justification = payload.get("justification") or []
            if not isinstance(justification, list):
                justification = [str(justification)]
            justification.append(
                f"UI semantic change detected (count={semantic_hint.get('change_count')})"
            )
            payload["justification"] = justification
    except Exception:
        pass
    return payload
