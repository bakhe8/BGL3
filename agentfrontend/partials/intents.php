<div class="glass-card">
    <div class="card-header">النوايا المكتشفة (Intents)</div>
    <?php if (empty($recentIntents)): ?>
        <p style="color: var(--text-secondary); font-style: italic;">لا توجد نوايا مسجلة بعد.</p>
    <?php else: ?>
        <ul style="list-style: none; padding: 0; margin: 0;">
            <?php foreach($recentIntents as $it): ?>
                <li style="padding: 10px 0; border-bottom: 1px solid var(--glass-border);">
                    <div style="display:flex; justify-content:space-between;">
                        <strong style="color: var(--accent-gold);"><?= htmlspecialchars($it['intent']) ?></strong>
                        <span style="color: var(--text-secondary); font-size: 0.8rem;"><?= htmlspecialchars($it['timestamp']) ?></span>
                    </div>
                    <div style="color: var(--text-primary); font-size: 0.9rem;">
                        ثقة <?= round(((float)$it['confidence']) * 100, 1) ?>% — <?= htmlspecialchars($it['reason']) ?>
                    </div>
                    <?php if(!empty($it['scope'])): ?>
                        <div style="color: var(--text-secondary); font-size: 0.85rem;">نطاق: <?= htmlspecialchars($it['scope']) ?></div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
