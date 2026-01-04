# Bank APIs - Deprecated Code Archive

> **Status**: ❌ DEPRECATED  
> **Date Archived**: 2026-01-04  
> **Reason**: Merged into unified create-bank.php API

---

## ⚠️ WARNING

**DO NOT USE THESE OLD APIs**

This code is archived for historical reference only.

---

## Why These APIs Were Deprecated

### Original APIs

**add-bank.php** (100 lines):
- Purpose: Add bank with aliases support
- Used in: Settings page → Add Bank modal
- Features: Aliases ✅ | Contacts ❌

**create_bank.php** (41 lines):
- Purpose: Add bank with contact details
- Used in: Settings page → Add Bank form
- Features: Aliases ❌ | Contacts ✅

### Problem

**Feature Gap**: Neither API supported BOTH aliases AND contacts!

---

## Current Solution

**Use**: `api/create-bank.php` (unified)

**Features**:
- ✅ Aliases support (from add-bank)
- ✅ Contact details (from create_bank)
- ✅ BankManagementService (shared logic)
- ✅ Transaction safety
- ✅ Duplicate check

**Usage** (both contexts work):
```javascript
// From modal (with aliases)
fetch('api/create-bank.php', {
    body: JSON.stringify({
        arabic_name: '...',
        english_name: '...',
        short_name: '...',
        aliases: ['...', '...']
    })
})

// From form (with contacts)
fetch('api/create-bank.php', {
    body: JSON.stringify({
        arabic_name: '...',
        english_name: '...',
        short_name: '...',
        department: '...',
        address_line1: '...',
        contact_email: '...'
    })
})
```

---

## Files Archived

- `add-bank.php` - Modal version (aliases)
- `create_bank.php` - Form version (contacts)

---

## References

- [PHASE5-Impact-Analysis-Bank-Merger.md](../../docs/architectural_analysis_2026_01_04/PHASE5-Impact-Analysis-Bank-Merger.md)
- [BankManagementService.php](../../app/Services/BankManagementService.php)
- [create-bank.php](../../api/create-bank.php) - Unified API

---

**Last Updated**: 2026-01-04  
**Status**: Permanently Archived
