<section class="glass-card">
    <div class="card-header">التعلم التراكمي (تغذية النية)</div>
    <?php
        $changes = is_array($learningFeedback['changes'] ?? null) ? $learningFeedback['changes'] : [];
        $stats = is_array($learningFeedback['stats'] ?? null) ? $learningFeedback['stats'] : [];
        $lookback = $learningFeedback['lookback_days'] ?? null;
        $ok = isset($learningFeedback['ok']) ? (bool)$learningFeedback['ok'] : false;
    ?>
    <div class="section-grid-auto">
        <div class="stat-box">
            <div class="stat-value" style="color: <?= $ok ? 'var(--success)' : 'var(--danger)' ?>;">
                <?= $ok ? 'OK' : 'WARN' ?>
            </div>
            <div class="stat-label">حالة التعلم</div>
        </div>
        <div class="stat-box">
            <div class="stat-value" style="color: var(--accent-cyan);">
                <?= $lookback !== null ? (int)$lookback : 'غير متوفر' ?>
            </div>
            <div class="stat-label">نافذة التقييم (أيام)</div>
        </div>
        <div class="stat-box">
            <div class="stat-value" style="color: var(--accent-secondary);">
                <?= count($changes) ?>
            </div>
            <div class="stat-label">تعديلات bias مطبقة</div>
        </div>
        <div class="stat-box">
            <div class="stat-value" style="color: var(--accent-gold);">
                <?= count($stats) ?>
            </div>
            <div class="stat-label">مصادر hint مقيمة</div>
        </div>
    </div>

    <div style="margin-top:12px;">
        <div style="font-weight:600; margin-bottom:6px;">آخر التعديلات</div>
        <?php if (empty($changes)): ?>
            <div style="color: var(--muted); font-style: italic;">لا توجد تغييرات تلقائية مؤخراً.</div>
        <?php else: ?>
            <?php foreach ($changes as $chg): ?>
                <div style="padding:6px 0; border-bottom:1px solid var(--line); font-size:0.9rem;">
                    <?= htmlspecialchars((string)$chg) ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div style="margin-top:12px;">
        <div style="font-weight:600; margin-bottom:6px;">ملخص الأداء لكل مصدر</div>
        <?php if (empty($stats)): ?>
            <div style="color: var(--muted); font-style: italic;">لا يوجد بيانات أداء بعد.</div>
        <?php else: ?>
            <?php foreach ($stats as $src => $row): ?>
                <div style="padding:6px 0; border-bottom:1px solid var(--line);">
                    <div style="display:flex; justify-content:space-between; gap:8px;">
                        <strong><?= htmlspecialchars((string)$src) ?></strong>
                        <span style="color: var(--muted); font-size:0.8rem;">
                            إجمالي: <?= (int)($row['total'] ?? 0) ?> | نجاح: <?= (int)($row['success'] ?? 0) ?> | فشل: <?= (int)($row['fail'] ?? 0) ?>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>
