<section class="glass-card">
    <div class="card-header">تنقية المعرفة</div>
    <?php
        $counts = is_array($knowledgeStatus['counts'] ?? null) ? $knowledgeStatus['counts'] : [];
        $byType = is_array($knowledgeStatus['by_type'] ?? null) ? $knowledgeStatus['by_type'] : [];
        $conflicts = is_array($knowledgeStatus['conflicts'] ?? null) ? $knowledgeStatus['conflicts'] : [];
        $ok = isset($knowledgeStatus['ok']) ? (bool)$knowledgeStatus['ok'] : false;
    ?>
    <div class="section-grid-auto">
        <div class="stat-box">
            <div class="stat-value" style="color: <?= $ok ? 'var(--success)' : 'var(--danger)' ?>;">
                <?= $ok ? 'OK' : 'WARN' ?>
            </div>
            <div class="stat-label">حالة التنقية</div>
        </div>
        <div class="stat-box">
            <div class="stat-value" style="color: var(--accent-cyan);">
                <?= (int)($counts['total'] ?? 0) ?>
            </div>
            <div class="stat-label">إجمالي عناصر المعرفة</div>
        </div>
        <div class="stat-box">
            <div class="stat-value" style="color: var(--accent-secondary);">
                <?= (int)($counts['active'] ?? 0) ?>
            </div>
            <div class="stat-label">عناصر فعّالة</div>
        </div>
        <div class="stat-box">
            <div class="stat-value" style="color: var(--accent-gold);">
                <?= (int)($counts['legacy'] ?? 0) ?>
            </div>
            <div class="stat-label">عناصر قديمة (Legacy)</div>
        </div>
        <div class="stat-box">
            <div class="stat-value" style="color: var(--danger);">
                <?= (int)($counts['stale'] ?? 0) + (int)($counts['stale_age'] ?? 0) ?>
            </div>
            <div class="stat-label">معرفة راكدة</div>
        </div>
        <div class="stat-box">
            <div class="stat-value" style="color: var(--accent-2);">
                <?= (int)($counts['superseded'] ?? 0) ?>
            </div>
            <div class="stat-label">تم استبدالها</div>
        </div>
        <div class="stat-box">
            <div class="stat-value" style="color: var(--danger);">
                <?= (int)($counts['conflicts'] ?? 0) ?>
            </div>
            <div class="stat-label">تعارضات معرفة</div>
        </div>
    </div>
    <div style="margin-top:10px; font-size:0.85rem; color: var(--text-secondary);">
        مصادر المعرفة: <?= !empty($byType) ? htmlspecialchars(json_encode($byType, JSON_UNESCAPED_UNICODE)) : 'غير متوفر' ?>
    </div>

    <div style="margin-top:14px;">
        <div style="font-weight:600; margin-bottom:6px;">أهم التعارضات</div>
        <?php if (empty($conflicts)): ?>
            <div style="color: var(--muted); font-style: italic;">لا توجد تعارضات مسجلة.</div>
        <?php else: ?>
            <?php foreach ($conflicts as $c): ?>
                <div style="padding:8px 0; border-bottom:1px solid var(--line);">
                    <div style="font-size:0.85rem; color: var(--muted);">
                        <?= htmlspecialchars((string)($c['key'] ?? 'غير معروف')) ?>
                    </div>
                    <div style="font-size:0.9rem;">
                        الفائز: <?= htmlspecialchars((string)($c['winner_path'] ?? 'غير متوفر')) ?>
                    </div>
                    <?php if (!empty($c['reason'])): ?>
                        <div style="font-size:0.8rem; color: var(--muted);">السبب: <?= htmlspecialchars((string)$c['reason']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>
