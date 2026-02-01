import sys
from pathlib import Path
import json

# Add project root
ROOT_DIR = Path(__file__).parent.parent
sys.path.append(str(ROOT_DIR))

# Fix: Import directly from file path mechanism if package import fails
try:
    from .bgl_core.brain.inference import InferenceEngine
except ImportError:
    # Fallback: add brain dir to path
    sys.path.append(str(ROOT_DIR / ".bgl_core" / "brain"))
    from inference import InferenceEngine


def debug():
    engine = InferenceEngine(Path(".bgl_core/brain/knowledge.db"))

    items = [
        {
            "reason": "Critical decoherence detected in the subspace emitter array during startup.",
            "task_name": "Quantum_Flux_Module",
        },
        {
            "reason": "Subspace emitter failed to stabilize harmonic resonance frequency.",
            "task_name": "Quantum_Flux_Module",
        },
    ]

    print("--- Debugging Local LLM Response ---")

    # Mocking the call inside _analyze_complex_cluster to see raw output
    prompt = "Analyze these software errors and propose a single architectural mitigation rule (JSON format with name, description, action=WARN/BLOCK, impact, solution, expectation):\n"
    for item in items:
        prompt += f"- {item['reason']} (Task: {item['task_name']})\n"

    print(f"Sending Prompt to {engine.local_llm_url}...")
    resp = engine._query_llm(prompt)

    print("\n--- RAW RESPONSE ---")
    print(json.dumps(resp, indent=2))

    content = resp.get("choices", [{}])[0].get("message", {}).get("content", "")
    print("\n--- CONTENT ---")
    print(content)

    # Try parsing
    try:
        json_str = content.replace("```json", "").replace("```", "").strip()
        rule = json.loads(json_str)
        print("\n--- PARSED JSON ---")
        print(rule)
    except Exception as e:
        print(f"\n❌ JSON Parsing Failed: {e}")


if __name__ == "__main__":
    debug()
