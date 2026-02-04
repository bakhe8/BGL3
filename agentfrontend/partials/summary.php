<section class="glass-card">
    <div class="card-header">ملخص سريع</div>
    <div class="section-grid-auto">
        <div class="stat-box">
            <div class="stat-value" data-live="pending-playbooks" style="color: var(--accent-gold);"><?= (int)$pendingPlaybooks ?></div>
            <div class="stat-label">خطط تشغيل تنتظر موافقتك</div>
        </div>
        <div class="stat-box">
            <div class="stat-value" data-live="proposal-count" style="color: var(--accent-secondary);"><?= count($proposals ?? []) ?></div>
            <div class="stat-label">اقتراحات الوكيل</div>
        </div>
        <div class="stat-box">
            <div class="stat-value" data-live="experience-total" style="color: var(--accent-cyan);">
                <?= (int)($experienceStats['total'] ?? 0) ?>
            </div>
            <div class="stat-label">إجمالي الخبرات المسجلة</div>
        </div>
        <div class="stat-box">
            <div class="stat-value" data-live="experience-recent" style="color: var(--accent-gold);">
                <?= (int)($experienceStats['recent'] ?? 0) ?>
            </div>
            <div class="stat-label">خبرات جديدة (آخر ساعة)</div>
        </div>
    <div class="stat-box">
        <div class="stat-value" data-live="permission-issues-count" style="color: <?= empty($permissionIssues) ? 'var(--success)' : 'var(--danger)' ?>;">
            <?= empty($permissionIssues) ? '0' : count($permissionIssues) ?>
        </div>
        <div class="stat-label">مشاكل صلاحيات</div>
    </div>
        <?php if (!empty($callgraphMeta)): ?>
        <div class="stat-box">
            <div class="stat-value" style="color: var(--accent-cyan);"><?= $callgraphMeta['total_routes'] ?? 0 ?></div>
            <div class="stat-label">مسارات مفهرسة</div>
        </div>
        <?php endif; ?>
    </div>
    <div style="margin-top:10px; color: var(--text-secondary); font-size:0.85rem;">
        آخر تحديث للخبرات: <span data-live="experience-last"><?= !empty($experienceStats['last_ts']) ? date('H:i:s', (int)$experienceStats['last_ts']) : 'غير متوفر' ?></span>
    </div>
    <?php if (!empty($perfMetrics)): ?>
    <div style="margin-top:10px; color: var(--text-secondary); font-size:0.9rem;">
        أداء الواجهة: <?= isset($perfMetrics['home_status']) ? 'HTTP '.$perfMetrics['home_status'] : 'غير متوفر' ?> في <?= $perfMetrics['home_load_ms'] ?? '?' ?> مللي ثانية (<?= $perfMetrics['home_bytes'] ?? '?' ?> بايت)
    </div>
    <?php endif; ?>
</section>
