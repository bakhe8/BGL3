# مرجع الكود المستخرج من التصميم

## بنية الملاحظات (Notes Structure)

### HTML Structure
```html
<section class="sidebar-section">
    <h3 class="sidebar-section-title">📝 الملاحظات</h3>
    <div class="notes-list">
        <!-- Static notes in reference -->
        <div class="note-item">
            <div class="note-header">
                <span class="note-author">أحمد محمد</span>
                <span class="note-time">قبل ساعتين</span>
            </div>
            <div class="note-content">
                يرجى التحقق من المبلغ...
            </div>
        </div>
    </div>
    
    <!-- Nested Alpine component for input -->
    <div x-data="{ showNoteInput: false, newNote: '' }">
        <div x-show="showNoteInput" class="note-input-box">
            <textarea x-model="newNote" placeholder="اكتب ملاحظتك هنا..." x-ref="noteTextarea"></textarea>
            <div class="note-input-actions">
                <button class="note-cancel-btn" @click="showNoteInput = false; newNote = ''">إلغاء</button>
                <button class="note-save-btn" @click="showNoteInput = false; newNote = ''">حفظ</button>
            </div>
        </div>
        <button class="add-note-btn" @click="showNoteInput = true; $nextTick(() => $refs.noteTextarea.focus())">
            + إضافة ملاحظة
        </button>
    </div>
</section>
```

### JavaScript (من المرجع - Mock)
```javascript
saveNote() {
    if (this.noteText.trim()) {
        console.log('Saving note:', this.noteText);
        alert('تم حفظ الملاحظة!');
        this.noteText = '';
        this.showNoteInput = false;
    }
}
```

## النسخة الفعلية المطلوبة

### JavaScript (Production - مع API حقيقي)
```javascript
async saveNote(content) {
    if (!content.trim()) return;
    
    try {
        const res = await fetch('/V3/api/save-note.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                guarantee_id: this.record.id,
                content: content.trim()
            })
        });
        
        const data = await res.json();
        
        if (data.success) {
            // Add to notes array immediately
            this.notes.unshift(data.note);
            return true;
        }
        return false;
    } catch(e) {
        console.error('Error saving note:', e);
        return false;
    }
}
```

### HTML (Production - مع x-for ديناميكي)
```html
<div class="sidebar-section" x-data="{ showNoteInput: false, newNote: '' }">
    <div class="sidebar-section-title">📝 الملاحظات</div>
    
    <!-- Dynamic notes list -->
    <template x-if="notes.length === 0 && !showNoteInput">
        <div style="text-align: center; color: var(--text-light); padding: 16px 0;">
            لا توجد ملاحظات
        </div>
    </template>
    
    <template x-for="note in notes" :key="note.id">
        <div class="note-item">
            <div class="note-header">
                <span class="note-author" x-text="note.created_by"></span>
                <span class="note-time" x-text="note.created_at?.substring(0,16)"></span>
            </div>
            <div class="note-content" x-text="note.content"></div>
        </div>
    </template>
    
    <!-- Input box -->
    <div x-show="showNoteInput" class="note-input-box" x-transition>
        <textarea x-model="newNote" placeholder="أضف ملاحظة..." x-ref="noteTextarea"></textarea>
        <div class="note-input-actions">
            <button @click="showNoteInput = false; newNote = ''" class="note-cancel-btn">إلغاء</button>
            <button @click="async () => {
                const success = await saveNote(newNote);
                if (success) {
                    newNote = '';
                    showNoteInput = false;
                }
            }" class="note-save-btn">حفظ</button>
        </div>
    </div>
    
    <button @click="showNoteInput = true; $nextTick(() => $refs.noteTextarea?.focus())" 
            x-show="!showNoteInput"
            class="add-note-btn">
        + إضافة ملاحظة
    </button>
</div>
```

## الفرق الرئيسي

| المرجع | النسخة الفعلية |
|--------|----------------|
| Static HTML notes | Dynamic `x-for` loop |
| Mock `alert()` | Real API call |
| No persistence | Database save |
| No state update | Updates `notes` array |
