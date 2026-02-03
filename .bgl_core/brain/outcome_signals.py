from __future__ import annotations

"""
outcome_signals.py
------------------
Deterministic "Outcome -> Signals" layer.

Why:
- The system produces many outcomes (guardian scans, policy expectations, approvals,
  experiences, readiness, etc.) but the intent/decision layers previously depended
  mostly on an LLM prompt (and a weak fallback).
- This module provides a single, testable place to convert outcomes into:
  1) a compact signals pack (for prompts / reports),
  2) an intent hint usable as a robust fallback when LLM times out.
"""

import re
from typing import Any, Dict, List, Tuple

try:
    from .brain_types import Intent  # type: ignore
except Exception:
    from brain_types import Intent


def _as_list(v: Any) -> List[Any]:
    if v is None:
        return []
    if isinstance(v, list):
        return v
    return [v]


def _top(items: List[Dict[str, Any]], key: str, limit: int = 5) -> List[str]:
    out: List[str] = []
    for it in items:
        if not isinstance(it, dict):
            continue
        val = it.get(key)
        if val is None:
            continue
        out.append(str(val))
        if len(out) >= limit:
            break
    return out


def _candidate_conf_by_uri(candidates: List[Dict[str, Any]]) -> Dict[str, float]:
    best: Dict[str, float] = {}
    for c in candidates:
        if not isinstance(c, dict):
            continue
        uri = str(c.get("uri") or "").strip()
        if not uri:
            continue
        try:
            conf = float(c.get("confidence", 0) or 0)
        except Exception:
            conf = 0.0
        if conf > best.get(uri, 0.0):
            best[uri] = conf
    return best


def _looks_like_scan_artifact(uri: str, error_body: str, errors: List[str]) -> bool:
    """
    Best-effort heuristic:
    Some failing routes are not product defects but artifacts of "safe scan"
    (e.g., GET on write-only endpoints => "Missing required field").
    """
    u = (uri or "").lower()
    body = (error_body or "").lower()
    err_join = " ".join([str(e or "") for e in errors]).lower()

    # Common "missing required" signals
    missing = bool(
        re.search(r"missing|required|validation|bad request|method not allowed", err_join)
        or re.search(r"مطلوب|غير صحيح|لا يمكن", body)
        or re.search(r"missing|required|invalid|not allowed", body)
    )

    # Endpoints likely requiring POST but scanned as GET in safe mode
    write_like = any(x in u for x in ("/api/create-", "/api/update_", "/api/delete_", "/api/import", "/api/save-"))
    return bool(missing and write_like)


