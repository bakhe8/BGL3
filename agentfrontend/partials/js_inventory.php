<section class="glass-card" id="js-inventory">
    <div class="card-header">ملفات JS الأكبر</div>
    <?php
    $jsInvPath = realpath(__DIR__ . '/../../.bgl_core/brain/js_inventory.json');
    $jsData = [];
    if (file_exists($jsInvPath)) {
        $jsData = json_decode(file_get_contents($jsInvPath), true) ?: [];
    }
    if (empty($jsData)): ?>
        <p style="color: var(--text-secondary);">لا توجد بيانات مفهرسة للـJS.</p>
    <?php else:
        $top = array_slice($jsData, 0, 5);
    ?>
        <table class="table-lite">
            <tr><th>الملف</th><th>الحجم (KB)</th><th>الأسطر</th></tr>
            <?php foreach($top as $f): ?>
                <tr>
                    <td><?= htmlspecialchars($f['path']) ?></td>
                    <td><?= round($f['bytes']/1024, 1) ?></td>
                    <td><?= (int)$f['lines'] ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</section>
