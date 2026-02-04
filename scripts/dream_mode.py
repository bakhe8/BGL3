import argparse
import asyncio
import os
import sys
import time
import random
from pathlib import Path
import json
import hashlib

try:
    import psutil
except ImportError:
    psutil = None  # type: ignore

# Setup paths
sys.path.append(str(Path.cwd()))
try:
    from .bgl_core.brain.inference import ReasoningEngine
except ImportError:
    sys.path.append(str(Path.cwd() / ".bgl_core" / "brain"))
    from inference import ReasoningEngine


def discover_files(root_path: Path):
    """Recursively scans for relevant files while ignoring noise."""
    exclude_dirs = {
        "vendor",
        "storage",
        ".git",
        "node_modules",
        "auto_insights",
        "brain",
        ".bgl_core",
        ".mypy_cache",
        ".pytest_cache",
        "__pycache__",
    }
    exclude_files = {
        "code_map.md",
        "task.md",
        "implementation_plan.md",
        "walkthrough.md",
    }

    found = []
    for p in root_path.rglob("*"):
        # Skip excluded directories
        if any(ex in p.parts for ex in exclude_dirs):
            continue

        if p.is_file() and p.suffix in {".php", ".py", ".sql", ".md", ".json"}:
            if p.name not in exclude_files:
                found.append(str(p.relative_to(root_path)))
    return found


def purge_orphans(insights_dir: Path, root_path: Path):
    """Deletes insights that no longer have a corresponding source file."""
    if not insights_dir.exists():
        return

    purged_count = 0
    import re

    for insight_file in insights_dir.glob("*.insight.md"):
        try:
            content = insight_file.read_text(encoding="utf-8", errors="ignore")
            # Extract relative path from metadata
            match = re.search(r"\*\*Path\*\*: `(.+?)`", content)
            if match:
                rel_path = match.group(1)
                source_file = root_path / rel_path
                if not source_file.exists():
                    print(
                        f"🗑️ Purging orphan insight: {insight_file.name} (Source missing: {rel_path})"
                    )
                    insight_file.unlink()
                    purged_count += 1
        except Exception as e:
            print(f"⚠️ Error checking orphan {insight_file.name}: {e}")

    if purged_count > 0:
        print(f"✅ Purged {purged_count} orphan insights.")


