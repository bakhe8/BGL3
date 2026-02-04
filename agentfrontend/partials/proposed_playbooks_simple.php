<section class="glass-card" id="proposed-playbooks">
    <div class="card-header">خطط تشغيل مقترحة (بانتظار موافقتك)</div>
    <?php
        $proposed_dir = __DIR__ . '/../brain/playbooks_proposed/';
        $files = glob(__DIR__ . '/../../.bgl_core/brain/playbooks_proposed/*.md');
    ?>
    <?php $hasPlaybooks = !empty($files); ?>
    <p data-empty="playbook" style="color: var(--text-secondary); font-style: italic; <?= $hasPlaybooks ? 'display:none;' : '' ?>">لا توجد خطط تشغيل مقترحة.</p>
    <?php if ($hasPlaybooks): ?>
        <?php foreach($files as $file): ?>
            <?php
                $pid = basename($file, '.md');
                $meta = @yaml_parse_file($file) ?: [];
                $origin = $meta['origin'] ?? null;
                $confidence = $meta['confidence'] ?? null;
                $maturity = $meta['maturity']['level'] ?? null;
            ?>
            <div style="padding:10px 0; border-bottom:1px solid var(--glass-border);" data-item="playbook">
                <strong>🆕 <?= htmlspecialchars($pid) ?></strong><br>
                المصدر: <?= htmlspecialchars($origin ?? 'غير متوفر') ?> |
                ثقة: <?= htmlspecialchars($confidence ?? 'غير متوفر') ?> |
                نضج: <?= htmlspecialchars($maturity ?? 'غير متوفر') ?>
                <div style="margin-top:6px;">
                    <a class="btn" href="<?= '/.bgl_core/brain/playbooks_proposed/' . $pid . '.md' ?>" target="_blank">مراجعة</a>
                    <a class="btn" href="?action=approve_playbook&id=<?= urlencode($pid) ?>" data-live-link="1" data-remove="playbook">اعتماد ودمج</a>
                    <a class="btn danger" href="?action=reject_playbook&id=<?= urlencode($pid) ?>" data-live-link="1" data-remove="playbook">رفض</a>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
