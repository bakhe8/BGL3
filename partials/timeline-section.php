<div class="timeline-panel">
    <div class="timeline-header">
        سجل العمليات
    </div>
    <div class="timeline-body">
        <?php if (empty($timeline)): ?>
            <div style="text-align: center; padding: 20px; color: var(--text-muted);">
                لا توجد سجلات
            </div>
        <?php else: ?>
            <?php foreach ($timeline as $item): ?>
                <div class="timeline-item">
                    <div style="display: flex; gap: 12px; margin-bottom: 16px;">
                        <div class="timeline-icon" style="font-size: 18px; margin-top: 2px;">
                            <?= $item['icon'] ?? '📝' ?>
                        </div>
                        <div style="flex: 1;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                                <span style="font-weight: 600; font-size: 13px;">
                                    <?= htmlspecialchars($item['action'] ?? 'عملية') ?>
                                </span>
                                <span style="font-size: 11px; color: var(--text-light);">
                                    <?= htmlspecialchars($item['date'] ?? '') ?>
                                </span>
                            </div>
                            <?php if (!empty($item['description'])): ?>
                                <div style="font-size: 12px; color: var(--text-secondary);">
                                    <?= htmlspecialchars($item['description']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
