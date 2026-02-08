"""
Lightweight watchdog to keep tool_server alive.

Usage:
    python scripts/tool_watchdog.py --port 8891
"""

from __future__ import annotations

import json
import os
import sys
import time
import subprocess
from argparse import ArgumentParser
from pathlib import Path
import urllib.request
import urllib.error


ROOT = Path(__file__).resolve().parents[1]

def _preferred_python() -> str:
    candidates = [
        ROOT / ".bgl_core" / ".venv312" / "Scripts" / "python.exe",
        ROOT / ".bgl_core" / ".venv" / "Scripts" / "python.exe",
        ROOT / ".bgl_core" / ".venv312" / "bin" / "python",
        ROOT / ".bgl_core" / ".venv" / "bin" / "python",
    ]
    for cand in candidates:
        if cand.exists():
            return str(cand)
    return sys.executable


def _ping(port: int, timeout_s: float = 2.0) -> bool:
    url = f"http://127.0.0.1:{port}/health"
    req = urllib.request.Request(url, method="GET")
    try:
        with urllib.request.urlopen(req, timeout=timeout_s) as resp:
            return resp.getcode() == 200
    except Exception:
        return False


def _spawn_tool_server(port: int) -> None:
    cmd = [_preferred_python(), str(ROOT / "scripts" / "tool_server.py"), "--port", str(port)]
    kwargs = {"cwd": str(ROOT)}
    if os.name == "nt":
        # CREATE_NO_WINDOW (0x08000000)
        kwargs["creationflags"] = 0x08000000
    subprocess.Popen(cmd, **kwargs)

def _kill_hung_tool_server() -> None:
    if os.name != "nt":
        return
    # Find any tool_server.py processes and terminate them (best-effort).
    ps = (
        "Get-CimInstance Win32_Process | "
        "Where-Object { $_.CommandLine -match 'tool_server\\.py' } | "
        "Select-Object -ExpandProperty ProcessId"
    )
    try:
        out = subprocess.check_output(
            ["powershell", "-NoProfile", "-Command", ps],
            text=True,
            stderr=subprocess.DEVNULL,
        )
        pids = [p.strip() for p in out.splitlines() if p.strip().isdigit()]
        for pid in pids:
            subprocess.call(
                ["taskkill", "/F", "/PID", pid],
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
            )
    except Exception:
        return


def main() -> int:
    ap = ArgumentParser()
    ap.add_argument("--port", type=int, default=8891)
    ap.add_argument("--interval", type=float, default=2.0)
    args = ap.parse_args()

    port = int(args.port)
    interval = max(0.8, float(args.interval))
    fail_count = 0
    while True:
        if _ping(port):
            fail_count = 0
        else:
            fail_count += 1
            if fail_count >= 3:
                _kill_hung_tool_server()
                _spawn_tool_server(port)
                fail_count = 0
                time.sleep(1.0)
        time.sleep(interval)


if __name__ == "__main__":
    raise SystemExit(main())
