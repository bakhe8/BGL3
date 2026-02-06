<div class="section-full" id="copilot-container" style="background:#0e1626;border:1px solid #223047;border-radius:12px;padding:12px;">
  <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
    <h3 style="color:#8bd3ff;margin:0;">محادثة CopilotKit</h3>
    <div style="display:flex; gap:10px; align-items:center; font-size:0.85rem;">
      <span style="color: var(--text-secondary);">الحالة:</span>
      <span style="font-weight:700;" data-live="tool-server-status"><?= $toolServerOnline ? 'متصل' : 'غير متصل' ?></span>
    </div>
  </div>
  <div style="margin-top:6px; font-size:0.8rem; color: var(--text-secondary);" data-live="tool-server-port">
    المنفذ: <?= (int)$toolServerPort ?>
  </div>
  <p style="color: var(--text-secondary); margin-top:6px;">تكتب هنا، النموذج يرد ويبث الأدوات تلقائياً.</p>
  <div id="copilot-root"></div>
  <p id="copilot-offline-hint" style="color: var(--text-secondary); margin-top:8px; display: <?= $toolServerOnline ? 'none' : 'block' ?>;">
    الجسر غير متصل حالياً. شغّل الجسر ليتم تفعيل المحادثة.
  </p>
</div>
<style>
  /* حصر العرض بدون تقييد الارتفاع */
  #copilot-container { max-width: 900px; margin: 0 auto; overflow: visible; }
  #copilot-root, #copilot-root > div { width: 100%; }
  #copilot-root > div { overflow: visible; max-height: none; }
  /* ضبط ارتفاع وعرض الـ Sidebar الافتراضي من CopilotKit إن وُجد */
  /* تصغير الحروف والحواف لعناصر الإدخال */
  #copilot-container * { font-size: 14px; }
  #copilot-container input,
  #copilot-container textarea {
    padding: 8px 10px !important;
    min-height: 38px !important;
  }
  #copilot-container button {
    padding: 8px 12px !important;
    min-height: 36px !important;
    border-radius: 8px !important;
  }
  /* إخفاء أي شريط تمرير أفقي غير مرغوب */
  #copilot-container { overflow-x: hidden; }
</style>
<script>
  // حماية ضد ReferenceError للمتغير process داخل الحزمة المبنية
  window.process = window.process || { env: { NODE_ENV: 'production' } };
window.BGL_TOOL_ENDPOINT = "http://localhost:<?= (int)$toolServerPort ?>/tool";
window.BGL_CHAT_ENDPOINT = "http://localhost:<?= (int)$toolServerPort ?>/chat";
</script>
<script src="agentfrontend/app/copilot/dist/copilot-widget.js?v=2"></script>
