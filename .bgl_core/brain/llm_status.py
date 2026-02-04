from __future__ import annotations

import argparse
import json
import time
from pathlib import Path

from llm_client import LLMClient


def write_status(state: str, client: LLMClient, status_path: Path) -> dict:
    data = {
        "timestamp": time.time(),
        "state": state,
        "base_url": client.cfg.base_url,
        "model": client.cfg.model,
    }
    status_path.parent.mkdir(parents=True, exist_ok=True)
    status_path.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")
    return data


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--warm", action="store_true", help="Warm up the LLM before reporting status")
    args = parser.parse_args()

    client = LLMClient()
    if args.warm:
        state = client.ensure_hot()
    else:
        state = client.state()

    root = Path(__file__).resolve().parents[2]
    status_path = root / ".bgl_core" / "logs" / "llm_status.json"
    write_status(state, client, status_path)
    print(json.dumps({"status": "ok", "state": state}, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
