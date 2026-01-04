<!-- Sidebar (Left) -->
<aside class="sidebar">
    
    <!-- Input Actions (New Proposal) -->
    <div class="input-toolbar">
        <!-- Import Stats (Interactive Filter) -->
        <?php if (isset($importStats) && ($importStats['total'] > 0)): ?>
        <div style="font-size: 11px; margin-bottom: 10px; display: flex; gap: 16px; align-items: center;">
            <a href="/?filter=all" 
               style="display: flex; align-items: center; gap: 4px; padding: 4px 8px; border-radius: 4px; text-decoration: none; transition: all 0.2s; <?= $statusFilter === 'all' ? 'background: #e0e7ff; font-weight: 600;' : '' ?>"
               onmouseover="if('<?= $statusFilter ?>' !== 'all') this.style.background='#f1f5f9'"
               onmouseout="if('<?= $statusFilter ?>' !== 'all') this.style.background='transparent'">
                <span style="color: #334155;">📊 <?= $displayTotal ?? $importStats['total'] ?></span>
            </a>
            <a href="/?filter=ready" 
               style="display: flex; align-items: center; gap: 4px; padding: 4px 8px; border-radius: 4px; text-decoration: none; transition: all 0.2s; <?= $statusFilter === 'ready' ? 'background: #dcfce7; font-weight: 600;' : '' ?>"
               onmouseover="if('<?= $statusFilter ?>' !== 'ready') this.style.background='#f1f5f9'"
               onmouseout="if('<?= $statusFilter ?>' !== 'ready') this.style.background='transparent'">
                <span style="color: #059669;">✅ <?= $importStats['ready'] ?? 0 ?></span>
            </a>
            <a href="/?filter=pending" 
               style="display: flex; align-items: center; gap: 4px; padding: 4px 8px; border-radius: 4px; text-decoration: none; transition: all 0.2s; <?= $statusFilter === 'pending' ? 'background: #fef3c7; font-weight: 600;' : '' ?>"
               onmouseover="if('<?= $statusFilter ?>' !== 'pending') this.style.background='#f1f5f9'"
               onmouseout="if('<?= $statusFilter ?>' !== 'pending') this.style.background='transparent'">
                <span style="color: #d97706;">⚠️ <?= $importStats['pending'] ?? 0 ?></span>
            </a>
            <a href="/?filter=released" 
               style="display: flex; align-items: center; gap: 4px; padding: 4px 8px; border-radius: 4px; text-decoration: none; transition: all 0.2s; <?= $statusFilter === 'released' ? 'background: #fee2e2; font-weight: 600;' : '' ?>"
               onmouseover="if('<?= $statusFilter ?>' !== 'released') this.style.background='#f1f5f9'"
               onmouseout="if('<?= $statusFilter ?>' !== 'released') this.style.background='transparent'">
                <span style="color: #dc2626;">🔓 <?= $importStats['released'] ?? 0 ?></span>
            </a>
        </div>
        <?php else: ?>
        <div class="toolbar-label">إدخال جديد</div>
        <?php endif; ?>
        <div class="toolbar-actions">
            <button class="btn-input" title="إدخال يدوي" data-action="showManualInput">
                <span>&#x270D;</span>
                <span>يدوي</span>
            </button>
            <button class="btn-input" title="رفع ملف Excel" data-action="showImportModal">
                <span>&#x1F4CA;</span>
                <span>ملف</span>
            </button>
            <button class="btn-input" title="لصق بيانات" data-action="showPasteModal">
                <span>&#x1F4CB;</span>
                <span>لصق</span>
            </button>
        </div>
        <!-- Hidden Input for Import -->
        <input type="file" id="hiddenFileInput" style="display: none;" accept=".xlsx,.xls,.csv" />
    </div>

    <!-- Progress -->
    <div class="progress-container">
        <div class="progress-bar">
            <div class="progress-fill" :style="`width: ${progress}%`"></div>
        </div>
        <div class="progress-text">
            <span>سجل <span x-text="currentIndex"></span> من <span x-text="totalRecords"></span></span>
            <span class="progress-percent" x-text="`${progress}%`"></span>
        </div>
    </div>
    
    <!-- Sidebar Body -->
    <div class="sidebar-body">
        <!-- Notes Section -->
        <div class="sidebar-section" id="notesSection">
            <div class="sidebar-section-title">
                📝 الملاحظات
            </div>
            
            <!-- Notes List -->
            <div id="notesList">
                <?php if (empty($mockNotes)): ?>
                    <div id="emptyNotesMessage" style="text-align: center; color: var(--text-light); font-size: var(--font-size-sm); padding: 16px 0;">
                        لا توجد ملاحظات
                    </div>
                <?php else: ?>
                    <?php foreach ($mockNotes as $note): ?>
                        <div class="note-item">
                            <div class="note-header">
                                <span class="note-author"><?= htmlspecialchars($note['created_by'] ?? 'مستخدم') ?></span>
                                <span class="note-time"><?= substr($note['created_at'] ?? '', 0, 16) ?></span>
                            </div>
                            <div class="note-content"><?= htmlspecialchars($note['content'] ?? '') ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- Note Input Box -->
            <div id="noteInputBox" class="note-input-box" style="display: none;">
                <textarea id="noteTextarea" placeholder="أضف ملاحظة..."></textarea>
                <div class="note-input-actions">
                    <button onclick="cancelNote()" class="note-cancel-btn">
                        إلغاء
                    </button>
                    <button onclick="saveNote()" class="note-save-btn">
                        حفظ
                    </button>
                </div>
            </div>
            
            <!-- Add Note Button -->
            <button id="addNoteBtn" onclick="showNoteInput()" class="add-note-btn">
                + إضافة ملاحظة
            </button>
        </div>
        
        <!-- Attachments Section -->
        <div class="sidebar-section" style="margin-top: 24px;">
            <div class="sidebar-section-title">
                📎 المرفقات
            </div>
            
            <!-- Upload Button -->
            <label class="add-note-btn" style="cursor: pointer; display: inline-block; width: 100%; text-align: center;">
                <input type="file" id="fileInput" style="display: none;" onchange="uploadFile(event)">
                + رفع ملف
            </label>
            
            <!-- Attachments List -->
            <div id="attachmentsList">
                <?php if (empty($mockAttachments)): ?>
                    <div id="emptyAttachmentsMessage" style="text-align: center; color: var(--text-light); font-size: var(--font-size-sm); padding: 16px 0;">
                        لا توجد مرفقات
                    </div>
                <?php else: ?>
                    <?php foreach ($mockAttachments as $file): ?>
                        <div class="note-item" style="display: flex; align-items: center; gap: 12px;">
                            <div style="font-size: 24px;">📄</div>
                            <div style="flex: 1; min-width: 0;">
                                <div class="note-content" style="margin: 0; font-weight: 500;"><?= htmlspecialchars($file['file_name'] ?? 'ملف') ?></div>
                                <div class="note-time"><?= substr($file['created_at'] ?? '', 0, 10) ?></div>
                            </div>
                            <a href="/V3/storage/<?= htmlspecialchars($file['file_path'] ?? '') ?>" 
                               target="_blank" 
                               style="color: var(--text-light); text-decoration: none; font-size: 18px; padding: 4px;"
                               title="تحميل">
                                ⬇️
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</aside>
