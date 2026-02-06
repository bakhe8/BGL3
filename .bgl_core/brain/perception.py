from pathlib import Path
from typing import Optional, Dict, Any
import re


UI_MAP_JS = r"""
(limit) => {
  const elements = Array.from(
    document.querySelectorAll('button, a, input, select, textarea, [role], [onclick]')
  );

  function pickText(el) {
    const t = (el.innerText || '').trim();
    const v = (el.value || '').toString().trim();
    const p = (el.placeholder || '').toString().trim();
    const a = (el.getAttribute('aria-label') || '').trim();
    return (t || v || p || a || '').trim();
  }

  return elements.slice(0, limit).map(el => {
    const rect = el.getBoundingClientRect();
    const tag = (el.tagName || '').toLowerCase();
    const text = pickText(el).slice(0, 120);
    const href = (tag === 'a') ? (el.getAttribute('href') || '') : '';

    return {
      tag,
      role: el.getAttribute('role') || '',
      text,
      id: el.id || '',
      classes: el.className || '',
      type: el.type || (tag === 'a' ? 'link' : ''),
      href,
      name: el.getAttribute('name') || '',
      x: rect.x, y: rect.y, w: rect.width, h: rect.height,
      z: getComputedStyle(el).zIndex || 'auto'
    };
  });
}
"""


UI_SEMANTIC_JS = r"""
(limit) => {
  function norm(t) {
    return (t || '').replace(/\s+/g, ' ').trim().slice(0, 160);
  }
  function textOf(el) {
    if (!el) return '';
    return norm(el.innerText || el.textContent || '');
  }

  const title = norm(document.title || '');
  const headings = Array.from(document.querySelectorAll('h1,h2,h3'))
    .slice(0, limit)
    .map(h => ({ tag: (h.tagName || '').toLowerCase(), text: textOf(h) }))
    .filter(h => h.text);

  const landmarks = {};
  ['header','nav','main','aside','footer','section'].forEach(tag => {
    const nodes = Array.from(document.querySelectorAll(tag))
      .slice(0, 3)
      .map(n => textOf(n))
      .filter(Boolean);
    if (nodes.length) landmarks[tag] = nodes;
  });

  const forms = Array.from(document.querySelectorAll('form'))
    .slice(0, 3)
    .map(form => {
      const action = form.getAttribute('action') || '';
      const method = (form.getAttribute('method') || 'get').toLowerCase();
      const fields = [];
      const inputs = Array.from(form.querySelectorAll('input, select, textarea')).slice(0, 12);
      inputs.forEach(el => {
        const id = el.getAttribute('id') || '';
        let label = '';
        if (id) {
          const lab = form.querySelector(`label[for='${id}']`);
          if (lab) label = textOf(lab);
        }
        if (!label) {
          const parent = el.closest('label');
          if (parent) label = textOf(parent);
        }
        fields.push({
          tag: (el.tagName || '').toLowerCase(),
          type: el.getAttribute('type') || '',
          name: el.getAttribute('name') || '',
          placeholder: el.getAttribute('placeholder') || '',
          label: label
        });
      });
      return { action, method, fields };
    });

  const tables = Array.from(document.querySelectorAll('table'))
    .slice(0, 3)
    .map(table => {
      const headers = Array.from(table.querySelectorAll('th'))
        .map(th => textOf(th))
        .filter(Boolean)
        .slice(0, 10);
      const caption = textOf(table.querySelector('caption'));
      let rows = 0;
      try { rows = table.querySelectorAll('tbody tr').length; } catch (e) { rows = 0; }
      if (!rows) {
        const totalRows = table.querySelectorAll('tr').length;
        rows = totalRows > 0 ? Math.max(0, totalRows - 1) : 0;
      }
      return { headers, rows, caption };
    });

  const stats = Array.from(document.querySelectorAll('[class*="stat"],[class*="metric"],[class*="kpi"],[data-stat],[data-metric]'))
    .slice(0, 8)
    .map(el => textOf(el))
    .filter(Boolean)
    .map(t => t.slice(0, 120));

  const container = document.querySelector('main') || document.body;
  let text_excerpt = '';
  try {
    text_excerpt = norm((container && container.innerText) ? container.innerText : '').slice(0, 1200);
  } catch (e) { text_excerpt = ''; }

  const text_blocks = Array.from(container ? container.querySelectorAll('p, li, td, th, caption, article, section') : [])
    .map(el => textOf(el))
    .filter(t => t && t.length > 20)
    .slice(0, Math.max(6, limit * 3));

  return { title, headings, landmarks, forms, tables, stats, text_blocks, text_excerpt };
}
"""


