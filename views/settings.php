<?php
require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Settings;

// Load current settings
$settings = new Settings();
$currentSettings = $settings->all();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الإعدادات - BGL System v3.0</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --bg-body: #f1f5f9;
            --bg-card: #ffffff;
            --bg-secondary: #f8fafc;
            --border-primary: #e2e8f0;
            --text-primary: #1e293b;
            --text-secondary: #475569;
            --text-muted: #64748b;
            --accent-primary: #3b82f6;
            --accent-success: #16a34a;
            --accent-danger: #dc2626;
            --font-family: 'Tajawal', sans-serif;
            --radius-md: 8px;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.06);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: var(--font-family);
            background: var(--bg-body);
            color: var(--text-primary);
            padding: 20px;
            line-height: 1.6;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        
        .header {
            background: var(--bg-card);
            padding: 20px 24px;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .btn {
            padding: 10px 20px;
            background: var(--accent-primary);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s;
            display: inline-block;
            cursor: pointer;
            font-family: var(--font-family);
            font-size: 14px;
        }
        
        .btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }
        
        .btn-success {
            background: var(--accent-success);
        }
        
        .btn-danger {
            background: var(--accent-danger);
        }
        
        .card {
            background: var(--bg-card);
            border-radius: var(--radius-md);
            padding: 24px;
            box-shadow: var(--shadow-md);
            margin-bottom: 20px;
        }
        
        .card-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 20px;
            color: var(--text-primary);
            border-bottom: 2px solid var(--border-primary);
            padding-bottom: 12px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-primary);
            font-size: 14px;
        }
        
        .form-help {
            display: block;
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 8px;
        }
        
        .form-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border-primary);
            border-radius: var(--radius-md);
            font-family: var(--font-family);
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .form-input:focus {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid var(--border-primary);
        }
        
        .alert {
            padding: 16px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        .alert-success {
            background: rgba(22, 163, 74, 0.1);
            color: var(--accent-success);
            border: 1px solid var(--accent-success);
        }
        
        .alert-error {
            background: rgba(220, 38, 38, 0.1);
            color: var(--accent-danger);
            border: 1px solid var(--accent-danger);
        }
        
        .alert-hidden {
            display: none;
        }
        
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        @media (max-width: 768px) {
            .grid-2 {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>⚙️ إعدادات النظام</h1>
            <a href="../index.php" class="btn">العودة للرئيسية</a>
        </div>
        
        <!-- Alert Messages -->
        <div id="alertSuccess" class="alert alert-success alert-hidden"></div>
        <div id="alertError" class="alert alert-error alert-hidden"></div>
        
        <!-- Settings Form -->
        <form id="settingsForm">
            
            <!-- Matching Thresholds -->
            <div class="card">
                <h2 class="card-title">عتبات المطابقة</h2>
                
                <div class="form-group">
                    <label class="form-label">عتبة القبول التلقائي (MATCH_AUTO_THRESHOLD)</label>
                    <span class="form-help">النقاط >= 90% يتم قبولها تلقائياً بدون مراجعة</span>
                    <input type="number" class="form-input" name="MATCH_AUTO_THRESHOLD" 
                           value="<?= $currentSettings['MATCH_AUTO_THRESHOLD'] ?>" 
                           min="0" max="1" step="0.01" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">عتبة المراجعة (MATCH_REVIEW_THRESHOLD)</label>
                    <span class="form-help">النقاط < 70% يتم إخفاؤها من القائمة</span>
                    <input type="number" class="form-input" name="MATCH_REVIEW_THRESHOLD" 
                           value="<?= $currentSettings['MATCH_REVIEW_THRESHOLD'] ?>" 
                           min="0" max="1" step="0.01" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">عتبة المطابقة الضعيفة (MATCH_WEAK_THRESHOLD)</label>
                    <span class="form-help">يتم مزامنتها مع عتبة المراجعة</span>
                    <input type="number" class="form-input" name="MATCH_WEAK_THRESHOLD" 
                           value="<?= $currentSettings['MATCH_WEAK_THRESHOLD'] ?>" 
                           min="0" max="1" step="0.01" required>
                </div>
            </div>
            
            <!-- Score Weights -->
            <div class="card">
                <h2 class="card-title">أوزان النقاط</h2>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">وزن الاسم الرسمي</label>
                        <span class="form-help">WEIGHT_OFFICIAL (1.0)</span>
                        <input type="number" class="form-input" name="WEIGHT_OFFICIAL" 
                               value="<?= $currentSettings['WEIGHT_OFFICIAL'] ?>" 
                               min="0.01" step="0.01" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">وزن الاسم البديل المؤكد</label>
                        <span class="form-help">WEIGHT_ALT_CONFIRMED (0.95)</span>
                        <input type="number" class="form-input" name="WEIGHT_ALT_CONFIRMED" 
                               value="<?= $currentSettings['WEIGHT_ALT_CONFIRMED'] ?>" 
                               min="0.01" step="0.01" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">وزن الاسم البديل المتعلم</label>
                        <span class="form-help">WEIGHT_ALT_LEARNING (0.75)</span>
                        <input type="number" class="form-input" name="WEIGHT_ALT_LEARNING" 
                               value="<?= $currentSettings['WEIGHT_ALT_LEARNING'] ?>" 
                               min="0.01" step="0.01" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">عقوبة المطابقة الضبابية</label>
                        <span class="form-help">WEIGHT_FUZZY (0.80)</span>
                        <input type="number" class="form-input" name="WEIGHT_FUZZY" 
                               value="<?= $currentSettings['WEIGHT_FUZZY'] ?>" 
                               min="0.01" step="0.01" required>
                    </div>
                </div>
            </div>
            
            <!-- Conflict Detection & Limits -->
            <div class="card">
                <h2 class="card-title">اكتشاف التعارضات والحدود</h2>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">دلتا التعارض (CONFLICT_DELTA)</label>
                        <span class="form-help">فرق النقاط لاكتشاف التعارضات (0.1)</span>
                        <input type="number" class="form-input" name="CONFLICT_DELTA" 
                               value="<?= $currentSettings['CONFLICT_DELTA'] ?>" 
                               min="0.01" step="0.01" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">حد الاقتراحات (CANDIDATES_LIMIT)</label>
                        <span class="form-help">أقصى عدد اقتراحات معروضة (20)</span>
                        <input type="number" class="form-input" name="CANDIDATES_LIMIT" 
                               value="<?= $currentSettings['CANDIDATES_LIMIT'] ?>" 
                               min="1" step="1" required>
                    </div>
                </div>
            </div>
            
            <!-- Actions -->
            <div class="form-actions">
                <button type="submit" class="btn btn-success">💾 حفظ التغييرات</button>
                <button type="button" id="resetBtn" class="btn btn-danger">🔄 استعادة الافتراضيات</button>
            </div>
        </form>
    </div>
    
    <script>
        const form = document.getElementById('settingsForm');
        const successAlert = document.getElementById('alertSuccess');
        const errorAlert = document.getElementById('alertError');
        const resetBtn = document.getElementById('resetBtn');
        
        // Show alert
        function showAlert(type, message) {
            const alert = type === 'success' ? successAlert : errorAlert;
            alert.textContent = message;
            alert.classList.remove('alert-hidden');
            
            // Hide after 5 seconds
            setTimeout(() => {
                alert.classList.add('alert-hidden');
            }, 5000);
        }
        
        // Hide alerts
        function hideAlerts() {
            successAlert.classList.add('alert-hidden');
            errorAlert.classList.add('alert-hidden');
        }
        
        // Form submission
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            hideAlerts();
            
            const formData = new FormData(form);
            const settings = {};
            
            for (let [key, value] of formData.entries()) {
                // Convert to number if possible
                settings[key] = isNaN(value) ? value : parseFloat(value);
            }
            
            try {
                const response = await fetch('../api/settings.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(settings)
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showAlert('success', '✅ تم حفظ الإعدادات بنجاح');
                } else {
                    const errorMsg = data.errors ? data.errors.join(', ') : data.error;
                    showAlert('error', '❌ خطأ: ' + errorMsg);
                }
            } catch (error) {
                showAlert('error', '❌ خطأ في الاتصال: ' + error.message);
            }
        });
        
        // Reset to defaults
        resetBtn.addEventListener('click', async () => {
            if (!confirm('هل أنت متأكد من استعادة الإعدادات الافتراضية؟')) {
                return;
            }
            
            hideAlerts();
            
            const defaults = {
                'MATCH_AUTO_THRESHOLD': 0.90,
                'MATCH_REVIEW_THRESHOLD': 0.70,
                'MATCH_WEAK_THRESHOLD': 0.70,
                'WEIGHT_OFFICIAL': 1.0,
                'WEIGHT_ALT_CONFIRMED': 0.95,
                'WEIGHT_ALT_LEARNING': 0.75,
                'WEIGHT_FUZZY': 0.80,
                'CONFLICT_DELTA': 0.1,
                'CANDIDATES_LIMIT': 20
            };
            
            try {
                const response = await fetch('../api/settings.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(defaults)
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showAlert('success', '✅ تم استعادة الإعدادات الافتراضية');
                    // Reload page to show new values
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showAlert('error', '❌ خطأ: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                showAlert('error', '❌ خطأ في الاتصال: ' + error.message);
            }
        });
    </script>
</body>
</html>
