from typing import Dict, Any
import json
import os
import urllib.request


def resolve_intent(diagnostic: Dict[str, Any]) -> Dict[str, Any]:
    """
    Smart Intent Resolver.
    Uses LLM to interpret diagnostic data and determine true intent.
    """
    print("[*] SmartIntentResolver: Interpreting system diagnostics...")

    openai_key = os.getenv("OPENAI_KEY") or os.getenv("OPENAI_API_KEY")
    # Use 127.0.0.1 to avoid Windows IPv6/localhost resolution issues
    ollama_url = os.getenv("LLM_BASE_URL", "http://127.0.0.1:11434/v1/chat/completions")

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
    if not (openai_key or "localhost" in ollama_url or "127.0.0.1" in ollama_url):
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
        # Heuristic for rapid local testing
        payload = {
            "model": os.getenv("LLM_MODEL", "llama3.1:latest"),
            "messages": [{"role": "user", "content": prompt}],
            "response_format": {"type": "json_object"},
            "temperature": 0.0,
        }
        req = urllib.request.Request(
            ollama_url,
            json.dumps(payload).encode(),
            {"Content-Type": "application/json"},
        )
        # Allow cold-start load time for local models
        with urllib.request.urlopen(req, timeout=30) as response:
            res = json.loads(response.read().decode())
            data = json.loads(res["choices"][0]["message"]["content"])

            # Validate Enum
            try:
                data["intent"] = Intent(data["intent"]).value
            except ValueError:
                data["intent"] = Intent.OBSERVE.value  # Fallback

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
