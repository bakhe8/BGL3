<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>استيراد البيانات الأولية - BGL Setup</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/setup.css">
</head>
<body class="setup-page">
    <div class="setup-container">
        <div class="setup-card">
            <div class="setup-header">
                <h1>📥 استيراد البيانات الأولية</h1>
                <p>ضع ملفاتك في المجلدات وسيتم معالجتها تلقائياً</p>
            </div>

            <!-- Ultra-Minimal Import System -->
            <div style="background: #eff6ff; border: 2px solid #3b82f6; border-radius: 12px; padding: 30px; margin-bottom: 30px; text-align: center;">
                <div style="font-size: 48px; margin-bottom: 15px;">📁</div>
                <h3 style="color: #1e40af; margin-bottom: 15px;">مجلد الاستيراد</h3>
                <code style="background: white; padding: 12px 20px; border-radius: 8px; display: inline-block; margin-bottom: 20px; font-size: 16px;">
                    setup/input/files/
                </code>
                <p style="color: #64748b; margin-bottom: 25px;">
                    ضع جميع ملفاتك (Excel, CSV, Word) في هذا المجلد أو أي مجلد فرعي بداخله
                </p>
                
                <button onclick="startImport()" class="btn btn-primary" style="font-size: 18px; padding: 15px 40px;">
                    ⚡ استيراد الآن
                </button>
            </div>

            <!-- Loading -->
            <div id="loading" style="display: none; text-align: center; padding: 40px;">
                <div style="display: inline-block; width: 50px; height: 50px; border: 5px solid #e2e8f0; border-top-color: #3b82f6; border-radius: 50%; animation: spin 1s linear infinite;"></div>
                <p style="margin-top: 15px; color: #64748b;">جاري المعالجة...</p>
            </div>

            <!-- Results -->
            <div id="results" style="display: none;">
                <div style="background: #f0fdf4; border: 2px solid #22c55e; border-radius: 12px; padding: 25px; margin-bottom: 20px;">
                    <h3 style="color: #16a34a; margin-bottom: 15px;">✅ تمت المعالجة بنجاح!</h3>
                    <div class="stats">
                        <div class="stat-card">
                            <div class="stat-value" id="filesProcessed">0</div>
                            <div class="stat-label">ملفات تمت معالجتها</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value" id="suppliersFound">0</div>
                            <div class="stat-label">موردين تم استخراجهم</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value" id="banksFound">0</div>
                            <div class="stat-label">بنوك تم استخراجها</div>
                        </div>
                    </div>
                    
                    <div style="text-align: center; margin-top: 20px;">
                        <a href="review.php" class="btn btn-primary">
                            📋 انتقل لمراجعة البيانات
                        </a>
                    </div>
                </div>
            </div>

            <!-- Navigation -->
            <div style="text-align: center; padding: 20px;">
                <a href="review.php" class="btn btn-secondary">
                    ← العودة للمراجعة
                </a>
            </div>

            <!-- Excel Tab -->
            <div id="excel-tab" class="tab-content">
                <div style="background: #eff6ff; border: 2px solid #3b82f6; border-radius: 12px; padding: 20px; margin-bottom: 20px;">
                    <h3 style="color: #1e40af; margin-bottom: 10px;">📁 المجلد:</h3>
                    <code style="background: white; padding: 8px 12px; border-radius: 6px; display: block; margin-bottom: 10px;">
                        setup/input/excel/
                    </code>
                    <p style="color: #1e40af; font-size: 14px;">
                        ضع ملفات Excel (.xlsx, .xls) أو CSV (.csv) في هذا المجلد
                    </p>
                </div>

                <!-- Files List -->
                <div style="margin-bottom: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                        <h3>الملفات الموجودة:</h3>
                        <button onclick="scanFolders()" class="btn btn-sm btn-primary">
                            🔄 تحديث
                        </button>
                    </div>

                    <div id="excel-files-list" style="max-height: 300px; overflow-y: auto;">
                        <!-- Will be populated by JavaScript -->
                    </div>
                </div>

                <div style="text-align: center;">
                    <button onclick="processExcel()" id="processExcelBtn" class="btn btn-success" style="font-size: 18px; padding: 16px 40px;" disabled>
                        معالجة جميع الملفات →
                    </button>
                </div>
            </div>

            <!-- Word Tab -->
            <div id="word-tab" class="tab-content" style="display: none;">
                <div style="background: #f0fdf4; border: 2px solid #10b981; border-radius: 12px; padding: 20px; margin-bottom: 20px;">
                    <h3 style="color: #047857; margin-bottom: 10px;">📁 المجلد:</h3>
                    <code style="background: white; padding: 8px 12px; border-radius: 6px; display: block; margin-bottom: 10px;">
                        setup/input/word/
                    </code>
                    <p style="color: #047857; font-size: 14px;">
                        ضع مستندات Word (.docx) في هذا المجلد
                    </p>
                </div>

                <div style="margin-bottom: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                        <h3>الملفات الموجودة:</h3>
                        <button onclick="scanFolders()" class="btn btn-sm btn-primary">
                            🔄 تحديث
                        </button>
                    </div>

                    <div id="word-files-list" style="max-height: 300px; overflow-y: auto;">
                        <!-- Will be populated by JavaScript -->
                    </div>
                </div>

                <div style="text-align: center;">
                    <button onclick="processWord()" id="processWordBtn" class="btn btn-success" style="font-size: 18px; padding: 16px 40px;" disabled>
                        معالجة جميع المستندات →
                    </button>
                </div>
            </div>

            <!-- Manual Upload Tab -->
            <div id="manual-tab" class="tab-content" style="display: none;">
                <div class="upload-area" id="uploadArea" onclick="document.getElementById('fileInput').click()">
                    <div class="upload-icon">📁</div>
                    <h3>اضغط لرفع ملف CSV</h3>
                    <p>أو اسحب الملف وأفلته هنا</p>
                </div>

                <input type="file" id="fileInput" accept=".csv" style="display: none;">

                <div style="text-align: center; margin-top: 20px;">
                    <a href="../templates/sample.csv" download class="btn btn-sm btn-primary">
                        ⬇️ تحميل ملف نموذجي
                    </a>
                </div>
            </div>

            <!-- Results -->
            <div id="results" style="display: none; margin-top: 30px;">
                <div class="stats">
                    <div class="stat-card">
                        <div class="stat-value" id="filesProcessed">0</div>
                        <div class="stat-label">ملفات معالجة</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value" id="suppliersFound">0</div>
                        <div class="stat-label">موردين فريدين</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value" id="banksFound">0</div>
                        <div class="stat-label">بنوك فريدة</div>
                    </div>
                </div>

                <div style="text-align: center; margin-top: 30px;">
                    <button onclick="window.location.href='review.php'" class="btn btn-success" style="font-size: 18px; padding: 16px 40px;">
                        التالي: مراجعة البيانات →
                    </button>
                </div>
            </div>

            <div id="loading" style="display: none; text-align: center; margin-top: 30px;">
                <div style="font-size: 48px; animation: spin 1s linear infinite;">⏳</div>
                <p>جاري معالجة الملفات...</p>
            </div>
        </div>
    </div>

    <script>
        const loading = document.getElementById('loading');
        const results = document.getElementById('results');

        async function startImport() {
            loading.style.display = 'block';
            results.style.display = 'none';

            try {
                const response = await fetch('../api/process-all.php', {
                    method: 'POST'
                });

                const data = await response.json();

                if (data.success) {
                    document.getElementById('filesProcessed').textContent = data.data.processed;
                    document.getElementById('suppliersFound').textContent = data.data.suppliers;
                    document.getElementById('banksFound').textContent = data.data.banks;

                    loading.style.display = 'none';
                    results.style.display = 'block';

                    showToast(`تم معالجة ${data.data.processed} ملف بنجاح!`, 'success');
                } else {
                    throw new Error(data.error || 'فشلت المعالجة');
                }
            } catch (error) {
                loading.style.display = 'none';
                showToast(error.message, 'error');
            }
        }

        function showToast(message, type) {
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.textContent = message;
            document.body.appendChild(toast);

            setTimeout(() => toast.remove(), 3000);
        }
    </script>

    <style>
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    </style>
</body>
</html>
