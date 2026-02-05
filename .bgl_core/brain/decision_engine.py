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

    prompt = f"""
    Evaluate the risk of this proposed autonomous action.
    Intent: {json.dumps(intent_payload, indent=2)}
    Policy Context: {json.dumps(policy, indent=2)}
    
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
        return client.chat_json(prompt, temperature=0.0)
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
