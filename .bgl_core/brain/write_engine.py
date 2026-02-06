import json
import os
import re
import shutil
import time
from dataclasses import dataclass
from fnmatch import fnmatch
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

try:
    import yaml  # type: ignore
except Exception:  # pragma: no cover
    yaml = None  # type: ignore

try:
    from .patch_plan import PatchPlan, PatchOperation, load_plan, PlanError  # type: ignore
except Exception:
    from patch_plan import PatchPlan, PatchOperation, load_plan, PlanError


@dataclass
class WriteResult:
    ok: bool
    message: str
    plan_id: str
    changes: List[Dict[str, Any]]
    errors: List[str]
    backups: List[str]


def _posix_path(path: str) -> str:
    return path.replace("\\", "/").lstrip("/")


def _safe_relpath(path: str) -> str:
    norm = _posix_path(path)
    if norm.startswith("../") or "/../" in norm or norm == "..":
        raise PlanError(f"Unsafe path traversal: {path}")
    if ":" in norm or norm.startswith("//"):
        raise PlanError(f"Absolute or invalid path: {path}")
    return norm


def _load_scope(root: Path) -> Dict[str, Any]:
    scope_path = root / ".bgl_core" / "brain" / "write_scope.yml"
    if not scope_path.exists():
        raise PlanError("write_scope.yml not found.")
    if yaml is None:
        raise PlanError("PyYAML not available for write_scope.yml.")
    data = yaml.safe_load(scope_path.read_text(encoding="utf-8")) or {}
    if not isinstance(data, dict):
        raise PlanError("Invalid write_scope.yml structure.")
    return data


def _match_any(path: str, patterns: List[str]) -> bool:
    for pat in patterns:
        if fnmatch(path, pat):
            return True
    return False


