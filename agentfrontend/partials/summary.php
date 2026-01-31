<section class="glass-card">
    <div class="card-header">ملخص سريع</div>
    <div class="section-grid-auto">
        <div class="stat-box">
            <div class="stat-value" style="color: var(--success);"><?= empty($failing) ? 'PASS' : 'WARN' ?></div>
            <div class="stat-label">حالة النظام</div>
        </div>
        <div class="stat-box">
            <div class="stat-value" style="color: var(--accent-gold);"><?= (int)$pendingPlaybooks ?></div>
            <div class="stat-label">Playbooks تنتظر موافقتك</div>
        </div>
        <div class="stat-box">
            <div class="stat-value" style="color: var(--accent-secondary);"><?= count($proposals ?? []) ?></div>
            <div class="stat-label">اقتراحات الوكيل</div>
        </div>
    <div class="stat-box">
        <div class="stat-value" style="color: <?= empty($permissionIssues) ? 'var(--success)' : 'var(--danger)' ?>;">
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
    <div style="margin-top:14px; display:flex; flex-wrap:wrap; gap:10px;">
        <form method="POST">
            <input type="hidden" name="action" value="assure">
            <button class="btn primary" type="submit">تشغيل فحص جديد</button>
        </form>
        <a class="btn ghost" href="#proposed-playbooks">اذهب للـPlaybooks</a>
        <a class="btn ghost" href="#proposals">اذهب للاقتراحات</a>
        <button class="btn ghost" type="button" onclick="location.reload()">تحديث الصفحة</button>
    </div>
    <?php if (!empty($perfMetrics)): ?>
    <div style="margin-top:10px; color: var(--text-secondary); font-size:0.9rem;">
        أداء الواجهة: <?= isset($perfMetrics['home_status']) ? 'HTTP '.$perfMetrics['home_status'] : 'n/a' ?> في <?= $perfMetrics['home_load_ms'] ?? '?' ?> ms (<?= $perfMetrics['home_bytes'] ?? '?' ?> bytes)
    </div>
    <?php endif; ?>
</section>
