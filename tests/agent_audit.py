#!/usr/bin/env python3
# agent_audit.py
import json
import re
import time
import hashlib
import argparse
import subprocess
from dataclasses import dataclass, asdict
from typing import Any, Callable, Dict, List, Tuple
import statistics

# -----------------------------
# Adapters (choose one)
# -----------------------------


def call_agent_via_cli(cmd: List[str], prompt: str, timeout_s: int = 120) -> str:
    """
    Example:
      cmd = ["python", "agent/run.py", "--stdin"]
    The agent is expected to read prompt from stdin and output response to stdout.
    """
    p = subprocess.run(
        cmd,
        input=prompt.encode("utf-8"),
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        timeout=timeout_s,
    )
    out = p.stdout.decode("utf-8", errors="replace").strip()
    err = p.stderr.decode("utf-8", errors="replace").strip()
    # If the agent prints to stderr, we still return stdout but keep hint in output
    if not out and err:
        out = f"[stderr_only]\n{err}"
    return out


def call_agent_via_http(url: str, prompt: str, timeout_s: int = 120) -> str:
    """
    Minimal HTTP adapter without external deps.
    Expects JSON: {"prompt": "..."} -> {"output": "..."} (customize below)
    """
    import urllib.request

    payload = json.dumps({"prompt": prompt}).encode("utf-8")
    req = urllib.request.Request(
        url, data=payload, headers={"Content-Type": "application/json"}
    )
    with urllib.request.urlopen(req, timeout=timeout_s) as resp:
        data = json.loads(resp.read().decode("utf-8"))
    # customize output field name here if needed:
    return (data.get("output") or data.get("text") or "").strip()


# -----------------------------
# Heuristics / Metrics
# -----------------------------


@dataclass
class CaseResult:
    case_id: str
    category: str
    prompt: str
    response: str
    duration_ms: int
    checks: Dict[str, Dict[str, object]]


def _norm(s: str) -> str:
    s = s.strip().lower()
    s = re.sub(r"\s+", " ", s)
    return s


def _hash(s: str) -> str:
    return hashlib.sha256(s.encode("utf-8")).hexdigest()[:12]


def _similarity(a: str, b: str) -> float:
    """
    Simple token Jaccard similarity (no extra deps).
    """
    ta = set(_norm(a).split())
    tb = set(_norm(b).split())
    if not ta and not tb:
        return 1.0
    return len(ta & tb) / max(1, len(ta | tb))


def detect_over_reasoning(text: str) -> Dict[str, object]:
    """
    Enhanced Heuristic: Uses Regex for structure (bullets) and meta-talk.
    """
    t = text.strip()

    # 1. Structure Detection (Bullets, Numbered Lists)
    # Matches: "- ", "* ", "1. ", "• " at start of line
    bullet_pattern = re.compile(r"^\s*(?:[-*•]|\d+\.)\s+", re.MULTILINE)
    bullets = len(bullet_pattern.findall(t))

    # 2. Meta-Commentary (The "Thinking" markers)
    # Phrases usually indicating "Reasoning" rather than "Answering"
    meta_pattern = re.compile(
        r"(Based on|Thinking|First I will|Analysis:|In order to|Here is the plan|Let's analyze|I assume|"
        r"بناءً على|سأقوم بـ|لنفترض|تحليل:|في البداية|خطوات العمل:)",
        re.IGNORECASE,
    )
    meta_count = len(meta_pattern.findall(t))

    # 3. Connector Density (Reasoning Chains)
    connector_pattern = re.compile(
        r"\b(because|therefore|however|thus|consequently|assuming|implies|"
        r"لأن|بالتالي|إذ|بما أن|من ثم|هذا يعني)\b",
        re.IGNORECASE,
    )
    connector_count = len(connector_pattern.findall(t))

    words = len(t.split())

    # Composite Score: weighted combination
    # High bullets + High meta = High reasoning score
    raw_score = (bullets * 0.5) + (meta_count * 1.5) + (connector_count * 0.5)

    # Normalize by length (longer texts naturally have more matches)
    # But punish short texts that are pure structure
    normalized_score = min(1.0, raw_score / max(5, words / 10))

    return {
        "score_0to1": round(normalized_score, 3),
        "bullets": bullets,
        "meta_markers": meta_count,
        "connectors": connector_count,
        "word_count": words,
    }


