<!-- Paste Modal -->
<div id="pasteModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
    <div style="background: white; width: 600px; max-width: 90%; padding: 24px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
        <h3 style="margin: 0 0 16px 0; color: var(--text-primary);">لصق بيانات من Excel</h3>
        <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 16px;">انسخ الصفوف من Excel والصقها هنا</p>
        <textarea id="pasteArea" style="width: 100%; height: 200px; border: 1px solid var(--border-neutral); border-radius: 8px; padding: 12px; font-family: monospace; font-size: 13px;" placeholder="رقم الضمان | المورد | البنك | القيمة..."></textarea>
        <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('pasteModal').style.display='none'">إلغاء</button>
            <button type="button" class="btn btn-primary" onclick="processPaste()">معالجة</button>
        </div>
    </div>
</div>
