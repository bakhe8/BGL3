<?php
/**
 * Partial: Timeline Section
 * Server-rendered timeline events
 * Required variables: $timeline (array of events)
 */

if (!isset($timeline)) {
    $timeline = [];
}

$eventCount = count($timeline);
?>

<aside class="timeline-panel">
    <header class="timeline-header mb-2 relative">
        <div class="timeline-title">
            <span>⏲️</span>
            <span>Timeline</span>
        </div>
        <span class="timeline-count cursor-help" title="<?= $eventCount ?> أحداث">
            <span><?= $eventCount ?></span> حدث
        </span>
    </header>
    <div class="timeline-body h-full overflow-y-auto">
        <div class="timeline-list">
            <div class="timeline-line"></div>
            
            <?php if (empty($timeline)): ?>
                <div class="text-center text-gray-400 text-sm py-8">
                    لا توجد أحداث في التاريخ
                </div>
            <?php else: ?>
                <?php foreach ($timeline as $index => $event): ?>
                    <div class="timeline-item" style="cursor: pointer;">
                        <div class="timeline-dot <?= $index === 0 ? 'active' : '' ?>"></div>
                        <div class="event-card <?= $index === 0 ? 'current' : '' ?>">
                            <div class="event-header">
                                <span class="event-icon"><?= htmlspecialchars($event['icon'] ?? '📋') ?></span>
                                <span class="event-type"><?= htmlspecialchars($event['action'] ?? 'حدث') ?></span>
                            </div>
                            <div class="event-date"><?= htmlspecialchars($event['created_at'] ?? '') ?></div>
                            <?php if (!empty($event['change_reason'])): ?>
                                <div class="event-note"><?= htmlspecialchars($event['change_reason']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($event['user'])): ?>
                                <div class="event-user">👤 <?= htmlspecialchars($event['user']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</aside>
