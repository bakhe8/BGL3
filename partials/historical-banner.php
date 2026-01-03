<?php
/**
 * Historical Banner Partial
 * Shows a banner indicating the user is viewing a historical snapshot.
 */
?>
<div id="historical-banner" class="historical-banner">
    <div style="display: flex; align-items: center; justify-content: space-between; 
                background: #fef3c7; border: 2px solid #f59e0b; border-radius: 8px; 
                padding: 12px 16px; margin-bottom: 16px;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <span style="font-size: 20px;">🕰️</span>
            <div>
                <div style="font-weight: 600; color: #92400e;">نسخة تاريخية</div>
                <div style="font-size: 12px; color: #78350f;">تعرض الحالة قبل حدوث التغيير</div>
            </div>
        </div>
        <button data-action="timeline-load-current" 
                style="background: #f59e0b; color: white; border: none; 
                       padding: 8px 16px; border-radius: 6px; font-weight: 600; 
                       cursor: pointer; transition: background 0.2s;"
                onmouseover="this.style.background='#d97706'"
                onmouseout="this.style.background='#f59e0b'">
            ↩️ العودة للوضع الحالي
        </button>
    </div>
</div>