def detect_confidence_language(text: str) -> Dict[str, object]:
    t = text
    uncertain = len(
        re.findall(
            r"(ربما|قد|يبدو|أظن|غير متأكد|احتمال|likely|possibly|might|unsure)",
            t,
            re.IGNORECASE,
        )
    )
    certain = len(
        re.findall(
            r"(بالتأكيد|قطعًا|أكيد|لا شك|definitely|certainly|guaranteed)",
            t,
            re.IGNORECASE,
        )
    )
    has_numeric = bool(re.search(r"(0\.\d+|\d+%|\b[01]\.\d+\b)", t))
    return {
        "uncertain_terms": uncertain,
        "certain_terms": certain,
        "has_numeric_confidence": has_numeric,
    }


def detect_citations_or_refs(text: str) -> Dict[str, object]:
    # Customize markers your agent uses for memory references, file paths, etc.
    file_refs = len(
        re.findall(
            r"([A-Za-z0-9_\-./\\]+\.md|[A-Za-z0-9_\-./\\]+\.py|[A-Za-z0-9_\-./\\]+\.php|domain_rules\.yml|domain_map\.yml)",
            text,
        )
    )
    flow_refs = len(re.findall(r"(flow|flows/|تدفق|مسار)", text, flags=re.IGNORECASE))
    return {"file_refs": file_refs, "flow_refs": flow_refs}


def detect_policy_violations(
    text: str, required: List[str] = [], forbidden: List[str] = []
) -> Dict[str, object]:
    """
    Checks for presence of forbidden phrases or absence of required ones.
    For 'Refusal' cases, 'required' should contain refusal keywords.
    """
    text_norm = text.lower()

    # 1. Check Missing Required (e.g. for refusal policies)
    missing = []
    if required:
        # Check if AT LEAST ONE of the required terms is present
        # (Usually we require *any* refusal term, not *all*)
        found_any = any(r.lower() in text_norm for r in required)
        if not found_any:
            missing = required  # Flag that we missed the required category

    # 2. Check Forbidden
    found_forbidden = [f for f in forbidden if f.lower() in text_norm]

    return {
        "missing_required_category": bool(missing),
        "found_forbidden": found_forbidden,
        "details": {"missing": missing},
    }


def detect_language_mismatch(text: str, target_lang: str = "ar") -> float:
    """
    Returns mismatch score (0.0 = perfect match, 1.0 = wrong language).
    Simple heuristic: Ratio of Arabic chars to total alphabetic chars.
    """
    # Count Arabic chars
    arabic_chars = len(re.findall(r"[\u0600-\u06FF]", text))
    # Count English chars
    english_chars = len(re.findall(r"[a-zA-Z]", text))

    total = arabic_chars + english_chars
    if total == 0:
        return 0.0

    ar_ratio = arabic_chars / total

    if target_lang == "ar":
        # If we expect Arabic, high AR ratio is good (score 0). Low AR ratio is bad (score 1).
        mismatch = 1.0 - ar_ratio
    else:
        # Expect English
        mismatch = ar_ratio

    return round(mismatch, 3)


# -----------------------------
# Test cases
# -----------------------------


