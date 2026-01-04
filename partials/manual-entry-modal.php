<!-- Manual Entry Modal -->
<div id="manualEntryModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
    <div style="background: white; width: 500px; max-width: 90%; padding: 24px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
        <h3 style="margin: 0 0 20px 0; color: var(--text-primary);">إدخال ضمان يدوي</h3>
        <form id="manualEntryForm">
            <div class="form-group" style="margin-bottom: 16px;">
                <label class="form-label">رقم الضمان</label>
                <input type="text" name="guarantee_number" class="form-input" required>
            </div>
            <div class="form-group" style="margin-bottom: 16px;">
                <label class="form-label">المورد</label>
                <input type="text" name="supplier" class="form-input" required>
            </div>
            <div class="form-group" style="margin-bottom: 16px;">
                <label class="form-label">البنك</label>
                <input type="text" name="bank" class="form-input">
            </div>
            <div class="form-group" style="margin-bottom: 16px;">
                <label class="form-label">القيمة</label>
                <input type="number" name="amount" class="form-input" required>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 24px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('manualEntryModal').style.display='none'">إلغاء</button>
                <button type="submit" class="btn btn-primary">حفظ</button>
            </div>
        </form>
    </div>
</div>
