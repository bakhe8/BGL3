<section class="glass-card" id="domain-map">
    <div class="card-header">خريطة الدومين (Domain Map)</div>
    <?php if ($domainMap): ?>
        <div class="card-row">
            <div>
                <div class="stat-label" style="margin-bottom:6px;">الكيانات</div>
                <ul style="list-style:none; padding:0; margin:0;">
                    <?php foreach(($domainMap['entities'] ?? []) as $name => $info): ?>
                        <li style="padding:6px 0; border-bottom:1px solid var(--glass-border);">
                            <strong style="color: var(--accent-gold);"><?= htmlspecialchars($name) ?></strong>
                            <?php if(!empty($info['description'])): ?>
                                <div style="color: var(--text-secondary); font-size:0.9rem;"><?= htmlspecialchars($info['description']) ?></div>
                            <?php endif; ?>
                            <?php if(!empty($info['key_fields'])): ?>
                                <div style="color: var(--text-secondary); font-size:0.85rem;">Fields: <?= htmlspecialchars(implode(', ', (array)$info['key_fields'])) ?></div>
                            <?php endif; ?>
                            <?php if(!empty($info['lifecycle'])): ?>
                                <div style="color: var(--text-secondary); font-size:0.85rem;">Lifecycle: <?= htmlspecialchars(implode(' → ', (array)$info['lifecycle'])) ?></div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div>
                <div class="stat-label" style="margin-bottom:6px;">العلاقات</div>
                <ul style="padding-left:18px; color: var(--text-primary);">
                    <?php foreach(($domainMap['relations'] ?? []) as $rel): ?>
                        <li><?= htmlspecialchars($rel) ?></li>
                    <?php endforeach; ?>
                </ul>
                <div class="stat-label" style="margin:10px 0 4px;">القواعد الثابتة</div>
                <ul style="padding-left:18px; color: var(--text-primary);">
                    <?php foreach(($domainMap['invariants'] ?? []) as $inv): ?>
                        <li><?= htmlspecialchars($inv) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div>
                <div class="stat-label" style="margin-bottom:6px;">KPIs</div>
                <ul style="list-style:none; padding:0; margin:0;">
                    <?php foreach(($domainMap['operational_kpis'] ?? []) as $kpi): ?>
                        <li style="padding:6px 0; border-bottom:1px solid var(--glass-border);">
                            <strong><?= htmlspecialchars($kpi['name'] ?? '') ?></strong>
                            <div style="color: var(--text-secondary); font-size:0.85rem;">
                                الهدف: <?= htmlspecialchars($kpi['target'] ?? '') ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php elseif ($domainMapRaw): ?>
        <pre style="white-space:pre-wrap; color: var(--text-secondary); background: rgba(255,255,255,0.02); padding:12px; border-radius:10px; border:1px solid var(--glass-border);"><?= htmlspecialchars($domainMapRaw) ?></pre>
    <?php else: ?>
        <p style="color: var(--text-secondary);">لم يتم العثور على docs/domain_map.yml</p>
    <?php endif; ?>
</section>
