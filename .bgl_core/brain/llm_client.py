from __future__ import annotations

"""
llm_client.py
-------------
Unified local LLM client (Ollama-compatible) with:
- HOT/COLD/OFFLINE detection
- Warm-up (keep_alive) to reduce cold-start timeouts
- Simple JSON chat completion helper

This is intentionally lightweight and uses urllib.request to avoid extra deps.
"""

import json
import os
import time
import urllib.error
import urllib.request
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, Optional, Tuple

try:
    from .config_loader import load_config  # type: ignore
except Exception:
    try:
        from config_loader import load_config  # type: ignore
    except Exception:
        load_config = None  # type: ignore


def _swap_localhost(url: str) -> str:
    # Avoid Windows "localhost"/IPv6 resolution surprises by trying both variants.
    if "localhost" in url:
        return url.replace("localhost", "127.0.0.1")
    if "127.0.0.1" in url:
        return url.replace("127.0.0.1", "localhost")
    return url


def _normalize_urls(llm_base_url: str) -> Tuple[str, str]:
    """
    Returns (chat_url, base_api_url).
    chat_url: .../v1/chat/completions
    base_api_url: ... (no /v1/...) suitable for /api/* endpoints
    """
    u = (llm_base_url or "").strip()
    if not u:
        u = "http://127.0.0.1:11434/v1/chat/completions"

    # If the caller passed the host root (e.g. http://127.0.0.1:11434), normalize.
    if "/v1/" not in u and not u.rstrip("/").endswith("/v1/chat/completions"):
        u = u.rstrip("/") + "/v1/chat/completions"

    chat_url = u
    base_api = (
        u.replace("/v1/chat/completions", "")
        .replace("/v1/chat/completions/", "")
        .rstrip("/")
    )
    return chat_url, base_api


@dataclass
class LLMClientConfig:
    base_url: str
    model: str
    keep_alive: str = "5m"
    poll_interval_s: float = 2.0
    max_wait_s: float = 45.0
    cold_probe_timeout_s: float = 0.5
    warm_fire_timeout_s: float = 1.0
    chat_timeout_s: float = 60.0


class LLMClient:
    def __init__(self, cfg: Optional[LLMClientConfig] = None):
        cfg_dict: Dict[str, Any] = {}
        if load_config is not None:
            try:
                root = Path(__file__).resolve().parents[2]
                cfg_dict = load_config(root) or {}
            except Exception:
                cfg_dict = {}
        llm_cfg = cfg_dict.get("llm") if isinstance(cfg_dict.get("llm"), dict) else {}

        base = os.getenv(
            "LLM_BASE_URL",
            llm_cfg.get("base_url") if llm_cfg else cfg_dict.get("llm_base_url", "http://127.0.0.1:11434/v1/chat/completions"),
        )
        model = os.getenv(
            "LLM_MODEL",
            llm_cfg.get("model") if llm_cfg else cfg_dict.get("llm_model", "llama3.1:latest"),
        )

        # Allow tweaking time budgets without code changes.
        max_wait = float(
            os.getenv(
                "LLM_WARMUP_MAX_WAIT",
                llm_cfg.get("warmup_max_wait") if llm_cfg else cfg_dict.get("llm_warmup_max_wait", "45"),
            )
            or 45
        )
        poll = float(
            os.getenv(
                "LLM_WARMUP_POLL_S",
                llm_cfg.get("warmup_poll_s") if llm_cfg else cfg_dict.get("llm_warmup_poll_s", "2"),
            )
            or 2
        )
        chat_timeout = float(
            os.getenv(
                "LLM_CHAT_TIMEOUT",
                llm_cfg.get("chat_timeout") if llm_cfg else cfg_dict.get("llm_chat_timeout", "60"),
            )
            or 60
        )

        self.cfg = cfg or LLMClientConfig(
            base_url=base,
            model=model,
            max_wait_s=max_wait,
            poll_interval_s=poll,
            chat_timeout_s=chat_timeout,
        )
        self.chat_url, self.base_api = _normalize_urls(self.cfg.base_url)
        self.alt_chat_url, self.alt_base_api = _normalize_urls(_swap_localhost(self.chat_url))

    def _brain_state(self, chat_url: str) -> str:
        """
        Diagnose brain state using Ollama /api/ps:
        - HOT: at least one model loaded
        - COLD: service reachable but no models listed
        - OFFLINE: cannot reach endpoint
        """
        try:
            ps_url = chat_url.replace("/v1/chat/completions", "/api/ps")
            req = urllib.request.Request(ps_url)
            with urllib.request.urlopen(req, timeout=self.cfg.cold_probe_timeout_s) as resp:
                dat = json.loads(resp.read().decode())
            return "HOT" if dat.get("models") else "COLD"
        except Exception:
            return "OFFLINE"

    def state(self) -> str:
        # Try primary and alternate.
        s = self._brain_state(self.chat_url)
        if s != "OFFLINE":
            return s
        return self._brain_state(self.alt_chat_url)

    def _warm(self, base_api: str) -> bool:
        try:
            trigger_url = base_api.rstrip("/") + "/api/generate"
            payload = json.dumps(
                {"model": self.cfg.model, "prompt": "", "keep_alive": self.cfg.keep_alive}
            )
            req = urllib.request.Request(
                trigger_url,
                payload.encode(),
                {"Content-Type": "application/json"},
            )
            # This may block while loading; we only want to trigger it.
            try:
                urllib.request.urlopen(req, timeout=self.cfg.warm_fire_timeout_s)
            except (TimeoutError, urllib.error.URLError):
                pass
            return True
        except Exception:
            return False

    def ensure_hot(self) -> str:
        """
        Best-effort warm-up. Returns final state.
        """
        debug = os.getenv("LLM_DEBUG", "0") == "1"
        s = self.state()
        if debug:
            print(f"[*] LLMClient: state={s} model={self.cfg.model}")
        if s == "COLD":
            # Fire warm-up on both variants to maximize chances (Windows DNS quirks).
            self._warm(self.base_api)
            if self.alt_base_api != self.base_api:
                self._warm(self.alt_base_api)

            deadline = time.time() + self.cfg.max_wait_s
            while time.time() < deadline:
                s = self.state()
                if debug:
                    print(f"[*] LLMClient: state={s} waiting...")
                if s != "COLD":
                    break
                time.sleep(self.cfg.poll_interval_s)
        return s

    def chat_json(self, prompt: str, *, temperature: float = 0.0) -> Dict[str, Any]:
        """
        Chat completion expecting a JSON object in message content.
        Raises on network/parse errors; callers should handle fallback.
        """
        payload = {
            "model": self.cfg.model,
            "messages": [{"role": "user", "content": prompt}],
            "response_format": {"type": "json_object"},
            "temperature": float(temperature),
            "stream": False,
        }

        # Ensure the service is warmed before the expensive call.
        self.ensure_hot()

        def _call(url: str) -> Dict[str, Any]:
            req = urllib.request.Request(
                url,
                json.dumps(payload).encode(),
                {"Content-Type": "application/json"},
            )
            with urllib.request.urlopen(req, timeout=self.cfg.chat_timeout_s) as response:
                res = json.loads(response.read().decode())
            content = (
                (((res.get("choices") or [{}])[0]).get("message") or {}).get("content")
            )
            if not isinstance(content, str) or not content.strip():
                raise ValueError("LLM response missing choices[0].message.content")
            return json.loads(content)

        # Prefer primary url, then alternate.
        try:
            return _call(self.chat_url)
        except Exception:
            return _call(self.alt_chat_url)
