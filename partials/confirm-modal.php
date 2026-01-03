<?php
/**
 * Global Confirmation Modal Partial
 * included in index.php
 */
?>
<div id="bgl-confirm-overlay" style="display: none; position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:99999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:white;padding:24px;border-radius:12px;text-align:center;min-width:320px;box-shadow:0 10px 25px rgba(0,0,0,0.2);transform:scale(0.9);animation:popIn 0.2s forwards;">
        <style>@keyframes popIn { to { transform: scale(1); } }</style>
        <h3 style="margin:0 0 16px;color:#1e293b;font-size:18px">تأكيد الإجراء</h3>
        <p id="bgl-confirm-message" style="margin-bottom:24px;color:#64748b;font-size:15px;line-height:1.5"></p>
        <div style="display:flex;justify-content:center;gap:12px">
            <button id="bgl-confirm-yes" class="btn btn-primary" style="background:#2563eb;color:white;border:none;padding:8px 20px;border-radius:6px;cursor:pointer">نعم، تابع</button>
            <button id="bgl-confirm-no" class="btn btn-secondary" style="background:#e2e8f0;color:#475569;border:none;padding:8px 20px;border-radius:6px;cursor:pointer">إلغاء</button>
        </div>
    </div>
</div>
