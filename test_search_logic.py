import sqlite3
import asyncio
from pathlib import Path
import sys

# Setup paths to import from .bgl_core/brain
sys.path.append(str(Path.cwd() / ".bgl_core" / "brain"))
import embeddings


async def test_loop():
    print("--- 1. Checking for existing Insights ---")
    results = embeddings.search("[Insight]", top_k=10)
    print(f"Initial Search Results: {len(results)}")
    for label, score, text in results:
        print(f" - {label} (Score: {score:.2f}): {text[:50]}...")

    print("\n--- 2. Verifying Search by Keyword ---")
    # Search for something specific from the last indexed file (embeddings.py)
    keyword_results = embeddings.search("embedding cache sqlite", top_k=3)
    if keyword_results:
        print("Found relevant insight via semantic search:")
        for label, score, text in keyword_results:
            print(f" - {label} ({score:.2f}): {text[:100]}...")
    else:
        print("No relevant insight found for keyword 'embedding cache sqlite'.")


if __name__ == "__main__":
    asyncio.run(test_loop())
