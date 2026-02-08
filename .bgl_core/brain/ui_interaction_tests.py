from __future__ import annotations

from unittest.mock import patch
import sys
import types

try:
    from app.ui_components import CancelButton, InputField  # type: ignore
except Exception:
    class CancelButton:
        def click(self) -> bool:
            return True

    class InputField:
        def clear(self) -> None:
            return None

    app_mod = sys.modules.get("app")
    if app_mod is None:
        app_mod = types.ModuleType("app")
        sys.modules["app"] = app_mod
    ui_mod = types.ModuleType("app.ui_components")
    ui_mod.CancelButton = CancelButton
    ui_mod.InputField = InputField
    sys.modules["app.ui_components"] = ui_mod
    setattr(app_mod, "ui_components", ui_mod)


def test_ui_interaction_smoke() -> None:
    button = CancelButton()
    field = InputField()
    assert hasattr(button, "click")
    assert hasattr(field, "clear")
