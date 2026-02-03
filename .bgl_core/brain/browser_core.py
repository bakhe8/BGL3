"""
browser_core.py (Deprecated)
----------------------------
This module used to provide a separate Playwright wrapper.

We keep it as a thin compatibility layer to avoid maintaining multiple
competing browser abstractions.

Use `.bgl_core/brain/browser_sensor.py` (BrowserSensor) for scanning.
Use `.bgl_core/brain/browser_manager.py` (BrowserManager) for scenarios.
"""

from __future__ import annotations

from typing import Any, Dict

try:
    from .browser_sensor import BrowserSensor  # type: ignore
except Exception:
    from browser_sensor import BrowserSensor


class BrowserCore(BrowserSensor):
    """
    Compatibility wrapper around BrowserSensor.

    Historical API: navigate()
    Current API: scan_url()
    """

    async def navigate(
        self,
        path: str = "/",
        wait_until: str = "networkidle",
        timeout: int = 30000,
    ) -> Dict[str, Any]:
        # BrowserSensor currently uses networkidle/30s internally.
        # Keep params for callers, but delegate to scan_url.
        _ = wait_until, timeout
        return await self.scan_url(path, measure_perf=False)

