<section id="plan-library" class="glass-card">
    <div class="card-header">مكتبة خطط الكتابة</div>
    <p style="color: var(--text-secondary); font-size:0.9rem; margin-top:6px;">رفع خطة كتابة أو تطبيق خطة موجودة عبر الساندبوكس أو مباشرة.</p>

    <form method="POST" enctype="multipart/form-data" data-live="1" style="margin-top:12px; display:flex; flex-direction:column; gap:8px;">
        <input type="hidden" name="action" value="upload_plan">
        <label style="font-size:0.85rem; color: var(--muted);">رفع خطة جديدة</label>
        <input type="file" name="plan_file" accept=".json,.yml,.yaml" required>
        <input type="text" name="proposal_id" placeholder="ربط بالخطة لاقتراح (اختياري)" style="padding:8px; border-radius:8px; border:1px solid var(--glass-border); background:transparent; color:var(--text-primary);">
        <button type="submit" class="btn" style="padding:8px 10px; border:1px solid var(--accent-cyan); background:rgba(0,242,255,0.08); color:var(--accent-cyan);">
            رفع وربط
        </button>
    </form>

    <div style="margin-top:16px;">
        <label style="font-size:0.85rem; color: var(--muted);">تطبيق خطة موجودة</label>
        <?php $hasPlans = !empty($patchPlans); ?>
        <?php if (!$hasPlans): ?>
            <p style="color: var(--text-secondary); font-style: italic;">لا توجد خطط محفوظة بعد.</p>
        <?php else: ?>
            <form method="POST" data-live="1" style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                <input type="hidden" name="action" value="apply_plan">
                <select name="plan_path" style="min-width:220px; padding:8px; border-radius:8px; border:1px solid var(--glass-border); background:rgba(10,16,24,0.8); color:var(--text-primary);">
                    <?php foreach ($patchPlans as $pl): ?>
                        <option value="<?= htmlspecialchars($pl['path'] ?? '') ?>">
                            <?= htmlspecialchars($pl['path'] ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="proposal_id" placeholder="ID اقتراح (اختياري)" style="padding:8px; border-radius:8px; border:1px solid var(--glass-border); background:transparent; color:var(--text-primary);">
                <button type="submit" class="btn" style="padding:8px 10px; border:1px solid var(--accent-cyan); background:rgba(0,242,255,0.08); color:var(--accent-cyan);">
                    تطبيق في الساندبوكس
                </button>
            </form>
            <form method="POST" data-live="1" style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                <input type="hidden" name="action" value="force_plan">
                <select name="plan_path" style="min-width:220px; padding:8px; border-radius:8px; border:1px solid var(--glass-border); background:rgba(10,16,24,0.8); color:var(--text-primary);">
                    <?php foreach ($patchPlans as $pl): ?>
                        <option value="<?= htmlspecialchars($pl['path'] ?? '') ?>">
                            <?= htmlspecialchars($pl['path'] ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="proposal_id" placeholder="ID اقتراح (اختياري)" style="padding:8px; border-radius:8px; border:1px solid var(--glass-border); background:transparent; color:var(--text-primary);">
                <button type="submit" class="btn" style="padding:8px 10px; border:1px solid var(--danger); background:rgba(255,77,77,0.08); color:var(--danger);">
                    تطبيق مباشر (إنتاج)
                </button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($hasPlans): ?>
        <div style="margin-top:14px;">
            <div style="font-size:0.85rem; color: var(--muted);">الخطط المحفوظة:</div>
            <ul style="list-style:none; padding:0; margin:8px 0 0;">
                <?php foreach (array_slice($patchPlans, 0, 8) as $pl): ?>
                    <li style="padding:6px 0; border-bottom:1px solid var(--line);">
                        <div style="font-size:0.9rem; color: var(--text-primary);">
                            <?= htmlspecialchars($pl['path'] ?? '') ?>
                        </div>
                        <div style="font-size:0.75rem; color: var(--muted);">
                            <?= !empty($pl['mtime']) ? date('Y-m-d H:i', (int)$pl['mtime']) : '' ?> · <?= isset($pl['size']) ? round(((int)$pl['size']) / 1024, 1) . ' KB' : '' ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</section>
