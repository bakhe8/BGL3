<?php
$prodDay = $prodOpsSummary['day'] ?? ['total' => 0, 'allowed' => 0, 'blocked' => 0];
$prodWeek = $prodOpsSummary['week'] ?? ['total' => 0, 'allowed' => 0, 'blocked' => 0];
$prodLastTs = $prodOpsSummary['last_ts'] ?? null;
$prodRows = $prodOps['rows'] ?? [];
$prodStatusOptions = $prodOpsFilters['status'] ?? [];
$prodOperationOptions = $prodOpsFilters['operation'] ?? [];
$prodSourceOptions = $prodOpsFilters['source'] ?? [];
$prodFilterDays = (int)($prodFilters['days'] ?? 7);
$prodFilterLimit = (int)($prodFilters['limit'] ?? 60);
$prodFilterStatus = (string)($prodFilters['status'] ?? '');
$prodFilterOperation = (string)($prodFilters['operation'] ?? '');
$prodFilterSource = (string)($prodFilters['source'] ?? '');
$prodFilterScope = (string)($prodFilters['scope'] ?? '');

if (!function_exists('bgl_fmt_ts')) {
    function bgl_fmt_ts($ts): string {
        if (!$ts) return '—';
        return date('Y-m-d H:i:s', (int)$ts);
    }
}

function bgl_status_badge(string $status): array {
    $status = trim($status);
    if ($status === 'allowed') return ['label' => 'مسموح', 'color' => 'var(--success)'];
    if (stripos($status, 'blocked') === 0) return ['label' => 'مرفوض', 'color' => 'var(--danger)'];
    if ($status === '') return ['label' => 'غير معروف', 'color' => 'var(--muted)'];
    return ['label' => $status, 'color' => 'var(--accent-2)'];
}
?>

<section class="ops-card" id="prod-operations">
    <div class="card-title">سجل عمليات الإنتاج</div>
    <div class="card-subtitle">سجل مركزي يوضح كل عمليات الكتابة على الإنتاج (نجاح/رفض/سبب).</div>

    <div class="metric-grid">
        <div class="metric-card">
            <div class="label">آخر 24 ساعة</div>
            <div class="value"><?= htmlspecialchars((string)($prodDay['total'] ?? 0)) ?></div>
            <div style="font-size:0.75rem; color: var(--muted); margin-top:4px;">
                مسموح: <?= htmlspecialchars((string)($prodDay['allowed'] ?? 0)) ?> |
                مرفوض: <?= htmlspecialchars((string)($prodDay['blocked'] ?? 0)) ?>
            </div>
        </div>
        <div class="metric-card">
            <div class="label">آخر 7 أيام</div>
            <div class="value"><?= htmlspecialchars((string)($prodWeek['total'] ?? 0)) ?></div>
            <div style="font-size:0.75rem; color: var(--muted); margin-top:4px;">
                مسموح: <?= htmlspecialchars((string)($prodWeek['allowed'] ?? 0)) ?> |
                مرفوض: <?= htmlspecialchars((string)($prodWeek['blocked'] ?? 0)) ?>
            </div>
        </div>
        <div class="metric-card">
            <div class="label">آخر عملية إنتاج</div>
            <div class="value" style="font-size:1rem;"><?= htmlspecialchars(bgl_fmt_ts($prodLastTs)) ?></div>
            <div style="font-size:0.75rem; color: var(--muted); margin-top:4px;">تاريخ/وقت آخر عملية</div>
        </div>
    </div>

    <div style="margin-top:10px; color: var(--text-secondary); font-size:0.85rem;">
        عرض تلقائي للسجل بدون فلاتر يدوية (التحكم مؤتمت بالكامل).
    </div>

    <div style="margin-top:16px; overflow:auto; max-height:320px;">
        <?php if (!empty($prodRows)): ?>
            <table style="width:100%; border-collapse: collapse; font-size:0.85rem;">
                <thead>
                    <tr style="text-align:right; border-bottom:1px solid var(--line);">
                        <th style="padding:8px;">الوقت</th>
                        <th style="padding:8px;">الحالة</th>
                        <th style="padding:8px;">العملية</th>
                        <th style="padding:8px;">الأمر</th>
                        <th style="padding:8px;">النطاق</th>
                        <th style="padding:8px;">المصدر</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($prodRows as $row): ?>
                        <?php $badge = bgl_status_badge((string)($row['status'] ?? '')); ?>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:8px; white-space:nowrap;"><?= htmlspecialchars(bgl_fmt_ts($row['timestamp'] ?? null)) ?></td>
                            <td style="padding:8px; color: <?= $badge['color'] ?>; font-weight:700;"><?= htmlspecialchars($badge['label']) ?></td>
                            <td style="padding:8px;"><?= htmlspecialchars((string)($row['operation'] ?? '')) ?></td>
                            <td style="padding:8px; max-width:240px; overflow:hidden; text-overflow:ellipsis;">
                                <details>
                                    <summary><?= htmlspecialchars((string)($row['command'] ?? '—')) ?></summary>
                                    <pre class="log-panel" style="margin-top:6px;"><?= htmlspecialchars((string)($row['payload_json'] ?? '')) ?></pre>
                                </details>
                            </td>
                            <td style="padding:8px; max-width:180px; overflow:hidden; text-overflow:ellipsis;">
                                <?= htmlspecialchars((string)($row['scope'] ?? '')) ?>
                            </td>
                            <td style="padding:8px;"><?= htmlspecialchars((string)($row['source'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div style="color: var(--muted);">لا توجد عمليات إنتاج مسجلة ضمن الفلاتر المحددة.</div>
        <?php endif; ?>
    </div>
</section>
