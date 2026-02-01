import argparse
import json
import os
import sys
import urllib.request
from pathlib import Path
from typing import Dict, Any

ROOT = Path(__file__).resolve().parents[1]
sys.path.append(str(ROOT / ".bgl_core" / "brain"))

from llm_tools import dispatch as tool_dispatch  # type: ignore
from llm_tools import tool_schema  # type: ignore
from context_extractor import extract as extract_context  # type: ignore
from embeddings import search as embed_search  # type: ignore
from experience_replay import fetch as replay_fetch, save as replay_save  # type: ignore
from intent_resolver import resolve_intent  # type: ignore
from decision_engine import decide  # type: ignore
from decision_db import insert_intent, insert_decision, insert_outcome  # type: ignore


def compute_dynamic_params() -> Dict[str, Any]:
    """
    Derive temperature/max_tokens from recent outcomes in knowledge.db.
    """
    import sqlite3

    db = ROOT / ".bgl_core" / "brain" / "knowledge.db"
    temp = 0.6
    max_tokens = 1024
    try:
        conn = sqlite3.connect(db)
        cur = conn.cursor()
        rows = cur.execute(
            "SELECT result FROM outcomes ORDER BY id DESC LIMIT 50"
        ).fetchall()
        conn.close()
        successes = sum(1 for r in rows if r[0] == "success")
        total = len(rows) or 1
        rate = successes / total
        if rate < 0.5:
            temp = 0.3
        elif rate > 0.8:
            temp = 0.7
        # token budget heuristic
        max_tokens = 768 if rate < 0.5 else 1280
    except Exception:
        pass
    return {"temperature": temp, "max_tokens": max_tokens}


def build_system_messages(mode: str, include_tools: bool, snapshot: Dict[str, Any], concise: bool = False, chat_only: bool = False) -> list:
    base = [
        {
            "role": "system",
            "content": (
                f"You are BGL3-AI in mode={mode}. "
                "Respond in Arabic. Be concise and helpful. "
                "Do not propose actions or commands unless the user explicitly asks for execution."
            ),
        }
    ]
    if include_tools and not chat_only:
        schema = tool_schema()
        base.append(
            {
                "role": "system",
                "content": "Available tools (JSON): " + json.dumps(schema["tools"], ensure_ascii=False),
            }
        )
    if snapshot:
        base.append(
            {
                "role": "system",
                "content": "Live snapshot: " + json.dumps(snapshot, ensure_ascii=False),
            }
        )
    return base


def ask_llm(prompt: str, mode: str = "design", include_tools: bool = False, snapshot: Dict[str, Any] | None = None, concise: bool = False, chat_only: bool = False):
    """Enhanced CLI interface to the BGL3 local LLM (Ollama)."""

    url = os.getenv("LLM_BASE_URL", "http://localhost:11434/v1/chat/completions")
    model = os.getenv("LLM_MODEL", "llama3.1")

    params = compute_dynamic_params()

    headers = {"Content-Type": "application/json"}
    messages = build_system_messages(mode, include_tools, snapshot or {}, concise=concise, chat_only=chat_only)
    messages.append({"role": "user", "content": prompt})

    payload = {
        "model": model,
        "messages": messages,
        "temperature": params["temperature"],
        "max_tokens": params["max_tokens"],
        "stream": False,
    }

    try:
        req = urllib.request.Request(url, json.dumps(payload).encode(), headers)
        with urllib.request.urlopen(req, timeout=60) as response:
            res = json.loads(response.read().decode())
            return (
                res.get("choices", [{}])[0]
                .get("message", {})
                .get("content", "Error: No response content.")
            )
    except Exception as e:
        return f"Error: Could not connect to LLM. Is Ollama running? ({e})"


def run_ab(prompt: str, mode: str, snapshot: Dict[str, Any]) -> str:
    """Two-shot with different temperatures, choose best via score_response tool."""
    candidates = []
    for temp in (0.3, 0.7):
        resp = ask_llm(prompt, mode=mode, include_tools=True, snapshot=snapshot)
        score = tool_dispatch({"tool": "score_response", "payload": {"text": resp}}).get("score", 0)
        candidates.append((score, resp))
    candidates.sort(key=lambda x: x[0], reverse=True)
    return candidates[0][1]


def build_snapshot(domain: str) -> Dict[str, Any]:
    snap = {}
    snap["context_pack"] = tool_dispatch({"tool": "context_pack"})
    snap["recent_replay"] = replay_fetch(domain, limit=3)
    return snap


