import asyncio
import sys
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
    print("Initializing Specific Query Test...")
    engine = ReasoningEngine(Path("knowledge.db"))

    # Mock Browser
    mock_browser = AsyncMock()
    mock_browser.scan_url.return_value = {"summary": "Agent Dashboard"}
    engine.browser = mock_browser

    # User's specific question
    target_url = "http://localhost:8000/agent-dashboard.php"
    question = "ماهو ملف agent-dashboard.php في المشروع ؟"

    print(f"Target URL: {target_url}")
    print(f"Query: {question}")
    print("-" * 40)

    response = await engine.chat(
        [{"role": "user", "content": question}], target_url=target_url
    )

    print("\n" + "=" * 20 + " RESPONSE " + "=" * 20)
    print(response)


if __name__ == "__main__":
    asyncio.run(main())
