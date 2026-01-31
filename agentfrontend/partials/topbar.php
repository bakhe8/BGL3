<header class="topbar">
    <div class="brand">
        <span class="logo">🧠</span>
        <div>
            <div class="brand-name">BGL3 COMMAND CENTER</div>
            <div class="brand-sub">الواجهة الذكية المتقدمة لوكيل النظام</div>
        </div>
    </div>
    <div class="badge-live" style="border-color: <?= ($executionMode === 'direct') ? 'var(--danger)' : 'rgba(0,255,136,0.2)' ?>; color: <?= ($executionMode === 'direct') ? 'var(--danger)' : 'var(--success)' ?>;">
        <div class="pulse"></div>
        <?= ($executionMode === 'direct') ? 'وضع التنفيذ: مباشر (تحذير)' : 'وضع التنفيذ: ساندبوكس' ?>
        <span style="margin-right:10px; font-size:0.8rem; color: var(--text-secondary);">محاولات direct: <?= (int)$directAttempts ?></span>
    </div>
    <div class="actions">
        <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="assure">
            <button class="btn primary" type="submit">تشغيل فحص جديد</button>
        </form>
        <button class="btn" onclick="location.reload()">تحديث الصفحة</button>
    </div>
</header>
