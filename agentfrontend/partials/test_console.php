<?php
$runs = $dashboardRuns ?? [];
$logs = $dashboardTestLogs ?? [];

$testCards = [
    'run_pytest_smoke' => ['label' => 'Pytest (سريع)', 'desc' => 'اختبارات مختصرة للنواة'],
    'run_pytest_full' => ['label' => 'Pytest (كامل)', 'desc' => 'تشغيل كامل اختبارات بايثون'],
    'run_pytest_custom' => ['label' => 'Pytest (مخصص)', 'desc' => 'تشغيل ملفات محددة'],
    'run_phpunit' => ['label' => 'PHPUnit', 'desc' => 'اختبارات PHP الأساسية'],
    'run_ci' => ['label' => 'CI Script', 'desc' => 'تشغيل run_ci.ps1'],
];
?>
<section class="ops-card span-12" id="test-console">
    <div class="card-title">اختبارات وتشخيص سريع</div>
    <div class="card-subtitle">تشغيل الاختبارات الأساسية مباشرة مع سجل آخر تشغيل.</div>

    <div class="action-grid">
        <form method="POST" data-live="1">
            <input type="hidden" name="action" value="run_pytest_smoke">
            <button class="btn secondary" type="submit">Pytest سريع</button>
        </form>
        <form method="POST" data-live="1">
            <input type="hidden" name="action" value="run_pytest_full">
            <button class="btn" type="submit">Pytest كامل</button>
        </form>
        <form method="POST" data-live="1">
            <input type="hidden" name="action" value="run_phpunit">
            <button class="btn" type="submit">PHPUnit</button>
        </form>
        <form method="POST" data-live="1">
            <input type="hidden" name="action" value="run_ci">
            <button class="btn" type="submit">تشغيل CI</button>
        </form>
    </div>

    <div class="split-grid" style="margin-top:18px;">
        <form method="POST" data-live="1" class="ops-card" style="box-shadow:none; border:1px dashed var(--line);">
            <input type="hidden" name="action" value="run_pytest_custom">
            <div class="card-title" style="font-size:0.95rem;">تشغيل Pytest حسب اختيارك</div>
            <div class="card-subtitle">اختر ملفات اختبار محددة أو أضف خيارات Pytest.</div>
            <div style="max-height:220px; overflow:auto; border:1px solid var(--line); border-radius:10px; padding:10px;">
                <?php if (empty($pytestFiles)): ?>
                    <p style="color: var(--muted); font-style: italic;">لا توجد ملفات اختبار متاحة.</p>
                <?php else: ?>
                    <?php foreach ($pytestFiles as $file): ?>
                        <label style="display:flex; align-items:center; gap:8px; font-size:0.8rem; color: var(--muted); margin-bottom:6px;">
                            <input type="checkbox" name="pytest_files[]" value="<?= htmlspecialchars($file) ?>">
                            <?= htmlspecialchars($file) ?>
                        </label>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div style="margin-top:10px;">
                <label>خيارات إضافية (مثل `-k search` أو `-m slow`)</label>
                <input class="input" name="pytest_args" placeholder="-k search" />
            </div>
            <div style="margin-top:10px;">
                <button class="btn primary" type="submit">تشغيل الاختبار المحدد</button>
            </div>
        </form>
    </div>

    <div class="split-grid" style="margin-top:16px;">
        <?php foreach ($testCards as $key => $meta): ?>
            <?php
                $run = $runs[$key] ?? [];
                $ts = isset($run['timestamp']) ? date('Y-m-d H:i', (int)$run['timestamp']) : 'غير متوفر';
                $log = $logs[$key] ?? '';
            ?>
            <div class="metric-card">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <strong><?= htmlspecialchars($meta['label']) ?></strong>
                    <span class="pill"><?= htmlspecialchars($ts) ?></span>
                </div>
                <div style="font-size:0.8rem; color: var(--muted); margin-top:6px;"><?= htmlspecialchars($meta['desc']) ?></div>
                <?php if ($log): ?>
                    <pre class="log-panel" style="margin-top:10px;"><?= htmlspecialchars($log) ?></pre>
                <?php else: ?>
                    <div style="margin-top:10px; font-size:0.8rem; color: var(--muted);">لا يوجد سجل بعد.</div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>
