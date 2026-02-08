<section class="glass-card">
    <div class="card-header">نشر آمن (Canary)</div>
    <?php
        $summary = is_array($canaryStatus['summary'] ?? null) ? $canaryStatus['summary'] : [];
        $total = (int)($summary['total'] ?? 0);
        $active = (int)($summary['active'] ?? 0);
        $recent = is_array($summary['recent'] ?? null) ? $summary['recent'] : [];
        $ok = isset($canaryStatus['ok']) ? (bool)$canaryStatus['ok'] : false;
    ?>
    <div class="section-grid-auto">
        <div class="stat-box">
            <div class="stat-value" style="color: <?= $ok ? 'var(--success)' : 'var(--danger)' ?>;">
                <?= $ok ? 'OK' : 'WARN' ?>
            </div>
            <div class="stat-label">حالة المراقبة</div>
        </div>
        <div class="stat-box">
            <div class="stat-value" style="color: var(--accent-cyan);">
                <?= $total ?>
            </div>
            <div class="stat-label">إجمالي الإصدارات</div>
        </div>
        <div class="stat-box">
            <div class="stat-value" style="color: var(--accent-secondary);">
                <?= $active ?>
            </div>
            <div class="stat-label">قيد المراقبة</div>
        </div>
    </div>

    <div style="margin-top:12px;">
        <div style="font-weight:600; margin-bottom:6px;">آخر الإصدارات</div>
        <?php if (empty($recent)): ?>
            <div style="color: var(--muted); font-style: italic;">لا توجد إصدارات Canary بعد.</div>
        <?php else: ?>
            <?php foreach ($recent as $item): ?>
                <div style="padding:6px 0; border-bottom:1px solid var(--line);">
                    <div style="display:flex; justify-content:space-between; gap:8px;">
                        <strong><?= htmlspecialchars((string)($item['release_id'] ?? '')) ?></strong>
                        <span style="font-size:0.8rem; color: var(--muted);">
                            <?= htmlspecialchars((string)($item['status'] ?? '')) ?>
                        </span>
                    </div>
                    <?php if (!empty($item['notes'])): ?>
                        <div style="font-size:0.85rem; color: var(--text-secondary);">
                            <?= htmlspecialchars((string)$item['notes']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>
