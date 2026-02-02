import asyncio
import os
import sys
from pathlib import Path
from .bgl_core.brain.inference import ReasoningEngine
from .bgl_core.brain.browser_sensor import BrowserSensor


async def main():
    # Initialize Engine
    engine = ReasoningEngine(Path(".bgl_core/brain/knowledge.db"))

    # Initialize Browser (optional for this raw test)
    browser = BrowserSensor("http://localhost:8000")
    engine.browser = browser

    print("-" * 50)
    print("🤖 BGL3 AGENT RAW INTERFACE")
    print("-" * 50)

    # Get user question from command line or prompt
    if len(sys.argv) > 1:
        user_query = " ".join(sys.argv[1:])
    else:
        user_query = input("Enter your raw query for the agent: ")

    print(f"[*] Processing query: {user_query}")

    messages = [{"role": "user", "content": user_query}]

    # Target index.php for visual grounding
    response = await engine.chat(messages, target_url="http://localhost:8000/index.php")

    print("\n" + "=" * 20 + " AGENT RESPONSE " + "=" * 20)
    print(response)
    print("=" * 56 + "\n")


if __name__ == "__main__":
    asyncio.run(main())
