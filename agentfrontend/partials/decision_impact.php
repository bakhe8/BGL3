<?php
$successDisplay = $successRate !== null ? ($successRate . '%') : 'غير متوفر';
$directDisplay = $directAttempts ?? 0;
$outcomes = $recentOutcomes ?? [];
$proposalChanges = $proposalChangeMap ?? [];
?>
<section class="ops-card span-6" id="decision-impact">
    <div class="card-title">أثر القرارات</div>
    <div class="card-subtitle">تتبّع قرارات الوكيل ونتائجها الأخيرة.</div>

    <div class="metric-grid">
        <div class="metric-card">
            <div class="value"><?= htmlspecialchars((string)$successDisplay) ?></div>
            <div class="label">معدل النجاح</div>
        </div>
        <div class="metric-card">
            <div class="value"><?= (int)$directDisplay ?></div>
            <div class="label">محاولات مباشرة</div>
        </div>
        <div class="metric-card">
            <div class="value"><?= count($outcomes) ?></div>
            <div class="label">نتائج مسجلة</div>
        </div>
    </div>

    <div style="margin-top:14px;">
        <?php if (empty($outcomes)): ?>
            <p style="color: var(--muted); font-style: italic;">لا توجد نتائج مسجلة بعد.</p>
        <?php else: ?>
            <?php foreach ($outcomes as $o): ?>
                <?php
                    $res = (string)($o['result'] ?? '');
                    $tone = in_array($res, ['success','success_with_override','success_direct'], true) ? 'var(--success)' : (in_array($res, ['blocked','skipped'], true) ? 'var(--accent-2)' : 'var(--danger)');
                    $intent = (string)($o['intent'] ?? '');
                    $proposalId = '';
                    if (str_starts_with($intent, 'apply_')) {
                        $proposalId = substr($intent, 6);
                    } elseif (str_starts_with($intent, 'proposal.apply|')) {
                        $parts = explode('|', $intent);
                        $proposalId = $parts[1] ?? '';
                    }
                    $changeEntry = $proposalId !== '' && isset($proposalChanges[$proposalId]) ? $proposalChanges[$proposalId] : null;
                    $changeItems = [];
                    if (is_array($changeEntry)) {
                        $changeItems = $changeEntry['new_changes'] ?? [];
                        if (empty($changeItems)) {
                            $changeItems = $changeEntry['post_changes'] ?? [];
                        }
                    }
                ?>
                <div style="border-bottom:1px solid var(--line); padding:10px 0;">
                    <div style="display:flex; justify-content:space-between; gap:8px;">
                        <strong style="color: <?= $tone ?>;"><?= htmlspecialchars($res) ?></strong>
                        <span style="font-size:0.75rem; color: var(--muted);">
                            <?= !empty($o['timestamp']) ? date('Y-m-d H:i', (int)$o['timestamp']) : '' ?>
                        </span>
                    </div>
                    <div style="font-size:0.85rem;">
                        النية: <?= htmlspecialchars($o['intent'] ?? '') ?> • القرار: <?= htmlspecialchars($o['decision'] ?? '') ?>
                        <?php if (!empty($o['risk_level'])): ?>
                            • المخاطرة: <?= htmlspecialchars($o['risk_level']) ?>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($o['notes'])): ?>
                        <div style="font-size:0.8rem; color: var(--muted); margin-top:4px;">
                            <?= htmlspecialchars($o['notes']) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($proposalId !== ''): ?>
                        <div style="margin-top:6px; font-size:0.8rem; color: var(--muted);">
                            تغييرات الملفات:
                            <?php if (!empty($changeItems)): ?>
                                <ul style="margin:6px 0 0; padding-right:18px;">
                                    <?php foreach (array_slice($changeItems, 0, 6) as $ch): ?>
                                        <li><?= htmlspecialchars(($ch['status'] ?? '') . ' ' . ($ch['path'] ?? '')) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <span>لا تغييرات مسجلة</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>
