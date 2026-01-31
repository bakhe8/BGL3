<div class="glass-card">
    <div class="card-header">أسوأ المسارات (حسب الأخطاء الحديثة)</div>
    <?php if (empty($worstRoutes)): ?>
        <p style="color: var(--success);">لا مشاكل رصدت مؤخراً</p>
    <?php else: ?>
        <table style="width:100%; color: var(--text-primary); font-size: 0.9rem;">
            <tr><th>Route</th><th>Score</th><th>HTTP fails</th><th>Net fails</th></tr>
            <?php foreach($worstRoutes as $wr): ?>
                <tr>
                    <td><?= htmlspecialchars($wr['route']) ?></td>
                    <td><?= (int)$wr['score'] ?></td>
                    <td><?= (int)$wr['http_fail'] ?></td>
                    <td><?= (int)$wr['net_fail'] ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</div>
