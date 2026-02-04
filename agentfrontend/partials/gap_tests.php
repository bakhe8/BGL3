<div class="glass-card">
    <div class="card-header">اختبارات الفجوات (مختصر)</div>
    <ul style="list-style: none; padding: 0; margin: 0;">
        <li style="padding: 8px 0; border-bottom: 1px solid var(--glass-border);">
            <strong>حماية حد المعدّل</strong>
            <span style="color: <?= $gapRateLimit['passed'] ? 'var(--success)' : 'var(--danger)' ?>; font-weight:700; margin-right:10px;">
                <?= $gapRateLimit['passed'] ? 'نجاح' : 'فشل' ?>
            </span>
            <?php if(!$gapRateLimit['passed']): ?>
                <div style="color: var(--text-secondary); font-size: 0.85rem;">لا توجد دلائل على حد المعدّل في `autoload/routes`.</div>
            <?php else: ?>
                <div style="color: var(--text-secondary); font-size: 0.85rem;">الأدلة: <?= htmlspecialchars(implode(', ', $gapRateLimit['evidence'])) ?></div>
            <?php endif; ?>
        </li>
    </ul>
</div>
