<div class="glass-card">
    <div class="card-header">آخر القرارات (Decisions)</div>
    <?php if (empty($recentDecisions)): ?>
        <p style="color: var(--text-secondary); font-style: italic;">لا قرارات مسجلة بعد.</p>
    <?php else: ?>
        <ul style="list-style: none; padding: 0; margin: 0;">
            <?php foreach($recentDecisions as $dec): ?>
                <li style="padding: 10px 0; border-bottom: 1px solid var(--glass-border);">
                    <div style="display:flex; justify-content:space-between;">
                        <strong style="color: var(--accent-cyan);"><?= htmlspecialchars($dec['decision']) ?></strong>
                        <span style="color: var(--text-secondary); font-size: 0.8rem;"><?= htmlspecialchars($dec['created_at']) ?></span>
                    </div>
                    <div style="color: var(--text-primary); font-size: 0.9rem;">
                        intent: <?= htmlspecialchars($dec['intent']) ?> (ثقة <?= round(((float)$dec['confidence']) * 100, 1) ?>%) — مخاطرة: <?= htmlspecialchars($dec['risk_level']) ?>
                    </div>
                    <?php if(!empty($dec['justification'])): ?>
                        <div style="color: var(--text-secondary); font-size: 0.85rem;"><?= htmlspecialchars($dec['justification']) ?></div>
                    <?php endif; ?>
                    <?php if((int)$dec['requires_human'] === 1): ?>
                        <div style="color: var(--danger); font-size: 0.85rem;">يتطلب موافقة بشرية</div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
