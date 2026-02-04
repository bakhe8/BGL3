<section class="glass-card">
    <div class="card-header">طلبات الموافقة (Pending)</div>
    <?php $hasPerms = !empty($permissions); ?>
    <p data-empty="permission" style="color: var(--success); font-weight: 600; <?= $hasPerms ? 'display:none;' : '' ?>">لا توجد طلبات معلّقة</p>
    <?php if ($hasPerms): ?>
        <ul style="list-style:none; padding:0; margin:0;">
            <?php foreach ($permissions as $perm): ?>
                <li style="padding:10px 0; border-bottom:1px solid var(--glass-border);" data-item="permission">
                    <div style="display:flex; justify-content:space-between; gap:10px;">
                        <div>
                            <strong style="color: var(--accent-cyan);">
                                <?= htmlspecialchars($perm['operation'] ?? 'operation') ?>
                            </strong>
                            <?php if (!empty($perm['command'])): ?>
                                <div style="color: var(--text-secondary); font-size:0.85rem; margin-top:4px;">
                                    <?= htmlspecialchars($perm['command']) ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($perm['timestamp'])): ?>
                                <div style="color: var(--text-secondary); font-size:0.75rem; margin-top:4px;">
                                    <?= date('Y-m-d H:i', (int)$perm['timestamp']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex; gap:8px; align-items:center;">
                            <form method="POST" data-live="1" data-remove="permission">
                                <input type="hidden" name="action" value="permission">
                                <input type="hidden" name="perm_id" value="<?= (int)$perm['id'] ?>">
                                <input type="hidden" name="status" value="GRANTED">
                                <button type="submit" class="btn" style="padding:6px 10px;border:1px solid var(--accent-cyan);background:rgba(0,242,255,0.08);color:var(--accent-cyan);">
                                    موافقة
                                </button>
                            </form>
                            <form method="POST" data-live="1" data-remove="permission">
                                <input type="hidden" name="action" value="permission">
                                <input type="hidden" name="perm_id" value="<?= (int)$perm['id'] ?>">
                                <input type="hidden" name="status" value="DENIED">
                                <button type="submit" class="btn" style="padding:6px 10px;border:1px solid var(--danger);background:rgba(255,77,77,0.08);color:var(--danger);">
                                    رفض
                                </button>
                            </form>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
