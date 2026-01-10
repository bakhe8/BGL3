# AI Matching System

## 🤖 Overview

The AI Matching System in BGL3 automatically suggests suppliers and banks based on imported data, learning from user confirmations and corrections over time.

---

## How It Works

### 1. Import Phase

When Excel data is imported:

```
Raw Supplier Name → Normalization → Fuzzy Matching → Confidence Score → Suggestion
```

**Example:**
- Input: `"شركة النقل الوطنيه للمقاولات المحدوده"`
- Normalized: `"شركه النقل الوطني للمقاولات"`
- Matched: `supplier_id: 42` with confidence `0.85`

---

## Matching Algorithm

### Step 1: Text Normalization

**Operations:**
- Remove diacritics (تشكيل)
- Standardize Arabic characters (ة → ه، ى → ي)
- Remove extra whitespace
- Strip common suffixes (المحدودة، ش.م.م)

```php
// Example
"شركة النقل الوطنيّة المحدودة"
    ↓
"شركه النقل الوطني"
```

### Step 2: Fuzzy Matching

Uses **Levenshtein Distance** with customizations:

```php
similarity = 1 - (levenshtein_distance / max_length)
```

**Thresholds:**
- ✅ `≥ 0.85` - Auto-match (high confidence)
- ⚠️ `0.60-0.84` - Suggest (medium confidence)
- ❌ `< 0.60` - No suggestion (low confidence)

### Step 3: Learning Cache

System checks `supplier_learning_cache` table first for faster matching:

```sql
SELECT * FROM supplier_learning_cache
WHERE normalized_input = ?
ORDER BY effective_score DESC
LIMIT 5
```

**Score Calculation:**
```
total_score = (fuzzy_score * 100) + source_weight + (usage_count * 10)
effective_score = total_score - (block_count * 20)
star_rating = CAST((effective_score / 30) AS INTEGER) -- 1 to 5 stars
```

---

## Learning Mechanism

### User Confirms Suggestion ✅

```
usage_count += 1
→ effective_score increases
→ Higher ranking in future suggestions
```

### User Rejects Suggestion ❌

```
block_count += 1
→ effective_score decreases
→ Lower ranking or removed from suggestions
```

### User Picks Manual Option 📝

```
Creates new learning_cache entry
→ Available for future matches
```

---

## UI Components

### Suggestion Chips

```html
<button class="chip chip-5-star">
  شركة النقل الوطني
  <span class="confidence">★★★★★ 95%</span>
</button>
```

**Star Ratings:**
- ⭐⭐⭐⭐⭐ 5 stars - Very high confidence
- ⭐⭐⭐⭐ 4 stars - High confidence
- ⭐⭐⭐ 3 stars - Medium confidence
- ⭐⭐ 2 stars - Low confidence
- ⭐ 1 star - Very low confidence

---

## Settings & Configuration

### Confidence Thresholds

Adjustable in `/views/settings.php`:

```php
AI_AUTO_MATCH_THRESHOLD = 0.85  // Auto-select without asking
AI_SUGGESTION_THRESHOLD = 0.60  // Show as suggestion
AI_LEARNING_ENABLED = true      // Enable learning from user
```

### Learning Weight

```php
USAGE_COUNT_WEIGHT = 10   // Points per confirmation
BLOCK_COUNT_PENALTY = 20  // Points per rejection
SOURCE_WEIGHT = 5         // Initial match quality
```

---

## Performance

### Cache Benefits

- ⚡ **First Match**: ~50-100ms (full fuzzy search)
- ⚡ **Cached Match**: ~5-10ms (direct lookup)
- 📊 **Cache Hit Rate**: ~80% after 100+ guarantees

### Optimization Strategies

1. **Normalization Cache**: Pre-normalize all supplier names
2. **Index Usage**: `normalized_input` is indexed
3. **Batch Processing**: Process multiple guarantees together
4. **Score Pre-calculation**: Use SQLite generated columns

---

## Statistics

View AI performance in **Statistics** page:

- Total matches suggested
- Auto-match rate
- User confirmation rate
- Average confidence score
- Most frequent suppliers

---

## Technical Details

### Service Classes

- `AIMatchingService.php` - Core matching logic
- `LearningRepository.php` - Cache management
- `SupplierRepository.php` - Supplier data access

### API Endpoints

- `GET /api/suggestions-learning.php?raw=<name>` - Get suggestions
- `POST /api/save-and-next.php` - Record user decision

---

## Future Improvements

- 🔮 Machine learning model (TensorFlow/PyTorch)
- 🔮 Multi-field matching (supplier + contract number)
- 🔮 Bank matching with same algorithm
- 🔮 Export learning data for analysis

---

*For implementation details, see `/app/Services/AIMatchingService.php`*
