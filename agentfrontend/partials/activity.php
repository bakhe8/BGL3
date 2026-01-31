<div class="glass-card" style="grid-column: span 3;">
    <div class="card-header">أحدث نشاطات الوكيل (Activity Feed)</div>
    <div style="display: flex; flex-direction: column; gap: 10px;">
        <?php if(empty($activities)): ?>
            <p style="color: var(--text-secondary); font-style: italic;">لا توجد نشاطات مسجلة حالياً...</p>
        <?php else: ?>
            <?php foreach($activities as $act): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; background: rgba(255,255,255,0.02); padding: 10px; border-radius: 8px; border-right: 3px solid <?= $act['status'] === 'ERROR' ? 'var(--danger)' : 'var(--success)' ?>;">
                    <div>
                        <strong style="color: var(--accent-gold); font-size: 0.85rem;">[<?= $act['type'] ?>]</strong>
                        <span style="font-size: 0.9rem; margin-right: 10px;"><?= htmlspecialchars($act['message']) ?></span>
                    </div>
                    <span style="font-size: 0.75rem; color: var(--text-secondary);"><?= date('H:i:s', (int)$act['timestamp']) ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
