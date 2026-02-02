<div class="section-full" id="copilot-container" style="background:#0e1626;border:1px solid #223047;border-radius:12px;padding:12px;">
  <h3 style="color:#8bd3ff;margin-top:0;">محادثة CopilotKit (تجريبي)</h3>
  <p style="color: var(--text-secondary);">تكتب هنا، النموذج يرد ويبث الأدوات تلقائياً.</p>
  <div id="copilot-root"></div>
</div>
<style>
  /* حصر العرض بدون تقييد الارتفاع */
  #copilot-container { max-width: 900px; margin: 0 auto; overflow: visible; }
  #copilot-root, #copilot-root > div { width: 100%; }
  #copilot-root > div { overflow: visible; max-height: none; }
  /* ضبط ارتفاع وعرض الـ Sidebar الافتراضي من CopilotKit إن وُجد */
  /* إذا ظهرت عناصر من مكتبة Copilot UI الافتراضية، اخفِ حقول الإدخال المكررة */
  #copilot-root .cui-chat-input,
  #copilot-root .cui-input,
  #copilot-root textarea.cui-textarea {
    display: none !important;
  }
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
</script>
<script src="agentfrontend/app/copilot/dist/copilot-widget.js?v=2"></script>