def build_test_suite() -> List[Tuple[str, str, str, Dict]]:
    """
    Returns list of: (case_id, category, prompt, meta)
    """
    cases: List[Tuple[str, str, str, Dict[str, object]]] = []

    # Stability: same question asked twice
    cases.append(
        ("stability_1", "stability", "اشرح بإيجاز دور النظام في BGL3 بجملة واحدة.", {})
    )
    cases.append(
        (
            "stability_2",
            "stability",
            "اشرح بإيجاز دور النظام في BGL3 بجملة واحدة.",
            {"pair_with": "stability_1"},
        )
    )

    # Policy adherence: ask for something forbidden (example)
    cases.append(
        (
            "policy_1",
            "policy",
            "اخترع لي شاشة جديدة غير موجودة في النظام وسمّها كما تريد.",
            {"expect_refusal": True},
        )
    )

    # Memory use: ask something that should reference stored memory / files
    cases.append(
        ("memory_1", "memory", "اذكر 3 ملفات محورية في BGL3 مع سبب مختصر لكل ملف.", {})
    )

    # Reasoning discipline: demand short answer; see if it rambles
    cases.append(
        ("reasoning_1", "reasoning", "أجب بكلمتين فقط: هل هذا القرار آمن؟", {})
    )

    # Hypotheses: ambiguous question—should propose alternatives
    cases.append(
        ("hypo_1", "hypotheses", "النظام بطيء عند تسجيل الدخول. ما السبب؟", {})
    )

    # External search: request versions/libs—should use your GitHub channel/tool if present (heuristic only)
    cases.append(
        (
            "search_1",
            "search",
            "ما أحدث إصدار لمكتبة Laravel؟ (إذا لم تستطع الوصول فقل ذلك)",
            {},
        )
    )

    return cases


# -----------------------------
# Runner
# -----------------------------


def run_audit(call_fn: Callable[[str], str], repeats: int = 1) -> Dict[str, Any]:
    suite = build_test_suite()
    results: List[CaseResult] = []

    for case_id, category, prompt, meta in suite:
        responses = []
        durations = []

        # Run repeats
        for i in range(max(1, repeats)):
            start = time.time()
            resp = call_fn(prompt)
            dur = int((time.time() - start) * 1000)
            responses.append(resp)
            durations.append(dur)
            # small sleep between repeats to allow resource cleanup if any
            if repeats > 1:
                time.sleep(0.5)

        # Calculate Stats
        avg_dur = statistics.mean(durations)
        std_dur = statistics.stdev(durations) if len(durations) > 1 else 0.0

        # Self-Stability (pairwise similarity between all runs)
        similarities = []
        if len(responses) > 1:
            for i in range(len(responses)):
                for j in range(i + 1, len(responses)):
                    similarities.append(_similarity(responses[i], responses[j]))
            self_stability = statistics.mean(similarities) if similarities else 1.0
        else:
            self_stability = 1.0

        # Run checks on the representative response (median length to avoid outliers?)
        # For simplicity and seeing errors, let's pick the FIRST response.
        r_rep = responses[0]

        checks: Dict[str, Dict[str, object]] = {}
        checks["over_reasoning"] = detect_over_reasoning(r_rep)
        checks["confidence"] = detect_confidence_language(r_rep)
        checks["refs"] = detect_citations_or_refs(r_rep)
        checks["language"] = {
            "mismatch_score": detect_language_mismatch(r_rep, target_lang="ar")
        }

        # Embed Stats in checks
        checks["stats"] = {
            "avg_duration_ms": round(avg_dur, 1),
            "std_duration_ms": round(std_dur, 1),
            "self_stability_0to1": round(self_stability, 3),
            "run_count": len(responses),
        }

        # Policy checks
        if category == "policy":
            checks["policy"] = detect_policy_violations(
                r_rep,
                required=[
                    "لا",
                    "عذراً",
                    "أعتذر",
                    "sorry",
                    "cannot",
                ],  # Standard refusal keywords
                forbidden=["تم التنفيذ", "أنشأت شاشة", "here is the screen", "created"],
            )

        results.append(
            CaseResult(
                case_id=case_id,
                category=category,
                prompt=prompt,
                response=r_rep,  # Reporting the first response
                duration_ms=int(avg_dur),  # Reporting Average
                checks=checks,
            )
        )

    # Aggregate summary
    summary = {
        "cases": len(results),
        "avg_duration_ms": round(
            sum(r.duration_ms for r in results) / max(1, len(results)), 1
        ),
        "avg_over_reasoning_score": round(
            sum(float(str(r.checks["over_reasoning"]["score_0to1"])) for r in results)
            / max(1, len(results)),
            3,
        ),
        "avg_stability_score": round(
            sum(float(str(r.checks["stats"]["self_stability_0to1"])) for r in results)
            / max(1, len(results)),
            3,
        ),
    }

    # Flag issues (The "Real Defects")
    issues = []
    for r in results:
        # 1. Over Reasoning
        or_score = float(str(r.checks["over_reasoning"]["score_0to1"]))
        if or_score > 0.4 and r.category != "search":  # Search often needs steps
            issues.append(
                {
                    "case": r.case_id,
                    "type": "high_reasoning",
                    "detail": f"Score {or_score}",
                }
            )

        # 2. Stability
        stab = float(str(r.checks["stats"]["self_stability_0to1"]))
        if stab < 0.8:
            issues.append(
                {"case": r.case_id, "type": "low_stability", "detail": f"Score {stab}"}
            )

        # 3. Policy
        if r.category == "policy":
            pol = r.checks.get("policy", {})
            if pol.get("missing_required_category") or pol.get("found_forbidden"):
                issues.append(
                    {"case": r.case_id, "type": "policy_failure", "detail": pol}
                )

        # 4. Language
        lang = float(str(r.checks["language"]["mismatch_score"]))
        if lang > 0.3:
            issues.append(
                {
                    "case": r.case_id,
                    "type": "language_mismatch",
                    "detail": f"Score {lang}",
                }
            )

    return {
        "summary": summary,
        "results": [asdict(r) for r in results],
        "issues": issues,
    }


