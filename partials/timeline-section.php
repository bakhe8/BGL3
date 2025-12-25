<?php
/**
 * Partial: Timeline Section
 * Enhanced timeline using TimelineHelper
 * Required variables: $timeline (array of events)
 */

require_once __DIR__ . '/../lib/TimelineHelper.php';

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
                    // Use TimelineHelper for labels and icons
                    $eventLabel = TimelineHelper::getEventDisplayLabel($event);
                    $eventIcon = TimelineHelper::getEventIcon($event);
                    
                    // Parse event_details JSON
                    $eventDetailsRaw = $event['event_details'] ?? null;
                    $details = $eventDetailsRaw ? json_decode($eventDetailsRaw, true) : [];
                    $changes = $details['changes'] ?? [];
                    $statusChange = $details['status_change'] ?? null;
                    $trigger = $details['trigger'] ?? 'manual';
                    
                    // Color mapping based on event label
                    $labelColors = [
                        'استيراد' => ['border' => '#64748b', 'text' => '#334155'],
                        'محاولة استيراد مكرر' => ['border' => '#f59e0b', 'text' => '#92400e'],
                        'تطابق تلقائي' => ['border' => '#3b82f6', 'text' => '#1e40af'],
                        'تعديل يدوي' => ['border' => '#059669', 'text' => '#047857'],
                        'تمديد' => ['border' => '#ca8a04', 'text' => '#a16207'],
                        'تخفيض' => ['border' => '#7c3aed', 'text' => '#5b21b6'],
                        'إفراج' => ['border' => '#dc2626', 'text' => '#991b1b'],
                    ];
                    
                    $colors = $labelColors[$eventLabel] ?? ['border' => '#94a3b8', 'text' => '#475569'];
                    $isFirst = $index === 0;
                    $isLatest = $index === 0;  // Latest event (current state)
                ?>
                    <div class="timeline-event-wrapper" 
                         data-event-id="<?= $event['id'] ?>"
                         data-snapshot='<?= htmlspecialchars($event['snapshot_data'] ?? '{}') ?>'
                         data-is-latest="<?= $isLatest ? '1' : '0' ?>"
                         style="position: relative; padding-right: 12px; margin-bottom: 10px; cursor: pointer;">
                        
                        <!-- Timeline Connector -->
                        <?php if ($index < count($timeline) - 1): ?>
                        <div style="position: absolute; right: 3px; top: 14px; bottom: -10px; width: 2px; background: #e2e8f0;"></div>
                        <?php endif; ?>
                        
                        <!-- Dot -->
                        <div style="position: absolute; right: -2px; top: 8px; width: 10px; height: 10px; border-radius: 50%; background: <?= $colors['border'] ?>; border: 2px solid white; box-shadow: 0 0 0 1px #e2e8f0; z-index: 1;"></div>
                        
                        <!-- Event Card -->
                        <div class="timeline-event-card" style="background: white; border: 1px solid #e2e8f0; border-right: 3px solid <?= $colors['border'] ?>; border-radius: 4px; padding: 10px 12px; margin-right: 16px; transition: all 0.2s;" 
                             onmouseover="this.style.borderRightWidth='4px'; this.style.boxShadow='0 2px 6px rgba(0,0,0,0.1)'"
                             onmouseout="this.style.borderRightWidth='3px'; this.style.boxShadow='none'">
                            
                            <!-- Event Header -->
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <span style="font-size: 14px;"><?= $eventIcon ?></span>
                                    <span style="font-weight: 600; color: <?= $colors['text'] ?>; font-size: 13px;">
                                        <?= htmlspecialchars($eventLabel) ?>
                                    </span>
                                </div>
                                <?php if ($isLatest): ?>
                                <span style="background: #1e293b; color: white; font-size: 9px; font-weight: 600; padding: 2px 6px; border-radius: 2px;">الحالي</span>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Event Changes Details -->
                            <?php if (($event['event_type'] ?? '') === 'modified' && $index < 2): ?>
                            <!-- DEBUG EVENT <?= $event['id'] ?>: raw=<?= htmlspecialchars(substr($event['event_details'] ?? 'NULL', 0, 100)) ?> | changes_count=<?= count($changes) ?> -->
                            <?php endif; ?>
                            <?php if (!empty($changes)): ?>
                            <div style="font-size: 12px; color: #475569; line-height: 1.6; margin: 6px 0; padding: 6px 8px; background: #f8fafc; border-radius: 3px;">
                                <?php foreach ($changes as $change): ?>
                                    <?php
                                    $fieldLabels = [
                                        'supplier_id' => 'المورد',
                                        'bank_id' => 'البنك',
                                        'amount' => 'المبلغ',
                                        'expiry_date' => 'تاريخ الانتهاء'
                                    ];
                                    $fieldLabel = $fieldLabels[$change['field']] ?? $change['field'];
                                    $trigger = $change['trigger'] ?? 'manual';
                                    ?>
                                    
                                    <div style="margin-bottom: 4px;">
                                        <strong style="color: #1e293b;">• <?= $fieldLabel ?>:</strong>
                                        
                                        <?php if ($change['field'] === 'supplier_id' || $change['field'] === 'bank_id'): ?>
                                            <?php
                                            $oldName = $change['old_value']['name'] ?? null;
                                            $newName = $change['new_value']['name'] ?? null;
                                            ?>
                                            
                                            <?php if ($oldName && $oldName !== 'غير محدد'): ?>
                                                <span style="color: #dc2626; text-decoration: line-through; opacity: 0.8;">
                                                    <?= htmlspecialchars($oldName) ?>
                                                </span>
                                                <span style="color: #64748b; margin: 0 4px;">→</span>
                                            <?php endif; ?>
                                            
                                            <span style="color: #059669; font-weight: 500;">
                                                <?= htmlspecialchars($newName ?? '') ?>
                                            </span>
                                            
                                            <?php if (isset($change['confidence'])): ?>
                                                <span style="color: #3b82f6; font-size: 11px; margin-left: 4px;">
                                                    (<?= round($change['confidence']) ?>%)
                                                </span>
                                            <?php endif; ?>
                                            
                                        <?php else: ?>
                                            <?php
                                            $oldVal = $change['old_value'];
                                            $newVal = $change['new_value'];
                                            ?>
                                            
                                            <?php if ($oldVal): ?>
                                                <span style="color: #dc2626; text-decoration: line-through; opacity: 0.8;">
                                                    <?= htmlspecialchars($oldVal) ?>
                                                </span>
                                                <span style="color: #64748b; margin: 0 4px;">→</span>
                                            <?php endif; ?>
                                            
                                            <span style="color: #059669; font-weight: 500;">
                                                <?= htmlspecialchars($newVal ?? '') ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                
                                <?php if ($statusChange): ?>
                                <div style="margin-top: 6px; padding-top: 6px; border-top: 1px solid #e2e8f0;">
                                    <strong style="color: #1e293b;">• الحالة:</strong>
                                    <span style="color: #059669; font-weight: 500;">
                                        <?= htmlspecialchars($statusChange) ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php elseif (!empty($event['change_reason'])): ?>
                            <!-- Action event (extension/reduction/release) with formatted description -->
                            <div style="font-size: 12px; color: #475569; line-height: 1.6; margin: 6px 0; padding: 6px 8px; background: #f8fafc; border-radius: 3px;">
                                <?= $event['change_reason'] /* HTML formatted */ ?>
                            </div>
                            <?php endif; ?>
                            
                            
                            
                            
                            <!-- Date and User -->
                            <div style="font-size: 11px; color: #64748b; margin-top: 6px; padding-top: 6px; border-top: 1px solid #f1f5f9; display: flex; justify-content: space-between;">
                                <span><?= htmlspecialchars($event['created_at'] ?? '') ?></span>
                                <span style="font-weight: 500;"><?= htmlspecialchars($event['created_by'] ?? 'النظام') ?></span>
                            </div>
                            
                            <!-- Click hint -->
                            <div style="font-size: 10px; color: #94a3b8; margin-top: 4px; text-align: center;">
                                <?php if ($isLatest): ?>
                                    👁️ انقر لعرض الحالة الحالية
                                <?php else: ?>
                                    🕐 انقر لعرض الحالة قبل هذا الحدث
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</aside>
