<?php
/**
 * Partial: Record Form Section
 * Returns HTML fragment for the main record form
 * Used by: api/get-record.php, index.php initial load
 */

// Required variables from including script:
// $record - array with record data
// $banks - array of banks for dropdown

if (!isset($record)) {
    $record = [
        'id' => 0,
        'supplier_name' => '',
        'bank_name' => '',
        'amount' => 0,
        'expiry_date' => '',
        'issue_date' => '',
        'contract_number' => '',
        'type' => 'Initial',
        'status' => 'pending'
    ];
}

// Default $isHistorical to false if not set
$isHistorical = $isHistorical ?? false;
$bannerData = $bannerData ?? null; // Should contain ['timestamp' => '...', 'reason' => '...']
?>

<!-- Record Form Content -->
<div id="record-form-sec" 
     data-record-index="<?= $index ?? 1 ?>" 
     data-record-id="<?= $record['id'] ?? 0 ?>"></div>
<?php if ($isHistorical): ?>
<div style="background-color: #fffbeb; border: 1px solid #f59e0b; padding: 12px; border-radius: 8px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
    <div style="display: flex; align-items: center; gap: 12px;">
        <span style="font-size: 20px;">📜</span>
        <div>
            <div style="font-weight: bold; color: #92400e; font-size: 14px;">نسخة تاريخية (READ ONLY)</div>
            <div style="font-size: 12px; color: #b45309;">
                تم الحفظ في: <?= $bannerData['timestamp'] ?? 'N/A' ?>
                <?php if(!empty($bannerData['reason'])): ?>
                     • السبب: <?= htmlspecialchars($bannerData['reason']) ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <button class="btn btn-sm btn-outline-warning" data-action="load-record" data-index="<?= $index ?? 1 ?>" style="background: white; border: 1px solid #d97706; color: #d97706;">
        العودة للوضع الحالي ↩️
    </button>
</div>
<?php endif; ?>

<!-- Record Form Content -->
<header class="card-header">
    <div class="header-title" style="display: flex; align-items: center; width: 100%;">
        <div class="record-actions" style="display: flex; gap: 8px; flex: 1; align-items: center;">
            <button class="btn btn-secondary btn-sm" data-action="saveAndNext">💾 حفظ</button>
            <div style="width: 1px; height: 20px; background: #e2e8f0; margin: 0 4px;"></div>
            <button class="btn btn-secondary btn-sm" data-action="extend">🔄 تمديد</button>
            <button class="btn btn-secondary btn-sm" data-action="reduce">📉 تخفيض</button>
            <button class="btn btn-secondary btn-sm" data-action="release">📤 إفراج</button>
        </div>

</header>

