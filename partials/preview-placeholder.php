<?php
/**
 * Preview Placeholder Partial - No Action State
 * Displayed when a guarantee is READY but no action (Extend/Reduce/Release) has been selected.
 */
?>
<div id="preview-section-content" class="preview-no-action-state" style="
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 400px;
    text-align: center;
    padding: 40px;
    color: var(--text-muted);
">
    <div style="font-size: 64px; margin-bottom: 24px; opacity: 0.6;">📋</div>
    <h3 style="color: var(--text-primary); margin-bottom: 12px; font-size: 20px; font-weight: 600;">ضمان بنكي جاهز</h3>
    <p style="margin-bottom: 8px; font-size: 14px; color: var(--text-secondary);">
        لم يتم اتخاذ أي إجراء على هذا الضمان حتى الآن.
    </p>
    <p style="font-size: 13px; color: var(--text-light);">
        يمكنك تنفيذ إجراء (تمديد، تخفيض، إفراج) عند الحاجة.
    </p>
</div>
