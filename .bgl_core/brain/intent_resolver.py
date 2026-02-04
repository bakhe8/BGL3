from typing import Dict, Any
import json
import os
import urllib.request

try:
    from .llm_client import LLMClient  # type: ignore
except Exception:
    from llm_client import LLMClient

def resolve_intent(diagnostic: Dict[str, Any]) -> Dict[str, Any]:
    """
    Smart Intent Resolver.
    Uses LLM to interpret diagnostic data and determine true intent.
    """
    print("[*] SmartIntentResolver: Interpreting system diagnostics...")

    openai_key = os.getenv("OPENAI_KEY") or os.getenv("OPENAI_API_KEY")
    # Local LLM base is optional; when absent and no OpenAI, we fall back to signals/heuristics.
    llm_base_url = os.getenv("LLM_BASE_URL", "")

    try:
        from .brain_types import Intent  # type: ignore
    except ImportError:
        from brain_types import Intent

    # Deterministic hint from Outcome->Signals layer (used for fallback).
    hint = None
    try:
        hint = (diagnostic.get("findings") or {}).get("signals_intent_hint")
        if not isinstance(hint, dict) or not hint.get("intent"):
            hint = None
    except Exception:
        hint = None

    prompt = f"""
    Analyze this system diagnostic and determine the single most critical intent.
    Diagnostic: {json.dumps(diagnostic, indent=2)}
    
    Possible Intents (Strict Enum):
    - "{Intent.STABILIZE.value}": Fix critical errors/failures.
    - "{Intent.EVOLVE.value}": Improve architecture or add rules.
    - "{Intent.UNBLOCK.value}": Resolve permissions or environment issues.
    - "{Intent.OBSERVE.value}": System is healthy, monitor only.
    
    Output JSON:
    {{
        "intent": "string (must match one of the above)",
        "confidence": float (0.0-1.0),
        "reason": "string justification",
        "scope": ["uris or files impacted"]
    }}
    """

    # Simple fallback if no AI is configured
    if not (openai_key or llm_base_url):
        if hint:
            try:
                hint["intent"] = Intent(hint["intent"]).value
            except Exception:
                hint["intent"] = Intent.OBSERVE.value
            hint.setdefault("confidence", 0.7)
            hint.setdefault("reason", "signals fallback (no AI configured)")
            hint.setdefault("scope", [])
            return hint
        return {
            "intent": Intent.OBSERVE.value,
            "confidence": 0.5,
            "reason": "No AI configured for smart resolution.",
        }

    try:
        client = LLMClient()
        data = client.chat_json(prompt, temperature=0.0)

        # Validate Enum + ensure required keys exist
        intent_val = data.get("intent")
        try:
            data["intent"] = Intent(intent_val).value
        except Exception:
            data["intent"] = Intent.OBSERVE.value
        data.setdefault("confidence", 0.7)
        data.setdefault("reason", "local_llm")
        data.setdefault("scope", [])
        return data

    except Exception as e:
        print(f"[*] SmartIntentResolver: Local AI failed ({e}). Using fallback.")
        # Prefer deterministic hint if present (Outcome->Signals layer).
        if hint:
            try:
                hint["intent"] = Intent(hint["intent"]).value
            except Exception:
                hint["intent"] = Intent.OBSERVE.value
            hint.setdefault("confidence", 0.75)
            hint.setdefault("reason", "signals fallback (LLM failed)")
            hint.setdefault("scope", [])
            return hint
        # Fallback to hardcoded logic if LLM fails
        return {
            "intent": Intent.STABILIZE.value
            if diagnostic["findings"].get("failing_routes")
            else Intent.OBSERVE.value,
            "confidence": 0.7,
            "reason": "LLM resolution failed; using heuristic fallback.",
        }
