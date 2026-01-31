import asyncio
import os
import time
from pathlib import Path
from typing import Any, Dict, Optional

from playwright.async_api import async_playwright, Browser, BrowserContext, Page


class BrowserCore:
    """
    Unified browser manager + sensor wrapper.
    - Single browser/context/page
    - Heartbeat with auto-restart
    - Queue via asyncio.Lock
    """

    def __init__(
        self,
        base_url: str = "http://localhost:8000",
        headless: bool = True,
        keep_page: bool = True,
        max_idle_seconds: int = 120,
        cpu_max_percent: float = None,
        ram_min_gb: float = None,
        capture_har: bool = None,
    ):
        self.base_url = base_url
        self.headless = headless
        self.keep_page = keep_page
        self.max_idle = max_idle_seconds
        self._playwright = None
        self._browser: Optional[Browser] = None
        self._context: Optional[BrowserContext] = None
        self._page: Optional[Page] = None
        self._lock = asyncio.Lock()
        self._last_use = 0.0
        self.cpu_max = cpu_max_percent if cpu_max_percent is not None else self._env_float("BGL_BROWSER_CPU_MAX", None)
        self.ram_min = ram_min_gb if ram_min_gb is not None else self._env_float("BGL_BROWSER_RAM_MIN_GB", None)
        self.capture_har = bool(int(os.getenv("BGL_CAPTURE_HAR", str(1 if capture_har else 0)))) if capture_har is None else capture_har

    def _env_float(self, key: str, default):
        try:
            val = os.getenv(key)
            return float(val) if val is not None else default
        except Exception:
            return default

    async def start(self):
        if not self._playwright:
            self._playwright = await async_playwright().start()
        if not self._browser:
            self._browser = await self._playwright.chromium.launch(headless=self.headless)
        if not self._context:
            self._context = await self._browser.new_context(base_url=self.base_url)
        if not self._page or self._page.is_closed():
            self._page = await self._context.new_page()
        self._last_use = time.time()

    async def stop(self):
        try:
            if self._page and not self._page.is_closed():
                await self._page.close()
        except Exception:
            pass
        try:
            if self._context:
                await self._context.close()
        except Exception:
            pass
        try:
            if self._browser:
                await self._browser.close()
        except Exception:
            pass
        try:
            if self._playwright:
                await self._playwright.stop()
        except Exception:
            pass
        self._browser = None
        self._context = None
        self._page = None
        self._playwright = None

    async def heartbeat(self):
        """Restart browser if idle too long or closed."""
        now = time.time()
        if self._page and not self._page.is_closed() and (now - self._last_use) < self.max_idle:
            return
        await self.restart()

    async def restart(self):
        await self.stop()
        await self.start()

    async def navigate(self, path: str = "/", wait_until: str = "networkidle", timeout: int = 30000) -> Dict[str, Any]:
        async with self._lock:
            await self.start()
            await self.heartbeat()
            report: Dict[str, Any] = {
                "status": "SUCCESS",
                "url": f"{self.base_url}{path}",
                "console_errors": [],
                "network_failures": [],
            }
            if self._resource_overloaded():
                report["status"] = "SKIPPED"
                report["error_message"] = "Skipped navigation due to resource threshold"
                return report
            page = self._page
            assert page

            trace_path = None
            if self.capture_har and self._context:
                try:
                    traces_dir = Path(".bgl_core/logs/traces")
                    traces_dir.mkdir(parents=True, exist_ok=True)
                    trace_path = traces_dir / f"trace_{int(time.time())}.zip"
                    await self._context.tracing.start(screenshots=True, snapshots=True, sources=False)
                except Exception:
                    trace_path = None

            # Ensure handlers exist once
            if not getattr(page, "_bgl_console_hook", False):
                page.on(
                    "console",
                    lambda msg: report["console_errors"].append(msg.text)  # type: ignore
                    if msg.type == "error"
                    else None,
                )
                page._bgl_console_hook = True  # type: ignore

            if not getattr(page, "_bgl_requestfailed_hook", False):
                async def handle_request_failed(request):
                    fail = request.failure
                    if isinstance(fail, str):
                        error_msg = fail
                    elif hasattr(fail, "error"):
                        error_msg = fail.error
                    else:
                        error_msg = "Unknown Error"
                    report["network_failures"].append({"url": request.url, "error": error_msg})  # type: ignore

                page.on("requestfailed", handle_request_failed)
                page._bgl_requestfailed_hook = True  # type: ignore

            try:
                await page.goto("about:blank", wait_until="domcontentloaded")
                response = await page.goto(f"{self.base_url}{path}", wait_until=wait_until, timeout=timeout)
                if not response or response.status >= 400:
                    report["status"] = "FAILED"
                    report["http_status"] = response.status if response else "NO_RESPONSE"
            except Exception as e:
                report["status"] = "ERROR"
                report["error_message"] = str(e)
            finally:
                if self.capture_har and self._context and trace_path:
                    try:
                        await self._context.tracing.stop(path=str(trace_path))
                        report["trace_path"] = str(trace_path)
                    except Exception:
                        pass
                if report.get("status") != "SUCCESS":
                    try:
                        snap_dir = Path(".bgl_core/logs/traces")
                        snap_dir.mkdir(parents=True, exist_ok=True)
                        snap_path = snap_dir / f"fail_{int(time.time())}.png"
                        await page.screenshot(path=str(snap_path))
                        report["screenshot"] = str(snap_path)
                    except Exception:
                        pass
                self._last_use = time.time()
                if not self.keep_page:
                    try:
                        await page.close()
                    except Exception:
                        pass
                    self._page = None
        return report

    def _resource_overloaded(self) -> bool:
        """
        Dynamic guard:
        - If thresholds provided via env/ctor: use them.
        - Otherwise: skip navigation only إذا كان CPU idle منخفض جداً (<10%) أو الذاكرة الحرة < 0.5 GB.
        """
        try:
            import psutil  # type: ignore
            cpu = psutil.cpu_percent(interval=0.1)
            mem = psutil.virtual_memory()
            avail_gb = mem.available / (1024 ** 3)

            # explicit thresholds
            if self.cpu_max is not None and cpu > self.cpu_max:
                return True
            if self.ram_min is not None and avail_gb < self.ram_min:
                return True

            # dynamic defaults
            if cpu > 90 and avail_gb < 0.5:
                return True
        except Exception:
            return False
        return False


async def _main_test():
    core = BrowserCore()
    await core.start()
    res = await core.navigate("/")
    print(res["status"])
    await core.stop()


if __name__ == "__main__":
    asyncio.run(_main_test())
