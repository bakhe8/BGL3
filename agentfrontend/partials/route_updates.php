<div class="glass-card">
    <div class="card-header">آخر تحديثات المسارات</div>
    <div id="routes-list">
        <?php if (empty($recentRoutes)): ?>
            <p style="color: var(--text-secondary); font-style: italic;">لا توجد مسارات تم تحديثها مؤخرًا.</p>
        <?php else: ?>
            <?php foreach($recentRoutes as $r): ?>
                <div style="padding:6px 0; border-bottom:1px solid var(--glass-border); display:flex; justify-content:space-between; gap:8px;">
                    <div>
                        <strong><?= htmlspecialchars($r['http_method'] ?? 'GET') ?></strong>
                        <span style="margin-right:6px;"><?= htmlspecialchars($r['uri'] ?? '') ?></span>
                        <div style="font-size:0.75rem; color: var(--text-secondary);">
                            <?= htmlspecialchars(basename((string)($r['file_path'] ?? ''))) ?>
                        </div>
                    </div>
                    <div style="font-size:0.75rem; color: var(--text-secondary);">
                        <?= !empty($r['last_validated']) ? date('H:i', (int)$r['last_validated']) : '' ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
