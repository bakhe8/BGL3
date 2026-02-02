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
    print("Initializing Hallucination Test...")
    engine = ReasoningEngine(Path("knowledge.db"))

    # Mock Browser (user might be on a generic page)
    mock_browser = AsyncMock()
    mock_browser.scan_url.return_value = {"summary": "Dashboard Home"}
    engine.browser = mock_browser

    # Test 1: General Improvement (Hallucination Trigger)
    q1 = "اقترح لي تطوير في اي جزء من النظام الحالي"
    print(f"\nQuery 1: {q1}")
    r1 = await engine.chat([{"role": "user", "content": q1}])
    print(f"Response: {r1}\n")

    # Test 2: Lifecycle (Domain Knowledge Trigger)
    q2 = "ماهو مسار الضمان البنكي Life cycle في BGL3"
    print(f"\nQuery 2: {q2}")
    r2 = await engine.chat([{"role": "user", "content": q2}])
    print(f"Response: {r2}\n")


if __name__ == "__main__":
    asyncio.run(main())
