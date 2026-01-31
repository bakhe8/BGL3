import asyncio
import time
import os
from pathlib import Path
from typing import Optional, List, Dict, Any

from playwright.async_api import async_playwright, Browser, BrowserContext, Page


class BrowserManager:
    """
    Singleton-style manager for Playwright browser/context reuse.
    Controls max pages, idle time, and restart on failure.
    """
    def __init__(self, base_url: str, headless: bool = True, max_pages: int = 3, idle_timeout: int = 120, persist: bool = False):
        self.base_url = base_url
        self.headless = headless
        self.max_pages = max_pages
        self.idle_timeout = idle_timeout
        self.persist = persist
        self._playwright = None
        self._browser: Optional[Browser] = None
        self._context: Optional[BrowserContext] = None
        self._pages: List[Page] = []
        self._last_restart = 0.0
        self._lock = asyncio.Lock()

    async def _ensure_browser(self):
        if self._browser:
            return
        self._playwright = await async_playwright().start()
        self._browser = await self._playwright.chromium.launch(headless=self.headless)
        self._context = await self._browser.new_context(base_url=self.base_url)
        self._last_restart = time.time()
        self._pages = []

    async def _cleanup_idle_pages(self):
        now = time.time()
        keep = []
        for page in self._pages:
            try:
                ts = page._impl_obj._timeout_settings._default_timeout  # best-effort; fallback below
            except Exception:
                ts = 0
            # If the page is closed, drop it
            if page.is_closed():
                continue
            # Idle logic: if page has no navigations recorded for idle_timeout, close it
            try:
                last_nav = page._impl_obj._page.get("last_navigation") if hasattr(page._impl_obj, "_page") else None
                last_ts = getattr(last_nav, "timestamp", None) if last_nav else None
            except Exception:
                last_ts = None
            if last_ts and now - last_ts > self.idle_timeout:
                await page.close()
                continue
            keep.append(page)
        self._pages = keep

    async def get_page(self) -> Page:
        async with self._lock:
            await self._ensure_browser()
            await self._cleanup_idle_pages()
            # Reuse an open page if available
            for page in self._pages:
                if not page.is_closed():
                    return page
            # Enforce max pages
            if len(self._pages) >= self.max_pages:
                # close the oldest
                try:
                    oldest = self._pages.pop(0)
                    await oldest.close()
                except Exception:
                    pass
            page = await self._context.new_page()
            self._pages.append(page)
            return page

    async def new_page(self) -> Page:
        async with self._lock:
            await self._ensure_browser()
            await self._cleanup_idle_pages()
            if len(self._pages) >= self.max_pages:
                try:
                    oldest = self._pages.pop(0)
                    await oldest.close()
                except Exception:
                    pass
            page = await self._context.new_page()
            self._pages.append(page)
            return page

    async def close(self):
        async with self._lock:
            try:
                for p in self._pages:
                    if not p.is_closed():
                        await p.close()
            except Exception:
                pass
            self._pages = []
            if self._context:
                try:
                    await self._context.close()
                except Exception:
                    pass
            if self._browser:
                try:
                    await self._browser.close()
                except Exception:
                    pass
            if self._playwright:
                try:
                    await self._playwright.stop()
                except Exception:
                    pass
            self._browser = None
            self._context = None
            self._playwright = None

    def status(self) -> Dict[str, Any]:
        return {
            "headless": self.headless,
            "pages": len([p for p in self._pages if not p.is_closed()]) if self._pages else 0,
            "last_restart": self._last_restart,
            "max_pages": self.max_pages,
            "idle_timeout": self.idle_timeout,
        }
