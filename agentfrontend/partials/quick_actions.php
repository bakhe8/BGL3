<?php
// Quick action bar: master verify, digest, open events, toggle auto/assisted
?>
<div class="card-row" style="margin: 16px 0;">
    <form method="POST">
        <input type="hidden" name="action" value="assure">
        <button type="submit" class="btn primary">تشغيل Master Verify</button>
    </form>
    <form method="POST">
        <input type="hidden" name="action" value="digest">
        <button type="submit" class="btn secondary">تحديث ذاكرة الخبرة (Digest)</button>
    </form>
    <a href="/api/agent-event.php" class="btn ghost" target="_blank">قناة الأحداث المباشرة</a>
    <form method="POST">
        <input type="hidden" name="action" value="toggle_mode">
        <button type="submit" class="btn ghost">
            تبديل نمط الوكيل (<?= htmlspecialchars($agentMode ?? 'assisted') ?>)
        </button>
    </form>
    <form method="POST">
        <input type="hidden" name="action" value="run_scenarios">
        <button type="submit" class="btn primary">تشغيل السيناريوهات الآن</button>
    </form>
    <form method="POST">
        <input type="hidden" name="action" value="run_api_contract">
        <button type="submit" class="btn secondary">اختبارات العقد API</button>
    </form>
    <form method="POST">
        <input type="hidden" name="action" value="restart_browser">
        <button type="submit" class="btn ghost" style="border-color: var(--danger); color: var(--danger);">
            إعادة تشغيل المتصفح
        </button>
    </form>
</div>
