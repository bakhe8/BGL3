<?php
$cfg = $effectiveCfg ?? [];
$execution_mode = strtolower((string)($cfg['execution_mode'] ?? 'sandbox'));
$agent_mode = strtolower((string)($cfg['agent_mode'] ?? ($cfg['decision']['mode'] ?? 'assisted')));
$decision_mode = strtolower((string)($cfg['decision']['mode'] ?? $agent_mode));
$base_url = (string)($cfg['base_url'] ?? 'http://localhost:8000');
$scenario_exploration = (int)($cfg['scenario_exploration'] ?? 1) === 1;
$novelty_auto = (int)($cfg['novelty_auto'] ?? 1) === 1;
$autonomous_scenario = (int)($cfg['autonomous_scenario'] ?? 1) === 1;
$autonomous_only = (int)($cfg['autonomous_only'] ?? 0) === 1;
$autonomous_max_steps = (int)($cfg['autonomous_max_steps'] ?? 8);
$autonomous_ui_limit = (int)($cfg['autonomous_ui_limit'] ?? 120);
$autonomous_avoid_upload = (int)($cfg['autonomous_avoid_upload'] ?? 0) === 1;
$upload_file = (string)($cfg['upload_file'] ?? '');
$scenario_include_api = (int)($cfg['scenario_include_api'] ?? 0) === 1;
$run_scenarios = (int)($cfg['run_scenarios'] ?? 1) === 1;
$keep_browser = (int)($cfg['keep_browser'] ?? 0) === 1;
$headless = (int)($cfg['headless'] ?? 0) === 1;
$llm = is_array($cfg['llm'] ?? null) ? $cfg['llm'] : [];
$llm_base_url = (string)($llm['base_url'] ?? ($cfg['llm_base_url'] ?? ''));
$llm_model = (string)($llm['model'] ?? ($cfg['llm_model'] ?? ''));
$llm_chat_timeout = (int)($llm['chat_timeout'] ?? ($cfg['llm_chat_timeout'] ?? 60));
$llm_warmup_max_wait = (int)($llm['warmup_max_wait'] ?? ($cfg['llm_warmup_max_wait'] ?? 45));
$llm_warmup_poll_s = (float)($llm['warmup_poll_s'] ?? ($cfg['llm_warmup_poll_s'] ?? 2));
$tool_port = (int)($toolServerPort ?? 8891);
?>
<section class="ops-card span-12" id="command-center">
    <div class="card-title">مركز التوجيه والتحكم</div>
    <div class="card-subtitle">كل ما تحتاجه لتشغيل الوكيل وتوجيهه وتشخيصه من مكان واحد.</div>

    <div class="action-grid">
        <form method="POST" data-live="1">
            <input type="hidden" name="action" value="assure">
            <button class="btn primary" type="submit">فحص شامل (Master Verify)</button>
        </form>
        <form method="POST" data-live="1">
            <input type="hidden" name="action" value="run_scenarios">
            <button class="btn secondary" type="submit">تشغيل السيناريوهات الآن</button>
        </form>
        <form method="POST" data-live="1">
            <input type="hidden" name="action" value="run_autonomous_now">
            <button class="btn" type="submit">سيناريو ذاتي فوري</button>
        </form>
        <form method="POST" data-live="1">
            <input type="hidden" name="action" value="run_api_contract">
            <button class="btn" type="submit">اختبارات عقود API</button>
        </form>
        <form method="POST" data-live="1">
            <input type="hidden" name="action" value="warm_llm">
            <button class="btn" type="submit">تسخين النموذج</button>
        </form>
        <form method="POST" data-live="1">
            <input type="hidden" name="action" value="start_tool_server">
            <button class="btn" type="submit">تشغيل جسر الأدوات</button>
        </form>
        <form method="POST" data-live="1">
            <input type="hidden" name="action" value="digest">
            <button class="btn" type="submit">تلخيص الخبرات</button>
        </form>
        <form method="POST" data-live="1">
            <input type="hidden" name="action" value="restart_browser">
            <button class="btn danger" type="submit">إعادة تشغيل المتصفح</button>
        </form>
    </div>

    <div class="split-grid" style="margin-top:18px;">
        <form method="POST" data-live="1">
            <input type="hidden" name="action" value="update_flags">
            <div class="split-grid" style="margin-top:0;">
                <div>
                    <label>وضع التنفيذ</label>
                    <select name="execution_mode" class="input">
                        <option value="sandbox" <?= $execution_mode === 'sandbox' ? 'selected' : '' ?>>ساندبوكس</option>
                        <option value="direct" <?= $execution_mode === 'direct' ? 'selected' : '' ?>>مباشر</option>
                        <option value="autonomous" <?= $execution_mode === 'autonomous' ? 'selected' : '' ?>>ذاتي</option>
                    </select>
                </div>
                <div>
                    <label>وضع الوكيل</label>
                    <select name="agent_mode" class="input">
                        <option value="assisted" <?= $agent_mode === 'assisted' ? 'selected' : '' ?>>مُساند</option>
                        <option value="auto" <?= $agent_mode === 'auto' ? 'selected' : '' ?>>ذاتي</option>
                    </select>
                </div>
                <div>
                    <label>وضع القرار</label>
                    <select name="decision_mode" class="input">
                        <option value="assisted" <?= $decision_mode === 'assisted' ? 'selected' : '' ?>>مُساند</option>
                        <option value="auto" <?= $decision_mode === 'auto' ? 'selected' : '' ?>>ذاتي</option>
                    </select>
                </div>
                <div>
                    <label>الرابط الأساسي</label>
                    <input class="input" name="base_url" value="<?= htmlspecialchars($base_url) ?>" />
                </div>
                <div>
                    <label>منفذ جسر الأدوات</label>
                    <input class="input" type="number" name="tool_server_port" value="<?= (int)$tool_port ?>" min="1024" max="65535" />
                </div>
                <div>
                    <label>LLM Base URL</label>
                    <input class="input" name="llm_base_url" placeholder="http://127.0.0.1:11434/v1/chat/completions" value="<?= htmlspecialchars($llm_base_url) ?>" />
                </div>
                <div>
                    <label>نموذج LLM</label>
                    <input class="input" name="llm_model" placeholder="llama3.1:latest" value="<?= htmlspecialchars($llm_model) ?>" />
                </div>
            </div>

            <div class="split-grid" style="margin-top:12px;">
                <label><input type="checkbox" name="scenario_exploration" <?= $scenario_exploration ? 'checked' : '' ?>> تشغيل الاستكشاف</label>
                <label><input type="checkbox" name="autonomous_scenario" <?= $autonomous_scenario ? 'checked' : '' ?>> سيناريو ذاتي</label>
                <label><input type="checkbox" name="run_scenarios" <?= $run_scenarios ? 'checked' : '' ?>> تشغيل السيناريوهات</label>
                <label><input type="checkbox" name="keep_browser" <?= $keep_browser ? 'checked' : '' ?>> إبقاء المتصفح مفتوح</label>
                <label><input type="checkbox" name="headless" <?= $headless ? 'checked' : '' ?>> بدون واجهة</label>
                <label><input type="checkbox" name="autonomous_only" <?= $autonomous_only ? 'checked' : '' ?>> تشغيل ذاتي فقط</label>
            </div>

            <details style="margin-top:12px;">
                <summary style="cursor:pointer; color: var(--muted);">خيارات متقدمة</summary>
                <div class="split-grid" style="margin-top:12px;">
                    <div>
                        <label>الخطوات الذاتية القصوى</label>
                        <input class="input" type="number" name="autonomous_max_steps" value="<?= (int)$autonomous_max_steps ?>" min="1" max="30" />
                    </div>
                    <div>
                        <label>حد واجهة التنفيذ الذاتي</label>
                        <input class="input" type="number" name="autonomous_ui_limit" value="<?= (int)$autonomous_ui_limit ?>" min="20" max="500" />
                    </div>
                    <div>
                        <label><input type="checkbox" name="autonomous_avoid_upload" <?= $autonomous_avoid_upload ? 'checked' : '' ?>> منع الرفع مؤقتًا</label>
                    </div>
                    <div>
                        <label><input type="checkbox" name="scenario_include_api" <?= $scenario_include_api ? 'checked' : '' ?>> تضمين API</label>
                    </div>
                    <div>
                        <label><input type="checkbox" name="novelty_auto" <?= $novelty_auto ? 'checked' : '' ?>> تجربة جديدة تلقائية</label>
                    </div>
                    <div>
                        <label>ملف الرفع</label>
                        <input class="input" name="upload_file" placeholder="AUGUST.xlsx" value="<?= htmlspecialchars($upload_file) ?>" />
                    </div>
                    <div>
                        <label>مهلة المحادثة (ث)</label>
                        <input class="input" type="number" name="llm_chat_timeout" value="<?= (int)$llm_chat_timeout ?>" min="10" max="240" />
                    </div>
                    <div>
                        <label>الانتظار الأقصى للتسخين (ث)</label>
                        <input class="input" type="number" name="llm_warmup_max_wait" value="<?= (int)$llm_warmup_max_wait ?>" min="5" max="180" />
                    </div>
                    <div>
                        <label>فاصل التسخين (ث)</label>
                        <input class="input" type="number" name="llm_warmup_poll_s" value="<?= htmlspecialchars($llm_warmup_poll_s) ?>" min="1" max="10" step="0.5" />
                    </div>
                </div>
            </details>

            <div style="margin-top:14px;">
                <button class="btn primary" type="submit">حفظ الإعدادات</button>
            </div>
        </form>
    </div>
</section>