def _read_text(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def _write_text(path: Path, content: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content, encoding="utf-8")


def _backup_file(root: Path, rel: str, backup_dir: Path) -> Optional[str]:
    src = root / rel
    if not src.exists():
        return None
    dst = backup_dir / rel
    dst.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(src, dst)
    return str(dst)


class WriteEngine:
    def __init__(self, root: Path):
        self.root = root
        self.scope = _load_scope(root)
        self.policy = self.scope.get("policy", {})
        self.forbid = self.scope.get("policy", {}).get("forbid_paths") or self.scope.get("forbid_paths") or []
        self.scopes = self.scope.get("scopes", [])

    def _resolve_scope(self, rel: str) -> Tuple[bool, Optional[Dict[str, Any]], str]:
        rel = _safe_relpath(rel)
        if _match_any(rel, self.forbid):
            return False, None, f"Path forbidden: {rel}"
        for sc in self.scopes:
            patterns = sc.get("paths", [])
            if _match_any(rel, patterns):
                return True, sc, ""
        return False, None, f"Path not in allowed scopes: {rel}"

    def _allowed_op(self, op: str, scope: Optional[Dict[str, Any]]) -> Tuple[bool, str]:
        if not scope:
            return False, "Missing scope for operation."
        ops = scope.get("operations", [])
        op_check = "create" if op == "mkdir" else op
        if ops and op_check not in ops:
            return False, f"Operation not allowed in scope ({scope.get('id','unknown')}): {op}"
        allow_create = bool(self.policy.get("allow_create", True))
        allow_delete = bool(self.policy.get("allow_delete", True))
        if op in {"create", "mkdir"} and not allow_create:
            return False, "Operation blocked by policy: create disabled."
        if op in {"delete", "rename", "move"} and not allow_delete:
            return False, "Operation blocked by policy: delete/rename disabled."
        return True, ""

    def _count_lines_delta(self, before: str, after: str) -> int:
        return abs(len(after.splitlines()) - len(before.splitlines()))

    def _apply_modify(self, rel: str, op: PatchOperation, max_bytes: int) -> Tuple[bool, str, int]:
        path = self.root / rel
        if not path.exists():
            return False, f"File not found: {rel}", 0
        before = _read_text(path)
        mode = (op.mode or "replace").lower()
        content = op.content or ""
        changed = before

        if mode == "overwrite":
            changed = content
        elif mode == "append":
            changed = before + content
        elif mode == "prepend":
            changed = content + before
        elif mode in {"replace", "insert_before", "insert_after"}:
            if not op.match:
                return False, "modify requires 'match' for replace/insert", 0
            if op.regex:
                pattern = re.compile(op.match, re.MULTILINE | re.DOTALL)
                if mode == "replace":
                    changed, count = pattern.subn(content, before, count=op.count or 0)
                elif mode == "insert_before":
                    def repl(m):
                        return content + m.group(0)
                    changed, count = pattern.subn(repl, before, count=op.count or 0)
                else:
                    def repl(m):
                        return m.group(0) + content
                    changed, count = pattern.subn(repl, before, count=op.count or 0)
            else:
                if mode == "replace":
                    changed = before.replace(op.match, content, op.count or -1)
                    count = before.count(op.match)
                elif mode == "insert_before":
                    changed = before.replace(op.match, content + op.match, op.count or -1)
                    count = before.count(op.match)
                else:
                    changed = before.replace(op.match, op.match + content, op.count or -1)
                    count = before.count(op.match)
            if count == 0:
                return False, f"No matches for modify in {rel}", 0
        else:
            return False, f"Unknown modify mode: {mode}", 0

        if len(changed.encode("utf-8")) > max_bytes:
            return False, f"File too large after modify: {rel}", 0

        if changed == before:
            return False, "No changes applied", 0
        _write_text(path, changed)
        delta = self._count_lines_delta(before, changed)
        return True, "modified", delta

    def apply(self, plan: PatchPlan, dry_run: bool = False) -> WriteResult:
        max_files = int(self.policy.get("max_files_per_change", 30))
        max_lines = int(self.policy.get("max_lines_per_change", 500))
        max_bytes = int(self.policy.get("max_file_bytes", 400000))
        require_backup = bool(self.policy.get("require_backup", True))

        changes: List[Dict[str, Any]] = []
        errors: List[str] = []
        backups: List[str] = []

        touched_files: set[str] = set()
        total_line_delta = 0

        backup_dir = self.root / ".bgl_core" / "backups" / plan.plan_id

        for op in plan.operations:
            rel = _safe_relpath(op.path)
            allowed, scope, reason = self._resolve_scope(rel)
            if not allowed:
                errors.append(reason)
                continue
            op_ok, op_reason = self._allowed_op(op.op, scope)
            if not op_ok:
                errors.append(f"{rel}: {op_reason}")
                continue
            touched_files.add(rel)
            if len(touched_files) > max_files:
                errors.append("Exceeded max_files_per_change.")
                break
            abs_path = self.root / rel

            if abs_path.exists() and abs_path.is_file() and abs_path.stat().st_size > max_bytes:
                errors.append(f"File too large: {rel}")
                continue

            if dry_run:
                changes.append({"op": op.op, "path": rel, "status": "dry_run"})
                continue

            if require_backup:
                b = _backup_file(self.root, rel, backup_dir)
                if b:
                    backups.append(b)

            if op.op == "create":
                if abs_path.exists():
                    errors.append(f"File already exists: {rel}")
                    continue
                if len((op.content or "").encode("utf-8")) > max_bytes:
                    errors.append(f"File too large: {rel}")
                    continue
                _write_text(abs_path, op.content or "")
                total_line_delta += len((op.content or "").splitlines())
                changes.append({"op": "create", "path": rel, "status": "ok"})
            elif op.op == "modify":
                ok, msg, delta = self._apply_modify(rel, op, max_bytes)
                if not ok:
                    errors.append(f"{rel}: {msg}")
                else:
                    total_line_delta += delta
                    changes.append({"op": "modify", "path": rel, "status": "ok"})
            elif op.op == "delete":
                if not abs_path.exists():
                    errors.append(f"File not found: {rel}")
                    continue
                try:
                    total_line_delta += len(_read_text(abs_path).splitlines())
                except Exception:
                    pass
                abs_path.unlink()
                changes.append({"op": "delete", "path": rel, "status": "ok"})
            elif op.op in {"rename", "move"}:
                if not op.to:
                    errors.append(f"{rel}: missing 'to' for {op.op}")
                    continue
                dst_rel = _safe_relpath(op.to)
                ok2, scope2, reason2 = self._resolve_scope(dst_rel)
                if not ok2:
                    errors.append(reason2)
                    continue
                op_ok2, op_reason2 = self._allowed_op(op.op, scope2)
                if not op_ok2:
                    errors.append(f"{dst_rel}: {op_reason2}")
                    continue
                touched_files.add(dst_rel)
                if len(touched_files) > max_files:
                    errors.append("Exceeded max_files_per_change.")
                    break
                dst_path = self.root / dst_rel
                dst_path.parent.mkdir(parents=True, exist_ok=True)
                if dst_path.exists():
                    errors.append(f"Destination exists: {dst_rel}")
                    continue
                if abs_path.exists():
                    abs_path.rename(dst_path)
                    changes.append({"op": op.op, "from": rel, "to": dst_rel, "status": "ok"})
                else:
                    errors.append(f"File not found: {rel}")
            elif op.op == "mkdir":
                (self.root / rel).mkdir(parents=True, exist_ok=True)
                changes.append({"op": "mkdir", "path": rel, "status": "ok"})
            else:
                errors.append(f"Unsupported op: {op.op}")

            if total_line_delta > max_lines:
                errors.append("Exceeded max_lines_per_change.")
                break

        ok = len(errors) == 0
        message = "ok" if ok else "errors"
        self._write_manifest(plan, changes, errors, backups)
        return WriteResult(ok=ok, message=message, plan_id=plan.plan_id, changes=changes, errors=errors, backups=backups)

    def _write_manifest(self, plan: PatchPlan, changes: List[Dict[str, Any]], errors: List[str], backups: List[str]) -> None:
        out_dir = self.root / ".bgl_core" / "logs"
        out_dir.mkdir(parents=True, exist_ok=True)
        record = {
            "ts": time.time(),
            "plan_id": plan.plan_id,
            "description": plan.description,
            "changes": changes,
            "errors": errors,
            "backups": backups,
        }
        path = out_dir / "write_engine_manifest.jsonl"
        with path.open("a", encoding="utf-8") as f:
            f.write(json.dumps(record, ensure_ascii=False) + "\n")


def main() -> None:
    import argparse

    parser = argparse.ArgumentParser()
    parser.add_argument("--plan", required=True, help="Path to patch plan (JSON/YAML)")
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()

    root = Path(__file__).parent.parent.parent
    plan = load_plan(Path(args.plan))
    engine = WriteEngine(root)
    result = engine.apply(plan, dry_run=args.dry_run)
    print(json.dumps({
        "ok": result.ok,
        "message": result.message,
        "plan_id": result.plan_id,
        "changes": result.changes,
        "errors": result.errors,
        "backups": result.backups
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
