# Learning Backend Flow Analysis

## التقرير: تحليل منطق الباك إند لأنظمة التعلم

**التاريخ**: 2026-01-03  
**الهدف**: توثيق التدفق الكامل لكل نظام تعلم من trigger إلى storage إلى retrieval

---

## 🔄 FLOW #1: Explicit Confirmations & Rejections

### Entry Points

#### Point A: Confirm (تأكيد)
**الموقع**: `api/save-and-next.php:262-281`  
**المحفز**: User clicks "Save" with supplier selected

**التسلسل**:
```
User action: Select Supplier X → Click Save
↓
save-and-next.php receives:
  - guarantee_id
  - supplier_id (X)
  - supplier_name
↓
Line 273-281: LearningRepository::logDecision()
  ↓
  INSERT INTO learning_confirmations (
    raw_supplier_name = currentGuarantee.rawData['supplier'],
    supplier_id = X,
    action = 'confirm',
    confidence = (not used in this flow),
    guarantee_id = current
  )
↓
Data stored in database
```

**الشروط**:
- `$currentGuarantee` exists
- `$currentGuarantee->rawData['supplier']` exists
- `$supplierId` is set

**لا يعتمد على**:
- Status
- active_action
- Decision source (auto/manual)

---

#### Point B: Reject (رفض ضمني)
**الموقع**: `api/save-and-next.php:283-303`  
**المحفز**: User selects supplier **different** from top suggestion

**التسلسل**:
```
save-and-next.php:285 → Get current suggestions
↓
authority = AuthorityFactory::create()
suggestions = authority->getSuggestions(rawSupplierName)
↓
IF (suggestions not empty) THEN
  topSuggestion = suggestions[0]
  
  IF (topSuggestion.supplier_id != chosen supplier_id) THEN
    ↓
    Line 290-298: LearningRepository::logDecision()
      ↓
      INSERT INTO learning_confirmations (
        raw_supplier_name = rawSupplierName,
        supplier_id = topSuggestion.supplier_id,  ← المرفوض
        action = 'reject',
        confidence = topSuggestion.confidence,
        matched_anchor = topSuggestion.official_name,
        guarantee_id = current
      )
```

**⚠️ CRITICAL**: This is **implicit** rejection (لا يطلب من المستخدم)

**الشروط**:
- Suggestions exist
- Top suggestion ≠ chosen supplier
- تلقائي 100% (no user input for reject)

---

### Retrieval Flow

**الموقع**: `LearningSignalFeeder::getSignals()`  
**المستدعي**: `UnifiedLearningAuthority::gatherSignals()`  
**متى**: Every suggestion request (index.php load, save-and-next)

**التسلسل**:
```
Input: normalized_supplier_name
↓
LearningRepository::getUserFeedback(normalizedInput)
  ↓
  SELECT supplier_id, action, COUNT(*) as count
  FROM learning_confirmations
  WHERE raw_supplier_name = normalizedInput
  GROUP BY supplier_id, action
  ↓
  Returns: [
    {supplier_id: 5, action: 'confirm', count: 3},
    {supplier_id: 7, action: 'reject', count: 1}
  ]
↓
LearningSignalFeeder processes:
  FOR EACH row:
    IF action == 'confirm':
      strength = min(1.0, count / 10)  ← 10+ confirms = max
      emit SignalDTO(type: 'learning_confirmation', strength)
    
    IF action == 'reject':
      strength = min(1.0, count / 5)   ← 5+ rejects = max
      emit SignalDTO(type: 'learning_rejection', strength)
↓
Signals returned to Authority
```

**⚠️ FRAGMENTATION**: Uses `raw_supplier_name` not normalized → same supplier with different spellings counted separately

---

## 🔄 FLOW #2: Alternative Names (Aliases)

### Entry Points (Write)

#### ⚠️ UNUSED: learnAlias()
**الموقع**: `SupplierLearningRepository::learnAlias()`  
**الحالة**: **غير مستدعى** في الكود المفحوص

