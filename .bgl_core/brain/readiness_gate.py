from __future__ import annotations

import json
import os
import socket
import time
import urllib.request
import urllib.error
from typing import Dict, Any, List, Tuple


def _swap_localhost(url: str) -> str:
    if "localhost" in url:
        return url.replace("localhost", "127.0.0.1")
    if "127.0.0.1" in url:
        return url.replace("127.0.0.1", "localhost")
    return url


def _http_check(urls: List[str], timeout_s: float = 4.0) -> Tuple[bool, str, str]:
    last_err = ""
    for url in urls:
        try:
            req = urllib.request.Request(url)
            with urllib.request.urlopen(req, timeout=timeout_s) as resp:
                code = resp.getcode()
                if code < 500:
                    return True, url, ""
                last_err = f"HTTP {code}"
        except Exception as e:
            last_err = str(e)
    return False, urls[0] if urls else "", last_err


def _port_check(port: int, hosts: List[str] | None = None) -> Tuple[bool, str]:
    if hosts is None:
        hosts = ["127.0.0.1", "::1"]
    last_err = ""
    for host in hosts:
        try:
            with socket.create_connection((host, port), timeout=2):
                return True, host
        except Exception as e:
            last_err = str(e)
            continue
    return False, last_err


def _ollama_tags(base_url: str) -> Tuple[bool, List[str], str]:
    try:
        with urllib.request.urlopen(base_url.rstrip("/") + "/api/tags", timeout=3) as r:
            data = json.loads(r.read().decode())
            models = [m.get("name") for m in data.get("models", []) if m.get("name")]
            return True, models, ""
    except Exception as e:
        return False, [], str(e)


def _ollama_warm(base_url: str, model: str) -> bool:
    try:
        payload = json.dumps({"model": model, "prompt": "", "keep_alive": "5m"})
        req = urllib.request.Request(
            base_url.rstrip("/") + "/api/generate",
            payload.encode(),
            {"Content-Type": "application/json"},
        )
        urllib.request.urlopen(req, timeout=1)
        return True
    except Exception:
        return False


def run_readiness(
    base_url: str,
    tool_port: int = 8891,
    llm_base_url: str | None = None,
    llm_model: str | None = None,
) -> Dict[str, Any]:
    """
    Readiness gate: checks HTTP base_url, tool_server port, and local LLM health.
    Returns a structured report without raising.
    """
    started = time.time()
    base_url = base_url.rstrip("/")
    alt_url = _swap_localhost(base_url)
    http_ok, http_url, http_err = _http_check([base_url + "/", alt_url + "/"])

    tool_ok, tool_detail = _port_check(tool_port)

    llm_base_url = llm_base_url or os.getenv(
        "LLM_BASE_URL", "http://127.0.0.1:11434/v1/chat/completions"
    )
    llm_base_api = llm_base_url.replace("/v1/chat/completions", "")
    llm_model = llm_model or os.getenv("LLM_MODEL", "llama3.1:latest")
    llm_ok, models, llm_err = _ollama_tags(llm_base_api)
    warmed = _ollama_warm(llm_base_api, llm_model) if llm_ok else False

    return {
        "ok": http_ok and tool_ok and llm_ok,
        "duration_ms": round((time.time() - started) * 1000, 1),
        "services": {
            "base_http": {
                "ok": http_ok,
                "url": http_url,
                "error": http_err,
            },
            "tool_server": {
                "ok": tool_ok,
                "detail": tool_detail,
                "port": tool_port,
            },
            "local_llm": {
                "ok": llm_ok,
                "base_url": llm_base_api,
                "model": llm_model,
                "models": models[:5],
                "warmed": warmed,
                "error": llm_err,
            },
        },
    }

