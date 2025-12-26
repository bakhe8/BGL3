<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>اكتمل الاستيراد - BGL Setup</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/setup.css">
</head>
<body class="setup-page">
    <div class="setup-container">
        <div class="setup-card">
            <div style="text-align: center;">
                <div style="font-size: 80px; margin-bottom: 20px;">✅</div>
                <h1 style="font-size: 36px; color: #16a34a; margin-bottom: 10px;">
                    اكتمل الاستيراد بنجاح!
                </h1>
                <p style="color: #64748b; font-size: 18px; margin-bottom: 40px;">
                    تم نقل البيانات إلى قاعدة البيانات الأساسية
                </p>
            </div>

            <div class="stats">
                <div class="stat-card" style="background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);">
                    <div class="stat-value" id="suppliersCount">0</div>
                    <div class="stat-label">موردين</div>
                </div>
                <div class="stat-card" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);">
                    <div class="stat-value" id="banksCount">0</div>
                    <div class="stat-label">بنوك</div>
                </div>
            </div>

            <div style="background: #f0fdf4; border: 2px solid #16a34a; border-radius: 12px; padding: 20px; margin: 30px 0;">
                <h3 style="color: #16a34a; margin-bottom: 10px;">✓ البيانات جاهزة للاستخدام</h3>
                <p style="color: #15803d;">
                    يمكنك الآن البدء في إدخال الضمانات. سيقترح البرنامج الموردين والبنوك تلقائياً من البيانات التي تم استيرادها.
                </p>
            </div>

            <div style="text-align: center; margin-top: 40px;">
                <button onclick="cleanup()" class="btn btn-danger" style="margin-left: 10px;">
                    🗑️ حذف ملفات الإعداد
                </button>
                <button onclick="window.location.href='/index.php'" class="btn btn-success" style="font-size: 18px; padding: 16px 40px;">
                    العودة للصفحة الرئيسية →
                </button>
            </div>

            <div style="text-align: center; margin-top: 30px; color: #64748b; font-size: 14px;">
                <p>💡 نصيحة: يمكنك حذف مجلد setup/ بالكامل الآن</p>
            </div>
        </div>
    </div>

    <script>
        // Get params from URL
        const params = new URLSearchParams(window.location.search);
        document.getElementById('suppliersCount').textContent = params.get('suppliers') || '0';
        document.getElementById('banksCount').textContent = params.get('banks') || '0';

        function cleanup() {
            if (!confirm('هل أنت متأكد من حذف كل ملفات الإعداد؟\nلا يمكن التراجع عن هذه العملية.')) {
                return;
            }

            // Delete temp database
            fetch('../api/cleanup.php', {method: 'POST'})
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showToast('تم الحذف بنجاح', 'success');
                        setTimeout(() => {
                            window.location.href = '/index.php';
                        }, 1500);
                    }
                })
                .catch(() => showToast('فشل الحذف', 'error'));
        }

        function showToast(message, type) {
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.textContent = message;
            document.body.appendChild(toast);

            setTimeout(() => toast.remove(), 3000);
        }
    </script>
</body>
</html>