def log_chat_intent(prompt: str, intent_payload: Dict[str, Any], decision_payload: Dict[str, Any]):
    db = ROOT / ".bgl_core" / "brain" / "knowledge.db"
    intent_id = insert_intent(
        db,
        intent_payload.get("intent", "observe"),
        float(intent_payload.get("confidence", 0)),
        intent_payload.get("reason", ""),
        json.dumps(intent_payload.get("scope", [])),
        json.dumps(intent_payload.get("context_snapshot", {})),
        source="chat",
    )
    decision_id = insert_decision(
        db,
        intent_id,
        decision_payload.get("decision", "observe"),
        decision_payload.get("risk_level", "low"),
        bool(decision_payload.get("requires_human", False)),
        "; ".join(decision_payload.get("justification", [])),
    )
    insert_outcome(db, decision_id, "success", notes="chat_flow")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("input", help="Prompt text or JSON payload for tool", nargs="?")
    parser.add_argument("--tool", action="store_true", help="Call llm_tools directly with JSON string")
    parser.add_argument("--mode", choices=["design", "patch", "refactor"], default="design")
    parser.add_argument("--domain", default="general", help="Domain tag for experience replay/context")
    parser.add_argument("--ab", action="store_true", help="Run A/B responses and pick best by score")
    parser.add_argument("--context", nargs="*", help="Paths/symbols to extract snippets for context")
    parser.add_argument("--chat-only", action="store_true", help="Force pure chat (no tools/snapshot)")
    args = parser.parse_args()

    if not args.input:
        parser.print_usage()
        sys.exit(1)

    if args.tool:
        try:
            payload = json.loads(args.input)
        except Exception:
            payload = {"tool": "tool_schema"}
        resp = tool_dispatch(payload)
        print(json.dumps(resp, ensure_ascii=False, indent=2))
        sys.exit(0)

    prompt = args.input

    # Greeting/short message: keep it simple, no tools/context
    greetings = ["مرحبا", "السلام عليكم", "hi", "hello", "اهلا", "اهلاً", "أهلاً"]
    stripped = prompt.strip().lower()
    is_greeting = any(stripped.startswith(g.lower()) for g in greetings) or len(stripped) < 20

    # كشف تلقائي للنوايا “التنفيذية”: إذا وجد أفعال مثل شغّل/افحص/اصلح/نفذ/طبق → فعل
    task_keywords = ["شغّل", "افحص", "اختبر", "اصلح", "نفّذ", "طبق", "apply", "run", "fix", "execute"]
    auto_task = any(k in stripped for k in task_keywords)
    # Intent resolution (observe-only, lightweight)
    try:
        intent_payload = resolve_intent({"vitals": {}, "findings": {}})
        # Override with explicit task intent
        if auto_task:
            intent_payload["intent"] = "auto_fix"
            intent_payload["confidence"] = max(intent_payload.get("confidence", 0.6), 0.75)
            intent_payload["reason"] = "user requested action in chat"
            intent_payload["scope"] = ["chat"]
    except Exception:
        intent_payload = {"intent": "observe", "confidence": 0.4, "reason": "fallback", "scope": []}
    decision_payload = decide(intent_payload, {})

    context_snippets = []
    replay = []
    snap = {}

    if not is_greeting and not args.chat_only and auto_task:
        context_snippets = extract_context(args.context) if args.context else []
        replay = replay_fetch(args.domain, limit=3)
        snap = build_snapshot(args.domain)
        if context_snippets:
            prompt = "Context snippets:\n" + json.dumps(context_snippets, ensure_ascii=False) + "\n\nTask:\n" + prompt
        if replay:
            prompt = "Recent similar tasks:\n" + json.dumps(replay, ensure_ascii=False) + "\n\n" + prompt

    use_tools = auto_task and not (is_greeting or args.chat_only)

    if args.ab and use_tools:
        response = run_ab(prompt, mode=args.mode, snapshot=snap)
    else:
        response = ask_llm(
            prompt,
            mode=args.mode,
            include_tools=use_tools,
            snapshot=snap if use_tools else {},
            concise=not use_tools,
            chat_only=not use_tools,
        )

    # Log intent/decision for chat flow
    try:
        log_chat_intent(prompt, intent_payload, decision_payload)
    except Exception:
        pass

    print("\n--- BGL3-AI RESPONSE ---\n")
    print(response)
    print("\n------------------------\n")
    # Save replay
    replay_save(prompt, response, domain=args.domain, outcome="unspecified")


if __name__ == "__main__":
    main()
