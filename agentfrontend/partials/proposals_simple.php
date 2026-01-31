<section class="glass-card">
    <div class="card-header">اقتراحات الوكيل (Proposals)</div>
    <?php if (empty($proposals)): ?>
        <p style="color: var(--text-secondary); font-style: italic;">لا توجد اقتراحات حالياً.</p>
    <?php else: ?>
        <ul style="list-style:none; padding:0; margin:0;">
            <?php foreach($proposals as $p): ?>
                <li style="padding:10px 0; border-bottom:1px solid var(--glass-border);">
                    <strong style="color: var(--accent-cyan);"><?= htmlspecialchars($p['name'] ?? $p['id'] ?? 'proposal') ?></strong>
                    <?php if(!empty($p['description'])): ?>
                        <div style="color: var(--text-primary); font-size:0.9rem;"><?= htmlspecialchars($p['description']) ?></div>
                    <?php endif; ?>
                    <div style="color: var(--text-secondary); font-size:0.8rem; margin-top:4px;">
                        النضج: <?= htmlspecialchars($p['maturity']['level'] ?? 'n/a') ?> |
                        تعارضات: <?= !empty($p['conflicts_with']) ? count($p['conflicts_with']) : 0 ?>
                    </div>
                    <form method="POST" style="margin-top:8px;">
                        <input type="hidden" name="action" value="apply_proposal">
                        <input type="hidden" name="proposal_id" value="<?= htmlspecialchars($p['id'] ?? '') ?>">
                        <button type="submit" class="btn" style="padding:6px 10px;border:1px solid var(--accent-cyan);background:rgba(0,242,255,0.08);color:var(--accent-cyan);">
                            تطبيق في الساندبوكس
                        </button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
