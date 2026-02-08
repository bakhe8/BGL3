import json
import os
from pathlib import Path
from typing import Any, Dict, List

try:
    import yaml  # type: ignore
except Exception:  # pragma: no cover
    yaml = None  # type: ignore


def _normalize(path: str) -> str:
    return str(path or "").replace("\\", "/").lstrip("/")


def _risk_tier(path: str) -> str:
    norm = _normalize(path).lower()
    if norm.startswith("api/"):
        return "high"
    if norm.startswith(".bgl_core/brain/"):
        return "high"
    if norm.startswith("app/") or norm.startswith("views/") or norm.startswith("partials/"):
        return "medium"
    if norm.startswith("agentfrontend/") or norm.startswith("public/js/"):
        return "medium"
    if norm.startswith("scripts/") or norm.startswith("tests/"):
        return "low"
    return "low"


def _load_write_scope(root: Path) -> Dict[str, Any]:
    scope_path = root / ".bgl_core" / "brain" / "write_scope.yml"
    if not scope_path.exists() or yaml is None:
        return {}
    try:
        return yaml.safe_load(scope_path.read_text(encoding="utf-8")) or {}
    except Exception:
        return {}


def require_tests_enabled(root: Path, default: bool = False) -> bool:
    env = os.getenv("BGL_REQUIRE_TESTS")
    if env is not None:
        return str(env).strip() == "1"
    scope = _load_write_scope(root)
    try:
        policy = scope.get("policy") or {}
        return bool(policy.get("require_tests", default))
    except Exception:
        return bool(default)


def load_code_contracts(root: Path) -> Dict[str, Any]:
    path = root / "analysis" / "code_contracts.json"
    if not path.exists():
        # Best-effort: attempt to generate if module is available
        try:
            from .code_contracts import build_code_contracts  # type: ignore
        except Exception:
            try:
                from code_contracts import build_code_contracts  # type: ignore
            except Exception:
                build_code_contracts = None  # type: ignore
        if build_code_contracts:
            try:
                build_code_contracts(root)
            except Exception:
                pass
    if not path.exists():
        return {}
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return {}


def _contract_map(data: Dict[str, Any]) -> Dict[str, Dict[str, Any]]:
    mapping: Dict[str, Dict[str, Any]] = {}
    contracts = data.get("contracts") or []
    for c in contracts:
        file_path = _normalize(c.get("file") or "")
        if file_path:
            mapping[file_path] = c
    return mapping


def collect_tests_for_files(root: Path, rel_paths: List[str]) -> List[str]:
    data = load_code_contracts(root)
    contract_map = _contract_map(data)
    tests: List[str] = []
    for rel in rel_paths:
        norm = _normalize(rel)
        contract = contract_map.get(norm) or {}
        for t in contract.get("tests") or []:
            tests.append(_normalize(t))
    # Keep only existing
    existing = []
    for t in sorted(set(tests)):
        if (root / t).exists():
            existing.append(str(root / t))
    return existing

def evaluate_files(
    root: Path,
    rel_paths: List[str],
    *,
    require_tests: bool,
    allow_scenarios: bool = True,
) -> Dict[str, Any]:
    rels = sorted({_normalize(p) for p in rel_paths if p})
    if not require_tests or not rels:
        return {"ok": True, "errors": []}

    data = load_code_contracts(root)
    contract_map = _contract_map(data)
    errors: List[str] = []

    for rel in rels:
        if not rel.lower().endswith((".php", ".py", ".js", ".jsx", ".ts", ".tsx")):
            continue
        contract = contract_map.get(rel) or {}
        risk = contract.get("risk") or _risk_tier(rel)
        if risk != "high":
            continue
        tests = contract.get("tests") or []
        scenarios = contract.get("scenarios") or []
        if tests:
            continue
        if allow_scenarios and scenarios:
            continue
        errors.append(
            f"tests_required: {rel} risk=high tests=0 scenarios={len(scenarios)}"
        )

    return {"ok": len(errors) == 0, "errors": errors}
