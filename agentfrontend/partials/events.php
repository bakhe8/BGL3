<div class="glass-card">
    <div class="card-header">أحداث آنية من المتصفح (Runtime Events)</div>
    <?php if (empty($events)): ?>
        <p style="color: var(--text-secondary); font-style: italic;">لم تُلتقط أحداث حتى الآن.</p>
    <?php else: ?>
        <ul style="list-style: none; padding: 0; margin: 0;">
            <?php foreach($events as $ev): ?>
                <li style="padding: 8px 0; border-bottom: 1px solid var(--glass-border);">
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: var(--accent-cyan); font-weight: 600;"><?= htmlspecialchars($ev['event_type']) ?></span>
                        <span style="color: var(--text-secondary); font-size: 0.8rem;"><?= date('H:i:s', (int)$ev['timestamp']) ?></span>
                    </div>
                    <div style="color: var(--text-primary); font-size: 0.9rem;">
                        <?= htmlspecialchars($ev['route'] ?? '/') ?> | <?= htmlspecialchars($ev['method'] ?? '') ?>
                        <?php if(!empty($ev['status'])): ?>
                            <span style="color: <?= ((int)$ev['status'] >=400) ? 'var(--danger)' : 'var(--success)' ?>;">[<?= $ev['status'] ?>]</span>
                        <?php endif; ?>
                        <?php if(!empty($ev['latency_ms'])): ?>
                            <span style="color: var(--accent-gold);"> (<?= round($ev['latency_ms']) ?> ms)</span>
                        <?php endif; ?>
                    </div>
                    <?php if(!empty($ev['error'])): ?>
                        <div style="color: var(--danger); font-size: 0.9rem;">⚠ <?= htmlspecialchars($ev['error']) ?></div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
