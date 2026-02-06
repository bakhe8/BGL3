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

    # Deterministic hints (ReasoningEngine + Hypothesis + Purpose + Outcome->Signals + UI Semantic)
    hint = None
    reasoning_hint = None
    hypothesis_hint = None
    purpose_hint = None
    signals_hint = None
    ui_semantic_hint = None
    try:
        reasoning_hint = (diagnostic.get("findings") or {}).get("reasoning_hint")
        if not isinstance(reasoning_hint, dict) or not reasoning_hint.get("intent"):
            reasoning_hint = None
    except Exception:
        reasoning_hint = None
    try:
        signals_hint = (diagnostic.get("findings") or {}).get("signals_intent_hint")
        if not isinstance(signals_hint, dict) or not signals_hint.get("intent"):
            signals_hint = None
    except Exception:
        signals_hint = None
    try:
        findings = (diagnostic.get("findings") or {})
        ui_delta = findings.get("ui_semantic_delta") or {}
        ui_sem = findings.get("ui_semantic") or {}
        self_policy = findings.get("self_policy") or {}
        thresholds = (self_policy.get("semantic_thresholds") or {}) if isinstance(self_policy, dict) else {}
        change_min = int(thresholds.get("propose_fix_change", 6) or 6)
        if ui_delta.get("changed"):
            change_count = int(ui_delta.get("change_count") or 0)
            if change_count >= max(4, change_min):
                keywords = (ui_sem.get("summary") or {}).get("text_keywords", [])
                if not isinstance(keywords, list):
                    keywords = []
                ui_semantic_hint = {
                    "intent": "evolve",
                    "confidence": 0.6,
                    "reason": f"ui_semantic_change:{change_count}",
                    "scope": [str(k) for k in keywords[:8]],
                }
    except Exception:
        ui_semantic_hint = None
    try:
        purpose = (diagnostic.get("findings") or {}).get("purpose") or diagnostic.get("purpose") or ""
        purpose = str(purpose or "").strip()
        if purpose:
            p = purpose.lower()
            intent_guess = ""
            if any(k in p for k in ["fix", "stabiliz", "bug", "error", "repair", "patch", "fault", "crash", "fail", "failures", "فشل", "عطل", "خلل", "إصلاح"]):
                intent_guess = "stabilize"
            elif any(k in p for k in ["unblock", "permission", "approval", "blocked", "unlock", "صلاح", "موافقة", "محجوب", "تعطيل"]):
                intent_guess = "unblock"
            elif any(k in p for k in ["evolve", "improve", "expand", "architecture", "rule", "policy", "تعلم", "تطوير", "تحسين", "توسيع", "سياسة"]):
                intent_guess = "evolve"
            elif any(k in p for k in ["observe", "monitor", "watch", "record", "مراقبة", "رصد", "متابعة"]):
                intent_guess = "observe"
            if intent_guess:
                purpose_hint = {
                    "intent": intent_guess,
                    "confidence": 0.6,
                    "reason": f"purpose:{purpose[:120]}",
                    "scope": [],
                }
    except Exception:
        purpose_hint = None
    try:
        hypotheses = (diagnostic.get("findings") or {}).get("hypotheses") or []
        h_list = [h for h in hypotheses if isinstance(h, dict)]
        if h_list:
            def _weight(h: Dict[str, Any]) -> float:
                try:
                    pri = float(h.get("priority") or 0)
                except Exception:
                    pri = 0.0
                try:
                    conf = float(h.get("confidence") or 0)
                except Exception:
                    conf = 0.0
                return pri * 0.7 + conf * 0.3

            h_list.sort(key=_weight, reverse=True)
            h = h_list[0]
            rel_intent = str(h.get("related_intent") or "").strip().lower()
            tags = h.get("tags") or []
            if isinstance(tags, str):
                tags = [t.strip() for t in tags.split(",") if t.strip()]
            if not rel_intent:
                for t in tags:
                    tl = str(t).lower()
                    if "unblock" in tl:
                        rel_intent = "unblock"
                        break
                    if "stabilize" in tl:
                        rel_intent = "stabilize"
                        break
                    if "evolve" in tl:
                        rel_intent = "evolve"
                        break
            if rel_intent:
                title = str(h.get("title") or "").strip()
                stmt = str(h.get("statement") or "").strip()
                reason = "hypothesis"
                if title:
                    reason += f":{title}"
                if stmt:
                    reason += f":{stmt[:120]}"
                hypothesis_hint = {
                    "intent": rel_intent,
                    "confidence": max(0.55, min(0.85, float(h.get("confidence") or 0.65))),
                    "reason": reason,
                    "scope": (h.get("evidence") or {}).get("routes", []) if isinstance(h.get("evidence"), dict) else [],
                }
    except Exception:
        hypothesis_hint = None
    # Weighted selection when LLM is not available
    try:
        self_policy = (diagnostic.get("findings") or {}).get("self_policy") or {}
        bias = (self_policy.get("intent_bias") or {}) if isinstance(self_policy, dict) else {}
    except Exception:
        bias = {}

    candidates = []
    def _score(h):
        if not h:
            return 0.0
        try:
            base = float(h.get("confidence", 0.5) or 0.5)
        except Exception:
            base = 0.5
        key = h.get("_hint_key") or ""
        try:
            w = float(bias.get(key, 1.0))
        except Exception:
            w = 1.0
        return base * w

    if reasoning_hint:
        rh = dict(reasoning_hint); rh["_hint_key"] = "reasoning"; candidates.append(rh)
    if hypothesis_hint:
        hh = dict(hypothesis_hint); hh["_hint_key"] = "hypothesis"; candidates.append(hh)
    if purpose_hint:
        ph = dict(purpose_hint); ph["_hint_key"] = "purpose"; candidates.append(ph)
    if signals_hint:
        sh = dict(signals_hint); sh["_hint_key"] = "signals"; candidates.append(sh)
    if ui_semantic_hint:
        uh = dict(ui_semantic_hint); uh["_hint_key"] = "ui_semantic"; candidates.append(uh)

    if candidates:
        candidates.sort(key=_score, reverse=True)
        hint = dict(candidates[0])
        hint.pop("_hint_key", None)

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
