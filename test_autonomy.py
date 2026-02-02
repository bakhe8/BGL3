import asyncio
import sys
import os
from pathlib import Path
from unittest.mock import AsyncMock

# Add current dir to path to find .bgl_core
sys.path.append(str(Path.cwd()))

try:
    from .bgl_core.brain.inference import ReasoningEngine
except ImportError:
    # Fallback if relative import fails
    sys.path.append(str(Path.cwd() / ".bgl_core" / "brain"))
    from inference import ReasoningEngine


async def main():
    print("Initializing Autonomous Reasoning Test...")
    engine = ReasoningEngine(Path("knowledge.db"))

    # Mock Browser to trigger the code path in inference.py
    mock_browser = AsyncMock()
    mock_browser.scan_url.return_value = {"summary": "Mock visual scan"}
    engine.browser = mock_browser

    target_url = "http://localhost:8000/views/batches.php"
    question = "كيف يميز النظام بين الدفعات المفتوحة والمغلقة؟ اشرح المنطق من الكود."

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
