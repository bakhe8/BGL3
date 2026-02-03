import sys
import time
import subprocess
from pathlib import Path
import os

# Paths to watch
WATCH_DIRS = [
    Path("scripts"),
    Path(".bgl_core/brain"),
]


def get_file_mtimes():
    """Recursively get mtimes of all .py files in WATCH_DIRS."""
    mtimes = {}
    for d in WATCH_DIRS:
        root = Path.cwd() / d
        if not root.exists():
            continue
        for p in root.rglob("*.py"):
            try:
                mtimes[str(p)] = p.stat().st_mtime
            except FileNotFoundError:
                pass
    return mtimes


def main():
    print("🚀 BGL3 Dev Runner: Starting tool_server with Hot Reload...")
    print(f"👀 Watching: {', '.join([str(d) for d in WATCH_DIRS])}")

    server_script = Path("scripts/tool_server.py")
    process = None

    def start_server():
        nonlocal process
        print("\n[♻️] Starting server...")
        # Use the same python interpreter
        process = subprocess.Popen([sys.executable, str(server_script)])

    def stop_server():
        nonlocal process
        if process:
            print("[🛑] Stopping server...")
            process.terminate()
            try:
                process.wait(timeout=2)
            except subprocess.TimeoutExpired:
                process.kill()
            process = None

    start_server()
    last_mtimes = get_file_mtimes()

    try:
        while True:
            time.sleep(1)
            current_mtimes = get_file_mtimes()

            # Check for changes
            changed = False
            if len(current_mtimes) != len(last_mtimes):
                changed = True
            else:
                for f, mtime in current_mtimes.items():
                    if f not in last_mtimes or last_mtimes[f] != mtime:
                        changed = True
                        print(f"\n[📝] File changed: {Path(f).name}")
                        break

            if changed:
                stop_server()
                start_server()
                last_mtimes = current_mtimes

    except KeyboardInterrupt:
        print("\n[👋] Exiting Dev Runner.")
        stop_server()


if __name__ == "__main__":
    main()