<div class="card-body">
    <!-- Side-by-Side Grid for Supplier and Bank -->
    <div class="fields-grid">
    <!-- Supplier Field -->
    <div class="field-group">
        <div class="field-row">
            <label class="field-label">
                المورد
                <?php 
                // Contextual status indicator for supplier
                $supplierMissing = empty($record['supplier_id']);
                if ($supplierMissing && ($record['status'] ?? 'pending') === 'pending'):
                ?>
                    <span class="field-status-indicator field-status-missing" title="المورد غير محدد - يحتاج قرار">⚠️</span>
                <?php elseif (!$supplierMissing): ?>
                    <span class="field-status-indicator field-status-ok" title="المورد محدد">✓</span>
                <?php endif; ?>
            </label>
            <input type="text" 
                   class="field-input" 
                   id="supplierInput" 
                   name="supplier_name"
                   data-preview-field="supplier_name"
                   value="<?= htmlspecialchars($record['supplier_name'], ENT_QUOTES, 'UTF-8', false) ?>"
                   data-record-id="<?= $record['id'] ?>"
                   data-action="handleSupplierInput"
                   <?= $isHistorical ? 'readonly disabled style="background:#f9fafb;cursor:not-allowed;"' : '' ?>>
            <input type="hidden" id="supplierIdHidden" name="supplier_id" value="<?= $record['supplier_id'] ?? '' ?>">
        </div>
        
        <!-- Suggestions Chips -->
        <div class="chips-row" id="supplier-suggestions" <?= $isHistorical ? 'style="display:none"' : '' ?>>
            <?php if (!empty($supplierMatch['suggestions'])): ?>
                <?php foreach ($supplierMatch['suggestions'] as $sugg): 
                    // Skip if this suggestion is already the selected & approved supplier
                    $isSelected = ($record['supplier_id'] == ($sugg['id'] ?? 0));
                    $isApproved = ($record['status'] ?? '') === 'approved' || ($record['status'] ?? '') === 'issued'; // "Ready" or "Issued"
                    
                    if ($isSelected && $isApproved) continue;
                ?>
                    <button class="chip <?= ($record['supplier_name'] === ($sugg['name'] ?? '')) ? 'chip-selected' : 'chip-candidate' ?>" 
                            data-action="selectSupplier"
                            data-id="<?= $sugg['id'] ?? 0 ?>"
                            data-name="<?= htmlspecialchars($sugg['name'] ?? '') ?>">
                        <?php if (($sugg['score'] ?? 0) > 90): ?><span>⭐ </span><?php endif; ?>
                        <span><?= htmlspecialchars($sugg['name'] ?? '') ?></span>
                        <span class="chip-source"><?= $sugg['score'] ?? 0 ?>%</span>
                    </button>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="font-size: 11px; color: #94a3b8; padding: 4px;">لا توجد اقتراحات</div>
            <?php endif; ?>
        </div>
        
        <!-- Add Supplier Button (Hidden by default) -->
        <div id="addSupplierContainer" style="display:none; margin-top: 8px;">
            <button class="btn btn-sm btn-outline-primary" 
                    data-action="createSupplier"
                    style="width: 100%; justify-content: center; gap: 8px; border-style: dashed;">
                <span>➕</span>
                <span>إضافة "<span id="newSupplierName"></span>" وتعيينه كمورد لهذا الضمان</span>
            </button>
        </div>

        <div class="field-hint">
            <div class="hint-group">
                <span class="hint-label">Excel:</span>
                <span class="hint-value" id="excelSupplier">
                    <?= htmlspecialchars($guarantee->rawData['supplier'] ?? '') ?>
                </span>
            </div>
            
            <?php if (!empty($supplierMatch['score'])): ?>
                <?php
                    $score = $supplierMatch['score'];
                    $color = $score >= 90 ? '#10b981' : ($score >= 70 ? '#f59e0b' : '#ef4444');
                ?>
                <div class="hint-divider">|</div>
                <div class="hint-score" style="display: flex; align-items: center; gap: 6px;">
                    <div class="hint-dot" style="background-color: <?= $color ?>;"></div>
                    <span style="color: <?= $color ?>; font-weight: bold;">
                        <?= $score ?>%
                    </span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Bank Field (Updated to Text Input + Chips) -->
    <div class="field-group">
        <div class="field-row">
            <label class="field-label">
                البنك
                <?php 
                // Contextual status indicator for bank
                $bankMissing = empty($record['bank_id']);
                if ($bankMissing && ($record['status'] ?? 'pending') === 'pending'):
                ?>
                    <span class="field-status-indicator field-status-missing" title="البنك غير محدد - يحتاج قرار">⚠️</span>
                <?php elseif (!$bankMissing): ?>
                    <span class="field-status-indicator field-status-ok" title="البنك محدد">✓</span>
                <?php endif; ?>
            </label>
            <input type="text" 
                   class="field-input" 
                   id="bankNameInput" 
                   name="bank_name"
                   data-preview-field="bank_name"
                   value="<?= htmlspecialchars($record['bank_name'], ENT_QUOTES, 'UTF-8', false) ?>"
                   placeholder="اسم البنك"
                   <?= $isHistorical ? 'readonly disabled style="background:#f9fafb;cursor:not-allowed;"' : '' ?>>
            <input type="hidden" id="bankSelect" name="bank_id" value="<?= $record['bank_id'] ?? '' ?>">
        </div>
        
        <div class="chips-row">
            <!-- Always show Best Bank Match with star if found AND not already selected/approved -->
            <?php 
                $isAlreadySelected = isset($record['bank_id']) && 
                                   isset($bankMatch['id']) && 
                                   $record['bank_id'] == $bankMatch['id'];
                
                if (!empty($bankMatch['id']) && !$isAlreadySelected): 
            ?>
                 <button class="chip <?= ($record['bank_name'] === $bankMatch['name']) ? 'chip-selected' : '' ?>"
                        data-action="selectBank"
                        data-id="<?= $bankMatch['id'] ?>"
                        data-name="<?= htmlspecialchars($bankMatch['name']) ?>">
                    <span>⭐ </span>
                    <span><?= htmlspecialchars($bankMatch['name']) ?></span>
                </button>
            <?php endif; ?>

            <!-- Only show generic banks list if confidence is low (<80%) or no match -->
            <?php 
                $showGenericBanks = empty($bankMatch['score']) || $bankMatch['score'] < 80;
                if (isset($banks) && $showGenericBanks && !$isHistorical): 
            ?>
                <?php 
                    $count = 0; 
                    foreach ($banks as $bank): 
                        // Skip if same as match to avoid duplicates
                        if (isset($bankMatch['id']) && $bank['id'] == $bankMatch['id']) continue;
                        if ($count > 5) break; // Limit to 5 generic banks
                        $count++;
                ?>
                    <button class="chip <?= (isset($record['bank_id']) && $record['bank_id'] == $bank['id']) ? 'chip-selected' : '' ?>"
                            data-action="selectBank"
                            data-id="<?= $bank['id'] ?>"
                            data-name="<?= htmlspecialchars($bank['official_name']) ?>"
                            data-center="<?= htmlspecialchars($bank['department'] ?? 'مركز خدمات التجارة') ?>"
                            data-po-box="<?= htmlspecialchars($bank['po_box'] ?? '3555') ?>"
                            data-email="<?= htmlspecialchars($bank['email'] ?? 'info@bank.com') ?>">
                        <span><?= htmlspecialchars($bank['official_name']) ?></span>
                    </button>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="field-hint">
            <div class="hint-group">
                <span class="hint-label">Excel:</span>
                <span class="hint-value" id="excelBank">
                    <?= htmlspecialchars($guarantee->rawData['bank'] ?? '') ?>
                </span>
            </div>
        </div>
    </div>
    </div>
    <!-- End of fields-grid -->

    <!-- Info Grid -->
    <div class="info-grid">
        <div class="info-item">
            <div class="info-label">رقم الضمان</div>
            <div class="info-value" data-preview-field="guarantee_number"><?= htmlspecialchars($record['guarantee_number'] ?? 'N/A') ?></div>
        </div>
        <div class="info-item">
            <div class="info-label">
                <?php 
                // 🔥 Read from raw_data to determine correct label
                $relatedTo = $guarantee->rawData['related_to'] ?? 'contract';
                echo $relatedTo === 'purchase_order' ? 'رقم أمر الشراء' : 'رقم العقد';
                ?>
            </div>
            <div class="info-value" data-preview-field="contract_number"><?= htmlspecialchars($record['contract_number']) ?></div>
        </div>
        <div class="info-item">
            <div class="info-label">المبلغ</div>
            <div class="info-value highlight" data-preview-field="amount">
                <?= number_format($record['amount'], 2, '.', ',') ?> ر.س
            </div>
        </div>
        <div class="info-item">
            <div class="info-label">تاريخ الانتهاء</div>
            <div class="info-value" data-preview-field="expiry_date"><?= htmlspecialchars($record['expiry_date']) ?></div>
        </div>
        <div class="info-item">
            <div class="info-label">النوع</div>
            <div class="info-value" data-preview-field="type"><?= htmlspecialchars($record['type']) ?></div>
        </div>
    </div>
</div>
