<section class="glass-card" id="kpis">
    <div class="card-header">مؤشرات الأداء</div>
    <?php if (!empty($domainMap['operational_kpis'] ?? [])): ?>
        <?php
            $kpiGroups = [
                'الاستيراد' => ['import_success_rate', 'data_quality_score'],
                'أخطاء API' => ['api_error_rate', 'validation_failure_rate'],
                'الأداء' => ['contract_latency_ms'],
            ];
            $kpiLabels = [
                'import_success_rate' => 'نجاح الاستيراد',
                'api_error_rate' => 'معدل أخطاء API',
                'contract_latency_ms' => 'زمن الاستجابة',
                'validation_failure_rate' => 'فشل التحقق',
                'data_quality_score' => 'جودة البيانات',
            ];
            $kpiIcons = [
                'ok' => '✅',
                'warn' => '⚠️',
                'bad' => '❗',
            ];
            $kpiByName = [];
            foreach ($domainMap['operational_kpis'] as $k) {
                $name = (string)($k['name'] ?? '');
                if ($name !== '') $kpiByName[$name] = $k;
            }
            $renderKpi = function(string $name) use ($kpiByName, $kpiLabels, $kpiCurrent) {
                $kpi = $kpiByName[$name] ?? null;
                if (!$kpi) return;
                $label = $kpiLabels[$name] ?? $name;
                $target = (string)($kpi['target'] ?? 'غير متوفر');
                $scope = $kpiScopes[$name] ?? (array)($kpi['scope'] ?? []);
                $current = $kpiCurrent[$name] ?? null;
                $unit = str_ends_with($name, '_rate') ? '%' : (str_ends_with($name, '_ms') ? ' مللي ثانية' : '');
                $status = 'warn';
                if (is_numeric($current)) {
                    $cur = (float)$current;
                    if (str_contains($target, '<=') || str_contains($target, '≤')) {
                        $status = $cur <= (float)filter_var($target, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) ? 'ok' : 'bad';
                    } elseif (str_contains($target, '>=') || str_contains($target, '≥')) {
                        $status = $cur >= (float)filter_var($target, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) ? 'ok' : 'bad';
                    }
                }
                $slug = preg_replace('/[^a-zA-Z0-9_]+/', '_', (string)$name);
        ?>
            <div class="stat-box" style="padding:10px 0; border-bottom:1px solid var(--glass-border);">
                <div style="display:flex; align-items:center; gap:8px;">
                    <div style="font-weight:700;"><?= htmlspecialchars($label) ?></div>
                    <div style="color: var(--text-secondary); font-size:0.9rem;">(الهدف: <?= htmlspecialchars($target) ?>)</div>
                </div>
                <?php if (!empty($scope)): ?>
                    <div style="color: var(--text-secondary); font-size:0.8rem; margin-top:2px;">
                        النطاق الحالي: <?= htmlspecialchars(implode('، ', $scope)) ?>
                    </div>
                <?php endif; ?>
                <div style="margin-top:6px;">
                    <span style="font-weight:700;">
                        <?= $status === 'ok' ? '✅' : ($status === 'bad' ? '❗' : '⚠️') ?>
                    </span>
                    <span data-live="kpi-<?= htmlspecialchars($slug) ?>-current" style="margin-right:6px;">
                        الحالي: <?= $current !== null ? htmlspecialchars($current . $unit) : 'غير متوفر' ?>
                    </span>
                    <span style="color: var(--text-secondary); font-size:0.85rem;">
                        <?= $status === 'ok' ? 'ضمن الهدف' : ($status === 'bad' ? 'خارج الهدف' : 'غير واضح') ?>
                    </span>
                </div>
            </div>
        <?php }; ?>

        <?php foreach ($kpiGroups as $group => $names): ?>
            <div style="margin-top:12px;">
                <div style="font-weight:700; margin-bottom:6px; color: var(--text-secondary);"><?= htmlspecialchars($group) ?></div>
                <?php foreach ($names as $name) { $renderKpi($name); } ?>
            </div>
        <?php endforeach; ?>

        <div style="margin-top:16px; border-top:1px dashed var(--glass-border); padding-top:12px;">
            <div style="font-weight:700; margin-bottom:6px;">تقرير جودة البيانات (تفصيلي)</div>
            <?php
                $banksTotal = $dataQualityDetails['banks_total'] ?? 0;
                $supTotal = $dataQualityDetails['suppliers_total'] ?? 0;
                $banksInvalid = $dataQualityDetails['banks_invalid'] ?? [];
                $supInvalid = $dataQualityDetails['suppliers_invalid'] ?? [];
            ?>
            <div style="color: var(--text-secondary); font-size:0.9rem;">
                البنوك: <?= (int)$banksTotal ?> | المخالفات: <?= count($banksInvalid) ?>
                — الموردون: <?= (int)$supTotal ?> | المخالفات: <?= count($supInvalid) ?>
            </div>

            <?php if (!empty($banksInvalid)): ?>
                <div style="margin-top:8px;">
                    <div style="font-weight:600; margin-bottom:4px;">بنوك تحتاج تصحيح</div>
                    <ul style="margin:0; padding-left:18px;">
                        <?php foreach ($banksInvalid as $b): ?>
                            <?php $issues = array_filter($b['issues'] ?? []); ?>
                            <li>
                                <?= htmlspecialchars((string)($b['name'] ?? 'غير معروف')) ?>
                                <?= !empty($issues) ? ' — ' . htmlspecialchars(implode('، ', $issues)) : '' ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($supInvalid)): ?>
                <div style="margin-top:8px;">
                    <div style="font-weight:600; margin-bottom:4px;">موردون يحتاجون تصحيح</div>
                    <ul style="margin:0; padding-left:18px;">
                        <?php foreach ($supInvalid as $s): ?>
                            <?php $issues = array_filter($s['issues'] ?? []); ?>
                            <li>
                                <?= htmlspecialchars((string)($s['name'] ?? 'غير معروف')) ?>
                                <?= !empty($issues) ? ' — ' . htmlspecialchars(implode('، ', $issues)) : '' ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <p style="color: var(--text-secondary);">لم يتم تعريف مؤشرات الأداء بعد في `domain_map.yml`.</p>
    <?php endif; ?>
</section>
