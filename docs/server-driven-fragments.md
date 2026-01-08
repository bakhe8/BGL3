# Server-Driven Fragments

## Concept
To avoid complex client-side templating and logic duplication, the system uses **Server-Driven UI Fragments**. The Client (JS) requests a resource, and the Server (PHP) returns the exact HTML to be inserted into the DOM.

## Implementation: Supplier Suggestions

### The Contract
*   **Endpoint**: `/api/suggestions-learning.php`
*   **Trigger**: User typing in `#supplierInput` (Debounced 300ms).
*   **Response**: Pure HTML encoded as text.

### The Fragment
The server renders a list of buttons (`<button class="chip">`).
```html
<button class="chip chip-unified" data-action="selectSupplier" ...>
    <span class="chip-name">Supplier Name</span>
    <span class="chip-confidence">95%</span>
</button>
```

### Client Responsibility (`records.controller.js`)
1.  `fetch('/api/suggestions-learning.php?ignore_ajax=1&...')`
2.  `container.innerHTML = await response.text()`
3.  **Zero parsing logic**: JS does not loop JSON. It blindly injects the HTML.

## Evidence Index
*   **Client Fetch**: `public/js/records.controller.js` (Line 727: `fetch('/api/suggestions-learning.php?raw='...)`).
*   **Client Injection**: `public/js/records.controller.js` (Line 743: `container.innerHTML = html`).
*   **Server Content-Type**: `api/suggestions-learning.php` (Line 13: `header('Content-Type: text/html; charset=utf-8')`).
*   **Server Rendering**: `api/suggestions-learning.php` (Line 50: `include __DIR__ . '/../partials/suggestions.php'`).
