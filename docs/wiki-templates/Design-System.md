# Design System

## 🎨 Overview

BGL3 uses a **custom CSS design system** with zero external dependencies (Tailwind has been completely removed).

---

## 📁 Structure

### Core Files

1. **`design-system.css`** (208 lines)
   - CSS Variables
   - Base resets
   - Typography
   - Scrollbar styling

2. **`components.css`** (762 lines)
   - Buttons
   - Cards
   - Tables
   - Forms
   - Modals
   - Toasts
   - Badges
   - Loading states

3. **`layout.css`** (188 lines)
   - Top bar (header)
   - Navigation
   - Page containers
   - Grid system

4. **`batch-detail.css`** (262 lines)
   - Page-specific styles for batch-detail.php

---

## 🎨 CSS Variables

### Colors

```css
--bg-body: #f1f5f9;
--bg-card: #ffffff;
--bg-secondary: #f8fafc;

--text-primary: #1e293b;
--text-secondary: #475569;
--text-muted: #64748b;

--accent-primary: #3b82f6;
--accent-success: #16a34a;
--accent-danger: #dc2626;
--accent-warning: #f59e0b;
```

### Spacing

```css
--space-xs: 4px;
--space-sm: 8px;
--space-md: 16px;
--space-lg: 24px;
--space-xl: 32px;
--space-2xl: 48px;
```

### Typography

```css
--font-family: 'Tajawal', sans-serif;
--font-size-xs: 12px;
--font-size-sm: 14px;
--font-size-md: 16px;
--font-size-lg: 18px;
--font-size-xl: 20px;
```

---

## 🧩 Components

### Buttons

```html
<button class="btn">Default</button>
<button class="btn btn-primary">Primary</button>
<button class="btn btn-success">Success</button>
<button class="btn btn-danger">Danger</button>
```

### Cards

```html
<div class="card">
  <h3 class="card-title">Title</h3>
  <p>Content...</p>
</div>
```

### Badges

```html
<span class="badge">Default</span>
<span class="badge badge-success">جاهز</span>
<span class="badge badge-warning">معلق</span>
```

### Tables

```html
<table class="table">
  <thead>...</thead>
  <tbody>...</tbody>
</table>
```

---

## 📱 Responsive

### Breakpoints

```css
/* Mobile */
@media (max-width: 768px) { ... }

/* Tablet */
@media (min-width: 769px) and (max-width: 1024px) { ... }

/* Desktop */
@media (min-width: 1025px) { ... }
```

---

## 🌐 Browser Support

- ✅ Chrome/Edge (modern)
- ✅ Firefox
- ✅ Safari (with `-webkit-` prefixes)

**Graceful degradation:**

- Scrollbar styling: Firefox only
- backdrop-filter: Modern browsers only

---

## 🔄 Migration from Tailwind

All pages have been migrated:

- ❌ **Removed:** Tailwind CDN
- ✅ **Replaced:** Custom design system
- ✅ **Result:** ~1300 lines organized CSS, zero dependencies

---

*For component examples, see [UI Components](UI-Components)*
