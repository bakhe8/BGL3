import sys
import asyncio
from pathlib import Path
import io

# Force stdin/stdout encoding to utf-8 to handle Arabic text correctly
sys.stdin = io.TextIOWrapper(sys.stdin.buffer, encoding="utf-8")
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8")

# Path setup
sys.path.append(str(Path.cwd()))
try:
    from .bgl_core.brain.inference import ReasoningEngine  # type: ignore
    from .bgl_core.brain.brain_types import Intent, Context  # type: ignore
except ImportError:
    sys.path.append(str(Path.cwd() / ".bgl_core" / "brain"))
    from inference import ReasoningEngine  # type: ignore
    from brain_types import Intent, Context  # type: ignore


async def main():
    import argparse

    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--db",
        type=str,
        default=".bgl_core/brain/knowledge.db",
        help="Path to Brain DB",
    )
    parser.add_argument("--intent", type=str, default="observe", help="simulate intent")
    args = parser.parse_args()

    # Read prompt from stdin
    prompt = sys.stdin.read().strip()
    if not prompt:
        return

    # Map string to Intent Enum
    intent_map = {
        "observe": Intent.OBSERVE,
        "evolve": Intent.EVOLVE,
        "stabilize": Intent.STABILIZE,
    }
    intent_enum = intent_map.get(args.intent.lower(), Intent.OBSERVE)

    # Init engine
    db_path = Path(args.db)
    engine = ReasoningEngine(db_path)

    # Build Context
    context = Context(
        query_text=prompt,
        intent=intent_enum,
        env_state={"mode": "cli"},  # Not 'audit' unless we want it to be
    )

    # Run
    import contextlib

    try:
        # Suppress all stdout during reasoning to avoid audit pollution (e.g. tool output, logs)
        f = io.StringIO()
        with contextlib.redirect_stdout(f):
            plan = await engine.reason(context)

        # Output ONLY the text response for the audit to consume
        output = plan.get("response") or plan.get("expert_synthesis") or "No response."
        print(output)
    except Exception as e:
        # Don't throw to stderr, just print error as response so audit captures it
        print(f"Error executing brain: {e}")


if __name__ == "__main__":
    asyncio.run(main())
