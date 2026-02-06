import json
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Dict, List, Optional

try:
    import yaml  # type: ignore
except Exception:  # pragma: no cover - optional
    yaml = None  # type: ignore


class PlanError(Exception):
    pass


@dataclass
class PatchOperation:
    op: str
    path: str
    mode: Optional[str] = None
    content: Optional[str] = None
    match: Optional[str] = None
    regex: bool = False
    count: Optional[int] = None
    to: Optional[str] = None
    meta: Dict[str, Any] = field(default_factory=dict)

    @staticmethod
    def from_dict(data: Dict[str, Any]) -> "PatchOperation":
        op = str(data.get("op") or "")
        path = str(data.get("path") or "")
        if not op or not path:
            raise PlanError("PatchOperation requires 'op' and 'path'.")
        op_data = {k: v for k, v in data.items() if k not in {"op", "path"}}
        return PatchOperation(
            op=op,
            path=path,
            mode=op_data.get("mode"),
            content=op_data.get("content"),
            match=op_data.get("match"),
            regex=bool(op_data.get("regex", False)),
            count=op_data.get("count"),
            to=op_data.get("to"),
            meta=op_data,
        )


@dataclass
class PatchPlan:
    version: int
    plan_id: str
    operations: List[PatchOperation]
    description: str = ""
    created_at: Optional[float] = None
    metadata: Dict[str, Any] = field(default_factory=dict)

    @staticmethod
    def from_dict(data: Dict[str, Any]) -> "PatchPlan":
        if not isinstance(data, dict):
            raise PlanError("Plan payload must be a dict.")
        version = int(data.get("version") or 0)
        plan_id = str(data.get("id") or "")
        if version <= 0 or not plan_id:
            raise PlanError("Plan requires 'version' and 'id'.")
        ops_raw = data.get("operations")
        if not isinstance(ops_raw, list) or not ops_raw:
            raise PlanError("Plan requires non-empty 'operations'.")
        operations = [PatchOperation.from_dict(o) for o in ops_raw]
        return PatchPlan(
            version=version,
            plan_id=plan_id,
            operations=operations,
            description=str(data.get("description") or ""),
            created_at=data.get("created_at"),
            metadata=data.get("metadata") or {},
        )


def load_plan(path: Path) -> PatchPlan:
    if not path.exists():
        raise PlanError(f"Plan file not found: {path}")
    data: Dict[str, Any]
    if path.suffix.lower() in {".yml", ".yaml"}:
        if yaml is None:
            raise PlanError("PyYAML not available for YAML plan.")
        data = yaml.safe_load(path.read_text(encoding="utf-8")) or {}
    else:
        data = json.loads(path.read_text(encoding="utf-8"))
    return PatchPlan.from_dict(data)
