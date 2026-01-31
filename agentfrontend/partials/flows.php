<section class="glass-card" id="flows">
    <div class="card-header">التدفقات الحرجة (Flows)</div>
    <?php if (!empty($flows)): ?>
        <ul style="list-style:none; padding:0; margin:0;">
            <?php foreach($flows as $flow): ?>
                <li style="padding:8px 0; border-bottom:1px solid var(--glass-border); display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <strong style="color: var(--accent-primary);"><?= htmlspecialchars($flow['title']) ?></strong>
                        <div style="color: var(--text-secondary); font-size:0.85rem;"><?= htmlspecialchars($flow['file']) ?></div>
                    </div>
                    <a class="btn ghost" href="<?= '/docs/flows/' . htmlspecialchars($flow['file']) ?>" target="_blank">فتح</a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p style="color: var(--text-secondary);">لم يتم العثور على تدفقات موثقة بعد.</p>
    <?php endif; ?>
</section>
