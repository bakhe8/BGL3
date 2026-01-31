<section class="glass-card" id="kpis">
    <div class="card-header">مؤشرات الأداء (KPIs)</div>
    <?php if (!empty($domainMap['operational_kpis'] ?? [])): ?>
        <div class="section-grid-auto">
            <?php foreach($domainMap['operational_kpis'] as $kpi): ?>
                <div class="stat-box" style="padding:10px 0;">
                    <div class="stat-label" style="color: var(--text-secondary); font-size:0.85rem;">
                        <?= htmlspecialchars($kpi['name'] ?? '') ?>
                    </div>
                    <div class="stat-value" style="font-size: 1.6rem; color: var(--accent-primary);">
                        <?= htmlspecialchars($kpi['target'] ?? 'n/a') ?>
                    </div>
                    <?php if (!empty($kpi['scope'])): ?>
                        <div style="color: var(--text-secondary); font-size:0.8rem;">
                            نطاق: <?= htmlspecialchars(implode(', ', (array)$kpi['scope'])) ?>
                        </div>
                    <?php endif; ?>
                    <?php
                        $key = $kpi['name'] ?? '';
                        $current = $kpiCurrent[$key] ?? null;
                        $unit = str_ends_with($key, '_rate') ? '%' : (str_ends_with($key, '_ms') ? ' ms' : '');
                    ?>
                    <div style="color: var(--text-secondary); font-size:0.8rem; margin-top:4px;">
                        current: <?= $current !== null ? htmlspecialchars($current . $unit) : 'n/a' ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p style="color: var(--text-secondary);">لم يتم تعريف KPIs بعد في domain_map.yml.</p>
    <?php endif; ?>
</section>
