<?php
$latestReportTs = $latestReport['timestamp'] ?? null;
$latestReportTime = $latestReportTs ? date('Y-m-d H:i', (int)$latestReportTs) : 'غير متوفر';
$healthScore = $latestReport['health_score'] ?? ($stats['health_score'] ?? null);
$routeScanLimit = $latestReport['route_scan_limit'] ?? null;
$scanDuration = $latestReport['scan_duration_seconds'] ?? null;
$reportPath = $projectRoot . '/.bgl_core/logs/latest_report.html';
$reportExists = file_exists($reportPath);
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
    <div style="margin-top:10px; font-size:0.8rem; color: var(--muted);">
        ملف التقرير: <code>.bgl_core/logs/latest_report.html</code>
    </div>
</section>
