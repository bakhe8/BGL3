from typing import Dict, Any
import json
import os
import urllib.request


def decide(intent_payload: Dict[str, Any], policy: Dict[str, Any]) -> Dict[str, Any]:
    """
    Smart Decision Engine.
    Uses LLM to evaluate risk and maturity before authorizing actions.
    """
    print("[*] SmartDecisionEngine: Evaluating risk-benefit ratio...")

    openai_key = os.getenv("OPENAI_KEY") or os.getenv("OPENAI_API_KEY")
    ollama_url = os.getenv("LLM_BASE_URL", "http://localhost:11434/v1/chat/completions")

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

    if not (openai_key or "localhost" in ollama_url):
        # Fallback to conservative decision
        return {
            "decision": "propose_fix" if intent != "observe" else "observe",
            "risk_level": "medium",
            "requires_human": True,
            "justification": [
                "No AI available for risk assessment; defaulting to manual."
            ],
            "maturity": "experimental",
        }

    try:
        payload = {
            "model": os.getenv("LLM_MODEL", "llama3.1"),
            "messages": [{"role": "user", "content": prompt}],
            "response_format": {"type": "json_object"},
            "temperature": 0.0,
        }
        req = urllib.request.Request(
            ollama_url,
            json.dumps(payload).encode(),
            {"Content-Type": "application/json"},
        )
        with urllib.request.urlopen(req, timeout=10) as response:
            res = json.loads(response.read().decode())
            return json.loads(res["choices"][0]["message"]["content"])
    except Exception:
        return {
            "decision": "block",
            "risk_level": "high",
            "requires_human": True,
            "justification": ["Decision logic exception; blocking for safety."],
            "maturity": "experimental",
        }
