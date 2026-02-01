import sys
import os
from pathlib import Path
import json

# Add project root to path so we can import .bgl_core
# Add project root to path so we can import .bgl_core
ROOT_DIR = Path(__file__).parent.parent
sys.path.append(str(ROOT_DIR))

# Fix: Import directly from file path mechanism if package import fails
try:
    from .bgl_core.brain.inference import InferenceEngine
except ImportError:
    # Fallback: add brain dir to path
    sys.path.append(str(ROOT_DIR / ".bgl_core" / "brain"))
    from inference import InferenceEngine


def test_local_llm():
    print("--- Testing Local LLM Integration (Ollama) ---")

    # Initialize Engine (DB path doesn't matter for this test)
    engine = InferenceEngine(Path("dummy.db"))

    # Override ENV just in case (though code defaults to localhost)
    os.environ["LLM_MODEL"] = "llama3.1"

    prompt = "Explain why Model-View-Controller (MVC) is useful in one short sentence."
    print(f"Prompt: {prompt}")
    print("Sending request to localhost:11434...")

    try:
        response = engine._query_llm(prompt)

        if not response:
            print(
                "❌ No response received (Check if Ollama is running / Model downloaded)"
            )
            exit(1)

        content = response.get("choices", [{}])[0].get("message", {}).get("content", "")
        if content:
            print(f"\n✅ Success! Response:\n{content}")
            exit(0)
        else:
            print(f"❌ Received empty content: {response}")
            exit(1)

    except Exception as e:
        print(f"❌ Exception occurred: {e}")
        exit(1)


if __name__ == "__main__":
    test_local_llm()
