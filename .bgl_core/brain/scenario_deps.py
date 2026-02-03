from __future__ import annotations

import importlib.util
import os
from dataclasses import dataclass
from pathlib import Path
from typing import List, Dict, Any


@dataclass
class ScenarioDepsReport:
    ok: bool
    missing: List[str]
    notes: List[str]
    playwright_version: str | None
    chromium_path: str | None

    def to_dict(self) -> Dict[str, Any]:
        return {
            "ok": self.ok,
            "missing": self.missing,
            "notes": self.notes,
            "playwright_version": self.playwright_version,
            "chromium_path": self.chromium_path,
        }


def _module_present(name: str) -> bool:
    return importlib.util.find_spec(name) is not None


def _playwright_version() -> str | None:
    try:
        import importlib.metadata as md
        return md.version("playwright")
    except Exception:
        return None


def check_scenario_deps() -> ScenarioDepsReport:
    """
    Lightweight dependency check for scenario execution.
    Does not install anything; only reports what is missing.
    """
    missing: List[str] = []
    notes: List[str] = []
    playwright_version: str | None = _playwright_version()
    chromium_path: str | None = None

    # Python package deps (required)
    for mod in ("yaml", "playwright"):
        if not _module_present(mod):
            missing.append(mod)

    # Optional deps (nice-to-have)
    if not _module_present("python_ghost_cursor"):
        notes.append(
            "Optional: python_ghost_cursor not installed; scenarios will run without the visible cursor overlay."
        )

    # Playwright version + browser availability
    if "playwright" not in missing:
        try:
            from playwright.sync_api import sync_playwright  # type: ignore

            with sync_playwright() as p:
                chromium_path = p.chromium.executable_path
                if not chromium_path or not Path(chromium_path).exists():
                    missing.append("playwright-browsers")
                    notes.append(
                        "Chromium not installed. Run: python -m playwright install chromium"
                    )
        except Exception as exc:
            if "playwright-browsers" not in missing:
                missing.append("playwright-browsers")
            notes.append(f"Playwright launch check failed: {exc}")

    # Environment hints
    if os.getenv("BGL_INCLUDE_API") == "1":
        notes.append("API scenarios enabled (BGL_INCLUDE_API=1).")

    ok = len(missing) == 0
    return ScenarioDepsReport(
        ok=ok,
        missing=missing,
        notes=notes,
        playwright_version=playwright_version,
        chromium_path=chromium_path,
    )


async def check_scenario_deps_async() -> ScenarioDepsReport:
    """
    Async-safe variant for use inside asyncio loops (guardian).
    """
    missing: List[str] = []
    notes: List[str] = []
    playwright_version: str | None = _playwright_version()
    chromium_path: str | None = None

    for mod in ("yaml", "playwright"):
        if not _module_present(mod):
            missing.append(mod)

    if not _module_present("python_ghost_cursor"):
        notes.append(
            "Optional: python_ghost_cursor not installed; scenarios will run without the visible cursor overlay."
        )

    if "playwright" not in missing:
        try:
            from playwright.async_api import async_playwright  # type: ignore

            async with async_playwright() as p:
                chromium_path = p.chromium.executable_path
                if not chromium_path or not Path(chromium_path).exists():
                    missing.append("playwright-browsers")
                    notes.append(
                        "Chromium not installed. Run: python -m playwright install chromium"
                    )
        except Exception as exc:
            if "playwright-browsers" not in missing:
                missing.append("playwright-browsers")
            notes.append(f"Playwright async check failed: {exc}")

    if os.getenv("BGL_INCLUDE_API") == "1":
        notes.append("API scenarios enabled (BGL_INCLUDE_API=1).")

    ok = len(missing) == 0
    return ScenarioDepsReport(
        ok=ok,
        missing=missing,
        notes=notes,
        playwright_version=playwright_version,
        chromium_path=chromium_path,
    )
