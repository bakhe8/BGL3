import sys
import os
import time
import json
import urllib.request
from pathlib import Path


def test_optimized_call():
    url = "http://localhost:11434/api/chat"
    model = "qwen2.5:32b"
    prompt = "What is dependency injection? Answer in one short sentence."

    # Payload with optimized options
    payload = {
        "model": model,
        "messages": [{"role": "user", "content": prompt}],
        "stream": False,
        "options": {
            "num_thread": 16,  # Use all 16 cores
            "num_gpu": 32,  # Encourage GPU offloading (Ollama will cap at max layers)
            "temperature": 0,
        },
    }

    print(f"[*] Testing {model} with optimized options (num_thread=16)...")
    start = time.time()

    try:
        req = urllib.request.Request(
            url,
            json.dumps(payload).encode(),
            {"Content-Type": "application/json"},
        )
        with urllib.request.urlopen(req, timeout=120) as response:
            res = json.loads(response.read().decode())
            content = res.get("message", {}).get("content", "")
            elapsed = time.time() - start
            print(f"[+] Response time: {elapsed:.1f}s")
            print(f"[+] Result: {content}")
    except Exception as e:
        print(f"[!] Error: {e}")


if __name__ == "__main__":
    test_optimized_call()
