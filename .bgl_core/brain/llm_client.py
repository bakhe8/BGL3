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
import threading

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
        # Read warmup settings from config or env
        llm_cfg = cfg_dict.get("llm", {})
        base = (
            os.getenv("LLM_BASE_URL") or str(llm_cfg.get("base_url", ""))
            if llm_cfg.get("base_url")
            else "http://localhost:11434"
        )

        # Smart model selection: qwen2.5-coder:7b default (best for 8GB GPU)
        default_model = "qwen2.5-coder:7b"  # High coding intelligence, fits in VRAM

        model = str(
            os.getenv("LLM_MODEL")
            if os.getenv("LLM_MODEL")
            else cfg_dict.get("llm_model", default_model),
        )

        # Allow tweaking time budgets without code changes.
        max_wait = float(
            os.getenv(
                "LLM_WARMUP_MAX_WAIT",
                str(llm_cfg.get("warmup_max_wait", "45"))
                if llm_cfg
                else cfg_dict.get("llm_warmup_max_wait", "45"),
            )
            or 45
        )
        poll = float(
            os.getenv(
                "LLM_WARMUP_POLL_S",
                str(llm_cfg.get("warmup_poll_s", "2"))
                if llm_cfg
                else cfg_dict.get("llm_warmup_poll_s", "2"),
            )
            or 2
        )
        chat_timeout = float(
            os.getenv(
                "LLM_CHAT_TIMEOUT",
                str(llm_cfg.get("chat_timeout", "60"))
                if llm_cfg
                else cfg_dict.get("llm_chat_timeout", "60"),
            )
            or 60
        )

        self.cfg = cfg or LLMClientConfig(
            base_url=str(base or "http://localhost:11434"),
            model=str(model or "qwen2.5-coder:7b"),  # Best fit for 8GB GPU
            max_wait_s=max_wait,
            poll_interval_s=poll,
            chat_timeout_s=chat_timeout,
            warm_fire_timeout_s=45,
            cold_probe_timeout_s=2,
            keep_alive="5m",  # Keep model in memory for 5 minutes
        )
        self.chat_url, self.base_api = _normalize_urls(self.cfg.base_url)
        self.alt_chat_url, self.alt_base_api = _normalize_urls(
            _swap_localhost(self.chat_url)
        )

        # ROOT CAUSE FIX: Auto-warm model on initialization
        # Eliminates 25s lazy-loading delay by warming in background!
        self._auto_warm_in_background()

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
            with urllib.request.urlopen(
                req, timeout=self.cfg.cold_probe_timeout_s
            ) as resp:
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
                {
                    "model": self.cfg.model,
                    "prompt": "",
                    "keep_alive": self.cfg.keep_alive,
                }
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

    def _auto_warm_in_background(self) -> None:
        """
        ROOT CAUSE FIX: Background model warming.

        Problem: Ollama lazy-loads models (takes 20-30s).
        Solution: Warm model immediately on LLMClient creation in background thread.

        Benefits:
        - First chat_json() call is instant (model already loaded)
        - No manual 'ollama run' needed
        - No 25s wait on first use
        - User doesn't notice loading time
        """

        def _warm_worker():
            try:
                s = self.state()
                if s == "COLD":
                    # Fire warmup request
                    self._warm(self.base_api)
                    if self.alt_base_api != self.base_api:
                        self._warm(self.alt_base_api)
            except Exception:
                # Silent fail - don't block initialization
                pass

        # Run in daemon thread so it doesn't block app exit
        thread = threading.Thread(target=_warm_worker, daemon=True)
        thread.start()

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

            # FIXED: Model loading takes 20-30s on first use - allow adequate time!
            max_wait = min(self.cfg.max_wait_s, 45)  # 45s instead of 15s
            deadline = time.time() + max_wait
            attempts = 0
            max_retries = 5  # More patient retries

            while time.time() < deadline and attempts < max_retries:
                s = self.state()
                if debug:
                    print(
                        f"[*] LLMClient: state={s} attempt={attempts + 1}/{max_retries}"
                    )
                if s != "COLD":
                    break
                time.sleep(self.cfg.poll_interval_s)
                attempts += 1

            # Raise timeout exception if service didn't warm up
            if s == "COLD":
                raise TimeoutError(
                    f"LLM failed to warm up after {attempts} attempts ({max_wait}s). "
                    f"Is Ollama running? Try: ollama serve"
                )
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
            with urllib.request.urlopen(
                req, timeout=self.cfg.chat_timeout_s
            ) as response:
                res = json.loads(response.read().decode())
            content = (((res.get("choices") or [{}])[0]).get("message") or {}).get(
                "content"
            )
            if not isinstance(content, str) or not content.strip():
                raise ValueError("LLM response missing choices[0].message.content")
            return json.loads(content)

        # Prefer primary url, then alternate.
        try:
            return _call(self.chat_url)
        except Exception:
            return _call(self.alt_chat_url)
