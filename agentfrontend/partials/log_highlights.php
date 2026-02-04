<div class="glass-card">
    <div class="card-header">أبرز اللوقز</div>
    <div id="logs-list">
        <?php if (empty($logHighlights)): ?>
            <p style="color: var(--text-secondary); font-style: italic;">لا توجد رسائل حرجة مؤخراً.</p>
        <?php else: ?>
            <?php foreach($logHighlights as $l): ?>
                <div style="padding:6px 0; border-bottom:1px solid var(--glass-border);">
                    <div style="font-size:0.8rem; color: var(--text-secondary);"><?= htmlspecialchars($l['source'] ?? '') ?></div>
                    <div style="font-size:0.9rem;"><?= htmlspecialchars($l['message'] ?? '') ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
