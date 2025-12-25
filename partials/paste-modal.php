<!-- Smart Paste Modal -->
<div id="smartPasteModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div style="background: white; width: 100%; max-width: 600px; border-radius: 12px; box-shadow: 0 25px 50px rgba(0,0,0,0.3); overflow: hidden;">
        <div style="background: #f9fafb; padding: 20px 24px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 18px; font-weight: 700; color: #1f2937; display: flex; align-items: center; gap: 8px; margin: 0;">
                📋 لصق بيانات ذكي (Smart Paste)
            </h3>
            <button id="btnClosePasteModal" style="color: #9ca3af; background: none; border: none; font-size: 32px; line-height: 1; cursor: pointer; padding: 0;" onmouseover="this.style.color='#4b5563'" onmouseout="this.style.color='#9ca3af'">&times;</button>
        </div>
        
        <div style="padding: 24px;">
            <div style="margin-bottom: 16px; background: #dbeafe; color: #1e40af; padding: 12px; border-radius: 8px; font-size: 14px; display: flex; gap: 8px;">
                💡
                <div>
                    قم بنسخ نص الإيميل أو الطلب ولصقه هنا. سيقوم النظام باستخراج البيانات تلقائياً.
                </div>
            </div>

            <textarea id="smartPasteInput" style="width: 100%; height: 192px; padding: 16px; border: 2px dashed #d1d5db; border-radius: 8px; background: #f9fafb; font-family: monospace; font-size: 14px; line-height: 1.5; resize: vertical;" placeholder="مثال: يرجى إصدار ضمان بنكي بمبلغ 50,000 ريال لصالح شركة المراعي..." onfocus="this.style.background='white'; this.style.borderColor='#3b82f6'" onblur="this.style.background='#f9fafb'; this.style.borderColor='#d1d5db'"></textarea>
            
            <div id="smartPasteError" style="margin-top: 12px; color: #dc2626; font-size: 14px; font-weight: 700; display: none;"></div>
        </div>

        <div style="background: #f9fafb; padding: 16px 24px; display: flex; justify-content: flex-end; gap: 12px; border-top: 1px solid #e5e7eb;">
            <button id="btnCancelPaste" style="padding: 8px 16px; color: #4b5563; background: transparent; border: none; border-radius: 8px; font-weight: 500; cursor: pointer;" onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='transparent'">إلغاء</button>
            <button id="btnProcessPaste" style="padding: 12px 24px; background: #2563eb; color: white; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 6px rgba(37, 99, 235, 0.3);" onmouseover="this.style.background='#1d4ed8'" onmouseout="this.style.background='#2563eb'">
                ✨ تحليل وإضافة
            </button>
        </div>
    </div>
</div>
