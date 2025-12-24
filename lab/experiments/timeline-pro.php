<?php
// timeline-pro.php
// تجربة "كرونوس" (Chronos) - التصميم الاحترافي
// محاولة إبهار المستخدم بتصميم فاتح، عصري، وتفاعلي بالكامل

$EXPERIMENT_NAME = 'Chronos (Pro)';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DesignLab - <?php echo $EXPERIMENT_NAME; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="/design-lab/assets/css/base.css">
    
    <style>
        :root {
            /* Premium Light Palette */
            --bg-body: #f1f5f9;
            --bg-surface: #ffffff;
            --bg-surface-trans: rgba(255, 255, 255, 0.85);
            
            --primary: #4f46e5; /* Indigo 600 - Deep & Professional */
            --primary-soft: #eef2ff;
            
            --text-main: #0f172a; /* Slate 900 */
            --text-secondary: #64748b; /* Slate 500 */
            --text-light: #94a3b8;
            
            --border: #e2e8f0;
            --border-hover: #cbd5e1;
            
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
            
            --radius-md: 12px;
            --radius-lg: 20px;
        }

        body {
            background-color: var(--bg-body);
            background-image: 
                radial-gradient(at 0% 0%, rgba(79, 70, 229, 0.1) 0px, transparent 50%),
                radial-gradient(at 100% 0%, rgba(59, 130, 246, 0.1) 0px, transparent 50%);
            color: var(--text-main);
            height: 100vh;
            overflow: hidden;
            font-family: 'Tajawal', sans-serif;
            display: flex;
            flex-direction: column;
        }

        /* Glass Header */
        .glass-header {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255,255,255,0.5);
            height: 70px;
            display: flex;
            align-items: center;
            padding: 0 32px;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .brand {
            font-size: 20px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), #8b5cf6);
            background-clip: text;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        /* Layout */
        .workspace {
            display: grid;
            grid-template-columns: 320px 1fr 400px;
            gap: 24px;
            padding: 24px;
            height: calc(100vh - 70px);
            overflow: hidden;
        }

        /* 1. Context Panel (Left) */
        .panel {
            background: var(--bg-surface-trans);
            border-radius: var(--radius-lg);
            border: 1px solid rgba(255,255,255,0.8);
            box-shadow: var(--shadow-sm);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            backdrop-filter: blur(8px);
            height: 100%;
        }

        .panel-header {
            padding: 20px;
            border-bottom: 1px solid var(--border);
            font-weight: 700;
            color: var(--text-secondary);
            font-size: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .list-item {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }
        
        .list-item:hover {
            background: #f8fafc;
            padding-right: 24px;
        }
        
        .list-item.active {
            background: var(--primary-soft);
        }
        
        .list-item.active::before {
            content: '';
            position: absolute;
            right: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: var(--primary);
            border-top-left-radius: 4px;
            border-bottom-left-radius: 4px;
        }

        .item-title { font-weight: 700; font-size: 15px; margin-bottom: 4px; }
        .item-sub { font-size: 13px; color: var(--text-secondary); }

        /* 2. Timeline Core (Center) */
        .timeline-scroll-area {
            overflow-y: auto;
            padding-right: 8px; /* Custom Scrollbar margin */
            padding-bottom: 100px;
            
            /* Hide Scrollbar visually but allow scroll */
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 transparent;
        }

        /* The Hero Card */
        .hero-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 32px;
            margin-bottom: 40px;
            box-shadow: var(--shadow-md);
            border: 1px solid white;
            position: relative;
            overflow: hidden;
        }
        
        .hero-card::before {
            content: '';
            position: absolute;
            top: 0; right: 0; width: 100%; height: 6px;
            background: linear-gradient(90deg, var(--primary), #8b5cf6);
        }

        .hero-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 24px;
        }
        
        .status-badge {
            background: #ecfdf5;
            color: #059669;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 700;
            border: 1px solid #d1fae5;
        }

        .grid-data {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
        }
        
        .data-point label { display: block; font-size: 12px; color: var(--text-secondary); margin-bottom: 6px; font-weight: 500; }
        .data-point value { display: block; font-size: 16px; font-weight: 700; color: var(--text-main); }

        /* Modern Timeline */
        .timeline-wrapper {
            position: relative;
            padding: 0 20px;
        }
        
        .timeline-spine {
            position: absolute;
            right: 49px;
            top: 0;
            bottom: 0px;
            width: 2px;
            background: #e2e8f0;
            z-index: 0;
        }

        .flow-node {
            display: flex;
            gap: 24px;
            margin-bottom: 32px;
            position: relative;
            z-index: 1;
        }

        .node-icon {
            width: 60px;
            height: 60px;
            border-radius: 24px; /* Squircle */
            background: white;
            border: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
            box-shadow: var(--shadow-sm);
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        .flow-node.active .node-icon {
            border-color: var(--primary);
            background: var(--primary);
            color: white;
            box-shadow: 0 10px 15px -3px rgba(79, 70, 229, 0.4);
            transform: scale(1.1);
        }

        .node-content {
            flex: 1;
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #f1f5f9;
            box-shadow: var(--shadow-sm);
            transition: transform 0.2s;
        }
        
        .node-content:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .node-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        
        .time-stamp { font-size: 12px; color: var(--text-light); background: #f8fafc; padding: 4px 8px; border-radius: 6px; }

        /* The "Action Driver" - New Concept */
        .action-driver {
            margin-top: 40px;
            background: white;
            border-radius: 20px;
            padding: 8px; /* Internal padding for input look */
            box-shadow: var(--shadow-lg);
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 12px;
            position: sticky;
            bottom: 20px;
            width: 100%;
            z-index: 10;
        }

        .driver-context {
            width: 50px;
            height: 50px;
            background: var(--bg-body);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-secondary);
        }

        .driver-input {
            flex: 1;
            border: none;
            outline: none;
            font-family: inherit;
            font-size: 16px;
            color: var(--text-main);
            background: transparent;
            padding: 0 12px;
        }

        .driver-actions {
            display: flex;
        }
        
        .micro-btn {
            padding: 10px 20px;
            border-radius: 12px;
            background: var(--primary);
            color: white;
            border: none;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: 0.2s;
        }
        .micro-btn:hover { background: #4338ca; }
        
        .micro-btn.secondary {
            background: transparent;
            color: var(--text-secondary);
        }
        .micro-btn.secondary:hover { background: #f1f5f9; color: var(--text-main); }


        /* 3. Preview (Right) */
        .preview-pane {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            border: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .preview-header {
            background: #f8fafc;
            padding: 16px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .paper-mockup {
            flex: 1;
            padding: 40px;
            overflow-y: auto;
            background: white;
            font-size: 13px;
            line-height: 1.8;
            color: #334155;
        }

        /* Animations */
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .animate-entry {
            animation: slideUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            opacity: 0;
        }
        
        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }

    </style>
</head>
<body x-data="{ 
    activeRecord: 1, 
    showActionInput: false,
    timelineStatus: 'ready' 
}">

    <!-- Filter/Backdrop (Aesthetic only) -->
    <div style="position: fixed; top: -50%; left: -50%; width: 200%; height: 200%; background: radial-gradient(circle at center, white 0%, transparent 70%); opacity: 0.4; pointer-events: none; z-index: 1;"></div>

    <!-- Header -->
    <header class="glass-header">
        <div class="brand">
            <span style="font-size: 24px;">✨</span>
            <span>Chronos Pro</span>
        </div>
        <div style="display: flex; gap: 16px;">
            <a href="/lab" class="micro-btn secondary">إغلاق المختبر</a>
        </div>
    </header>

    <!-- Workspace -->
    <main class="workspace" style="position: relative; z-index: 2;">
        
        <!-- 1. The List (Context) -->
        <aside class="panel animate-entry delay-1">
            <div class="panel-header">
                <span>الجلسة الحالية</span>
                <span style="background: #eff6ff; color: var(--primary); padding: 2px 8px; border-radius: 6px; font-size: 12px;">515</span>
            </div>
            <div style="flex: 1; overflow-y: auto;">
                <div class="list-item active">
                    <div class="item-title">G-2023-001</div>
                    <div class="item-sub">شركة الأفق للتجارة</div>
                </div>
                <!-- Dummies -->
                <div class="list-item">
                    <div class="item-title">G-2023-045</div>
                    <div class="item-sub">مؤسسة الرياض</div>
                </div>
                <div class="list-item">
                    <div class="item-title">G-2023-089</div>
                    <div class="item-sub">مجموعة التميمي والمشارك</div>
                </div>
                <div class="list-item">
                    <div class="item-title">G-2023-112</div>
                    <div class="item-sub">شركة البناء الحديث</div>
                </div>
            </div>
        </aside>

        <!-- 2. The Timeline (Hero) -->
        <section class="timeline-scroll-area animate-entry delay-2">
            
            <!-- Hero Card -->
            <div class="hero-card">
                <div class="hero-header">
                    <div>
                        <div style="font-size: 13px; color: var(--text-secondary); margin-bottom: 4px;">رقم الضمان</div>
                        <div style="font-size: 32px; font-weight: 800; letter-spacing: -1px; color: var(--text-main);">G-2023-001</div>
                    </div>
                    <div class="status-badge">🟢 ساري المفعول</div>
                </div>
                
                <div class="grid-data">
                    <div class="data-point">
                        <label>المورد (الطرف الثاني)</label>
                        <value>شركة الأفق للتجارة والمقاولات</value>
                    </div>
                    <div class="data-point">
                        <label>البنك المصدر</label>
                        <value>مصرف الراجحي - فرع الشركات</value>
                    </div>
                    <div class="data-point">
                        <label>مبلغ الضمان</label>
                        <value>150,000.00 ر.س</value>
                    </div>
                    <div class="data-point">
                        <label>تاريخ الانتهاء</label>
                        <value>31 ديسمبر 2024</value>
                    </div>
                </div>
            </div>

            <!-- Timeline Flow -->
            <div class="timeline-wrapper">
                <div class="timeline-spine"></div>

                <!-- Event: Import -->
                <div class="flow-node">
                    <div class="node-icon">📥</div>
                    <div class="node-content">
                        <div class="node-header">
                            <strong>استيراد سجل جديد</strong>
                            <span class="time-stamp">10:30 AM</span>
                        </div>
                        <p style="color: var(--text-secondary); font-size: 14px; margin: 0;">
                            تم استيراد البيانات من ملف Excel الجلسة رقم 515.
                        </p>
                    </div>
                </div>

                <!-- Event: Match -->
                <div class="flow-node">
                    <div class="node-icon">🤖</div>
                    <div class="node-content">
                        <div class="node-header">
                            <strong>مطابقة آلية ناجحة</strong>
                            <span class="time-stamp">10:31 AM</span>
                        </div>
                        <p style="color: var(--text-secondary); font-size: 14px; margin: 0;">
                            تم التعرف على المورد "شركة الأفق" والبنك "الراجحي" بنسبة تطابق 98%.
                        </p>
                    </div>
                </div>

                <!-- Event: Action (Extension) -->
                <div class="flow-node active">
                    <div class="node-icon">✨</div>
                    <div class="node-content" style="border-color: var(--primary-soft); background: #fdfcff;">
                        <div class="node-header">
                            <strong style="color: var(--primary);">إعداد خطاب تمديد</strong>
                            <span class="time-stamp" style="background: var(--primary-soft); color: var(--primary);">جاري العمل</span>
                        </div>
                        
                        <div style="margin: 16px 0;">
                            <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px;">تاريخ التمديد المقترح</label>
                            <div style="background: white; border: 1px solid #e2e8f0; padding: 12px; border-radius: 12px; display: flex; align-items: center; justify-content: space-between;">
                                <span style="font-weight: 700;">30 يونيو 2025</span>
                                <span style="color: var(--text-light); font-size: 12px;">(+6 أشهر)</span>
                            </div>
                        </div>

                        <div style="display: flex; gap: 12px; margin-top: 20px;">
                            <button class="micro-btn">اعتماد وطباعة</button>
                            <button class="micro-btn secondary" style="color: #ef4444; background: #fef2f2;">إلغاء الإجراء</button>
                        </div>
                    </div>
                </div>

                <!-- The Action Driver (Floating) -->
                <div class="action-driver" x-data>
                    <div class="driver-context">
                        <span style="font-size: 20px;">⚡</span>
                    </div>
                    <input type="text" class="driver-input" placeholder="ما هو الإجراء التالي؟ (مثلاً: تمديد، إفراج، تعديل)...">
                    <div class="driver-actions">
                        <button class="micro-btn secondary" title="قائمة الإجراءات">☰</button>
                        <button class="micro-btn" style="border-radius: 8px; padding: 10px 16px;">⏎</button>
                    </div>
                </div>

            </div>
        </section>

        <!-- 3. Preview (Right) -->
        <aside class="preview-pane animate-entry delay-3">
            <div class="preview-header">
                <span style="font-weight: 600; font-size: 14px;">معاينة المستند</span>
                <div style="display: flex; gap: 8px;">
                    <button style="border: none; background: none; cursor: pointer;">🖨️</button>
                    <button style="border: none; background: none; cursor: pointer;">⬇️</button>
                </div>
            </div>
            <div class="paper-mockup">
                <div style="text-align: center; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid #000;">
                    <h2 style="margin: 0;">نموذج طلب تمديد</h2>
                </div>
                <p><strong>السادة / مصرف الراجحي</strong></p>
                <p>تحية طيبة وبعد،،،</p>
                <br>
                <p>نرجو من سعادتكم التكرم بتمديد صلاحية خطاب الضمان البنكي رقم <strong>G-2023-001</strong> والصادر لصالح المستفيد <strong>شركة الأفق للتجارة</strong>.</p>
                <br>
                <p>فترة التمديد المطلوبة: <strong>6 أشهر</strong>.</p>
                <p>تاريخ الانتهاء الجديد: <strong>30/06/2025</strong>.</p>
                <br><br><br>
                <p style="text-align: left;">التوقيع المعتمد</p>
            </div>
        </aside>

    </main>

</body>
</html>
