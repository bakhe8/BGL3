import asyncio
import json
import os
import time
from playwright.async_api import async_playwright
from pathlib import Path
from typing import Dict, Any, Optional


class BrowserSensor:
    _playwright: Any = None
    _browser: Any = None
    _context: Any = None
    _page: Any = None
    _lock = asyncio.Lock()

    def __init__(
        self,
        base_url: str = "http://localhost:8000",
        headless: bool = True,
        project_root: Optional[Path] = None,
        capture_har: bool = False,
        capture_failures: bool = True,
    ):
        self.base_url = base_url
        self.headless = headless
        root = Path(project_root) if project_root else Path(os.getcwd())
        self.reports_dir = root / ".bgl_core" / "logs" / "browser_reports"
        self.reports_dir.mkdir(parents=True, exist_ok=True)
        self.capture_har = capture_har
        self.capture_failures = capture_failures
        self.status_file = self.reports_dir / "browser_status.json"

    async def _ensure_browser(self):
        if not BrowserSensor._playwright:
            BrowserSensor._playwright = await async_playwright().start()
        if not BrowserSensor._browser:
            BrowserSensor._browser = await BrowserSensor._playwright.chromium.launch(
                headless=self.headless
            )
        if not BrowserSensor._context:
            BrowserSensor._context = await BrowserSensor._browser.new_context(
                base_url=self.base_url
            )
        if not BrowserSensor._page or BrowserSensor._page.is_closed():
            BrowserSensor._page = await BrowserSensor._context.new_page()

    async def scan_url(
        self, path: str = "/", measure_perf: bool = False
    ) -> Dict[str, Any]:
        """
        Scans a specific URL for frontend errors (Console and Network).
        If measure_perf is True, captures timing data and identifies JS hotspots.
        """
        if path.startswith("http"):
            target_url = path
        else:
            target_url = f"{self.base_url}{path}"

        if measure_perf:
            separator = "&" if "?" in target_url else "?"
            target_url += f"{separator}measure_perf=1"

        report: Dict[str, Any] = {
            "url": target_url,
            "status": "SUCCESS",
            "console_errors": [],
            "network_failures": [],
            "performance": None,
            "screenshot_path": None,
        }

        await self._ensure_browser()
        # Serialize all scans to a single page to avoid window/tab spam
        async with BrowserSensor._lock:
            start_ts = time.time()
            self._write_status(busy=True, url=target_url, started=start_ts)

            # Reuse singleton page for all scans (avoid multiple tabs/windows)
            context_kwargs: Dict[str, Any] = {}
            har_path = None
            if self.capture_har:
                har_path = self.reports_dir / f"har_{int(time.time())}.har"
                context_kwargs = {
                    "record_har_path": str(har_path),
                    "record_har_mode": "minimal",
                }

            # If HAR requested, open a temporary context; otherwise reuse shared page
            if context_kwargs:
                assert BrowserSensor._browser is not None
                context = await BrowserSensor._browser.new_context(**context_kwargs)
                page = await context.new_page()
            else:
                context = BrowserSensor._context
                page = BrowserSensor._page

                # Ensure handlers registered only once
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
                        report["network_failures"].append(
                            {"url": request.url, "error": error_msg}
                        )  # type: ignore

                    page.on("requestfailed", handle_request_failed)
                    page._bgl_requestfailed_hook = True  # type: ignore

            try:
                # Reset page to a neutral state before navigation
                await page.goto("about:blank", wait_until="domcontentloaded")

                # Navigate with timeout
                response = await page.goto(
                    target_url, wait_until="networkidle", timeout=30000
                )

                if not response or response.status >= 400:
                    report["status"] = "FAILED"
                    report["http_status"] = (
                        response.status if response else "NO_RESPONSE"
                    )

                # Capture UI Structure (Interactive Elements)
                ui_elements = await page.evaluate("""() => {
                    const elements = Array.from(document.querySelectorAll('button, a, input, [role="button"]'));
                    return elements.map(el => ({
                        tag: el.tagName.toLowerCase(),
                        text: el.innerText.trim() || el.value || el.placeholder || el.getAttribute('aria-label') || 'unlabeled',
                        id: el.id || 'none',
                        classes: el.className || 'none',
                        type: el.type || (el.tagName === 'A' ? 'link' : 'generic')
                    })).filter(el => el.text !== 'unlabeled' || el.id !== 'none');
                }""")
                report["interactive_elements"] = ui_elements

                # Capture Performance Data
                if measure_perf:
                    perf_data = await page.evaluate("""() => {
                        const timing = window.performance.timing;
                        const nav = window.performance.getEntriesByType('navigation')[0];
                        const resources = window.performance.getEntriesByType('resource');
                        
                        // Identify JS hotspots (scripts taking > 50ms to load or having many entries)
                        const js_hotspots = resources
                            .filter(r => r.initiatorType === 'script')
                            .map(r => ({ name: r.name.split('/').pop(), duration: r.duration }))
                            .filter(r => r.duration > 50)
                            .sort((a, b) => b.duration - a.duration);

                        return {
                            dom_load: timing.domContentLoadedEventEnd - timing.navigationStart,
                            full_load: timing.loadEventEnd - timing.navigationStart,
                            js_hotspots: js_hotspots,
                            total_resources: resources.length
                        };
                    }""")
                    report["performance"] = perf_data

                # Take screenshot for evidence only on failure if configured
                failed = (
                    report["status"] != "SUCCESS"
                    or report["console_errors"]
                    or report["network_failures"]
                )
                if failed and self.capture_failures:
                    screenshot_filename = (
                        f"scan_{path.replace('/', '_')}_{int(time.time())}.png"
                    )
                    screenshot_path = self.reports_dir / screenshot_filename
                    await page.screenshot(path=str(screenshot_path))
                    report["screenshot_path"] = str(screenshot_path)
                if har_path:
                    report["har_path"] = str(har_path)

            except Exception as e:
                report["status"] = "ERROR"
                report["error_message"] = str(e)

            finally:
                try:
                    await page.goto(
                        "about:blank", wait_until="domcontentloaded", timeout=15000
                    )
                except Exception:
                    pass
                try:
                    if context_kwargs:
                        await page.close()
                except Exception:
                    pass
                if context_kwargs:
                    try:
                        await context.close()
                    except Exception:
                        pass
                self._write_status(
                    busy=False, url=target_url, started=start_ts, ended=time.time()
                )

        return report

    def _write_status(
        self, busy: bool, url: str, started: float, ended: Optional[float] = None
    ):
        data = {
            "busy": busy,
            "url": url,
            "started_at": started,
            "ended_at": ended,
            "updated_at": time.time(),
            "mode": "headless" if self.headless else "visible",
        }
        try:
            self.status_file.write_text(json.dumps(data), encoding="utf-8")
        except Exception:
            pass


async def main():
    # Simple test run - HEADED for user visibility
    sensor = BrowserSensor(headless=False)
    print(f"[*] Starting VISIBLE test scan of {sensor.base_url}...")
    result = await sensor.scan_url("/")
    print(json.dumps(result, indent=2))


if __name__ == "__main__":
    # Note: In the real orchestrator, this will be called via an event loop
    try:
        asyncio.run(main())
    except Exception as e:
        print(f"[!] Sensor test failed: {e}")
