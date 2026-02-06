from __future__ import annotations

import json
import re
import time
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

try:
    from .llm_client import LLMClient  # type: ignore
    from .patch_plan import PatchPlan, PlanError  # type: ignore
except Exception:
    from llm_client import LLMClient
    from patch_plan import PatchPlan, PlanError


class PlanGenerationError(Exception):
    pass


def _load_write_caps(root: Path) -> Dict[str, Any]:
    cap_path = root / ".bgl_core" / "brain" / "write_capabilities.json"
    if not cap_path.exists():
        return {}
    try:
        return json.loads(cap_path.read_text(encoding="utf-8"))
    except Exception:
        return {}


def _tokenize(text: str) -> List[str]:
    tokens = re.findall(r"[a-zA-Z_]{3,}", text.lower())
    stop = {"the", "and", "with", "from", "that", "this", "into", "only", "should", "must"}
    return [t for t in tokens if t not in stop]


def _collect_candidate_files(root: Path, scopes: List[Dict[str, Any]], keywords: List[str], limit: int = 140) -> List[str]:
    files: List[str] = []
    seen: set[str] = set()

    patterns: List[str] = []
    for sc in scopes:
        for p in sc.get("paths", []):
            patterns.append(str(p))

    def add_path(path: Path) -> None:
        try:
            rel = path.relative_to(root).as_posix()
        except Exception:
            rel = str(path)
        if rel in seen:
            return
        seen.add(rel)
        files.append(rel)

    for pat in patterns:
        try:
            for path in root.glob(pat):
                if not path.is_file():
                    continue
                add_path(path)
                if len(files) >= limit * 3:
                    break
        except Exception:
            continue

    if not files:
        return []

    if keywords:
        kw = [k.lower() for k in keywords]
        hits = [f for f in files if any(k in f.lower() for k in kw)]
        if hits:
            return hits[:limit]

    return files[:limit]


def _build_prompt(proposal: Dict[str, Any], candidate_files: List[str], caps: Dict[str, Any]) -> str:
    schema_path = Path(__file__).resolve().parent / "patch_plan.schema.json"
    schema_text = schema_path.read_text(encoding="utf-8") if schema_path.exists() else ""
    allowed_scopes = caps.get("scopes", [])
    scopes_text = json.dumps(allowed_scopes, ensure_ascii=False, indent=2) if allowed_scopes else "[]"
    files_text = "\n".join(candidate_files[:120]) if candidate_files else "(no file index)"

    proposal_payload = {
        "id": proposal.get("id"),
        "name": proposal.get("name"),
        "description": proposal.get("description"),
        "action": proposal.get("action"),
        "impact": proposal.get("impact"),
        "solution": proposal.get("solution"),
        "expectation": proposal.get("expectation"),
        "evidence": proposal.get("evidence"),
    }

    return f"""
You are generating a BGL3 Patch Plan JSON that will be executed by an automated write engine.

STRICT RULES:
- Return ONLY valid JSON.
- Conform to the JSON schema below.
- Use ONLY relative paths (no absolute paths, no .. traversal).
- Prefer modify operations with match+insert/replace. Do not guess file contents.
- If unsure, keep the plan minimal and safe (1-3 operations).
- Use only files that exist in the candidate file list unless creating a new file.
- Avoid editing vendor/, .git/, node_modules/, storage/database.

JSON SCHEMA:
{schema_text}

ALLOWED PATH SCOPES (guidance):
{scopes_text}

CANDIDATE FILES (relative):
{files_text}

PROPOSAL CONTEXT:
{json.dumps(proposal_payload, ensure_ascii=False, indent=2)}

Return the patch plan JSON now.
""".strip()


def generate_plan_from_proposal(proposal: Dict[str, Any], root: Path, *, plan_id: Optional[str] = None) -> PatchPlan:
    caps = _load_write_caps(root)
    scopes = caps.get("scopes", []) if isinstance(caps.get("scopes"), list) else []
    keywords = _tokenize(
        " ".join(
            [
                str(proposal.get("name") or ""),
                str(proposal.get("description") or ""),
                str(proposal.get("action") or ""),
                str(proposal.get("impact") or ""),
            ]
        )
    )
    candidate_files = _collect_candidate_files(root, scopes, keywords)

    llm = LLMClient()
    prompt = _build_prompt(proposal, candidate_files, caps)

    try:
        payload = llm.chat_json(prompt, temperature=0.1)
    except Exception as e:
        raise PlanGenerationError(f"LLM plan generation failed: {e}")

    if not isinstance(payload, dict):
        raise PlanGenerationError("LLM output is not a JSON object.")

    if not payload.get("version"):
        payload["version"] = 1
    if not payload.get("id"):
        pid = plan_id or f"auto_{proposal.get('id','proposal')}_{int(time.time())}"
        payload["id"] = pid

    # Attach metadata
    meta = payload.get("metadata") if isinstance(payload.get("metadata"), dict) else {}
    meta.update({
        "generated_by": "llm",
        "source": "proposal",
        "proposal_id": proposal.get("id"),
        "generated_at": time.time(),
    })
    payload["metadata"] = meta

    try:
        return PatchPlan.from_dict(payload)
    except Exception as e:
        raise PlanGenerationError(f"Generated plan is invalid: {e}")
