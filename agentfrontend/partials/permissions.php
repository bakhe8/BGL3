<div class="glass-card">
    <div class="card-header">صلاحيات حرجة</div>
    <?php if (empty($permissionIssues)): ?>
        <p style="color: var(--success); font-weight: 600;">كل شيء سليم</p>
    <?php else: ?>
        <ul style="padding-left: 18px; color: var(--danger); font-size: 0.9rem;">
            <?php foreach($permissionIssues as $perm): ?>
                <li><?= htmlspecialchars($perm) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
