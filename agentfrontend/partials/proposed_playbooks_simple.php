<section class="glass-card" id="proposed-playbooks">
    <div class="card-header">Playbooks مقترحة (بانتظار موافقتك)</div>
    <?php
        $proposed_dir = __DIR__ . '/../brain/playbooks_proposed/';
        $files = glob(__DIR__ . '/../../.bgl_core/brain/playbooks_proposed/*.md');
    ?>
    <?php if (empty($files)): ?>
        <p style="color: var(--text-secondary); font-style: italic;">لا توجد Playbooks مقترحة.</p>
    <?php else: ?>
        <?php foreach($files as $file): ?>
            <?php $pid = basename($file, '.md'); $meta = @yaml_parse_file($file) ?: []; ?>
            <div style="padding:10px 0; border-bottom:1px solid var(--glass-border);">
                <strong>🆕 <?= htmlspecialchars($pid) ?></strong><br>
                Origin: <?= htmlspecialchars($meta['origin'] ?? 'auto_generated') ?> |
                ثقة: <?= htmlspecialchars($meta['confidence'] ?? 0.65) ?> |
                نضج: <?= htmlspecialchars($meta['maturity']['level'] ?? 'experimental') ?>
                <div style="margin-top:6px;">
                    <a class="btn" href="<?= '/.bgl_core/brain/playbooks_proposed/' . $pid . '.md' ?>" target="_blank">مراجعة</a>
                    <a class="btn" href="?action=approve_playbook&id=<?= urlencode($pid) ?>">اعتماد ودمج</a>
                    <a class="btn danger" href="?action=reject_playbook&id=<?= urlencode($pid) ?>">رفض</a>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
