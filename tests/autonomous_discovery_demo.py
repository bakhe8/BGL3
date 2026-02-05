import sys
import json
import urllib.request
from pathlib import Path

# Add brain to path
sys.path.append(str(Path(__file__).resolve().parents[1] / ".bgl_core" / "brain"))
from embeddings import search as search_embeddings


def autonomous_discovery_demo(query):
    print(f"[*] Goal: Answer '{query}' without hardcoded file paths.")
    print("[*] Phase 1: Semantic Discovery (Vector Search)...")

    # Use the existing project infrastructure (knowledge.db)
    # This imitates the "Big Company" move of automated context retrieval
    results = search_embeddings(query, top_k=3)

    if not results:
        print("[!] No semantic results found. Falling back to Grep...")
        # (Simplified for demo)
        return "Couldn't find enough context automatically."

    print(f"[+] Found {len(results)} relevant items.")

    context = ""
    for label, score, text in results:
        print(f"  - Reading context from: {label} (Alignment: {score:.2f})")
        context += f"Context Source: {label}\n{text}\n\n"

    print("[*] Phase 2: Autonomous Synthesis...")

    prompt = f"""
I am the BGL3 AI Agent. I have autonomously retrieved the following context from the project knowledge base.
Please answer the user's question in Arabic using ONLY this context.

User Question: {query}

Context:
{context[:5000]}
"""

    payload = {
        "model": "qwen2.5-coder:7b",
        "prompt": prompt,
        "stream": False,
        "options": {"temperature": 0.1},
    }

    try:
        req = urllib.request.Request(
            "http://localhost:11434/api/generate",
            json.dumps(payload).encode(),
            {"Content-Type": "application/json"},
        )
        with urllib.request.urlopen(req, timeout=60) as response:
            res = json.loads(response.read().decode())
            print("\n" + "=" * 50)
            print("الرد الآلي (بدون تدخل بشري في اختيار الملفات):")
            print("=" * 50)
            print(res.get("response"))
            print("=" * 50)
    except Exception as e:
        print(f"[!] Synthesis failed: {e}")


if __name__ == "__main__":
    # Asking about something we haven't manually hardcoded paths for today
    autonomous_discovery_demo("ما هي آلية تمديد الضمان البنكي؟")
