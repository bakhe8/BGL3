<?php if (!empty($blockers)): ?>
    <?php foreach($blockers as $b): ?>
    <div class="blocker-alert">
        <div class="blocker-icon">!</div>
        <div class="blocker-content">
            <h3>تحدي معرفي: العميل عالق (Cognitive Blocker)</h3>
            <p><strong>المهمة:</strong> <?= htmlspecialchars($b['task_name']) ?></p>
            <p><strong>السبب:</strong> <?= htmlspecialchars($b['reason']) ?></p>
            <div class="blocker-footer">
                <span>الأولوية: <?= htmlspecialchars($b['priority'] ?? 'N/A') ?></span>
                <span>تاريخ: <?= htmlspecialchars($b['timestamp']) ?></span>
            </div>
            <form method="POST" style="margin-top:10px;">
                <input type="hidden" name="action" value="resolve">
                <input type="hidden" name="blocker_id" value="<?= (int)$b['id'] ?>">
                <button type="submit" class="btn-intervention">تأكيد الحل اليدوي</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