**التسلسل المقصود** (لو تم الاستدعاء):
```
learnAlias(supplierId, rawName, normalized)
↓
Check if alias exists:
  SELECT id FROM supplier_alternative_names 
  WHERE normalized_name = normalized
↓
IF not exists:
  INSERT INTO supplier_alternative_names (
    supplier_id, 
    alternative_name = rawName,
    normalized_name = normalized,
    source = 'learning',
    usage_count = 1
  )
```

**لماذا غير مستدعى؟**: غير واضح، ربما logic قديم

---

#### ⚠️ UNUSED: incrementUsage() / decrementUsage()
**الموقع**: `SupplierLearningRepository::incrementUsage/decrementUsage()`  
**الحالة**: **غير مستدعى** في الكود المفحوص

**الوظيفة المقصودة**:
- `incrementUsage()`: زيادة usage_count (positive learning)
- `decrementUsage()`: تقليل usage_count (negative learning، حد أدنى -5)

**⚠️ NOTE**: Methods exist but no callers found

---

### Retrieval Flow

**الموقع**: `AliasSignalFeeder::getSignals()`  
**المستدعي**: `UnifiedLearningAuthority::gatherSignals()`

**التسلسل**:
```
Input: normalized_supplier_name
↓
SupplierAlternativeNameRepository::findAllByNormalizedName(normalized)
  ↓
  SELECT * FROM supplier_alternative_names
  WHERE normalized_name = normalized
  -- NO usage_count filter
  ↓
  Returns: [
    {supplier_id: 5, alternative_name: "...", source: 'learning', usage_count: 3},
    {supplier_id: 7, alternative_name: "...", source: 'manual', usage_count: 0}
  ]
↓
AliasSignalFeeder processes:
  FOR EACH alias:
    emit SignalDTO(
      type: 'alias_exact',
      strength: 1.0,  ← Always maximum (exact match)
      metadata: {
        source: alias.source,
        usage_count: alias.usage_count  ← For context only
      }
    )
↓
Signals returned to Authority
```

