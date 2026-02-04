<div class="glass-card">
    <div class="card-header">أهداف الاستكشاف (Autonomy Goals)</div>
    <div id="goals-list">
        <?php if (empty($autonomyGoals)): ?>
            <p style="color: var(--text-secondary); font-style: italic;">لا توجد أهداف تلقائية حالياً.</p>
        <?php else: ?>
            <?php foreach($autonomyGoals as $g): ?>
                <div style="padding:6px 0; border-bottom:1px solid var(--glass-border);">
                    <div style="display:flex; justify-content:space-between;">
                        <strong><?= htmlspecialchars($g['goal'] ?? '') ?></strong>
                        <span style="font-size:0.75rem; color: var(--text-secondary);"><?= !empty($g['created_at']) ? date('H:i', (int)$g['created_at']) : '' ?></span>
                    </div>
                    <div style="font-size:0.85rem; color: var(--text-secondary);"><?= htmlspecialchars($g['source'] ?? '') ?></div>
                    <div style="font-size:0.9rem; margin-top:4px;">
                        <?php if (!empty($g['payload']['uri'])): ?>
                            <?= htmlspecialchars($g['payload']['uri']) ?>
                        <?php elseif (!empty($g['payload']['href'])): ?>
                            <?= htmlspecialchars($g['payload']['href']) ?>
                        <?php elseif (!empty($g['payload']['key'])): ?>
                            <?= htmlspecialchars($g['payload']['key']) ?>: <?= htmlspecialchars((string)($g['payload']['from'] ?? '')) ?> → <?= htmlspecialchars((string)($g['payload']['to'] ?? '')) ?>
                        <?php elseif (!empty($g['payload']['message'])): ?>
                            <?= htmlspecialchars($g['payload']['message']) ?>
                        <?php else: ?>
                            <?= htmlspecialchars(json_encode($g['payload'], JSON_UNESCAPED_UNICODE)) ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
