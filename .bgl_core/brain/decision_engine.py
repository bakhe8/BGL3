from typing import Dict, Any, List
import json
import os

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
    runtime_hint = _runtime_decision_hint(intent_payload)
    code_intent_hint = _code_intent_decision_hint(intent_payload)
    code_temporal_hint = _code_temporal_decision_hint(intent_payload)

    prompt = f"""
    Evaluate the risk of this proposed autonomous action.
    Intent: {json.dumps(intent_payload, indent=2)}
    Policy Context: {json.dumps(policy, indent=2)}
    UI Semantic Hint: {json.dumps(semantic_hint, indent=2)}
    Runtime Evidence Hint: {json.dumps(runtime_hint, indent=2)}
    Code Intent Hint: {json.dumps(code_intent_hint, indent=2)}
    Code Temporal Hint: {json.dumps(code_temporal_hint, indent=2)}
    
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
        payload = _apply_semantic_override(payload, intent_payload)
        payload = _apply_runtime_override(payload, runtime_hint)
        payload = _apply_code_intent_override(payload, code_intent_hint)
        payload = _apply_code_temporal_override(payload, code_temporal_hint)
        payload = _apply_policy_overrides(payload, intent_payload, policy)
        return _attach_explanation(
            payload, intent_payload, policy, runtime_hint, code_intent_hint, code_temporal_hint
        )
    except (TimeoutError, Exception) as e:
        print(
            f"[!] SmartDecisionEngine: LLM unavailable ({type(e).__name__}). Using deterministic fallback."
        )
        payload = _deterministic_decision(intent, confidence, intent_payload)
        payload = _apply_runtime_override(payload, runtime_hint)
        payload = _apply_code_intent_override(payload, code_intent_hint)
        payload = _apply_code_temporal_override(payload, code_temporal_hint)
        payload = _apply_policy_overrides(payload, intent_payload, policy)
        return _attach_explanation(
            payload, intent_payload, policy, runtime_hint, code_intent_hint, code_temporal_hint
        )


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


def _runtime_decision_hint(intent_payload: Dict[str, Any]) -> Dict[str, Any]:
    hint = intent_payload.get("runtime_hint")
    if isinstance(hint, dict) and hint:
        return hint
    try:
        meta = intent_payload.get("metadata") or {}
        hint = meta.get("runtime_hint")
        if isinstance(hint, dict) and hint:
            return hint
    except Exception:
        pass
    return {"has_evidence": False, "event_count": 0, "error_count": 0}


def _code_intent_decision_hint(intent_payload: Dict[str, Any]) -> Dict[str, Any]:
    sig = intent_payload.get("code_intent_signals") or {}
    if not isinstance(sig, dict) or not sig:
        return {}
    suggested = str(sig.get("suggested_intent") or "").lower()
    total = 0
    try:
        total = int(sig.get("total") or 0)
    except Exception:
        total = 0
    counts = sig.get("intent_counts") or {}
    try:
        count = int(counts.get(suggested) or 0)
    except Exception:
        count = 0
    if not suggested:
        return {}
    ratio = (count / max(1, total)) if total else 0.0
    confidence = min(0.8, 0.45 + min(0.35, ratio))
    return {
        "intent": suggested,
        "count": count,
        "total": total,
        "confidence": round(confidence, 3),
        "top_tokens": (sig.get("top_tokens") or [])[:8],
    }


def _code_temporal_decision_hint(intent_payload: Dict[str, Any]) -> Dict[str, Any]:
    sig = intent_payload.get("code_temporal_signals") or {}
    if not isinstance(sig, dict) or not sig:
        return {}
    counts = sig.get("counts") or {}
    try:
        total = int(sig.get("total") or 0)
    except Exception:
        total = 0
    try:
        stateful = int(counts.get("stateful") or 0)
    except Exception:
        stateful = 0
    try:
        startup_exec = int(counts.get("startup_exec") or 0)
    except Exception:
        startup_exec = 0
    try:
        accum = int(counts.get("accumulates") or 0)
    except Exception:
        accum = 0
    ratio = (stateful / max(1, total)) if total else 0.0
    return {
        "stateful_ratio": round(ratio, 3),
        "stateful": stateful,
        "startup_exec": startup_exec,
        "accumulates": accum,
        "top_markers": (sig.get("top_markers") or [])[:8],
    }


def _apply_code_intent_override(
    payload: Dict[str, Any], code_hint: Dict[str, Any]
) -> Dict[str, Any]:
    if not isinstance(payload, dict) or not isinstance(code_hint, dict) or not code_hint:
        return payload
    decision = str(payload.get("decision", "observe") or "observe").lower()
    risk_level = str(payload.get("risk_level", "low") or "low").lower()
    requires_human = bool(payload.get("requires_human", False))

    force_no_human = False
    try:
        force_no_human = bool(policy.get("force_no_human_approvals", False))
    except Exception:
        force_no_human = False
    if not force_no_human:
        try:
            env_force = os.getenv("BGL_FORCE_NO_HUMAN_APPROVALS")
            if env_force is not None:
                force_no_human = str(env_force).strip().lower() in ("1", "true", "yes", "on")
        except Exception:
            force_no_human = False
    intent = str(code_hint.get("intent") or "").lower()
    confidence = float(code_hint.get("confidence") or 0.0)
    just = _ensure_justification(payload)

    if intent in ("stabilize", "unblock") and decision == "observe" and confidence >= 0.5:
        decision = "propose_fix"
        requires_human = True
        if risk_level == "low":
            risk_level = "medium"
        just.append(f"code_intent_hint:{intent} (conf={confidence})")
    elif intent in ("evolve",) and decision == "observe" and confidence >= 0.6:
        just.append(f"code_intent_hint:{intent} (conf={confidence})")

    if force_no_human:
        requires_human = False
        policy_notes.append("policy:force_no_human_approvals")

    payload["decision"] = decision
    payload["risk_level"] = risk_level
    payload["requires_human"] = requires_human
    payload["justification"] = just
    payload["code_intent_hint"] = code_hint
    return payload


def _apply_code_temporal_override(
    payload: Dict[str, Any], temporal_hint: Dict[str, Any]
) -> Dict[str, Any]:
    if not isinstance(payload, dict) or not isinstance(temporal_hint, dict) or not temporal_hint:
        return payload
    decision = str(payload.get("decision", "observe") or "observe").lower()
    risk_level = str(payload.get("risk_level", "low") or "low").lower()
    requires_human = bool(payload.get("requires_human", False))
    just = _ensure_justification(payload)

    ratio = float(temporal_hint.get("stateful_ratio") or 0.0)
    startup_exec = int(temporal_hint.get("startup_exec") or 0)
    accum = int(temporal_hint.get("accumulates") or 0)
    if decision == "auto_fix" and (ratio >= 0.25 or accum > 0 or startup_exec > 0):
        decision = "propose_fix"
        requires_human = True
        if risk_level == "low":
            risk_level = "medium"
        just.append(
            f"code_temporal_hint: stateful_ratio={ratio}, startup_exec={startup_exec}, accum={accum}"
        )
    elif decision == "propose_fix" and (ratio >= 0.4 or startup_exec > 3):
        if risk_level == "low":
            risk_level = "medium"
        just.append(
            f"code_temporal_hint: elevated statefulness (ratio={ratio})"
        )

    payload["decision"] = decision
    payload["risk_level"] = risk_level
    payload["requires_human"] = requires_human
    payload["justification"] = just
    payload["code_temporal_hint"] = temporal_hint
    return payload


def _apply_runtime_override(payload: Dict[str, Any], runtime_hint: Dict[str, Any]) -> Dict[str, Any]:
    if not isinstance(payload, dict):
        return payload
    if not isinstance(runtime_hint, dict):
        return payload
    has_evidence = bool(runtime_hint.get("has_evidence"))
    if not has_evidence:
        just = _ensure_justification(payload)
        just.append("runtime_hint: no evidence for scope/route")
        payload["justification"] = just
        return payload

    try:
        error_rate = float(runtime_hint.get("error_rate") or 0.0)
    except Exception:
        error_rate = 0.0
    last_error = str(runtime_hint.get("last_error") or "").strip()
    try:
        event_count = int(runtime_hint.get("event_count") or 0)
    except Exception:
        event_count = 0
    try:
        avg_latency = float(runtime_hint.get("avg_latency_ms") or 0.0)
    except Exception:
        avg_latency = 0.0

    risk_level = str(payload.get("risk_level", "low") or "low").lower()
    decision = str(payload.get("decision", "observe") or "observe").lower()
    requires_human = bool(payload.get("requires_human", False))
    just = _ensure_justification(payload)

    suspects = []
    try:
        suspects = runtime_hint.get("suspects") or []
    except Exception:
        suspects = []
    if event_count >= 3 and (error_rate >= 0.3 or last_error or suspects):
        # Escalate: unstable runtime evidence.
        if decision == "auto_fix":
            decision = "propose_fix"
            requires_human = True
        risk_level = "high"
        just.append(
            f"runtime_hint: elevated errors (rate={error_rate}, last_error={last_error[:120]})"
        )
        if suspects:
            just.append(f"runtime_hint: suspect_deps={','.join([str(s) for s in suspects[:4]])}")
    elif event_count >= 5 and avg_latency >= 2000:
        if risk_level == "low":
            risk_level = "medium"
        just.append(f"runtime_hint: high latency avg={avg_latency}ms")

    payload["decision"] = decision
    payload["risk_level"] = risk_level
    payload["requires_human"] = requires_human
    payload["justification"] = just
    payload["runtime_hint"] = runtime_hint
    return payload


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


def _ensure_justification(payload: Dict[str, Any]) -> List[str]:
    just = payload.get("justification") or []
    if isinstance(just, list):
        return [str(j) for j in just if j is not None]
    return [str(just)]


def _risk_rank(level: str) -> int:
    order = {"low": 0, "medium": 1, "high": 2}
    return order.get(str(level or "").lower(), 1)


def _extract_action_kind(intent_payload: Dict[str, Any]) -> str:
    kind = intent_payload.get("action_kind")
    if kind:
        return str(kind).lower()
    meta = intent_payload.get("metadata") or {}
    try:
        kind = meta.get("action_kind") or meta.get("kind")
    except Exception:
        kind = None
    return str(kind or "").lower()


def _scope_requires_human(scope: Any) -> bool:
    if not scope:
        return False
    protected_prefixes = (
        "app/",
        "api/",
        "templates/",
        "views/",
        "partials/",
        "public/",
        "storage/database/",
    )
    try:
        items = scope if isinstance(scope, list) else [scope]
    except Exception:
        items = []
    for item in items:
        try:
            raw = str(item)
        except Exception:
            return True
        path = raw.replace("\\", "/").lstrip("./")
        if "://" in path or path.startswith("http"):
            return True
        if path.startswith(protected_prefixes):
            return True
    return False


def _extract_domain_rule_info(intent_payload: Dict[str, Any]) -> Dict[str, Any]:
    """
    Extract domain-rule violations from intent payload or env snapshot.
    Returns a small summary for deterministic gating.
    """
    violations = []
    summary = {}
    try:
        violations = intent_payload.get("domain_rule_violations") or []
    except Exception:
        violations = []
    try:
        summary = intent_payload.get("domain_rule_summary") or {}
    except Exception:
        summary = {}
    # Fall back to env snapshot summary if present
    try:
        env_snapshot = intent_payload.get("env_snapshot") or {}
        env_rules = env_snapshot.get("domain_rules") or {}
        if not summary and isinstance(env_rules, dict):
            summary = env_rules.get("summary") or {}
        if not violations and isinstance(env_rules, dict):
            # Only a count may be available from env snapshot
            count = int(env_rules.get("violations_count") or 0)
            if count > 0:
                violations = [{"rule_id": "domain_rules", "severity": "critical"}] * count
    except Exception:
        pass

    # Compute counts
    count = len(violations) if isinstance(violations, list) else 0
    critical = 0
    rule_ids: List[str] = []
    if isinstance(violations, list):
        for v in violations:
            if not isinstance(v, dict):
                continue
            rid = v.get("rule_id")
            if rid:
                rule_ids.append(str(rid))
            sev = str(v.get("severity", "")).lower()
            if sev == "critical":
                critical += 1
    # Merge with summary when available
    if isinstance(summary, dict):
        try:
            critical = max(critical, int(summary.get("critical_count") or 0))
        except Exception:
            pass
    return {
        "count": count,
        "critical_count": critical,
        "rule_ids": sorted(list(set(rule_ids)))[:10],
    }


def _apply_policy_overrides(
    payload: Dict[str, Any], intent_payload: Dict[str, Any], policy: Dict[str, Any]
) -> Dict[str, Any]:
    """
    Deterministic policy layer that constrains high-risk decisions
    regardless of LLM output.
    """
    if not isinstance(payload, dict):
        payload = {}
    policy = policy or {}

    decision = str(payload.get("decision", "observe") or "observe").lower()
    risk_level = str(payload.get("risk_level", "low") or "low").lower()
    requires_human = bool(payload.get("requires_human", False))

    try:
        confidence = float(intent_payload.get("confidence", 0.0) or 0.0)
    except Exception:
        confidence = 0.0

    policy_notes: List[str] = []

    mode = str(policy.get("mode", "assisted") or "assisted").lower()
    if decision == "auto_fix" and mode not in ("auto", "autonomous"):
        decision = "propose_fix"
        requires_human = True
        policy_notes.append(f"policy:decision_mode={mode}")

    auto_cfg = policy.get("auto_fix") or {}
    try:
        min_conf = float(auto_cfg.get("min_confidence", 0.7) or 0.7)
    except Exception:
        min_conf = 0.7
    max_risk = str(auto_cfg.get("max_risk", "medium") or "medium").lower()

    if decision == "auto_fix" and confidence < min_conf:
        decision = "propose_fix"
        requires_human = True
        policy_notes.append(f"policy:auto_fix_min_conf={min_conf}")

    if decision == "auto_fix" and _risk_rank(risk_level) > _risk_rank(max_risk):
        decision = "propose_fix"
        requires_human = True
        policy_notes.append(f"policy:auto_fix_max_risk={max_risk}")

    # Action-kind and scope hard constraints
    action_kind = _extract_action_kind(intent_payload)
    scope = intent_payload.get("scope") or []
    if decision == "auto_fix" and action_kind == "write_prod":
        decision = "propose_fix"
        requires_human = True
        policy_notes.append("policy:write_prod_requires_review")
    if decision == "auto_fix" and _scope_requires_human(scope):
        decision = "propose_fix"
        requires_human = True
        policy_notes.append("policy:scope_requires_review")

    # Domain rule gating (deterministic) - skip for explicit deterministic_gate tasks.
    try:
        meta = intent_payload.get("metadata") or {}
        if bool(meta.get("deterministic_gate", False)):
            meta = None
    except Exception:
        meta = None
    domain_info = _extract_domain_rule_info(intent_payload)
    if domain_info.get("critical_count", 0) > 0 and meta is not None:
        # Escalate risk and require human for any execution-oriented path.
        if decision == "auto_fix":
            decision = "propose_fix"
        requires_human = True
        risk_level = "high"
        policy_notes.append(
            f"domain_rules:critical violations ({domain_info.get('critical_count')}), rules={domain_info.get('rule_ids', [])}"
        )
        payload["force_requires_human"] = True

    # Per-policy override (metadata policy_key)
    try:
        meta = intent_payload.get("metadata") or {}
        policy_key = meta.get("policy_key")
    except Exception:
        policy_key = None
    if policy_key and isinstance(policy.get(str(policy_key)), dict):
        block = policy.get(str(policy_key)) or {}
        if "requires_human" in block:
            requires_human = bool(block.get("requires_human", True))
            policy_notes.append(
                f"policy_key:{policy_key}:requires_human={requires_human}"
            )

    payload["decision"] = decision
    payload["risk_level"] = risk_level
    payload["requires_human"] = requires_human

    if policy_notes:
        just = _ensure_justification(payload)
        for note in policy_notes:
            just.append(note)
        payload["justification"] = just
        payload["policy_constraints"] = policy_notes

    return payload


def _attach_explanation(
    payload: Dict[str, Any],
    intent_payload: Dict[str, Any],
    policy: Dict[str, Any],
    runtime_hint: Dict[str, Any],
    code_intent_hint: Dict[str, Any],
    code_temporal_hint: Dict[str, Any],
) -> Dict[str, Any]:
    if not isinstance(payload, dict):
        return payload

    decision = str(payload.get("decision", "observe") or "observe").lower()
    risk_level = str(payload.get("risk_level", "low") or "low").lower()
    requires_human = bool(payload.get("requires_human", False))
    reasons = _ensure_justification(payload)
    policy = policy or {}

    try:
        confidence = float(intent_payload.get("confidence", 0.0) or 0.0)
    except Exception:
        confidence = 0.0

    auto_cfg = policy.get("auto_fix") or {}
    try:
        min_conf = float(auto_cfg.get("min_confidence", 0.7) or 0.7)
    except Exception:
        min_conf = 0.7
    max_risk = str(auto_cfg.get("max_risk", "medium") or "medium").lower()
    mode = str(policy.get("mode", "assisted") or "assisted").lower()

    alternatives: List[str] = []
    expected: List[str] = []

    if decision == "auto_fix":
        alternatives = [
            "propose_fix (human review before execution)",
            "observe (defer change if signal confidence drops)",
        ]
        expected = [
            "execute changes automatically within allowed scope",
            "record outcome for audit and learning",
        ]
    elif decision == "propose_fix":
        alternatives = [
            f"auto_fix if confidence>={min_conf} and risk<={max_risk}",
            "observe if no actionable signals remain",
        ]
        expected = [
            "generate a patch plan or proposal for review",
            "await approval before any write execution",
        ]
    elif decision == "block":
        alternatives = [
            "propose_fix with explicit approval",
            "observe and gather more evidence",
        ]
        expected = [
            "block execution for safety",
            "log decision for traceability",
        ]
    else:  # observe
        alternatives = [
            "propose_fix if confidence increases or new signals appear",
            "auto_fix if policy allows and risk is low",
        ]
        expected = [
            "no changes executed",
            "monitor and collect more signals",
        ]

    payload["explanation"] = {
        "reasons": reasons,
        "alternatives": alternatives,
        "expected_outcomes": expected,
        "context": {
            "intent": intent_payload.get("intent"),
            "confidence": round(confidence, 3),
            "risk_level": risk_level,
            "requires_human": requires_human,
            "decision_mode": mode,
            "auto_fix_min_confidence": min_conf,
            "auto_fix_max_risk": max_risk,
            "domain_rules": _extract_domain_rule_info(intent_payload),
            "runtime_hint": runtime_hint,
            "code_intent_hint": code_intent_hint,
            "code_temporal_hint": code_temporal_hint,
        },
    }

    return payload
