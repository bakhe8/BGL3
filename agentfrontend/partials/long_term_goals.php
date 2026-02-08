<section class="glass-card">
    <div class="card-header">الأهداف طويلة المدى</div>
    <?php
        $summary = is_array($longTermGoals['summary'] ?? null) ? $longTermGoals['summary'] : [];
        $total = (int)($summary['total'] ?? 0);
        $active = (int)($summary['active'] ?? 0);
        $top = is_array($summary['top'] ?? null) ? $summary['top'] : [];
        $ok = isset($longTermGoals['ok']) ? (bool)$longTermGoals['ok'] : false;
    ?>
    <div class="section-grid-auto">
        <div class="stat-box">
            <div class="stat-value" style="color: <?= $ok ? 'var(--success)' : 'var(--danger)' ?>;">
                <?= $ok ? 'OK' : 'WARN' ?>
            </div>
            <div class="stat-label">حالة الجدولة</div>
        </div>
        <div class="stat-box">
            <div class="stat-value" style="color: var(--accent-cyan);">
                <?= $total ?>
            </div>
            <div class="stat-label">إجمالي الأهداف</div>
        </div>
        <div class="stat-box">
            <div class="stat-value" style="color: var(--accent-secondary);">
                <?= $active ?>
            </div>
            <div class="stat-label">أهداف فعّالة</div>
        </div>
    </div>

    <div style="margin-top:12px;">
        <div style="font-weight:600; margin-bottom:6px;">أعلى الأولويات</div>
        <?php if (empty($top)): ?>
            <div style="color: var(--muted); font-style: italic;">لا توجد أهداف طويلة المدى بعد.</div>
        <?php else: ?>
            <?php foreach ($top as $item): ?>
                <div style="padding:6px 0; border-bottom:1px solid var(--line);">
                    <div style="display:flex; justify-content:space-between; gap:8px;">
                        <strong><?= htmlspecialchars((string)($item['title'] ?? $item['goal'] ?? 'Goal')) ?></strong>
                        <span style="font-size:0.8rem; color: var(--muted);">P=<?= number_format((float)($item['priority'] ?? 0), 2) ?></span>
                    </div>
                    <div style="font-size:0.85rem; color: var(--text-secondary);">
                        <?= htmlspecialchars((string)($item['goal'] ?? '')) ?>
                    </div>
                    <?php if (!empty($item['last_outcome'])): ?>
                        <div style="font-size:0.8rem; color: var(--muted);">
                            آخر نتيجة: <?= htmlspecialchars((string)$item['last_outcome']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>