async def dream_cycle(files_override=None, max_insights_override=None, sleep_seconds=2.0, source="dream"):
    print("🌙 Entering Dream Mode... (Autonomous Learning Night Shift)")
    engine = ReasoningEngine(Path("knowledge.db"))

    root_path = Path.cwd()
    insights_dir = root_path / ".bgl_core" / "knowledge" / "auto_insights"
    insights_dir.mkdir(parents=True, exist_ok=True)

    print("🔍 Scanning for architectural changes...")
    purge_orphans(insights_dir, root_path)
    if files_override:
        files_to_analyze = list(dict.fromkeys([str(f) for f in files_override if f]))
    else:
        files_to_analyze = discover_files(root_path)
        # Save Architecture Snapshot (only for full scans)
        arch_snapshot = root_path / ".bgl_core" / "knowledge" / "arch_state.json"
        arch_snapshot.write_text(json.dumps(files_to_analyze, indent=2), encoding="utf-8")

    # SAFETY LIMITS
    MAX_INSIGHTS = int(max_insights_override or 1000)  # Increased for 1GB quota
    MAX_STORAGE_BYTES = 1 * 1024 * 1024 * 1024  # 1 GB Limit
    generated_count = 0

    print(
        f"🔭 Found {len(files_to_analyze)} files. Safety Limit: {MAX_INSIGHTS} insights (1GB Quota). Source: {source}."
    )
    print("Press Ctrl+C to stop.")

    # Shuffle to analyze different parts of the system
    random.shuffle(files_to_analyze)

    # Load types
    try:
        from .bgl_core.brain.brain_types import Intent, Context
    except ImportError:
        sys.path.append(str(Path.cwd() / ".bgl_core" / "brain"))
        from brain_types import Intent, Context

    root_path = Path.cwd()

    def get_file_hash(path):
        hasher = hashlib.sha256()
        with open(path, "rb") as f:
            while chunk := f.read(8192):
                hasher.update(chunk)
        return hasher.hexdigest()

    for relative_path in files_to_analyze:
        # CHECK LIMITS
        if generated_count >= MAX_INSIGHTS:
            print("🛑 Safety Limit Reached. Sleeping until next restart.")
            break

        current_size = sum(
            f.stat().st_size for f in insights_dir.glob("**/*") if f.is_file()
        )
        if current_size > MAX_STORAGE_BYTES:
            print("💾 Disk Quota Exceeded for Insights. Stopping.")
            break

        full_path = root_path / relative_path
        if not full_path.exists():
            continue

        source_hash = get_file_hash(full_path)
        file_name = Path(relative_path).name
        insight_file = insights_dir / f"{file_name}.insight.md"

        if insight_file.exists():
            # Check for staleness
            existing_content = insight_file.read_text(encoding="utf-8")
            if f"Source-Hash: {source_hash}" in existing_content:
                continue  # Still valid
            else:
                print(f"🔄 Source changed for {file_name}. Regenerating insight...")

        print(f"💡 Dreaming about: {relative_path}...")

        try:
            # Construct Cognitive Context
            # We use Intent.OBSERVE to indicate analysis without direct code modification
            context = Context(
                query_text=(
                    f"Analyze {relative_path}. "
                    "1. Summarize purpose. "
                    "2. Identify business logic risks. "
                    "3. Flag security issues. "
                    "4. Suggest modernization."
                ),
                intent=Intent.OBSERVE,
                file_focus=relative_path,
                env_state={"mode": "dream"},
            )

            # Trigger the Brain
            plan = await engine.reason(context)

            # Extract Insight
            insight = plan.get("expert_synthesis") or plan.get(
                "response", "No insight generated."
            )

            # Save Insight with Integrity Metadata
            insight_content = (
                f"# Insight: {file_name}\n"
                f"**Path**: `{relative_path}`\n"
                f"**Source-Hash**: {source_hash}\n"
                f"**Date**: {time.strftime('%Y-%m-%d %H:%M:%S')}\n\n"
                f"{insight}"
            )
            insight_file.write_text(insight_content, encoding="utf-8")

            print(f"✨ Insight saved to {insight_file.name}")
            generated_count += 1

        except Exception as e:
            print(f"😴 Nightmare on {file_name}: {e}")

        # Sleep to process gently
        if sleep_seconds and sleep_seconds > 0:
            time.sleep(float(sleep_seconds))


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--files",
        nargs="*",
        default=None,
        help="Optional list of relative file paths to analyze (skips full scan).",
    )
    parser.add_argument(
        "--max",
        type=int,
        default=None,
        help="Maximum number of insights to generate in this run.",
    )
    parser.add_argument(
        "--sleep",
        type=float,
        default=2.0,
        help="Seconds to sleep between files.",
    )
    parser.add_argument(
        "--source",
        default="dream",
        help="Freeform source tag (e.g. exploration).",
    )
    args = parser.parse_args()

    # SINGLETON LOCK
    pid_file = Path(".bgl_core/logs/dream_mode.pid")
    pid_file.parent.mkdir(parents=True, exist_ok=True)

    if pid_file.exists():
        try:
            old_pid = int(pid_file.read_text())
            # Check if process is actually running using psutil if available
            is_running = False
            if psutil:
                if psutil.pid_exists(old_pid):
                    is_running = True
            else:
                # Weak check or assume running if file exists?
                # Better to assume stale if we can't check, OR just warn.
                # Standard practice: without psutil, we can't be sure.
                # Let's assume stale if we can't verify, to avoid deadlock.
                # Actually, os.kill(pid, 0) works on Unix, but on Windows it's tricky without psutil.
                # We'll just warn.
                print(f"⚠️ psutil missing, cannot verify PID {old_pid}. Assuming stale.")

            if is_running:
                print(f"🛑 Dream Mode is already running (PID: {old_pid}). Exiting.")
                sys.exit(0)
            elif psutil:  # Only print this if we actually checked
                print(f"⚠️ Found stale PID file ({old_pid}). Taking over.")
        except Exception:
            pass  # Corrupt file, ignore

    # Write current PID
    pid_file.write_text(str(os.getpid()))

    import atexit

    def cleanup():
        try:
            if pid_file.exists():
                pid_file.unlink()
        except Exception:
            pass

    atexit.register(cleanup)

    try:
        asyncio.run(
            dream_cycle(
                files_override=args.files,
                max_insights_override=args.max,
                sleep_seconds=args.sleep,
                source=args.source,
            )
        )
    except ImportError:
        # Fallback if psutil missing (though it shouldn't be)
        print("⚠️ psutil not found, singleton check weak.")
        asyncio.run(
            dream_cycle(
                files_override=args.files,
                max_insights_override=args.max,
                sleep_seconds=args.sleep,
                source=args.source,
            )
        )
    except KeyboardInterrupt:
        print("\n👋 Dream Mode stopped by user.")
