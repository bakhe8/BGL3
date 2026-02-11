from __future__ import annotations

import os
import subprocess
import time
from pathlib import Path
from typing import Tuple, Optional


def _pid_alive(pid: int) -> bool:
    if pid <= 0:
        return False
    try:
        if os.name == "nt":
            res = subprocess.run(
                ["tasklist", "/FI", f"PID eq {pid}"],
                capture_output=True,
                text=True,
                timeout=3,
            )
            return str(pid) in (res.stdout or "")
        os.kill(pid, 0)
        return True
    except Exception:
        return False


def _parse_lock(raw: str) -> Tuple[int, float, str]:
    parts = (raw or "").strip().split("|")
    pid = int(parts[0]) if parts and parts[0].isdigit() else 0
    ts = 0.0
    if len(parts) > 1:
        try:
            ts = float(parts[1])
        except Exception:
            ts = 0.0
    lbl = parts[2] if len(parts) > 2 else ""
    return pid, ts, lbl


def describe_lock(lock_path: Path, ttl_sec: int = 7200) -> dict:
    """
    Return a structured snapshot of the lock state for diagnostics.
    """
    now = time.time()
    try:
        max_age = int(os.getenv("BGL_SCENARIO_LOCK_MAX_AGE_SEC", "0") or 0)
    except Exception:
        max_age = 0
    info = {
        "path": str(lock_path),
        "exists": lock_path.exists(),
        "ttl_sec": ttl_sec,
        "max_age_sec": max_age or None,
    }
    if not lock_path.exists():
        return info
    try:
        raw = lock_path.read_text(encoding="utf-8").strip()
        pid, ts, lbl = _parse_lock(raw)
        pid_alive = bool(pid and _pid_alive(pid))
        age = None
        if ts:
            try:
                age = max(0.0, now - ts)
            except Exception:
                age = None
        status = "unknown"
        if pid and pid_alive and age is not None:
            if max_age and age > max_age:
                status = "active_stale"
            elif age < ttl_sec:
                status = "active_fresh"
            else:
                status = "active_stale"
        elif pid and not pid_alive:
            status = "stale_dead_pid"
        elif ts and age is not None and age < ttl_sec:
            status = "recent_lock"
        info.update(
            {
                "pid": pid,
                "pid_alive": pid_alive,
                "timestamp": ts or None,
                "age_sec": round(age, 2) if age is not None else None,
                "label": lbl,
                "status": status,
            }
        )
    except Exception:
        info["status"] = "unreadable"
    return info


def refresh_lock(lock_path: Path, label: str = "") -> bool:
    """
    Refresh an existing lock heartbeat if owned by this PID.
    Returns True if refreshed, False otherwise.
    """
    if not lock_path.exists():
        return False
    try:
        raw = lock_path.read_text(encoding="utf-8")
        pid, _, lbl = _parse_lock(raw)
        if pid != os.getpid():
            return False
        now = time.time()
        lock_path.write_text(f"{pid}|{now}|{label or lbl}", encoding="utf-8")
        return True
    except Exception:
        return False


def acquire_lock(lock_path: Path, ttl_sec: int = 7200, label: str = "") -> Tuple[bool, str]:
    """
    Try to acquire a lock file.
    Returns (ok, reason). If ok is False, reason explains the active/stale lock.
    """
    now = time.time()
    try:
        max_age = int(os.getenv("BGL_SCENARIO_LOCK_MAX_AGE_SEC", "0") or 0)
    except Exception:
        max_age = 0
    if lock_path.exists():
        try:
            raw = lock_path.read_text(encoding="utf-8").strip()
            pid, ts, _ = _parse_lock(raw)
            pid_alive = bool(pid and _pid_alive(pid))
            if pid_alive and ts and max_age and (now - ts) > max_age:
                # Treat as stale even if PID is alive (PID reuse or hung process).
                pid_alive = False
            if pid_alive:
                # Treat as active only if heartbeat is fresh; otherwise allow takeover.
                if ts and (now - ts) < ttl_sec:
                    return False, f"active_pid:{pid}"
            if not pid_alive and pid:
                # Stale lock from dead PID: allow takeover regardless of timestamp.
                ts = 0.0
            if ts and (now - ts) < ttl_sec:
                return False, "recent_lock"
        except Exception:
            # If lock is unreadable, treat as active to be safe.
            return False, "lock_unreadable"
        try:
            lock_path.unlink()
        except Exception:
            try:
                lock_path.write_text(f"{os.getpid()}|{now}|{label}", encoding="utf-8")
                return True, "acquired_overwrite"
            except Exception:
                return False, "lock_stale_unremovable"
    try:
        lock_path.parent.mkdir(parents=True, exist_ok=True)
        lock_path.write_text(f"{os.getpid()}|{now}|{label}", encoding="utf-8")
        return True, "acquired"
    except Exception:
        # Best-effort: proceed without lock but warn caller.
        return True, "acquired_without_lock"


def release_lock(lock_path: Path) -> None:
    try:
        if lock_path.exists():
            lock_path.unlink()
    except Exception:
        pass
