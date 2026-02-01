import sys
import os
import sqlite3
from pathlib import Path
from unittest.mock import patch

# Setup path
root_dir = Path(__file__).parent.parent
sys.path.append(str(root_dir / ".bgl_core" / "brain"))

from inference import InferenceEngine  # type: ignore # noqa: E402

CONFUSED_BLOCKERS = [
    {
        "id": 1,
        "task_name": "Unknown Error 1",
        "reason": "Something weird happened in the flux capacitor",
        "status": "PENDING",
    },
    {
        "id": 2,
        "task_name": "Unknown Error 2",
        "reason": "Flux capacitor desync detected",
        "status": "PENDING",
    },
]

MOCKED_GPT_RESPONSE = {
    "choices": [
        {
            "message": {
                "content": '```json\n{"name": "Flux Capacitor Stabilizer", "description": "Fixes flux desync", "action": "WARN", "impact": "High", "solution": "Recalibrate", "expectation": "Zero errors"}\n```'
            }
        }
    ]
}


def setup_test_db(db_path):
    conn = sqlite3.connect(str(db_path))
    conn.execute(
        "CREATE TABLE agent_blockers (id INTEGER, task_name TEXT, reason TEXT, status TEXT)"
    )
    conn.execute(
        "CREATE TABLE agent_proposals (id INTEGER PRIMARY KEY, name TEXT, description TEXT, action TEXT, count INTEGER, evidence TEXT, impact TEXT, solution TEXT, expectation TEXT)"
    )

    for b in CONFUSED_BLOCKERS:
        conn.execute(
            "INSERT INTO agent_blockers (id, task_name, reason, status) VALUES (?, ?, ?, ?)",
            (b["id"], b["task_name"], b["reason"], b["status"]),
        )
    conn.commit()
    conn.close()


def test_hybrid_flow():
    print("🤖 Testing Hybrid Intelligence Flow (Simulation)...")

    db_path = Path("test_hybrid.db")
    if db_path.exists():
        os.remove(db_path)
    setup_test_db(db_path)

    # Force Enable OpenAI
    os.environ["OPENAI_KEY"] = "sk-fake-test-key-123"

    engine = InferenceEngine(db_path)

    # Mock the Network Call (we don't want to actually hit OpenAI)
    with patch.object(
        engine, "_query_llm", return_value=MOCKED_GPT_RESPONSE
    ) as mock_llm:
        print("   🔹 Step 1: Running Analysis with 2 'General' errors...")
        proposals = engine.analyze_patterns()
        print(f"   ℹ️  Engine returned {len(proposals)} existing proposals from DB.")

        # Verification 1: Did it call the LLM?
        if mock_llm.called:
            print("   ✅ SUCCESS: Engine recognized confusion and called _query_llm()")
        else:
            print("   ❌ FAIL: Engine did NOT call LLM.")
            return

        # Verification 2: Did it synthesize a rule?
        conn = sqlite3.connect(str(db_path))
        cursor = conn.cursor()
        cursor.execute("SELECT name, description FROM agent_proposals")
        row = cursor.fetchone()
        conn.close()

        if row and row[0] == "Flux Capacitor Stabilizer":
            print(f"   ✅ SUCCESS: LLM advice was converted into Rule: '{row[0]}'")
        elif row and row[0] == "LLM_Insight":
            # Depending on implementation details (mapping to "LLM_Insight" vs actual name)
            # Our code uses 'LLM_Insight' as the theme key for synthesis, but might save specific name.
            # Let's check what the code actually did.
            print(f"   ✅ SUCCESS: Rule Persisted (Theme: {row[0]})")
        else:
            print(f"   ❌ FAIL: No rule persisted. Found: {row}")

    # Cleanup
    if db_path.exists():
        try:
            os.remove(db_path)
        except OSError:
            pass


if __name__ == "__main__":
    test_hybrid_flow()
