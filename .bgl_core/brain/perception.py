from pathlib import Path
from typing import Optional, Dict, Any


async def capture_local_context(page, selector: Optional[str] = None, screenshot_dir: Optional[Path] = None, tag: str = "") -> Dict[str, Any]:
    """
    يجمع ما نراه موضعياً: الهدف، جار قريب، عنوان قريب، وسبب الاختيار (selector/hint).
    لا يلتقط الصفحة كاملة.
    يمكنه حفظ لقطة صغيرة للهدف لأغراض التعلم/التوثيق.
    """
    data: Dict[str, Any] = {}
    try:
        viewport = await page.evaluate("() => ({ w: window.innerWidth, h: window.innerHeight })")
        data["viewport"] = viewport
    except Exception:
        data["viewport"] = {}

    if selector:
        try:
            el = await page.query_selector(selector)
            if el:
                box = await el.bounding_box()
                text = (await el.inner_text() or "").strip()
                role = await el.get_attribute("role")
                aria = await el.get_attribute("aria-label")
                disabled = await el.get_attribute("disabled")
                # لون خلفية تقريبي
                bg = await el.evaluate("e => getComputedStyle(e).backgroundColor || ''")
                # موضع نسبي (وسط العنصر نسبةً للعرض/الارتفاع)
                if box and viewport.get("w") and viewport.get("h"):
                    cx = box["x"] + box["width"] / 2
                    cy = box["y"] + box["height"] / 2
                    rel = {"x_pct": cx / viewport["w"], "y_pct": cy / viewport["h"]}
                else:
                    rel = {}
                data["target"] = {
                    "selector": selector,
                    "text": text,
                    "role": role,
                    "aria": aria,
                    "disabled": bool(disabled),
                    "box": box,
                    "bg": bg,
                    "relative": rel,
                }
                # جار قريب (parent أو heading قريب)
                parent = await el.evaluate_handle("el => el.parentElement")
                if parent:
                    heading = await parent.evaluate_handle(
                        """p => {
                            const h = p.querySelector('h1, h2, h3, h4, h5, h6');
                            return h ? {text: h.innerText?.trim?.(), tag: h.tagName} : null;
                        }"""
                    )
                    data["neighbor"] = await heading.json_value() if heading else None
                # لقطة هدف إن طُلب
                if screenshot_dir and box:
                    screenshot_dir.mkdir(parents=True, exist_ok=True)
                    safe_tag = tag or "target"
                    shot_path = screenshot_dir / f"{safe_tag}.png"
                    try:
                        await el.screenshot(path=str(shot_path))
                        data["screenshot"] = str(shot_path)
                    except Exception:
                        pass
        except Exception:
            data["target"] = {"selector": selector, "error": "capture_failed"}
    return data
