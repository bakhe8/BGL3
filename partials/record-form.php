<?php
/**
 * Record Form Partial
 * Displays the main guarantee decision form
 * 
 * Required variables:
 * - $record: Current guarantee record data
 * - $banks: Array of all banks
 * - $supplierMatch: Supplier suggestions array
 * - $bankMatch: Matched bank data (optional)
 * - $guarantee: Original guarantee object (optional)
 * - $isHistorical: Boolean flag (optional)
 */
?>

<div class="record-form">
    <div style="padding: 24px;">
        <div class="form-grid" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">
            
            <!-- Supplier Field -->
            <div class="form-group">
                <label class="form-label" style="display: block; margin-bottom: 8px; font-weight: 600;">المورد / المستفيد</label>
                <input type="text" 
                       id="supplierInput" 
                       class="form-input" 
                       value="<?= htmlspecialchars($record['supplier_name'] ?? '') ?>"
                       placeholder="اسم المورد..."
                       style="width: 100%; padding: 10px; border: 1px solid var(--border-neutral); border-radius: 6px;">
                       
                <!-- Supplier Suggestions -->
                <?php if (!empty($supplierMatch['suggestions'])): ?>
                    <div id="supplier-suggestions" style="margin-top: 8px; display: flex; flex-wrap: gap; gap: 8px;">
                        <?php foreach ($supplierMatch['suggestions'] as $suggestion): ?>
                            <div class="chip" style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 6px; cursor: pointer;">
                                <span><?= htmlspecialchars($suggestion['name']) ?></span>
                                <span style="font-size: 11px; font-weight: 600; color: #3b82f6;"><?= $suggestion['score'] ?>%</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Bank Field -->
            <div class="form-group">
                <label class="form-label" style="display: block; margin-bottom: 8px; font-weight: 600;">البنك</label>
                <?php if (!empty($bankMatch)): ?>
                    <div class="chip" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; background: #f0fdf4; border: 1px solid #16a34a; border-radius: 6px;">
                        <span>🏦 <?= htmlspecialchars($bankMatch['name']) ?></span>
                        <span style="font-size: 11px; font-weight: 600; color: #16a34a;">100%</span>
                    </div>
                    <input type="hidden" id="bankSelect" value="<?= $bankMatch['id'] ?>">
                <?php else: ?>
                    <select id="bankSelect" class="form-input" style="width: 100%; padding: 10px; border: 1px solid var(--border-neutral); border-radius: 6px;">
                        <option value="">اختر البنك...</option>
                        <?php foreach ($banks as $bank): ?>
                            <option value="<?= $bank['id'] ?>" <?= ($record['bank_id'] == $bank['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($bank['official_name'] ?? $bank['arabic_name'] ?? 'بنك') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>

            <!-- Amount Field -->
            <div class="form-group">
                <label class="form-label" style="display: block; margin-bottom: 8px; font-weight: 600;">القيمة</label>
                <input type="number" 
                       class="form-input" 
                       value="<?= $record['amount'] ?? 0 ?>" 
                       disabled 
                       style="width: 100%; padding: 10px; border: 1px solid var(--border-neutral); border-radius: 6px; background: #f9fafb;">
            </div>

            <!-- Guarantee Number Field -->
            <div class="form-group">
                <label class="form-label" style="display: block; margin-bottom: 8px; font-weight: 600;">رقم الضمان</label>
                <input type="text" 
                       class="form-input" 
                       value="<?= htmlspecialchars($record['guarantee_number'] ?? '') ?>" 
                       disabled 
                       style="width: 100%; padding: 10px; border: 1px solid var(--border-neutral); border-radius: 6px; background: #f9fafb;">
            </div>
        </div>
    </div>

    <!-- Action Footer -->
    <div class="card-footer" style="background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 16px 24px; display: flex; align-items: center; justify-content: space-between;">
        <div style="display: flex; gap: 12px;">
            <button class="btn btn-primary" data-action="save-next" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; background: #3b82f6; color: white; border: none; border-radius: 6px; cursor: pointer;">
                <span>💾</span> حفظ
            </button>
            <button class="btn btn-secondary" data-action="skip" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; background: white; color: #64748b; border: 1px solid #cbd5e1; border-radius: 6px; cursor: pointer;">
                تجاوز
            </button>
        </div>
        
        <div style="display: flex; gap: 8px;">
            <button class="btn btn-ghost" data-action="extend" style="padding: 8px 14px; background: transparent; border: 1px solid #cbd5e1; border-radius: 6px; cursor: pointer;">تمديد</button>
            <button class="btn btn-ghost" data-action="reduce" style="padding: 8px 14px; background: transparent; border: 1px solid #cbd5e1; border-radius: 6px; cursor: pointer;">تخفيض</button>
            <button class="btn btn-ghost" data-action="release" style="padding: 8px 14px; background: transparent; border: 1px solid #cbd5e1; color: #dc2626; border-radius: 6px; cursor: pointer;">إفراج</button>
        </div>
    </div>
</div>
