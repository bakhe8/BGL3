<?php
$latestReportTs = $latestReport['timestamp'] ?? null;
$latestReportTime = $latestReportTs ? date('Y-m-d H:i', (int)$latestReportTs) : 'غير متوفر';
$healthScore = $latestReport['health_score'] ?? ($stats['health_score'] ?? null);
$routeScanLimit = $latestReport['route_scan_limit'] ?? null;
$scanDuration = $latestReport['scan_duration_seconds'] ?? null;
$reportPath = $projectRoot . '/.bgl_core/logs/latest_report.html';
$reportExists = file_exists($reportPath);
$attr = $latestReport['diagnostic_attribution'] ?? ($latestReport['findings']['diagnostic_attribution'] ?? []);
$attrClass = is_array($attr) ? ($attr['classification'] ?? null) : null;
$attrConf = is_array($attr) ? ($attr['confidence'] ?? null) : null;
$attrSignals = is_array($attr) ? ($attr['signals'] ?? []) : [];
?>
<section class="ops-card span-6" id="report-viewer">
    <div class="card-title">التقرير التشخيصي</div>
    <div class="card-subtitle">عرض سريع لآخر تقرير وتشغيله الكامل عند الحاجة.</div>
    <div class="metric-grid">
        <div class="metric-card">
            <div class="value"><?= $healthScore !== null ? round((float)$healthScore, 1) . '%' : 'غير متوفر' ?></div>
            <div class="label">Health Score</div>
        </div>
        <div class="metric-card">
            <div class="value"><?= htmlspecialchars($latestReportTime) ?></div>
            <div class="label">آخر تقرير</div>
        </div>
        <div class="metric-card">
            <div class="value"><?= $routeScanLimit !== null ? (int)$routeScanLimit : 'غير متوفر' ?></div>
            <div class="label">مسارات مفحوصة</div>
        </div>
        <div class="metric-card">
            <div class="value"><?= $scanDuration !== null ? round((float)$scanDuration, 2) . 's' : 'غير متوفر' ?></div>
            <div class="label">مدة الفحص</div>
        </div>
    </div>
    <div class="action-grid" style="margin-top:14px;">
        <a class="btn primary" href="agent-dashboard.php?report=latest" target="_blank" rel="noopener">
            <?= $reportExists ? 'فتح التقرير الكامل' : 'التقرير غير متوفر' ?>
        </a>
        <a class="btn ghost" href="agent-dashboard.php?report=template" target="_blank" rel="noopener">عرض قالب التقرير</a>
    </div>
    <div style="margin-top:12px; font-size:0.85rem;">
        <div style="color: var(--text-secondary); margin-bottom:4px;">إسناد التغيّر</div>
        <?php if (!empty($attrClass)): ?>
            <div>
                التصنيف: <strong><?= htmlspecialchars((string)$attrClass) ?></strong>
                <?php if ($attrConf !== null): ?>
                    • الثقة: <?= htmlspecialchars((string)$attrConf) ?>
                <?php endif; ?>
            </div>
            <div style="color: var(--text-secondary); font-size:0.8rem;">
                مفاتيح متغيّرة: <?= (int)($attrSignals['changed_keys'] ?? 0) ?>
                • اختبارات داخلية: <?= (int)($attrSignals['test_events'] ?? 0) ?>
                • كتابات داخلية: <?= (int)($attrSignals['write_events'] ?? 0) ?>
            </div>
        <?php else: ?>
            <div style="color: var(--muted); font-style: italic;">لا يوجد إسناد مسجّل بعد.</div>
        <?php endif; ?>
    </div>
    <div style="margin-top:10px; font-size:0.8rem; color: var(--muted);">
        ملف التقرير: <code>.bgl_core/logs/latest_report.html</code>
    </div>
</section>