**✅ COMPLIANCE**: No usage_count filtering (Query Pattern Audit #9)

---

### Conflict Detection (Trust Gate)

**الموقع**: `SmartProcessingService::evaluateTrust():431-474`  
**المحفز**: Auto-match attempt

**التسلسل**:
```
evaluateTrust(supplierId, source, score, rawName)
↓
normalized = Normalizer::normalize(rawName)
↓
conflicts = SupplierLearningRepository::findConflictingAliases(supplierId, normalized)
  ↓
  SELECT supplier_id, source
  FROM supplier_alternative_names
  WHERE normalized_name = normalized AND supplier_id != supplierId
  ↓
  Returns conflicting aliases for DIFFERENT suppliers
↓
IF (conflicts exist) THEN
  Check if current alias from 'learning':
    ↓
    SELECT source FROM supplier_alternative_names
    WHERE normalized_name = normalized AND supplier_id = supplierId
    ↓
    IF source == 'learning' THEN
      isTrusted = FALSE
      reason = "learning-sourced alias has conflicts"
      ↓
      BLOCK auto-match
```

**🔴 CRITICAL LOGIC**: Learning-sourced aliases with conflicts are NOT trusted

---

## 🔄 FLOW #3: Historical Selections

### Entry Points (Write)

**NO WRITE** - This system is **read-only passive**

Data comes from:
1. Guarantees imported → `guarantees.raw_data` contains supplier name
2. Decisions created → `guarantee_decisions.supplier_id` set

---

### Retrieval Flow

**الموقع**: `HistoricalSignalFeeder::getSignals()`  
**المستدعي**: `UnifiedLearningAuthority::gatherSignals()`

**التسلسل**:
```
Input: normalized_supplier_name
↓
GuaranteeDecisionRepository::getHistoricalSelections(normalized)
  ↓
  pattern = '%"supplier":"' + normalized + '"%'
  
  SELECT d.supplier_id, COUNT(*) as count
  FROM guarantees g
  JOIN guarantee_decisions d ON g.id = d.guarantee_id
  WHERE g.raw_data LIKE pattern
    AND d.supplier_id IS NOT NULL
  GROUP BY d.supplier_id
  ↓
  Returns: [
    {supplier_id: 5, count: 12},
    {supplier_id: 7, count: 3}
  ]
↓
HistoricalSignalFeeder processes:
  FOR EACH selection:
    count = selection.count
    
    IF count >= 5:
      signalType = 'historical_frequent'
    ELSE:
      signalType = 'historical_occasional'
    
    strength = 0.3 + (0.5 * log(count + 1) / log(20))
    ← Logarithmic scale
    
    emit SignalDTO(type: signalType, strength: strength)
↓
Signals returned to Authority
```

**🔴 FRAGILE**: JSON LIKE query (Query Pattern Audit #3)

---

## 🔄 FLOW #4: Fuzzy Matching

### Entry Points (Write)

**NO WRITE** - This system is **computational only**

---

### Retrieval Flow

**الموقع**: `FuzzySignalFeeder::getSignals()`  
**المستدعي**: `UnifiedLearningAuthority::gatherSignals()`

**التسلسل**:
```
Input: normalized_supplier_name
↓
SupplierRepository::getAllSuppliers()
  ↓
  SELECT id, official_name, normalized_name FROM suppliers
  ↓
  Returns ALL suppliers (no filtering)
↓
FuzzySignalFeeder processes:
  FOR EACH supplier:
    similarity = calculateSimilarity(input, supplier.normalized_name)
      ↓
      Uses levenshtein(str1, str2)
      similarity = 1 - (distance / max_length)
    
    IF similarity >= 0.55:  ← MIN_SIMILARITY
      
      IF similarity >= 0.85:
        signalType = 'fuzzy_official_strong'
      ELSE IF similarity >= 0.70:
        signalType = 'fuzzy_official_medium'
      ELSE:
        signalType = 'fuzzy_official_weak'
      
      emit SignalDTO(type: signalType, strength: similarity)
↓
Signals returned to Authority
```

**⚠️ PERFORMANCE**: O(n) calculations for every request (n = total suppliers)

---

## 🔄 FLOW #5: Entity Anchors

### Entry Points (Write)

**NO WRITE** - This system is **computational only**

---

### Retrieval Flow

**الموقع**: `AnchorSignalFeeder::getSignals()`  
**المستدعي**: `UnifiedLearningAuthority::gatherSignals()`

**التسلسل**:
```
Input: normalized_supplier_name
↓
ArabicEntityExtractor::extractAnchors(input)
  ↓
  Removes common words ("شركة", "مؤسسة", etc.)
  Extracts distinctive keywords
  ↓
  Returns: ["النورس", "التجارة"]
↓
IF anchors empty:
  return []  ← No signals
↓
FOR EACH anchor:
  
  matchingSuppliers = SupplierRepository::findByAnchor(anchor)
    ↓
    SELECT id, official_name FROM suppliers
    WHERE official_name LIKE '%' || anchor || '%'
  
  frequency = SupplierRepository::countSuppliersWithAnchor(anchor)
    ↓
    SELECT COUNT(*) FROM suppliers
    WHERE official_name LIKE '%' || anchor || '%'
  
  FOR EACH matching supplier:
    
    IF frequency <= 2:
      signalType = 'entity_anchor_unique'
      strength = 1.0 (if freq=1) or 0.9 (if freq=2)
    ELSE:
      signalType = 'entity_anchor_generic'
      strength = 0.7 (freq <= 5) or 0.5 (freq > 5)
    
    emit SignalDTO(type: signalType, strength: strength)
↓
Signals returned to Authority
```

**⚠️ PERFORMANCE**: Multiple LIKE queries per anchor

---

## 🎯 UnifiedLearningAuthority: Signal Aggregation

**This is WHERE ALL FLOWS CONVERGE**

**الموقع**: `UnifiedLearningAuthority::getSuggestions()`  
**نقطة الدخول المركزية**

### التسلسل الكامل

```
getSuggestions(rawInput)
↓
1. NORMALIZE INPUT
   normalized = Normalizer::normalize(rawInput)
↓
2. GATHER SIGNALS (calls ALL feeders)
   signals = []
   
   FOR EACH registered feeder:
     try:
       feederSignals = feeder->getSignals(normalized)
       signals.append(feederSignals)
     catch (Exception):
       log error, continue  ← Fault tolerant
   
   ↓
   signals = [
     SignalDTO(supplier_id:5, type:'alias_exact', strength:1.0),
     SignalDTO(supplier_id:5, type:'learning_confirmation', strength:0.3),
     SignalDTO(supplier_id:5, type:'historical_frequent', strength:0.6),
     SignalDTO(supplier_id:7, type:'fuzzy_official_medium', strength:0.75),
     ...
   ]
↓
3. AGGREGATE BY SUPPLIER
   grouped = {}
   
   FOR EACH signal:
     supplier_id = signal.supplier_id
     
     IF supplier_id not in grouped:
       grouped[supplier_id] = {
         signals: [],
         confirmations: 0,
         rejections: 0
       }
     
     grouped[supplier_id].signals.append(signal)
     
     IF signal.type == 'learning_confirmation':
       grouped[supplier_id].confirmations += signal.metadata.count
     
     IF signal.type == 'learning_rejection':
       grouped[supplier_id].rejections += signal.metadata.count
↓
4. COMPUTE CONFIDENCE
   FOR EACH supplier in grouped:
     
     confidence = ConfidenceCalculatorV2::calculate(
       signals: supplier.signals,
       confirmationCount: supplier.confirmations,
       rejectionCount: supplier.rejections
     )
     ↓
     Returns: {score: 0.85, level: 'high'}
     
     supplier.confidence = confidence
↓
5. FILTER BY THRESHOLD
   threshold = Settings::get('MATCH_REVIEW_THRESHOLD')  ← 0.70
   
   candidates = suppliers WHERE confidence.score >= threshold
↓
6. ORDER BY CONFIDENCE
   candidates.sort(by: confidence.score DESC)
↓
7. FORMAT
   suggestions = []
   
   FOR EACH candidate:
     dto = SuggestionFormatter::format(candidate)
     ↓
     Returns SuggestionDTO with all metadata
     
     suggestions.append(dto)
↓
RETURN suggestions[]
```

---

## 📊 Trigger Matrix

| Event | System #1 | System #2 | System #3 | System #4 | System #5 |
|-------|-----------|-----------|-----------|-----------|-----------|
| Import | ❌ | ❌ | ⚠️ Indirect | ❌ | ❌ |
| Suggestion Request | ✅ Read | ✅ Read | ✅ Read | ✅ Compute | ✅ Compute |
| Manual Decision | ✅ Write | ❌ | ⚠️ Indirect | ❌ | ❌ |
| Auto-Match | ❌ | ✅ Conflict Check | ✅ Read | ✅ Compute | ✅ Compute |

**⚠️ Indirect**: Data created by other operations, used passively

---

## ✅ الخلاصة

**Active Entry Points**: 2
1. `save-and-next.php:262-307` → Explicit Learning (System #1)
2. `UnifiedLearningAuthority::getSuggestions()` → All Systems (read)

**Passive Systems**: 3
- System #2 (Aliases): Read-only (write methods exist but unused)
- System #3 (Historical): Read-only (passive data collection)
- System #4 (Fuzzy): Computational
- System #5 (Anchors): Computational

**Critical Flows**:
- Implicit rejection (save-and-next:283-303) → **ACTIVE**
- Conflict detection (SmartProcessing:431-474) → **ACTIVE**
- Signal aggregation (UnifiedLearningAuthority:getSuggestions) → **CENTRAL HUB**

---

*كل flow موثق بأماكنه الدقيقة في الكود. أي تغيير يجب تحديث هذا التقرير.*
