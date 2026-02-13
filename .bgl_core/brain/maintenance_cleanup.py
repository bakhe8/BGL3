from __future__ import annotations

import json
import time
from pathlib import Path
from typing import Dict, Any, List, Tuple


def _as_bool(value: Any, default: bool = False) -> bool:
    if value is None:
        return default
    if isinstance(value, bool):
        return value
    try:
        return bool(int(value))
    except Exception:
        return default


def _as_int(value: Any, default: int) -> int:
    try:
        return int(value)
    except Exception:
        return default


def _within(path: Path, root: Path) -> bool:
    try:
        path.resolve().relative_to(root.resolve())
        return True
    except Exception:
        return False


def _collect_pycache_candidates(core_dir: Path) -> List[Path]:
    candidates: List[Path] = []
    for p in core_dir.rglob("*.pyc"):
        if _within(p, core_dir):
            candidates.append(p)
    for p in core_dir.rglob("__pycache__"):
        if _within(p, core_dir):
            candidates.append(p)
    return candidates


def _collect_sqlite_temp_candidates(core_dir: Path) -> List[Path]:
    candidates: List[Path] = []
    for p in core_dir.rglob("*.db-wal"):
        candidates.append(p)
    for p in core_dir.rglob("*.db-shm"):
        candidates.append(p)
    return candidates


def run_cleanup(
    root_dir: Path,
    cfg: Dict[str, Any],
    *,
    source: str = "master_verify",
) -> Dict[str, Any]:
    cleanup_cfg = (cfg or {}).get("cleanup") or {}
    enabled = _as_bool(cleanup_cfg.get("enabled", 1), True)
    dry_run = _as_bool(cleanup_cfg.get("dry_run", 0), False)
    remove_pycache = _as_bool(cleanup_cfg.get("remove_pycache", 1), True)
    remove_db_temp = _as_bool(cleanup_cfg.get("remove_db_temp", 1), True)
    # Time-based pruning removed: cleanup only targets safe temp artifacts.
    retention_enabled = _as_bool(cfg.get("retention_enabled", 0), False)
    disable_time_prune = True

    report: Dict[str, Any] = {
        "enabled": enabled,
        "dry_run": dry_run,
        "source": source,
        "timestamp": time.time(),
        "retention_enabled": retention_enabled,
        "retention_disable_time_prune": disable_time_prune,
        "deleted": [],
        "skipped": [],
        "counts": {},
    }
    if not enabled:
        return report

    core_dir = root_dir / ".bgl_core"
    candidates: List[Tuple[str, Path]] = []
    if remove_pycache:
        for p in _collect_pycache_candidates(core_dir):
            candidates.append(("pycache", p))
    if remove_db_temp:
        for p in _collect_sqlite_temp_candidates(core_dir / "brain"):
            candidates.append(("db_temp", p))

    now = time.time()
    deleted: List[str] = []
    skipped: List[str] = []
    for kind, path in candidates:
        try:
            if not path.exists():
                continue
            # Only operate within .bgl_core or storage/logs to avoid touching project files.
            if not (_within(path, core_dir) or _within(path, root_dir / "storage")):
                skipped.append(f"{kind}:{path} (outside roots)")
                continue
            if dry_run:
                deleted.append(f"{kind}:{path}")
                continue
            if path.is_dir():
                # Remove empty __pycache__ directories only.
                try:
                    path.rmdir()
                    deleted.append(f"{kind}:{path}")
                except Exception:
                    skipped.append(f"{kind}:{path} (dir not empty)")
            else:
                path.unlink(missing_ok=True)
                deleted.append(f"{kind}:{path}")
        except Exception as exc:
            skipped.append(f"{kind}:{path} (error: {exc})")

    report["deleted"] = deleted
    report["skipped"] = skipped
    report["counts"] = {
        "candidates": len(candidates),
        "deleted": len(deleted),
        "skipped": len(skipped),
    }
    try:
        log_path = core_dir / "logs" / "cleanup_report.json"
        log_path.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    except Exception:
        pass
    return report
