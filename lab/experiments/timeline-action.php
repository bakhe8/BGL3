<?php
// timeline-action.php
// تجربة "التايم لاين كمحرك للنظام" (Timeline Action System)
// بناءً على نتائج التدقيق DF-011

$EXPERIMENT_NAME = 'Timeline Action';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DesignLab - <?php echo $EXPERIMENT_NAME; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="/design-lab/assets/css/base.css">
    
    <style>
        :root {
            --primary: #8b5cf6; /* بنفسجي للتميز عن التصميم القديم */
            --primary-dark: #7c3aed;
            --secondary: #64748b;
            --bg-dark: #0f172a;
            --surface: #1e293b;
            --surface-hover: #334155;
            --border: #334155;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --success: #10b981;
            --warning: #f59e0b;
        }

        body {
            background-color: var(--bg-dark);
            color: var(--text-main);
            height: 100vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        /* 1. Unified Header */
        .app-header {
            background-color: var(--surface);
            border-bottom: 1px solid var(--border);
            height: 64px;
            display: flex;
            align-items: center;
            padding: 0 24px;
            justify-content: space-between;
            flex-shrink: 0;
            z-index: 10;
        }

        .header-title {
            font-size: 18px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-actions {
            display: flex;
            gap: 12px;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }
        .btn-primary:hover { background: var(--primary-dark); }

        .btn-ghost {
            background: transparent;
            color: var(--text-muted);
        }
        .btn-ghost:hover {
            background: rgba(255,255,255,0.05);
            color: white;
        }
        
        /* Layout Grid */
        .layout-container {
            display: grid;
            grid-template-columns: 350px 1fr 400px; /* List - Main/Timeline - Preview */
            flex: 1;
            overflow: hidden;
        }

        /* Column 1: Record List (Context) */
        .col-list {
            background: var(--surface);
            border-left: 1px solid var(--border);
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }
        
        .list-header {
            padding: 16px;
            border-bottom: 1px solid var(--border);
            font-weight: 700;
            color: var(--text-muted);
            font-size: 13px;
        }

        .record-item {
            padding: 16px;
            border-bottom: 1px solid var(--border);
            cursor: pointer;
            transition: background 0.2s;
        }
        .record-item:hover { background: var(--surface-hover); }
        .record-item.active {
            background: rgba(139, 92, 246, 0.1);
            border-right: 3px solid var(--primary);
        }
        
        /* Column 2: The Timeline Engine (The Core) */
        .col-main {
            background: var(--bg-dark);
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            padding: 32px;
            align-items: center; /* Centered Timeline */
        }

        /* The Data Card (Static Context) */
        .data-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 24px;
            width: 100%;
            max-width: 800px;
            margin-bottom: 40px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .data-card-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border);
        }

        .data-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 24px;
        }

        .data-field label {
            display: block;
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 4px;
        }
        .data-field .value {
            font-size: 16px;
            font-weight: 600;
        }

        /* The Interactive Timeline */
        .timeline-container {
            width: 100%;
            max-width: 800px;
            position: relative;
        }
        
        /* Timeline Line */
        .timeline-container::before {
            content: '';
            position: absolute;
            right: 40px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--border);
        }

        .timeline-event {
            position: relative;
            margin-bottom: 32px;
            padding-right: 80px; /* Space for the line/icon */
        }

        .event-point {
            position: absolute;
            right: 28px;
            top: 0;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--surface);
            border: 2px solid var(--secondary);
            z-index: 2;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
        }
        
        .timeline-event.active .event-point {
            background: var(--primary);
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.3);
        }

        .event-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 16px;
        }
        
        .event-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        
        .event-title { font-weight: 700; font-size: 14px; }
        .event-time { font-size: 12px; color: var(--text-muted); }

        /* Action Buttons Area */
        .action-area {
            margin-top: 16px;
            display: flex;
            gap: 8px;
        }

        .action-btn {
            background: rgba(139, 92, 246, 0.1);
            color: var(--primary);
            border: 1px solid rgba(139, 92, 246, 0.2);
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .action-btn:hover {
            background: rgba(139, 92, 246, 0.2);
        }

        /* New Event Creator (The Input) */
        .event-creator {
            margin-top: 40px;
            position: relative;
            padding-right: 80px;
        }
        .event-creator .event-point {
            border-style: dashed;
            background: transparent;
        }
        .creator-box {
            background: rgba(255,255,255,0.02);
            border: 1px dashed var(--border);
            border-radius: 8px;
            padding: 24px;
            text-align: center;
        }
        
        /* Column 3: The Preview (Liked in DF-010) */
        .col-preview {
            background: #1e1e1e; /* Darker background for preview */
            border-right: 1px solid var(--border);
            padding: 24px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .paper-preview {
            background: white;
            width: 100%;
            height: 100%;
            border-radius: 4px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            color: black;
            padding: 40px;
            font-size: 12px;
            overflow-y: auto;
        }
        
        .preview-header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }

    </style>
</head>
<body x-data="timelineLab()">

    <!-- 1. The Unified Header -->
    <header class="app-header">
        <div class="header-title">
            <span style="font-size: 24px">🧪</span>
            <span>DesignLab / Timeline Action</span>
        </div>
        <div class="header-actions">
            <button class="btn btn-ghost" @click="togglePreview">
                👁️ معاينة الخطاب
            </button>
            <a href="/lab" class="btn btn-ghost">خروج</a>
        </div>
    </header>

    <!-- Main Layout -->
    <div class="layout-container">
        
        <!-- RIGHT: Context List -->
        <aside class="col-list">
            <div class="list-header">السجلات (الجلسة 515)</div>
            <template x-for="item in records" :key="item.id">
                <div class="record-item" :class="{'active': activeRecord === item.id}" @click="activeRecord = item.id">
                    <div style="font-weight: 700" x-text="item.number"></div>
                    <div style="font-size: 12px; color: #94a3b8" x-text="item.supplier"></div>
                </div>
            </template>
        </aside>

        <!-- CENTER: The Action Timeline -->
        <main class="col-main">
            
            <!-- A. Current State Card (Summary) -->
            <div class="data-card">
                <div class="data-card-header">
                    <div>
                        <div style="font-size: 12px; color: #94a3b8">رقم الضمان</div>
                        <div style="font-size: 24px; font-weight: 800">G-2023-001</div>
                    </div>
                    <div class="tag" style="background: rgba(16, 185, 129, 0.1); color: #34d399; padding: 4px 12px; border-radius: 4px; height: fit-content">
                        جاهز
                    </div>
                </div>
                <div class="data-grid">
                    <div class="data-field">
                        <label>المورد</label>
                        <div class="value">شركة الأفق للتجارة</div>
                    </div>
                    <div class="data-field">
                        <label>البنك</label>
                        <div class="value">الراجحي</div>
                    </div>
                    <div class="data-field">
                        <label>المبلغ</label>
                        <div class="value">150,000</div>
                    </div>
                    <div class="data-field">
                        <label>تاريخ الانتهاء</label>
                        <div class="value">2024-12-31</div>
                    </div>
                </div>
            </div>

            <!-- B. The Interactive Timeline -->
            <div class="timeline-container">
                
                <!-- Event 1: Import -->
                <div class="timeline-event">
                    <div class="event-point">📥</div>
                    <div class="event-card">
                        <div class="event-header">
                            <span class="event-title">استيراد من Excel</span>
                            <span class="event-time">10:00 AM</span>
                        </div>
                        <div style="color: #94a3b8; font-size: 13px">
                            تم استيراد السجل بقيمة "150,000" واسم مورد خام "الأفق للتجارة".
                        </div>
                    </div>
                </div>

                <!-- Event 2: System Match -->
                <div class="timeline-event">
                    <div class="event-point">🤖</div>
                    <div class="event-card">
                        <div class="event-header">
                            <span class="event-title">مطابقة آلية</span>
                            <span class="event-time">10:01 AM</span>
                        </div>
                        <div style="color: #94a3b8; font-size: 13px">
                            تعرف النظام على المورد "شركة الأفق للتجارة" بنسبة وثوق 95%.
                        </div>
                        <div class="action-area">
                            <button class="action-btn">✅ اعتماد المطابقة</button>
                            <button class="action-btn" style="color: #f59e0b; background: rgba(245, 158, 11, 0.1); border-color: rgba(245, 158, 11, 0.2)">✏️ تصحيح الاسم</button>
                        </div>
                    </div>
                </div>

                <!-- Event 3: Extension Action (The New Way) -->
                <div class="timeline-event active">
                    <div class="event-point">📄</div>
                    <div class="event-card">
                        <div class="event-header">
                            <span class="event-title">طلب تمديد</span>
                            <span class="event-time">الآن</span>
                        </div>
                        <div style="margin-bottom: 12px">
                            <div style="font-size: 13px; color: #94a3b8; margin-bottom: 8px">إعدادات الخطاب:</div>
                            <input type="date" value="2025-06-30" style="background: #0f172a; border: 1px solid #334155; color: white; padding: 8px; border-radius: 4px">
                        </div>
                        <div class="action-area">
                            <button class="action-btn">🖨️ طباعة الخطاب</button>
                            <button class="action-btn" style="color: #ef4444; background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.2)">🗑️ إلغاء</button>
                        </div>
                    </div>
                </div>

                <!-- ADD NEW ACTION (The Driver) -->
                <div class="event-creator">
                    <div class="event-point">+</div>
                    <div class="creator-box">
                        <div style="font-size: 14px; margin-bottom: 16px; color: #94a3b8">إضافة إجراء جديد</div>
                        <div style="display: flex; justify-content: center; gap: 12px">
                            <button class="btn btn-primary">تحديث بيانات</button>
                            <button class="btn btn-primary" style="background: #8b5cf6">طلب تمديد</button>
                            <button class="btn btn-primary" style="background: #10b981">طلب إفراج</button>
                        </div>
                    </div>
                </div>

            </div>

        </main>

        <!-- LEFT: Preview (Toggleable) -->
        <aside class="col-preview" x-show="showPreview" x-transition>
            <div class="paper-preview">
                <div class="preview-header">
                    <h2>نموذج خطاب تمديد ضمان</h2>
                </div>
                <p>السادة / بنك الراجحي</p>
                <p>السلام عليكم ورحمة الله وبركاته،،،</p>
                <br>
                <p>إشارة إلى الضمان رقم <strong>G-2023-001</strong> الصادر لصالح <strong>شركة الأفق للتجارة</strong> بمبلغ <strong>150,000 ريال</strong>.</p>
                <p>نرجو منكم تمديد صلاحية الضمان المذكور أعلاه لمدة 6 أشهر إضافية لينتهي في <strong>2025-06-30</strong>.</p>
                <br><br>
                <p>وتقبلوا فائق التحية،،،</p>
            </div>
        </aside>

    </div>

    <script>
        function timelineLab() {
            return {
                showPreview: true,
                activeRecord: 1,
                records: [
                    { id: 1, number: 'G-2023-001', supplier: 'شركة الأفق للتجارة' },
                    { id: 2, number: 'G-2023-045', supplier: 'مؤسسة الرياض' },
                    { id: 3, number: 'G-2023-089', supplier: 'مجموعة التميمي' },
                    { id: 4, number: 'G-2023-102', supplier: 'شركة البناء الحديث' },
                ],
                
                togglePreview() {
                    this.showPreview = !this.showPreview;
                }
            }
        }
    </script>
</body>
</html>
