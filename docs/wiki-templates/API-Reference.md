# API Reference

## 🔌 Overview

BGL3 provides RESTful-style APIs for all operations. All endpoints accept JSON input and return JSON or HTML responses.

**Base URL:** `/api/`  
**Authentication:** None (single-user system)

---

## Guarantee Operations

### Save & Next

**Endpoint:** `POST /api/save-and-next.php`

**Purpose:** Save current decision and load next guarantee.

**Request:**
```json
{
  "guarantee_id": "123",
  "supplier_id": "55",
  "supplier_name": "Official Supplier Name",
  "current_index": 1,
  "status_filter": "all"
}
```

**Response:**
```json
{
  "success": true,
  "finished": false,
  "record": {
    "id": 124,
    "guarantee_number": "G-101",
    "supplier_name": "Next Supplier",
    "bank_name": "Bank Name",
    "amount": 50000,
    "expiry_date": "2025-12-31",
    "status": "pending"
  },
  "banks": [...],
  "currentIndex": 2,
  "totalRecords": 100
}
```

**Status Codes:**
- `200` - Success
- `400` - Validation error
- `500` - Server error

---

### Extend Guarantee

**Endpoint:** `POST /api/extend.php`

**Purpose:** Extend guarantee expiry date by 1 year.

**Request:**
```json
{
  "guarantee_id": "123"
}
```

**Response:** HTML fragment (updated decision card)

**Validation:**
- ✅ Status must be 'ready'
- ✅ Guarantee must not be locked
- ✅ Supplier and bank must be assigned

---

### Reduce Guarantee

**Endpoint:** `POST /api/reduce.php`

**Purpose:** Reduce guarantee amount.

**Request:**
```json
{
  "guarantee_id": "123",
  "new_amount": "25000.00"
}
```

**Response:** HTML fragment (updated decision card)

**Validation:**
- ✅ New amount < current amount
- ✅ New amount > 0
- ✅ Guarantee must not be locked

---

### Release Guarantee

**Endpoint:** `POST /api/release.php`

**Purpose:** Finalize and lock guarantee.

**Request:**
```json
{
  "guarantee_id": "123",
  "reason": "Project completed"
}
```

**Response:** HTML fragment (decision card with "Released" banner)

**Effects:**
- ✅ Sets `is_locked = 1`
- ✅ Sets `status = 'released'`
- ✅ Records release event in history
- ✅ Saves letter snapshot

---

## Suggestions & Learning

### Get Supplier Suggestions

**Endpoint:** `GET /api/suggestions-learning.php`

**Purpose:** Get AI-powered supplier suggestions.

**Parameters:**
- `raw` (required) - Raw supplier name from Excel
- `guarantee_id` (optional) - Current guarantee ID

**Example:**
```
GET /api/suggestions-learning.php?raw=شركة النقل&guarantee_id=123
```

**Response:** HTML string (suggestion chips)

```html
<button class="chip chip-5-star" data-supplier-id="42">
  شركة النقل الوطني
  <span class="confidence">★★★★★ 95%</span>
</button>
<button class="chip chip-4-star" data-supplier-id="17">
  شركة النقل الحديث
  <span class="confidence">★★★★ 82%</span>
</button>
```

---

## Timeline & History

### Get Timeline

**Endpoint:** `GET /api/timeline.php`

**Purpose:** Get guarantee history timeline.

**Parameters:**
- `guarantee_id` (required)

**Response:**
```json
{
  "success": true,
  "events": [
    {
      "id": 1,
      "event_type": "import",
      "created_at": "2026-01-10 14:30:00",
      "created_by": "System",
      "details": "Imported from Excel"
    },
    {
      "id": 2,
      "event_type": "decision",
      "created_at": "2026-01-10 14:35:00",
      "created_by": "User",
      "details": "Selected supplier: شركة النقل"
    }
  ]
}
```

---

## Letter Generation

### Preview Letter

**Endpoint:** `GET /api/preview-letter.php`

**Purpose:** Generate letter preview.

**Parameters:**
- `guarantee_id` (required)
- `action` (required) - extension|reduction|release

**Response:** HTML string (letter content)

---

### Generate PDF

**Endpoint:** `POST /api/generate-pdf.php`

**Purpose:** Generate and download PDF letter.

**Request:**
```json
{
  "guarantee_id": "123",
  "action": "release"
}
```

**Response:** PDF file download

---

## Import & Batch

### Upload Excel

**Endpoint:** `POST /api/upload-excel.php`

**Purpose:** Upload and parse Excel file.

**Request:** `multipart/form-data`
- `file` - Excel file (.xlsx)

**Response:**
```json
{
  "success": true,
  "batch_id": 15,
  "total_rows": 50,
  "valid_rows": 48,
  "errors": [
    {
      "row": 12,
      "error": "Missing guarantee number"
    }
  ]
}
```

---

### Get Batch Details

**Endpoint:** `GET /api/batch.php?id=15`

**Response:**
```json
{
  "success": true,
  "batch": {
    "id": 15,
    "filename": "guarantees_2026.xlsx",
    "total_guarantees": 50,
    "imported_at": "2026-01-10 14:00:00",
    "status": "processing"
  },
  "guarantees": [...]
}
```

---

## Statistics

### Get Dashboard Stats

**Endpoint:** `GET /api/stats.php`

**Response:**
```json
{
  "success": true,
  "stats": {
    "total_guarantees": 500,
    "pending": 120,
    "ready": 250,
    "released": 130,
    "total_amount": "15000000.00",
    "top_suppliers": [...],
    "top_banks": [...]
  }
}
```

---

## Settings

### Get Settings

**Endpoint:** `GET /api/settings.php`

**Response:**
```json
{
  "success": true,
  "settings": {
    "ai_enabled": true,
    "auto_match_threshold": 0.85,
    "suggestion_threshold": 0.60
  }
}
```

### Update Settings

**Endpoint:** `POST /api/settings.php`

**Request:**
```json
{
  "ai_enabled": true,
  "auto_match_threshold": 0.90
}
```

**Response:**
```json
{
  "success": true,
  "message": "Settings updated successfully"
}
```

---

## Error Responses

All endpoints return errors in this format:

```json
{
  "success": false,
  "error": "Error message in Arabic",
  "code": "ERROR_CODE"
}
```

**Common Error Codes:**
- `VALIDATION_ERROR` - Invalid input
- `NOT_FOUND` - Resource not found
- `LOCKED` - Resource is locked
- `INVALID_STATE` - Operation not allowed in current state

---

## Response Types

- **JSON** - Most endpoints return JSON
- **HTML** - Server-driven UI endpoints return HTML fragments
- **PDF** - Letter generation returns PDF files

---

*For implementation details, see `/docs/api-contracts.md`*
