# Decisions Log

Technical decisions made during development with rationale.

---

## 2026-01-10: Design System Migration

### Decision

Migrate from Tailwind CSS to custom design system

### Rationale

- **Problem:** Tailwind CDN dependency
- **Size:** Unused utility classes loaded
- **Customization:** Limited control over design tokens
- **Performance:** Extra HTTP request

### Solution

- Created `design-system.css` (208 lines) with CSS variables
- Created `components.css` (762 lines) with reusable components
- Removed Tailwind from all pages

### Impact

- ✅ Zero external CSS dependencies
- ✅ ~1300 lines organized, reusable CSS
- ✅ Full control over design tokens
- ✅ Better performance (no CDN)
- ✅ Consistent styling across all pages

### Files Changed

- `batches.php`, `batch-detail.php`, `statistics.php`, `settings.php`
- Created: `design-system.css`, `components.css`, `batch-detail.css`

---

## 2026-01-10: Unified Header Component

### Decision

Create a single header component for all pages

### Rationale

- **Problem:** Each page had different header markup
- **Inconsistency:** Navigation links varied
- **Maintenance:** Changes needed in multiple files

### Solution

Created `partials/unified-header.php` with:

- Dynamic active state detection
- Smart path resolution (root vs views/)
- Consistent navigation structure

### Impact

- ✅ Single source of truth for navigation
- ✅ Active state works automatically
- ✅ Easier to maintain and update
- ✅ Consistent UX across all pages

### Files Changed

- Created: `partials/unified-header.php`
- Updated: `batches.php`, `batch-detail.php`, `statistics.php`, `settings.php`

---

## 2026-01-10: Fixed Scrolling Issue

### Decision

Remove `overflow: hidden` from body element

### Rationale

- **Problem:** `statistics.php` couldn't scroll (content 5449px, viewport 911px)
- **Cause:** `body { overflow: hidden; height: 100%; }` in design-system.css
- **Original Intent:** For `index.php` sidebar layout only

### Solution

```css
/* Before */
body {
    height: 100%;
    overflow: hidden; /* Prevented scrolling! */
}

/* After */
body {
    min-height: 100vh; /* Allows expansion */
    /* overflow removed */
}
```

### Impact

- ✅ All pages can scroll normally
- ✅ `index.php` still works (has its own CSS)
- ✅ Statistics page accessible (all 5449px)

---

## 2026-01-10: Safari Compatibility

### Decision

Add `-webkit-` vendor prefixes for Safari support

### Rationale

- **Problem:** `backdrop-filter` doesn't work in Safari
- **Usage:** Modals and loading overlays use blur

### Solution

```css
.modal-backdrop {
    -webkit-backdrop-filter: blur(4px); /* Safari */
    backdrop-filter: blur(4px);         /* Modern */
}
```

### Impact

- ✅ Modals work in Safari
- ✅ Progressive enhancement
- ✅ ~5 lines added total

---

## 2026-01-10: Navigation Links Fix

### Decision

Use relative paths from views/ directory

### Rationale

- **Problem:** `href="index.php"` from `views/statistics.php` → `views/index.php` ❌
- **Expected:** Should go to root `/index.php` ✅

### Solution

```php
$basePath = ($currentDir === 'views') ? '../' : './';
<a href="<?= $basePath ?>index.php">الرئيسية</a>
```

### Impact

- ✅ All navigation links work correctly
- ✅ "الرئيسية" goes to root
- ✅ Works from both root and views/ pages

---

## 2026-01-[DATE]: PHP Version Requirement

### Decision

Require PHP 8.3+

### Rationale

- Modern syntax (named arguments, attributes)
- Better performance
- match expressions
- Type system improvements

### Impact

- ✅ Cleaner code
- ⚠️ Hosting requirement: PHP 8.3+

---

## 2026-01-[DATE]: SQLite over MySQL

### Decision

Use SQLite for database

### Rationale

- **Simplicity:** No separate server required
- **Portability:** Single file database
- **Development:** Easy setup
- **Backup:** Just copy `database.db`

### Impact

- ✅ Zero configuration
- ✅ Fast development
- ⚠️ Single-user limitation (for now)
- 🔄 Can migrate to MySQL/PostgreSQL later

---

## Template for New Decisions

```markdown
## YYYY-MM-DD: Decision Title

### Decision
What was decided?

### Rationale
Why was this decision made?

### Solution
How was it implemented?

### Impact
What changed? Any trade-offs?

### Files Changed
List of modified/created files
```

---

*Add new decisions to the top of this file*