def compute_outcome_signals(diagnostic: Dict[str, Any]) -> Dict[str, Any]:
    """
    Input expects a diagnostic-like dict:
    {
      "vitals": {...},
      "findings": {...},
      "readiness": {...} (optional)
    }
    Returns:
    {
      "signals": {...},
      "intent_hint": {...}
    }
    """
    vitals = diagnostic.get("vitals") or {}
    findings = diagnostic.get("findings") or {}
    readiness = diagnostic.get("readiness") or {}

    failing_routes = _as_list(findings.get("failing_routes"))
    expected_failures = _as_list(findings.get("expected_failures"))
    policy_candidates = _as_list(findings.get("policy_candidates"))
    pending_approvals = _as_list(findings.get("pending_approvals"))
    permission_issues = _as_list(findings.get("permission_issues"))
    experiences = _as_list(findings.get("experiences") or findings.get("recent_experiences"))
    scenario_deps = findings.get("scenario_deps") or {}

    # Classify failing routes into actionable vs likely-expected/scan-artifact.
    cand_conf = _candidate_conf_by_uri([c for c in policy_candidates if isinstance(c, dict)])
    actionable: List[Dict[str, Any]] = []
    explained: List[Tuple[str, str]] = []
    for fr in failing_routes:
        if not isinstance(fr, dict):
            continue
        uri = str(fr.get("uri") or fr.get("url") or "").strip()
        if not uri:
            continue
        errors = [str(e) for e in _as_list(fr.get("errors")) if e is not None]
        body = str(fr.get("error_body") or "")

        # If we have any policy candidate evidence, treat mid-confidence as "likely expected"
        conf = cand_conf.get(uri, 0.0)
        if conf >= 0.5:
            explained.append((uri, f"policy_candidate(conf={conf})"))
            continue

        if _looks_like_scan_artifact(uri, body, errors):
            explained.append((uri, "scan_artifact"))
            continue

        actionable.append(fr)

    readiness_ok = None
    try:
        readiness_ok = bool(readiness.get("ok")) if isinstance(readiness, dict) else None
    except Exception:
        readiness_ok = None

    pending_ops = []
    for p in pending_approvals:
        if not isinstance(p, dict):
            continue
        op = p.get("operation")
        if op:
            pending_ops.append(str(op))

    signals: Dict[str, Any] = {
        "readiness_ok": readiness_ok,
        "infra_ok": bool(vitals.get("infrastructure", True)),
        "arch_ok": bool(vitals.get("architecture", True)),
        "business_ok": bool(vitals.get("business_logic", True)),
        "counts": {
            "failing_routes": len(failing_routes),
            "actionable_failures": len(actionable),
            "expected_failures": len(expected_failures),
            "policy_candidates": len(policy_candidates),
            "pending_approvals": len(pending_approvals),
            "permission_issues": len(permission_issues),
            "experiences": len(experiences),
        },
        "top": {
            "failing_routes": _top([f for f in failing_routes if isinstance(f, dict)], "uri", 5),
            "actionable_failures": _top(actionable, "uri", 5),
            "expected_failures": _top([e for e in expected_failures if isinstance(e, dict)], "uri", 5),
            "pending_approvals": pending_ops[:5],
        },
        "scenario_deps_ok": bool(scenario_deps.get("ok", True)) if isinstance(scenario_deps, dict) else True,
        "explained_failures": [{"uri": u, "why": why} for (u, why) in explained[:10]],
    }

    # ---- Deterministic intent hint (fallback) ----
    hint_intent = Intent.OBSERVE.value
    hint_conf = 0.65
    hint_scope: List[str] = []
    hint_reason_parts: List[str] = []

    if pending_ops:
        hint_intent = Intent.UNBLOCK.value
        hint_conf = 0.85
        hint_scope = pending_ops[:10]
        hint_reason_parts.append(f"pending_approvals: {', '.join(pending_ops[:3])}")
    elif readiness_ok is False:
        hint_intent = Intent.UNBLOCK.value
        hint_conf = 0.85
        hint_reason_parts.append("readiness_gate failed")
        try:
            services = readiness.get("services") if isinstance(readiness, dict) else None
            if isinstance(services, dict):
                for k, v in services.items():
                    if isinstance(v, dict) and not v.get("ok", True):
                        hint_scope.append(k)
        except Exception:
            pass
    elif isinstance(scenario_deps, dict) and not scenario_deps.get("ok", True):
        hint_intent = Intent.UNBLOCK.value
        hint_conf = 0.75
        hint_reason_parts.append("scenario_deps missing")
        missing = scenario_deps.get("missing") or []
        if isinstance(missing, list):
            hint_scope = [str(m) for m in missing[:10]]
    elif actionable:
        hint_intent = Intent.STABILIZE.value
        hint_conf = 0.8
        hint_scope = _top(actionable, "uri", 10)
        hint_reason_parts.append(f"actionable_failures={len(actionable)}")
    else:
        # Nothing actionable; if we only have "explained" failures we should not trigger stabilize.
        hint_intent = Intent.OBSERVE.value
        hint_conf = 0.75
        if explained and failing_routes:
            hint_reason_parts.append("failing routes appear explained by policy/scan artifacts")
        else:
            hint_reason_parts.append("no actionable failures detected")

    hint_reason = "; ".join(hint_reason_parts) if hint_reason_parts else "signals fallback"

    intent_hint = {
        "intent": hint_intent,
        "confidence": round(float(hint_conf), 2),
        "reason": hint_reason,
        "scope": hint_scope,
    }

    return {"signals": signals, "intent_hint": intent_hint}

