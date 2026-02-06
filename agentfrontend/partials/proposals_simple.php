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
                    <?php $planPath = $p['plan_path'] ?? ''; $planExists = (bool)($p['plan_exists'] ?? false); ?>
                    <div style="margin-top:6px; font-size:0.8rem; color: var(--muted);">
                        الخطة المرتبطة:
                        <?php if (!empty($planPath)): ?>
                            <span style="color: <?= $planExists ? 'var(--success)' : 'var(--danger)' ?>;">
                                <?= htmlspecialchars($planPath) ?>
                            </span>
                        <?php else: ?>
                            <span>غير مرتبطة</span>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex; gap:10px; margin-top:8px;">
                        <form method="POST" data-live="1" data-remove="proposal">
                            <input type="hidden" name="action" value="apply_proposal">
                            <input type="hidden" name="proposal_id" value="<?= htmlspecialchars($p['id'] ?? '') ?>">
                            <?php if (!empty($planPath)): ?>
                                <input type="hidden" name="plan_path" value="<?= htmlspecialchars($planPath) ?>">
                            <?php endif; ?>
                            <button type="submit" class="btn" style="padding:6px 10px;border:1px solid var(--accent-cyan);background:rgba(0,242,255,0.08);color:var(--accent-cyan);">
                                تطبيق في الساندبوكس
                            </button>
                        </form>
                        <form method="POST" data-live="1" data-remove="proposal">
                            <input type="hidden" name="action" value="force_apply">
                            <input type="hidden" name="proposal_id" value="<?= htmlspecialchars($p['id'] ?? '') ?>">
                            <?php if (!empty($planPath)): ?>
                                <input type="hidden" name="plan_path" value="<?= htmlspecialchars($planPath) ?>">
                            <?php endif; ?>
                            <button type="submit" class="btn" style="padding:6px 10px;border:1px solid var(--danger);background:rgba(255,77,77,0.08);color:var(--danger);">
                                تطبيق مباشر (إنتاج)
                            </button>
                        </form>
                    </div>
                    <?php if (empty($planPath)): ?>
                        <form method="POST" data-live="1" style="margin-top:6px;">
                            <input type="hidden" name="action" value="generate_plan">
                            <input type="hidden" name="proposal_id" value="<?= htmlspecialchars($p['id'] ?? '') ?>">
                            <button type="submit" class="btn" style="padding:6px 10px;border:1px solid var(--accent-2);background:rgba(255,185,70,0.08);color:var(--accent-2);">
                                توليد خطة تلقائياً
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php if (empty($planPath) && !empty($patchPlans)): ?>
                        <form method="POST" data-live="1" style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                            <input type="hidden" name="action" value="attach_plan">
                            <input type="hidden" name="proposal_id" value="<?= htmlspecialchars($p['id'] ?? '') ?>">
                            <select name="plan_path" style="min-width:200px; padding:6px; border-radius:8px; border:1px solid var(--glass-border); background:rgba(10,16,24,0.8); color:var(--text-primary);">
                                <?php foreach ($patchPlans as $pl): ?>
                                    <option value="<?= htmlspecialchars($pl['path'] ?? '') ?>"><?= htmlspecialchars($pl['path'] ?? '') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn" style="padding:6px 10px;border:1px solid var(--accent-cyan);background:rgba(0,242,255,0.08);color:var(--accent-cyan);">
                                ربط خطة
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php if (!empty($planPath)): ?>
                        <form method="POST" data-live="1" style="margin-top:6px;">
                            <input type="hidden" name="action" value="clear_plan">
                            <input type="hidden" name="proposal_id" value="<?= htmlspecialchars($p['id'] ?? '') ?>">
                            <button type="submit" class="btn" style="padding:6px 10px;border:1px solid var(--accent-2);background:rgba(255,185,70,0.08);color:var(--accent-2);">
                                إزالة الخطة
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php
                        $pid = $p['id'] ?? '';
                        $diffPath = $projectRoot . '/.bgl_core/logs/proposal_' . $pid . '_sandbox.diff';
                    ?>
                    <?php if (!empty($pid) && file_exists($diffPath)): ?>
                        <?php $diffSnippet = bgl_read_diff_snippet($diffPath, 140); ?>
                        <details style="margin-top:10px;">
                            <summary style="cursor:pointer; color: var(--accent-cyan);">عرض آخر Diff (ساندبوكس)</summary>
                            <pre style="white-space:pre-wrap; font-size:0.78rem; margin-top:8px; padding:10px; border-radius:10px; background:rgba(8,12,18,0.9); border:1px solid var(--line);"><?= htmlspecialchars($diffSnippet) ?></pre>
                            <a href="agent-dashboard.php?diff=<?= htmlspecialchars((string)$pid) ?>" target="_blank" style="font-size:0.8rem; color: var(--accent-cyan);">فتح الملف كامل</a>
                        </details>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
