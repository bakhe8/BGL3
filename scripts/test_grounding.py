import asyncio
import os
import sys
import json
from pathlib import Path

# Add core to sys.path
ROOT = Path(__file__).resolve().parents[1]
sys.path.append(str(ROOT / ".bgl_core" / "brain"))

from inference import ReasoningEngine


async def test_grounding():
    print("[*] Testing Grounding logic...")
    # Mock database path
    db_path = ROOT / ".bgl_core" / "brain" / "knowledge.db"

    from browser_sensor import BrowserSensor

    browser = BrowserSensor()
    engine = ReasoningEngine(db_path, browser_sensor=browser)

    messages = [
        {
            "role": "user",
            "content": "في اعلى الواجه زر للدفعات .. ما الفرق بين دفعه مغلقه او مفتوحه ؟ ومتى استطيع طباعات الدفعه كامله",
        }
    ]

    print("[*] Sending grounded chat request with UI context...")
    # Using the local project URL (assuming record view)
    response = await engine.chat(messages, target_url="http://localhost:8000/index.php")

    print("\n--- AGENT RESPONSE ---\n")
    print(response)
    print("\n--- END RESPONSE ---\n")


if __name__ == "__main__":
    asyncio.run(test_grounding())
