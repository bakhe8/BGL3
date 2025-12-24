<!-- Paste Modal -->
<dialog id="paste_modal" class="modal">
    <div class="modal-box w-11/12 max-w-2xl bg-base-100 p-6 rounded-xl shadow-2xl">
        <h3 class="font-bold text-2xl mb-4 text-secondary flex items-center gap-2">
            📋 لصق بيانات سريعة (Text Parsing)
        </h3>
        
        <div class="alert alert-info shadow-sm mb-4">
            <div>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="stroke-current shrink-0 w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <span class="text-sm">الصق النص الكامل هنا. سيحاول الذكاء الاصطناعي استخراج رقم الضمان، المورد، البنك، والمبلغ تلقائياً.</span>
            </div>
        </div>

        <form id="pasteForm" onsubmit="handlePasteEntry(event)" class="space-y-4">
            <div class="form-control">
                <textarea 
                    name="pasted_text" 
                    class="textarea textarea-bordered h-48 w-full font-mono text-sm leading-relaxed" 
                    placeholder="مثال: ضمان بنكي رقم 123456 من بنك الراجحي لصالح شركة الأحمد بقيمة 50000 ريال..."
                    required></textarea>
            </div>

            <div class="modal-action mt-6">
                <button type="button" class="btn btn-ghost" onclick="paste_modal.close()">إلغاء</button>
                <button type="submit" class="btn btn-secondary px-8">
                    ✨ معالجة وإضافة
                </button>
            </div>
        </form>
    </div>
    <form method="dialog" class="modal-backdrop">
        <button>close</button>
    </form>
</dialog>
