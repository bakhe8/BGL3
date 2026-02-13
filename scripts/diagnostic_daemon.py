import os
import sys
import time
import subprocess
from argparse import ArgumentParser
from pathlib import Path


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


def _run_once(profile: str | None) -> int:
    env = os.environ.copy()
    env["BGL_RUN_SOURCE"] = "diagnostic_daemon"
    env["BGL_RUN_TRIGGER"] = "diagnostic_daemon"
    if profile:
        env["BGL_DIAGNOSTIC_PROFILE"] = profile
    cmd = [_preferred_python(), str(ROOT / ".bgl_core" / "brain" / "master_verify.py")]
    kwargs = {"cwd": str(ROOT), "env": env}
    if os.name == "nt":
        kwargs["creationflags"] = 0x08000000  # CREATE_NO_WINDOW
    return subprocess.call(cmd, **kwargs)


def main() -> int:
    ap = ArgumentParser()
    ap.add_argument("--interval", type=int, default=900, help="Seconds between runs.")
    ap.add_argument("--profile", type=str, default="", help="Override diagnostic profile (fast|medium|full).")
    ap.add_argument("--once", action="store_true", help="Run a single diagnostic and exit.")
    args = ap.parse_args()

    profile = args.profile.strip().lower() or None
    interval = max(60, int(args.interval))

    while True:
        _run_once(profile)
        if args.once:
            return 0
        time.sleep(interval)


if __name__ == "__main__":
    raise SystemExit(main())
