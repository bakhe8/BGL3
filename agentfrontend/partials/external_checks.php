<section class="glass-card">
    <div class="card-header">تحذيرات الأنماط الخارجية (Checks)</div>
    <?php if (empty($externalChecks)): ?>
        <p style="color: var(--text-secondary);">لا توجد تحذيرات حالياً.</p>
    <?php else: ?>
        <ul style="list-style:none; padding:0; margin:0;">
            <?php foreach($externalChecks as $c): 
                $passed = !empty($c['passed']);
                $cls = $passed ? 'badge-ok' : 'badge-warn';
                $label = $passed ? 'PASS' : 'WARN/FAIL';
            ?>
                <li style="padding:8px 0; border-bottom:1px solid var(--glass-border);">
                    <span class="badge <?= $cls ?>"><?= $label ?></span>
                    <strong><?= htmlspecialchars($c['id'] ?? $c['check'] ?? 'check') ?></strong>
                    <?php if (!empty($c['evidence'])): ?>
                        <div style="color:var(--text-secondary); font-size:0.85rem; margin-top:4px;">
                            <?= htmlspecialchars(is_array($c['evidence']) ? implode('; ', $c['evidence']) : $c['evidence']) ?>
                        </div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
