<?php
$ts = $latestReport['timestamp'] ?? null;
$health = $latestReport['health_score'] ?? null;
$runtimeCount = $latestReport['runtime_events_meta']['count'] ?? null;
$scanLimit = $latestReport['route_scan_limit'] ?? null;
$readinessOk = $latestReport['readiness']['ok'] ?? null;
$llmState = strtoupper((string)($llmStatus['state'] ?? 'UNKNOWN'));
?>
<section class="glass-card">
  <div class="card-header">لقطة سريعة</div>
  <div class="section-grid-auto">
    <div>
      <div class="stat-label">آخر تقرير</div>
      <div class="stat-value" data-live="snapshot-timestamp" style="font-size:1.2rem;"><?= $ts ? date('Y-m-d H:i', (int)$ts) : 'غير متوفر' ?></div>
    </div>
    <div>
      <div class="stat-label">الأحداث التشغيلية</div>
      <div class="stat-value" data-live="snapshot-runtime" style="font-size:1.2rem;"><?= $runtimeCount !== null ? (int)$runtimeCount : 'غير متوفر' ?></div>
    </div>
    <div>
      <div class="stat-label">حد فحص المسارات</div>
      <div class="stat-value" data-live="snapshot-route-scan-limit" style="font-size:1.2rem;"><?= $scanLimit !== null ? (int)$scanLimit : 'غير متوفر' ?></div>
    </div>
    <div>
      <div class="stat-label">الجاهزية</div>
      <div class="stat-value" data-live="snapshot-readiness" style="font-size:1.2rem; color: <?= $readinessOk ? 'var(--success)' : 'var(--danger)' ?>;">
        <?= $readinessOk === null ? 'غير متوفر' : ($readinessOk ? 'سليم' : 'يتطلب مراجعة') ?>
      </div>
    </div>
  </div>
</section>
