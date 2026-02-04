<?php
// Quick action bar: master verify, digest, open events, toggle auto/assisted
?>
<div class="card-row" style="margin: 16px 0;">
    <form method="POST" data-live="1">
        <input type="hidden" name="action" value="digest">
        <button type="submit" class="btn secondary">تحديث ذاكرة الخبرة (Digest)</button>
    </form>
    <a href="/api/agent-event.php" class="btn ghost" target="_blank">قناة الأحداث المباشرة</a>
    <form method="POST" data-live="1">
        <input type="hidden" name="action" value="toggle_mode">
        <button type="submit" class="btn ghost">
            <?php
                $agentModeLabel = $agentMode ?? 'assisted';
                if ($agentModeLabel === 'assisted') $agentModeLabel = 'مُساند';
                elseif ($agentModeLabel === 'auto') $agentModeLabel = 'ذاتي';
                elseif ($agentModeLabel === 'safe') $agentModeLabel = 'آمن';
            ?>
            تبديل نمط الوكيل (<?= htmlspecialchars($agentModeLabel) ?>)
        </button>
    </form>
    <form method="POST" data-live="1">
        <input type="hidden" name="action" value="run_scenarios">
        <button type="submit" class="btn primary">تشغيل السيناريوهات الآن</button>
    </form>
    <form method="POST" data-live="1">
        <input type="hidden" name="action" value="run_api_contract">
        <button type="submit" class="btn secondary">اختبارات العقد API</button>
    </form>
    <form method="POST" data-live="1">
        <input type="hidden" name="action" value="restart_browser">
        <button type="submit" class="btn ghost" style="border-color: var(--danger); color: var(--danger);">
            إعادة تشغيل المتصفح
        </button>
    </form>
</div>
