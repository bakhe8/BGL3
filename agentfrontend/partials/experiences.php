<div class="glass-card" style="grid-column: span 2;">
    <div class="card-header">ذاكرة الخبرة (Experiences)</div>
    <?php if (empty($experiences)): ?>
        <p style="color: var(--text-secondary); font-style: italic;">لم تُسجل تجارب بعد. شغّل السيناريوهات ثم اضغط تحديث الذاكرة.</p>
    <?php else: ?>
        <?php foreach($experiences as $exp): ?>
            <div style="border-bottom: 1px solid var(--glass-border); padding: 10px 0;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <strong style="color: var(--accent-gold);"><?= htmlspecialchars($exp['scenario']) ?></strong>
                    <span style="font-size: 0.85rem; color: var(--text-secondary);">ثقة <?= round($exp['confidence'] * 100) ?>%</span>
                </div>
                <p style="margin: 6px 0; color: var(--text-primary);"><?= htmlspecialchars($exp['summary']) ?></p>
                <span style="font-size: 0.75rem; color: var(--text-secondary);">أدلة: <?= (int)$exp['evidence_count'] ?> | وقت: <?= date('H:i', (int)$exp['created_at']) ?></span>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
