<?php
/**
 * Partial: Preview Section
 * Letter preview - Server-rendered, togglable with JS
 * Required variables: $record (guarantee data)
 */

if (!isset($record)) {
    return;
}
?>

<div id="preview-section" class="preview-section" style="display: none;">
    <header class="preview-header">
        <span class="preview-title" data-preview-title>📄 معاينة الخطاب</span>
        <button class="preview-print" data-action="print" data-print-type="extension">&#x1F5A8; طباعة</button>
    </header>
    <div class="preview-body">
        <div class="letter-paper">
            <div class="letter-header">
                <div class="letter-to">إلى: <span data-preview-target="bank_name"><?= htmlspecialchars($record['bank_name'] ?? '') ?></span></div>
                <div class="letter-greeting">السلام عليكم ورحمة الله وبركاته</div>
            </div>
            <div class="letter-body">
                <p><strong>الموضوع:</strong> طلب تمديد الضمان البنكي رقم <span data-preview-target="guarantee_number"><?= htmlspecialchars($record['guarantee_number'] ?? '') ?></span></p>
                
                <p>نشير إلى الضمان البنكي <span data-preview-target="type"><?= htmlspecialchars($record['type'] ?? '') ?></span> المشار إليه أعلاه والصادر لصالحنا من قبلكم بتاريخ <span data-preview-target="issue_date"><?= htmlspecialchars($record['issue_date'] ?? '') ?></span> بمبلغ وقدره <strong><span data-preview-target="amount"><?= number_format($record['amount'] ?? 0, 0, '.', ',') ?></span> ريال سعودي</strong> لصالح المورد <strong><span data-preview-target="supplier_name"><?= htmlspecialchars($record['supplier_name'] ?? '') ?></span></strong> بموجب العقد رقم <span data-preview-target="contract_number"><?= htmlspecialchars($record['contract_number'] ?? '') ?></span>.</p>
                
                <p>نرجو التكرم بتمديد صلاحية الضمان المذكور أعلاه لمدة إضافية حتى تاريخ <strong><span data-preview-target="expiry_date"><?= htmlspecialchars($record['expiry_date'] ?? '') ?></span></strong>.</p>
                
                <p>شاكرين لكم حسن تعاونكم،،،</p>
            </div>
            <div style="margin-top: 40px; text-align: left;">
                <p><strong>مستشفى الملك فيصل التخصصي</strong></p>
                <p>قسم الشؤون المالية</p>
            </div>
        </div>
    </div>
</div>
