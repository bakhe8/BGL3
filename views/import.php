<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>استيراد السجلات - V3</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Tajawal', sans-serif; background: #f5f5f5; }
        
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        
        .header {
            background: white;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .tab {
            padding: 12px 24px;
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .tab.active {
            background: #2563eb;
            color: white;
            border-color: #2563eb;
        }
        
        .panel {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .upload-area {
            border: 2px dashed #cbd5e0;
            border-radius: 8px;
            padding: 60px 20px;
            text-align: center;
            background: #f8fafc;
            transition: all 0.2s;
        }
        
        .upload-area.dragover {
            border-color: #2563eb;
            background: #eff6ff;
        }
        
        .upload-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }
        
        input, select {
            width: 100%;
            padding: 12px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            font-family: 'Tajawal', sans-serif;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: #2563eb;
            color: white;
        }
        
        .btn-primary:hover {
            background: #1d4ed8;
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        
        .alert {
            padding: 16px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .preview-table {
            width: 100%;
            margin-top: 20px;
            border-collapse: collapse;
        }
        
        .preview-table th,
        .preview-table td {
            padding: 12px;
            border: 1px solid #e0e0e0;
            text-align: right;
        }
        
        .preview-table th {
            background: #f8fafc;
            font-weight: 600;
        }
        
        .loading {
            text-align: center;
            font-size: 18px;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <div class="container" x-data="importApp()">
        <div class="header">
            <h1>استيراد السجلات</h1>
            <p>استيراد الضمانات من ملف Excel أو إدخال يدوي</p>
        </div>
        
        <div class="tabs">
            <div class="tab" :class="{ 'active': tab === 'excel' }" @click="tab = 'excel'">
                📊 استيراد من Excel
            </div>
            <div class="tab" :class="{ 'active': tab === 'manual' }" @click="tab = 'manual'">
                ✏️ إدخال يدوي
            </div>
        </div>
        
        <!-- Excel Import -->
        <div class="panel" x-show="tab === 'excel'">
            <div x-show="!result">
                <div class="upload-area" 
                     @drop.prevent="handleDrop"
                     @dragover.prevent="dragover = true"
                     @dragleave="dragover = false"
                     :class="{ 'dragover': dragover }">
                    <div class="upload-icon">📤</div>
                    <h3>اسحب وأفلت ملف Excel هنا</h3>
                    <p style="margin: 16px 0; color: #6b7280;">أو</p>
                    <input type="file" id="fileInput" @change="handleFileSelect" accept=".xlsx,.xls" style="display: none;">
                    <button class="btn btn-primary" @click="$refs.fileInput.click()">اختر ملف</button>
                    <p style="margin-top: 16px; color: #6b7280; font-size: 14px;">الصيغ المدعومة: .xlsx, .xls</p>
                </div>
                
                <input type="file" x-ref="fileInput" @change="handleFileSelect" accept=".xlsx,.xls" style="display: none;">
                
                <div x-show="loading" class="loading" style="margin-top: 30px;">
                    <p>جاري الاستيراد...</p>
                    <p style="font-size: 14px; margin-top: 10px;">الرجاء الانتظار</p>
                </div>
           </div>
            
            <!-- Result -->
            <div x-show="result">
                <div class="alert alert-success" x-show="result  && result.success">
                    <h3>✅ تم الاستيراد بنجاح!</h3>
                    <p>تم استيراد <strong x-text="result?.data?.imported"></strong> سجل</p>
                    <p x-show="result?.data?.skipped > 0">تم تخطي <strong x-text="result?.data?.skipped"></strong> سجل</p>
                </div>
                
                <div class="alert alert-error" x-show="result && !result.success">
                    <h3>❌ حدث خطأ</h3>
                    <p x-text="result?.message"></p>
                </div>
                
                <button class="btn btn-primary" @click="reset()">استيراد ملف آخر</button>
                <button class="btn btn-secondary" @click="window.location.href = '../index.php'">العودة للرئيسية</button>
            </div>
        </div>
        
        <!-- Manual Entry -->
        <div class="panel" x-show="tab === 'manual'">
            <form @submit.prevent="submitManual()">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label>رقم الضمان *</label>
                        <input type="text" x-model="manual.guarantee_number" required>
                    </div>
                    
                    <div class="form-group">
                        <label>رقم العقد</label>
                        <input type="text" x-model="manual.contract_number">
                    </div>
                    
                    <div class="form-group">
                        <label>اسم المورد *</label>
                        <input type="text" x-model="manual.supplier" required>
                    </div>
                    
                    <div class="form-group">
                        <label>البنك *</label>
                        <input type="text" x-model="manual.bank" required>
                    </div>
                    
                    <div class="form-group">
                        <label>المبلغ</label>
                        <input type="number" step="0.01" x-model="manual.amount">
                    </div>
                    
                    <div class="form-group">
                        <label>نوع الضمان</label>
                        <select x-model="manual.type">
                            <option value="ابتدائي">ابتدائي</option>
                            <option value="نهائي">نهائي</option>
                            <option value="دفعة مقدمة">دفعة مقدمة</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>تاريخ الإصدار</label>
                        <input type="date" x-model="manual.issue_date">
                    </div>
                    
                    <div class="form-group">
                        <label>تاريخ الانتهاء</label>
                        <input type="date" x-model="manual.expiry_date">
                    </div>
                </div>
                
                <div style="margin-top: 20px;">
                    <button type="submit" class="btn btn-primary" :disabled="loading">
                        <span x-show="!loading">حفظ السجل</span>
                        <span x-show="loading">جاري الحفظ...</span>
                    </button>
                    <button type="button" class="btn btn-secondary" @click="resetManual()">مسح</button>
                </div>
                
                <div x-show="result" class="alert" :class="result?.success ? 'alert-success' : 'alert-error'" style="margin-top: 20px;">
                    <p x-text="result?.message"></p>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function importApp() {
            return {
                tab: 'excel',
                dragover: false,
                loading: false,
                result: null,
                manual: {
                    guarantee_number: '',
                    contract_number: '',
                    supplier: '',
                    bank: '',
                    amount: '',
                    type: 'ابتدائي',
                    issue_date: '',
                    expiry_date: '',
                },
                
                handleDrop(e) {
                    this.dragover = false;
                    const file = e.dataTransfer.files[0];
                    if (file) {
                        this.uploadFile(file);
                    }
                },
                
                handleFileSelect(e) {
                    const file = e.target.files[0];
                    if (file) {
                        this.uploadFile(file);
                    }
                },
                
                async uploadFile(file) {
                    this.loading = true;
                    this.result = null;
                    
                    const formData = new FormData();
                    formData.append('file', file);
                    formData.append('imported_by', 'web_user');
                    
                    try {
                        const response = await fetch('../api/import.php', {
                            method: 'POST',
                            body: formData
                        });
                        
                        const data = await response.json();
                        this.result = data;
                    } catch (error) {
                        this.result = {
                            success: false,
                            message: 'حدث خطأ أثناء الاستيراد: ' + error.message
                        };
                    } finally {
                        this.loading = false;
                    }
                },
                
                async submitManual() {
                    this.loading = true;
                    this.result = null;
                    
                    try {
                        const response = await fetch('../api/manual-entry.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(this.manual)
                        });
                        
                        const data = await response.json();
                        this.result = data;
                        
                        if (data.success) {
                            this.resetManual();
                        }
                    } catch (error) {
                        this.result = {
                            success: false,
                            message: 'حدث خطأ: ' + error.message
                        };
                    } finally {
                        this.loading = false;
                    }
                },
                
                reset() {
                    this.result = null;
                    this.loading = false;
                },
                
                resetManual() {
                    this.manual = {
                        guarantee_number: '',
                        contract_number: '',
                        supplier: '',
                        bank: '',
                        amount: '',
                        type: 'ابتدائي',
                        issue_date: '',
                        expiry_date: '',
                    };
                }
            }
        }
    </script>
</body>
</html>
