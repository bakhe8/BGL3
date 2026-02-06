<?php
$goalOptions = [
    'operator_goal' => 'هدف تشغيلي عام',
    'explore_url' => 'استكشاف رابط',
    'verify_route' => 'تحقق من مسار',
    'gap_deepen' => 'تعميق فجوة معرفية',
];
?>
<section class="ops-card span-6" id="operator-goals">
    <div class="card-title">توجيه مباشر للوكيل</div>
    <div class="card-subtitle">أضف هدفًا يدويًا ليتم أخذه بعين الاعتبار أثناء الاستكشاف الذاتي.</div>
    <form method="POST" data-live="1">
        <input type="hidden" name="action" value="add_goal">
        <div class="split-grid" style="margin-top:0;">
            <div>
                <label>نوع الهدف</label>
                <select name="goal" class="input">
                    <?php foreach ($goalOptions as $value => $label): ?>
                        <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>رابط مستهدف (اختياري)</label>
                <input class="input" name="goal_uri" placeholder="https://example.com/page" />
            </div>
            <div>
                <label>مدة الصلاحية (ساعات)</label>
                <input class="input" type="number" name="expires_hours" value="24" min="0" max="168" />
            </div>
        </div>
        <div style="margin-top:12px;">
            <label>رسالة أو سياق إضافي</label>
            <textarea class="input" name="goal_message" rows="3" placeholder="صف الهدف بوضوح…"></textarea>
        </div>
        <div style="margin-top:12px;">
            <button class="btn primary" type="submit">إرسال الهدف</button>
        </div>
    </form>

    <div style="margin-top:16px;">
        <div class="card-title" style="font-size:0.95rem;">الأهداف الحالية</div>
        <div id="goals-list">
            <?php if (empty($autonomyGoals)): ?>
                <p style="color: var(--muted); font-style: italic;">لا توجد أهداف حالياً.</p>
            <?php else: ?>
                <?php foreach ($autonomyGoals as $g): ?>
                    <div style="border-bottom:1px solid var(--line); padding:10px 0;">
                        <div style="display:flex; justify-content:space-between; gap:8px;">
                            <strong><?= htmlspecialchars($g['goal'] ?? '') ?></strong>
                            <span style="font-size:0.75rem; color: var(--muted);">
                                <?= !empty($g['created_at']) ? date('Y-m-d H:i', (int)$g['created_at']) : '' ?>
                            </span>
                        </div>
                        <div style="font-size:0.8rem; color: var(--muted);">
                            المصدر: <?= htmlspecialchars($g['source'] ?? '') ?>
                            <?php if (!empty($g['expires_at'])): ?>
                                • ينتهي: <?= date('Y-m-d H:i', (int)$g['expires_at']) ?>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($g['payload'])): ?>
                            <div style="margin-top:6px; font-size:0.85rem;">
                                <?= htmlspecialchars(json_encode($g['payload'], JSON_UNESCAPED_UNICODE)) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>
