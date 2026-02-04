<section id="proposals" class="glass-card">
    <div class="card-header">اقتراحات الوكيل</div>
    <?php $hasProposals = !empty($proposals); ?>
    <p data-empty="proposal" style="color: var(--text-secondary); font-style: italic; <?= $hasProposals ? 'display:none;' : '' ?>">لا توجد اقتراحات حالياً.</p>
    <?php if ($hasProposals): ?>
        <ul style="list-style:none; padding:0; margin:0;">
            <?php foreach($proposals as $p): ?>
                <li style="padding:10px 0; border-bottom:1px solid var(--glass-border);" data-item="proposal">
                    <strong style="color: var(--accent-cyan);"><?= htmlspecialchars($p['name'] ?? $p['id'] ?? 'اقتراح') ?></strong>
                    <?php if(!empty($p['description'])): ?>
                        <div style="color: var(--text-primary); font-size:0.9rem;"><?= htmlspecialchars($p['description']) ?></div>
                    <?php endif; ?>
                    <div style="color: var(--text-secondary); font-size:0.8rem; margin-top:4px;">
                        النضج: <?= htmlspecialchars($p['maturity']['level'] ?? 'غير متوفر') ?> |
                        تعارضات: <?= !empty($p['conflicts_with']) ? count($p['conflicts_with']) : 0 ?>
                        <?php if (!empty($p['status'])): ?>
                             | الحالة: 
                             <?php if ($p['status'] === 'success'): ?>
                                <span style="color:var(--accent-cyan);font-weight:bold;">✅ تم الاختبار</span>
                             <?php elseif ($p['status'] === 'success_direct'): ?>
                                <span style="color:var(--danger);font-weight:bold;">⚠️ تم التطبيق</span>
                             <?php else: ?>
                                <span><?= htmlspecialchars($p['status']) ?></span>
                             <?php endif; ?>
                        <?php endif; ?>
                        <?php if (!empty($p['status_note'])): ?>
                            <div style="display:block; margin-top:4px; font-style:italic; color:var(--text-secondary);">
                                📝 النتيجة: <?= htmlspecialchars($p['status_note']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex; gap:10px; margin-top:8px;">
                        <form method="POST" data-live="1" data-remove="proposal">
                            <input type="hidden" name="action" value="apply_proposal">
                            <input type="hidden" name="proposal_id" value="<?= htmlspecialchars($p['id'] ?? '') ?>">
                            <button type="submit" class="btn" style="padding:6px 10px;border:1px solid var(--accent-cyan);background:rgba(0,242,255,0.08);color:var(--accent-cyan);">
                                تطبيق في الساندبوكس
                            </button>
                        </form>
                        <form method="POST" data-live="1" data-remove="proposal">
                            <input type="hidden" name="action" value="force_apply">
                            <input type="hidden" name="proposal_id" value="<?= htmlspecialchars($p['id'] ?? '') ?>">
                            <button type="submit" class="btn" style="padding:6px 10px;border:1px solid var(--danger);background:rgba(255,77,77,0.08);color:var(--danger);">
                                تطبيق مباشر (إنتاج)
                            </button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
