<div class="glass-card rules-list" style="grid-column: span 3;">
    <div class="card-header">الدستور المعماري النشط (Architectural Constitution)</div>
    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
        <?php foreach($laws as $law): ?>
            <div class="rule-item" style="border-right-color: <?= strtolower($law['action']) === 'block' ? 'var(--danger)' : 'var(--accent-gold)' ?>;">
                <div class="rule-info">
                    <h4>[<?= $law['id'] ?>] <?= $law['name'] ?></h4>
                    <p><?= $law['description'] ?></p>
                </div>
                <span class="rule-action <?= strtolower($law['action']) === 'block' ? 'action-block' : 'action-warn' ?>">
                    <?= $law['action'] ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
</div>
