<!-- Manual Entry Modal -->
<dialog id="manual_entry_modal" class="modal">
    <div class="modal-box w-11/12 max-w-3xl bg-base-100 p-6 rounded-xl shadow-2xl relative">
        <h3 class="font-bold text-2xl mb-6 text-primary flex items-center gap-2">
            ✍️ إدخال ضمان يدوي
        </h3>
        
        <form id="manualEntryForm" onsubmit="handleManualEntry(event)" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Guarantee Number -->
                <div class="form-control">
                    <label class="label"><span class="label-text font-bold">رقم الضمان *</span></label>
                    <input type="text" name="guarantee_number" class="input input-bordered w-full" required />
                </div>

                <!-- Supplier -->
                <div class="form-control">
                    <label class="label"><span class="label-text font-bold">المورد (اسم الشركة) *</span></label>
                    <input type="text" name="supplier" class="input input-bordered w-full" required />
                </div>

                <!-- Bank -->
                <div class="form-control">
                    <label class="label"><span class="label-text font-bold">البنك *</span></label>
                    <input type="text" name="bank" class="input input-bordered w-full" required />
                </div>

                <!-- Amount -->
                <div class="form-control">
                    <label class="label"><span class="label-text font-bold">المبلغ</span></label>
                    <input type="number" step="0.01" name="amount" class="input input-bordered w-full" />
                </div>
                
                 <!-- Type -->
                <div class="form-control">
                    <label class="label"><span class="label-text font-bold">نوع الضمان</span></label>
                    <select name="type" class="select select-bordered w-full">
                        <option value="ابتدائي">ابتدائي</option>
                        <option value="نهائي">نهائي</option>
                        <option value="دفعة مقدمة">دفعة مقدمة</option>
                        <option value="حسن تنفيذ">حسن تنفيذ</option>
                    </select>
                </div>
                
                 <!-- Contract Number -->
                <div class="form-control">
                    <label class="label"><span class="label-text font-bold">رقم العقد / المنافسة</span></label>
                    <input type="text" name="contract_number" class="input input-bordered w-full" />
                </div>

                <!-- Issue Date -->
                <div class="form-control">
                    <label class="label"><span class="label-text font-bold">تاريخ الإصدار</span></label>
                    <input type="date" name="issue_date" class="input input-bordered w-full" />
                </div>

                <!-- Expiry Date -->
                <div class="form-control">
                    <label class="label"><span class="label-text font-bold">تاريخ الانتهاء</span></label>
                    <input type="date" name="expiry_date" class="input input-bordered w-full" />
                </div>
            </div>

            <div class="modal-action mt-8 border-t pt-4">
                <button type="button" class="btn btn-ghost" onclick="manual_entry_modal.close()">إلغاء</button>
                <button type="submit" class="btn btn-primary px-8">
                    💾 حفظ الضمان
                </button>
            </div>
        </form>
    </div>
    <form method="dialog" class="modal-backdrop">
        <button>close</button>
    </form>
</dialog>
