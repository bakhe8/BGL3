<?php
/**
 * Partial: Timeline Section
 * Professional timeline for work environment
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
            
            <?php if (empty($timeline)): ?>
                <div class="text-center text-gray-400 text-sm py-8">
                    لا توجد أحداث في التاريخ
                </div>
            <?php else: ?>
                <?php foreach ($timeline as $index => $event): 
                    // Professional color scheme - neutral and subtle
                    $typeColors = [
                        'import' => ['border' => '#64748b', 'text' => '#334155'],
                        'decision' => ['border' => '#16a34a', 'text' => '#15803d'],
                        'extension' => ['border' => '#ca8a04', 'text' => '#a16207'],
                        'release' => ['border' => '#dc2626', 'text' => '#991b1b'],
                        'reduction' => ['border' => '#7c3aed', 'text' => '#5b21b6'],
                        'manual_edit' => ['border' => '#475569', 'text' => '#1e293b'],
                        'approve' => ['border' => '#059669', 'text' => '#047857'],
                        'update' => ['border' => '#2563eb', 'text' => '#1e40af'],
                    ];
                    
                    $eventType = $event['type'] ?? $event['action'] ?? 'default';
                    $colors = $typeColors[$eventType] ?? ['border' => '#94a3b8', 'text' => '#475569'];
                    $isFirst = $index === 0;
                ?>
                    <div style="position: relative; padding-right: 12px; margin-bottom: 10px;">
                        <!-- Timeline Connector -->
                        <?php if ($index < count($timeline) - 1): ?>
                        <div style="position: absolute; right: 3px; top: 14px; bottom: -10px; width: 2px; background: #e2e8f0;"></div>
                        <?php endif; ?>
                        
                        <!-- Dot -->
                        <div style="position: absolute; right: -2px; top: 8px; width: 10px; height: 10px; border-radius: 50%; background: <?= $colors['border'] ?>; border: 2px solid white; box-shadow: 0 0 0 1px #e2e8f0; z-index: 1;"></div>
                        
                        <!-- Event Card -->
                        <div style="background: white; border: 1px solid #e2e8f0; border-right: 3px solid <?= $colors['border'] ?>; border-radius: 4px; padding: 10px 12px; margin-right: 16px; transition: border-color 0.2s;" 
                             onmouseover="this.style.borderRightColor='<?= $colors['border'] ?>'; this.style.boxShadow='0 1px 3px rgba(0,0,0,0.1)'"
                             onmouseout="this.style.borderRightColor='<?= $colors['border'] ?>'; this.style.boxShadow='none'">
                            
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <span style="font-size: 14px; filter: grayscale(30%);"><?= htmlspecialchars($event['icon'] ?? '📋') ?></span>
                                    <span style="font-weight: 600; color: <?= $colors['text'] ?>; font-size: 13px;">
                                        <?php
                                        $labels = [
                                            'import' => 'استيراد', 'decision' => 'قرار', 'extension' => 'تمديد',
                                            'release' => 'إفراج', 'reduction' => 'تخفيض', 'manual_edit' => 'تعديل',
                                            'approve' => 'موافقة', 'update' => 'تحديث',
                                        ];
                                        echo $labels[$eventType] ?? htmlspecialchars($event['action'] ?? 'حدث');
                                        ?>
                                    </span>
                                </div>
                                <?php if ($isFirst): ?>
                                <span style="background: #1e293b; color: white; font-size: 9px; font-weight: 600; padding: 2px 6px; border-radius: 2px;">جديد</span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (!empty($event['change_reason']) || !empty($event['description'])): ?>
                            <div style="font-size: 12px; color: #475569; line-height: 1.4; margin: 6px 0; padding: 6px 8px; background: #f8fafc; border-radius: 3px;">
                                <?= htmlspecialchars($event['change_reason'] ?? $event['description'] ?? '') ?>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Date at bottom -->
                            <div style="font-size: 11px; color: #64748b; margin-top: 6px; padding-top: 6px; border-top: 1px solid #f1f5f9;">
                                <?= htmlspecialchars($event['created_at'] ?? $event['date'] ?? '') ?>
                            </div>
                            
                            <?php if (!empty($event['action_status'])): ?>
                            <div style="margin-top: 6px;">
                                <?php
                                $statuses = [
                                    'pending' => ['bg' => '#fef3c7', 'text' => '#92400e', 'label' => 'معلق'],
                                    'issued' => ['bg' => '#d1fae5', 'text' => '#065f46', 'label' => 'منفذ'],
                                    'cancelled' => ['bg' => '#fee2e2', 'text' => '#991b1b', 'label' => 'ملغى'],
                                ];
                                $st = $statuses[$event['action_status']] ?? ['bg' => '#f1f5f9', 'text' => '#475569', 'label' => $event['action_status']];
                                ?>
                                <span style="background: <?= $st['bg'] ?>; color: <?= $st['text'] ?>; font-size: 10px; font-weight: 600; padding: 2px 6px; border-radius: 3px;">
                                    <?= $st['label'] ?>
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</aside>