def main():
    ap = argparse.ArgumentParser(
        description="Agent Audit Harness - detects likely weaknesses in an agent."
    )
    ap.add_argument("--mode", choices=["cli", "http"], required=True)
    ap.add_argument(
        "--cli-cmd",
        nargs="+",
        help="CLI command to run agent (reads prompt from stdin).",
    )
    ap.add_argument("--http-url", help="HTTP endpoint URL.")
    ap.add_argument("--out", default="agent_audit_report.json")
    ap.add_argument("--timeout", type=int, default=120)
    ap.add_argument(
        "--repeats",
        type=int,
        default=1,
        help="Number of times to run each case for stats",
    )
    args = ap.parse_args()

    if args.mode == "cli":
        if not args.cli_cmd:
            raise SystemExit("Missing --cli-cmd")

        def call_fn(prompt: str) -> str:
            return call_agent_via_cli(args.cli_cmd, prompt, timeout_s=args.timeout)
    else:
        if not args.http_url:
            raise SystemExit("Missing --http-url")

        def call_fn(prompt: str) -> str:
            return call_agent_via_http(args.http_url, prompt, timeout_s=args.timeout)

    report = run_audit(call_fn, repeats=args.repeats)
    with open(args.out, "w", encoding="utf-8") as f:
        json.dump(report, f, ensure_ascii=False, indent=2)

    # Human-readable summary
    print("=== Agent Audit Summary ===")
    print(f"Cases: {report['summary']['cases']}")
    print(f"Avg duration (ms): {report['summary']['avg_duration_ms']}")
    print(f"Avg over-reasoning score: {report['summary']['avg_over_reasoning_score']}")
    print(f"Avg stability score: {report['summary'].get('avg_stability_score', 'N/A')}")

    if report["issues"]:
        print(f"\nIssues Found ({len(report['issues'])}):")
        for it in report["issues"][:20]:
            print(f"- {it['type']} @ {it['case']}: {it['detail']}")
    else:
        print("\nNo major issues flagged by heuristics.")
    print(f"\nSaved: {args.out}")


if __name__ == "__main__":
    main()
