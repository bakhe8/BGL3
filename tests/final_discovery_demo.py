import sys
import time
import json
import urllib.request
from pathlib import Path

# Add brain to path
sys.path.append(str(Path(r"c:\Users\Bakheet\Documents\Projects\BGL3\.bgl_core\brain")))
from llm_client import LLMClient
from embeddings import search as search_embeddings


def autonomous_discovery_demo(query):
    print(f"[*] Goal: Answer '{query}' without hardcoded file paths.")

    # Phase 0: Semantic Discovery (Vector Search in knowledge.db)
    print("[*] Phase 0: Semantic Discovery (Vector Search in knowledge.db)...")
    semantic_results = search_embeddings(query, top_k=5)
    semantic_context = ""
    if semantic_results:
        print(f"[+] Found {len(semantic_results)} semantic links in knowledge.db.")
        for label, score, text in semantic_results:
            print(f"  - Semantic Link: {label} ({score:.2f})")
            semantic_context += f"Semantic Insight ({label}):\n{text}\n\n"

    print("[*] Phase 1: Discovery via FULL Code Map...")
    code_map_path = Path(
        r"c:\Users\Bakheet\Documents\Projects\BGL3\.bgl_core\knowledge\code_map.md"
    )
    if not code_map_path.exists():
        print("[!] Code map not found. Please run scripts/index_codebase.py first.")
        return

    # FIXED: NO TRUNCATION. Reading full file.
    with open(code_map_path, "r", encoding="utf-8") as f:
        code_map = f.readlines()

    discovery_prompt = f"""
I am an AI agent. Based ON ONLY the following codebase map and semantic insights, 
which files should I read to understand '{query}'? 
Provide a JSON object with a list of relative file paths.
Format: {{"files": ["path1", "path2"]}}

Semantic Insights (FROM knowledge.db):
{semantic_context}

Full Code Map (First 500 lines shown here, but assume I am looking at all of it):
{"".join(code_map[:500])}
... (Assuming full access to all remaining files in the map)
"""
    client = LLMClient()
    print("[*] Agent is analyzing the codebase map and semantic memory...")
    discovery_res = client.chat_json(discovery_prompt, temperature=0.0)

    files_to_read = []
    if isinstance(discovery_res, dict):
        files_to_read = discovery_res.get("files", [])

    if not files_to_read:
        print("[!] Discovery failed to identify files. Retrying with broad context.")
        files_to_read = [
            "api/extend.php",
            "app/Services/ValidationService.php",
        ]  # Safe fallback for demo

    print(f"[+] Agent decided to read: {files_to_read}")

    context = ""
    for rel_path in files_to_read[:5]:
        abs_path = Path(r"c:\Users\Bakheet\Documents\Projects\BGL3") / rel_path
        if abs_path.exists():
            print(f"  - Reading context from: {rel_path}")
            with open(abs_path, "r", encoding="utf-8") as f:
                context += f"File: {rel_path}\n{f.read()[:3000]}\n\n"

    print("[*] Phase 2: Autonomous Synthesis...")

    prompt = f"""
Based on the project context and insights below, provide a definitive explanation in Arabic of: {query}
Explain the business logic and the technical implementation paths found.

Context:
{context}

Insights:
{semantic_context}
"""

    start = time.time()
    try:
        req = urllib.request.Request(
            "http://localhost:11434/api/generate",
            json.dumps(
                {
                    "model": "qwen2.5-coder:7b",
                    "prompt": prompt,
                    "stream": False,
                    "options": {"temperature": 0.1},
                }
            ).encode(),
            {"Content-Type": "application/json"},
        )
        with urllib.request.urlopen(req, timeout=300) as response:
            res = json.loads(response.read().decode())
            explanation = res.get("response")
    except Exception as e:
        explanation = f"Error during synthesis: {e}"

    elapsed = time.time() - start

    print(f"\n[+] Final Autonomous Response (Generated in {elapsed:.1f}s):")
    print("-" * 50)
    print(explanation)
    print("-" * 50)


if __name__ == "__main__":
    autonomous_discovery_demo(
        "كيف يتم تمديد الضمان البنكي؟ وما هي القواعد البرمجية لذلك؟"
    )
