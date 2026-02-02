import asyncio
import sys
import json
from pathlib import Path
from unittest.mock import AsyncMock

# Add current dir to path to find .bgl_core
sys.path.append(str(Path.cwd()))

try:
    from .bgl_core.brain.inference import ReasoningEngine
except ImportError:
    sys.path.append(str(Path.cwd() / ".bgl_core" / "brain"))
    from inference import ReasoningEngine


async def main():
    print("Initializing Evolution Test (Widget Creation)...")
    engine = ReasoningEngine(Path("knowledge.db"))

    # Mock Browser
    mock_browser = AsyncMock()
    mock_browser.scan_url.return_value = {"summary": "Agent Dashboard"}
    engine.browser = mock_browser

    # The Agent's own suggestion
    question = "أضف أدوات بصرية جديدة للوحة التحكم (Charts, Alerts) كما اقترحت."
    target_url = "http://localhost:8000/agent-dashboard.php"

    print(f"Query: {question}")
    print("-" * 40)

    # Get the plan
    plan = await engine.chat(
        [{"role": "user", "content": question}], target_url=target_url
    )

    print(f"[*] Decision: {plan.get('action')}")

    if plan.get("action") == "WRITE_FILE":
        params = plan.get("params", {})
        path = params.get("path")
        content = params.get("content")

        print(f"[*] Targeting File: {path}")

        # Simulate Server Logic: Execute the write
        if path and content:
            full_path = Path.cwd() / path
            full_path.parent.mkdir(parents=True, exist_ok=True)
            full_path.write_text(content, encoding="utf-8")
            print(f"[+] SUCCESS: Wrote {len(content)} bytes to {full_path}")
            print("\n--- CONTENT GENERATED ---\n")
            print(content)
            print("\n-------------------------")
    else:
        print("[!] Agent did not choose to write a file.")
        print(plan)


if __name__ == "__main__":
    asyncio.run(main())