async def capture_ui_map(page, limit: int = 50):
    """
    Return a compact UI map for interactive elements:
    - tag/role/text/id/classes/type (+ x/y/w/h).
    Shared implementation used by tools/sensors to avoid drift.
    """
    try:
        return await page.evaluate(UI_MAP_JS, limit)
    except Exception:
        return []


async def capture_semantic_map(page, limit: int = 12) -> Dict[str, Any]:
    """
    Return a compact semantic summary of the page:
    title/headings/landmarks/forms/tables/stats.
    """
    try:
        return await page.evaluate(UI_SEMANTIC_JS, limit)
    except Exception:
        return {}


def summarize_semantic_map(semantic: Dict[str, Any], max_items: int = 5) -> Dict[str, Any]:
    if not semantic:
        return {}
    def _norm_text(text: str) -> str:
        return re.sub(r"\s+", " ", (text or "").strip())
    def _take(items):
        return list(items[:max_items]) if isinstance(items, list) else []
    # Aggregate text blocks for keyword extraction
    text_blocks = semantic.get("text_blocks") or []
    if not isinstance(text_blocks, list):
        text_blocks = []
    text_joined = " ".join([_norm_text(t) for t in text_blocks if t])
    keywords = _extract_keywords(text_joined, max_keywords=10)
    summary = {
        "title": semantic.get("title") or "",
        "headings": _take(semantic.get("headings") or []),
        "landmarks": semantic.get("landmarks") or {},
        "forms": _take(semantic.get("forms") or []),
        "tables": _take(semantic.get("tables") or []),
        "stats": _take(semantic.get("stats") or []),
        "text_blocks": _take(text_blocks),
        "text_excerpt": semantic.get("text_excerpt") or "",
        "text_keywords": keywords,
    }
    return summary


def _extract_keywords(text: str, max_keywords: int = 8) -> list[str]:
    if not text:
        return []
    # Basic multilingual tokenization (Arabic + Latin)
    tokens = re.findall(r"[A-Za-z]{3,}|[\u0600-\u06FF]{2,}", text)
    if not tokens:
        return []
    stop = {
        "the","and","for","with","that","this","from","have","has","are","was","were","not",
        "you","your","but","into","over","under","then","than","there","their","here","also",
        "على","الى","من","في","عن","أن","إن","كان","كانت","هذا","هذه","ذلك","لكن","كما",
        "تم","هو","هي","هم","هن","مع","او","و","ما","لم","لن","قد","كل","تم","تمت","تمكن",
    }
    freq: Dict[str, int] = {}
    for t in tokens:
        k = t.strip().lower()
        if k in stop or len(k) < 2:
            continue
        freq[k] = freq.get(k, 0) + 1
    sorted_tokens = sorted(freq.items(), key=lambda x: (-x[1], x[0]))
    return [t for t, _ in sorted_tokens[:max_keywords]]


def project_interactive_elements(ui_map):
    """Backwards-compatible projection for older consumers (no coordinates)."""
    out = []
    for el in ui_map or []:
        out.append(
            {
                "tag": el.get("tag"),
                "text": el.get("text"),
                "id": el.get("id") or "none",
                "classes": el.get("classes") or "none",
                "type": el.get("type") or "generic",
            }
        )
    # Drop unlabeled elements unless they have an id (keep behavior similar to previous sensor)
    return [
        e
        for e in out
        if (e.get("text") and e["text"] != "unlabeled") or (e.get("id") and e["id"] != "none")
    ]


async def capture_local_context(page, selector: Optional[str] = None, screenshot_dir: Optional[Path] = None, tag: str = "", include_layout: bool = False) -> Dict[str, Any]:
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
    if include_layout:
        try:
            data["layout_map"] = await capture_ui_map(page, limit=50)
        except Exception:
            data["layout_map"] = {"error": "layout_capture_failed"}
    return data
