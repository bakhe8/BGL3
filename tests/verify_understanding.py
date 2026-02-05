import sys
import time
from pathlib import Path

# Add brain to path
sys.path.append(str(Path(__file__).resolve().parents[1] / ".bgl_core" / "brain"))
from llm_client import LLMClient


def autonomous_discovery_demo(query):
    print(f"[*] Goal: Answer '{query}' without hardcoded file paths.")
    print("[*] Phase 1: Discovery via Code Map...")

    code_map_path = Path(
        r"c:\Users\Bakheet\Documents\Projects\BGL3\.bgl_core\knowledge\code_map.md"
    )
    if not code_map_path.exists():
        print("[!] Code map not found. Please run scripts/index_codebase.py first.")
        return

    with open(code_map_path, "r", encoding="utf-8") as f:
        code_map = f.read()

    # Self-Discovery: The model explains which files are relevant based ONLY on the index
    discovery_prompt = f"""
I am an AI agent investigating BGL3. Based ON ONLY the following codebase map, 
which files should I read to understand '{query}'? 
Provide a comma-separated list of relative file paths.

Code Map:
{code_map[:8000]} ... (truncated)
"""
    client = LLMClient()
    print("[*] Agent is analyzing the codebase map to find target files...")
    discovery_res = client.chat_json(discovery_prompt, temperature=0.0)

    # We expect a response like {"files": "api/extend.php, app/Services/LetterBuilder.php"}
    # But let's handle string too
    files_to_read = []
    if isinstance(discovery_res, dict):
        files_str = discovery_res.get("files", "") or str(discovery_res)
    else:
        files_str = str(discovery_res)

    import re

    files_to_read = re.findall(r"[\w/\.]+\.(?:php|py)", files_str)

    print(f"[+] Agent decided to read: {files_to_read}")

    context = ""
    for rel_path in files_to_read[:3]:  # Limit to top 3 for speed
        abs_path = Path(r"c:\Users\Bakheet\Documents\Projects\BGL3") / rel_path
        if abs_path.exists():
            print(f"  - Reading context from: {rel_path}")
            with open(abs_path, "r", encoding="utf-8") as f:
                context += f"File: {rel_path}\n{f.read()[:2000]}\n\n"

    print("[*] Phase 2: Autonomous Synthesis...")


def verify_understanding():
    print("[*] Initializing LLMClient with qwen2.5-coder:7b...")
    client = LLMClient()

    # Read a core file for context
    file_path = (
        Path(__file__).resolve().parents[1] / ".bgl_core" / "brain" / "llm_client.py"
    )
    with open(file_path, "r", encoding="utf-8") as f:
        code_content = f.read()

    # Formulate a complex question about the system
    prompt = f"""
I am working on the BGL3 project. Here is the source code of 'llm_client.py'. 
Please explain:
1. What is the purpose of the 'ensure_hot' method?
2. How does the 'auto-warming' mechanism work to solve timeout issues?

Code:
{code_content[:2000]} ... (truncated for prompt)
"""

    print("[*] Sending analysis request to model...")
    start = time.time()
    try:
        # Use chat_json to get a structured meaningful response
        response = client.chat_json(prompt, temperature=0.1)
        elapsed = time.time() - start

        print(f"\n[+] Model Response (received in {elapsed:.1f}s):")
        print("-" * 50)
        # Handle the response which might be a dict or string depending on model behavior
        if isinstance(response, dict):
            for k, v in response.items():
                print(f"{k}: {v}")
        else:
            print(response)
        print("-" * 50)

    except Exception as e:
        print(f"[!] Error during verification: {e}")


if __name__ == "__main__":
    verify_understanding()
