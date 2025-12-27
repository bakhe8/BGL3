<!-- Manual Entry Modal -->
<div id="manualEntryModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; width: 90%; max-width: 700px; border-radius: 12px; box-shadow: 0 25px 50px rgba(0,0,0,0.3); overflow: hidden; max-height: 90vh; overflow-y: auto;">
        <div style="background: #f9fafb; padding: 20px 24px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 18px; font-weight: 700; color: #1f2937; display: flex; align-items: center; gap: 8px; margin: 0;">
                ✍️ إدخال سجل يدوي
            </h3>
            <button id="btnCloseManualEntry" style="color: #9ca3af; background: none; border: none; font-size: 32px; line-height: 1; cursor: pointer; padding: 0;" onmouseover="this.style.color='#4b5563'" onmouseout="this.style.color='#9ca3af'">&times;</button>
        </div>
        
        <form id="manualEntryForm" style="padding: 24px;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
                <!-- Supplier -->
                <div>
                    <label for="manualSupplier" style="display: block; font-size: 14px; font-weight: 700; color: #374151; margin-bottom: 4px;">
                        المورد <span style="color: #dc2626;">*</span>
                    </label>
                    <input type="text" id="manualSupplier" required
                        style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none;"
                        placeholder="اسم المورد"
                        onfocus="this.style.borderColor='#3b82f6'; this.style.boxShadow='0 0 0 3px rgba(59, 130, 246, 0.1)'"
                        onblur="this.style.borderColor='#d1d5db'; this.style.boxShadow='none'">
                </div>

                <!-- Bank -->
                <div>
                    <label for="manualBank" style="display: block; font-size: 14px; font-weight: 700; color: #374151; margin-bottom: 4px;">
                        البنك <span style="color: #dc2626;">*</span>
                    </label>
                    <input type="text" id="manualBank" required
                        style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none;"
                        placeholder="اسم البنك"
                        onfocus="this.style.borderColor='#3b82f6'; this.style.boxShadow='0 0 0 3px rgba(59, 130, 246, 0.1)'"
                        onblur="this.style.borderColor='#d1d5db'; this.style.boxShadow='none'">
                </div>

                <!-- Guarantee Number -->
                <div>
                    <label for="manualGuarantee" style="display: block; font-size: 14px; font-weight: 700; color: #374151; margin-bottom: 4px;">
                        رقم الضمان <span style="color: #dc2626;">*</span>
                    </label>
                    <input type="text" id="manualGuarantee" required
                        style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none;"
                        placeholder="رقم الضمان"
                        onfocus="this.style.borderColor='#3b82f6'; this.style.boxShadow='0 0 0 3px rgba(59, 130, 246, 0.1)'"
                        onblur="this.style.borderColor='#d1d5db'; this.style.boxShadow='none'">
                </div>

                <!-- Contract Number -->
                <div>
                    <label for="manualContract" style="display: block; font-size: 14px; font-weight: 700; color: #374151; margin-bottom: 4px;">
                        رقم العقد <span style="color: #dc2626;">*</span>
                    </label>
                    <input type="text" id="manualContract" required
                        style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none;"
                        placeholder="رقم العقد أو أمر الشراء"
                        onfocus="this.style.borderColor='#3b82f6'; this.style.boxShadow='0 0 0 3px rgba(59, 130, 246, 0.1)'"
                        onblur="this.style.borderColor='#d1d5db'; this.style.boxShadow='none'">
                </div>

                <!-- 🔥 NEW: Related To (Contract vs Purchase Order) -->
                <div>
                    <label style="display: block; font-size: 14px; font-weight: 700; color: #374151; margin-bottom: 8px;">
                        نوع المستند <span style="color: #dc2626;">*</span>
                    </label>
                    <div style="display: flex; gap: 20px;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 14px; color: #374151;">
                            <input type="radio" name="relatedTo" value="contract" checked
                                style="width: 18px; height: 18px; cursor: pointer; accent-color: #3b82f6;">
                            <span>📄 عقد</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 14px; color: #374151;">
                            <input type="radio" name="relatedTo" value="purchase_order"
                                style="width: 18px; height: 18px; cursor: pointer; accent-color: #10b981;">
                            <span>🛒 أمر شراء</span>
                        </label>
                    </div>
                    <small style="color: #6b7280; font-size: 12px; display: block; margin-top: 4px;">
                        حدد نوع المستند المرتبط بهذا الضمان البنكي
                    </small>
                </div>

                <!-- Amount -->
                <div>
                    <label for="manualAmount" style="display: block; font-size: 14px; font-weight: 700; color: #374151; margin-bottom: 4px;">
                        المبلغ <span style="color: #dc2626;">*</span>
                    </label>
                    <input type="text" id="manualAmount" required
                        style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none;"
                        placeholder="50000.00"
                        onfocus="this.style.borderColor='#3b82f6'; this.style.boxShadow='0 0 0 3px rgba(59, 130, 246, 0.1)'"
                        onblur="this.style.borderColor='#d1d5db'; this.style.boxShadow='none'">
                </div>

                <!-- Expiry Date -->
                <div>
                    <label for="manualExpiry" style="display: block; font-size: 14px; font-weight: 700; color: #374151; margin-bottom: 4px;">
                        تاريخ الانتهاء
                    </label>
                    <input type="date" id="manualExpiry"
                        style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none;"
                        onfocus="this.style.borderColor='#3b82f6'; this.style.boxShadow='0 0 0 3px rgba(59, 130, 246, 0.1)'"
                        onblur="this.style.borderColor='#d1d5db'; this.style.boxShadow='none'">
                </div>

                <!-- Type -->
                <div>
                    <label for="manualType" style="display: block; font-size: 14px; font-weight: 700; color: #374151; margin-bottom: 4px;">
                        نوع الضمان
                    </label>
                    <select id="manualType"
                        style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none; background: white;"
                        onfocus="this.style.borderColor='#3b82f6'; this.style.boxShadow='0 0 0 3px rgba(59, 130, 246, 0.1)'"
                        onblur="this.style.borderColor='#d1d5db'; this.style.boxShadow='none'">
                        <option value="">اختر النوع</option>
                        <option value="FINAL">نهائي (FINAL)</option>
                        <option value="ADVANCED">دفعة مقدمة (ADVANCED)</option>
                    </select>
                </div>

                <!-- Issue Date -->
                <div>
                    <label for="manualIssue" style="display: block; font-size: 14px; font-weight: 700; color: #374151; margin-bottom: 4px;">
                        تاريخ الإصدار
                    </label>
                    <input type="date" id="manualIssue"
                        style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none;"
                        onfocus="this.style.borderColor='#3b82f6'; this.style.boxShadow='0 0 0 3px rgba(59, 130, 246, 0.1)'"
                        onblur="this.style.borderColor='#d1d5db'; this.style.boxShadow='none'">
                </div>
            </div>

            <!-- Comment -->
            <div style="margin-top: 16px;">
                <label for="manualComment" style="display: block; font-size: 14px; font-weight: 700; color: #374151; margin-bottom: 4px;">
                    ملاحظات
                </label>
                <textarea id="manualComment" rows="2"
                    style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none; resize: vertical;"
                    placeholder="ملاحظات إضافية..."
                    onfocus="this.style.borderColor='#3b82f6'; this.style.boxShadow='0 0 0 3px rgba(59, 130, 246, 0.1)'"
                    onblur="this.style.borderColor='#d1d5db'; this.style.boxShadow='none'"></textarea>
            </div>

            <div id="manualEntryError" style="margin-top: 12px; color: #dc2626; font-size: 14px; font-weight: 700; display: none;"></div>
        </form>

        <div style="background: #f9fafb; padding: 16px 24px; display: flex; justify-content: flex-end; gap: 12px; border-top: 1px solid #e5e7eb;">
            <button id="btnCancelManualEntry" type="button" style="padding: 8px 16px; color: #4b5563; background: transparent; border: none; border-radius: 8px; font-weight: 500; cursor: pointer;" onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='transparent'">إلغاء</button>
            <button id="btnSaveManualEntry" type="button" style="padding: 12px 24px; background: #16a34a; color: white; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 6px rgba(22, 163, 74, 0.3);" onmouseover="this.style.background='#15803d'" onmouseout="this.style.background='#16a34a'">
                ✓ حفظ وإضافة
            </button>
        </div>
    </div>
</div>
