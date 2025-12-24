<?php
/**
 * Experiment: Unified Practical (Unified Workflow + Improved Current)
 * =================================================================
 * 
 * دمج:
 * - التوزيع الثلاثي (Sidebar | Workspace | Timeline)
 * - التصميم البصري النظيف (Improved Current)
 * 
 * الهدف: واجهة يومية عملية وسريعة
 */

$EXPERIMENT_NAME = 'Unified Practical';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unified Practical - DesignLab</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        /* ═══════════════════════════════════════════════════════════════
           RESET & BASE (From Improved Current)
           ═══════════════════════════════════════════════════════════════ */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        
        body {
            font-family: 'Tajawal', sans-serif;
            background: #f1f5f9; /* Slightly darker than improved-current for better contrast with white panels */
            color: #1e293b;
            height: 100vh;
            overflow: hidden;
            display: flex;
        }

        /* ═══════════════════════════════════════════════════════════════
           LAYOUT STRUCTURE (3 Columns)
           ═══════════════════════════════════════════════════════════════ */
        .app-sidebar {
            width: 260px;
            background: white;
            border-left: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            z-index: 10;
        }

        .app-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
            background: #f8fafc;
        }

        .app-timeline {
            width: 320px;
            background: white;
            border-right: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            z-index: 10;
        }

        /* ═══════════════════════════════════════════════════════════════
           SIDEBAR (Context/Queue)
           ═══════════════════════════════════════════════════════════════ */
        .sidebar-header {
            height: 60px;
            display: flex;
            align-items: center;
            padding: 0 20px;
            border-bottom: 1px solid #f1f5f9;
            gap: 12px;
        }

        .brand-icon {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, #3b82f6, #6366f1);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 800;
            font-size: 14px;
        }

        .brand-text {
            font-weight: 800;
            font-size: 16px;
            color: #1e293b;
        }

        .queue-list {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
        }

        .queue-group-title {
            font-size: 11px;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
            padding: 0 4px;
        }

        .queue-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 14px;
            background: white;
            border: 1px solid #f1f5f9;
            border-radius: 10px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .queue-item:hover {
            border-color: #cbd5e1;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .queue-item.active {
            background: #eff6ff;
            border-color: #bfdbfe;
        }

        .q-info h4 { font-size: 13px; font-weight: 700; margin-bottom: 4px; color: #334155; }
        .q-info p { font-size: 11px; color: #64748b; }
        
        .q-status {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        .q-status.red { background: #ef4444; }
        .q-status.orange { background: #f59e0b; }
        .q-status.green { background: #22c55e; }

        /* ═══════════════════════════════════════════════════════════════
           MAIN WORKSPACE
           ═══════════════════════════════════════════════════════════════ */
        .workspace-header {
            height: 60px;
            background: white;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
        }

        .record-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .record-id {
            font-size: 18px;
            font-weight: 800;
            color: #0f172a;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 700;
            background: #fef3c7;
            color: #d97706;
            border: 1px solid #fde68a;
        }

        .workspace-body {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
            display: flex;
            justify-content: center;
        }

        .decision-card {
            background: white;
            width: 100%;
            max-width: 800px;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }

        /* Improved Current Styling for Form */
        .card-content { padding: 32px; }

        .form-grid {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 24px;
            margin-bottom: 32px;
        }

        .field-group { margin-bottom: 24px; }
        .field-label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            margin-bottom: 8px;
        }
        
        .field-value-primary {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            padding-bottom: 8px;
            border-bottom: 2px solid #e2e8f0;
            width: 100%;
        }

        .chips-row { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; }
        
        .chip {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid #e2e8f0;
            background: white;
            color: #64748b;
            transition: all 0.2s;
        }
        
        .chip:hover { border-color: #94a3b8; color: #334155; }
        .chip.selected {
            background: #eff6ff;
            color: #2563eb;
            border-color: #bfdbfe;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            background: #f8fafc;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 32px;
        }

        .info-item label { font-size: 10px; color: #94a3b8; font-weight: 600; display: block; margin-bottom: 4px; }
        .info-item span { font-size: 13px; font-weight: 700; color: #334155; }
        .info-item span.highlight { color: #16a34a; }

        .actions-footer {
            background: #f8fafc;
            padding: 20px 32px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .btn-primary {
            background: #3b82f6;
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary:hover { background: #2563eb; }

        .btn-secondary {
            background: white;
            color: #64748b;
            border: 1px solid #e2e8f0;
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
        }
        .btn-secondary:hover { border-color: #cbd5e1; color: #334155; }

        /* ═══════════════════════════════════════════════════════════════
           TIMELINE (History)
           ═══════════════════════════════════════════════════════════════ */
        .timeline-header {
            height: 60px;
            padding: 0 20px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .section-title { font-weight: 700; color: #334155; font-size: 14px; }

        .timeline-content {
            flex: 1;
            padding: 20px;
            position: relative;
            overflow-y: auto;
        }

        .timeline-line {
            position: absolute;
            right: 34px; /* Adjusted for RTL padding */
            top: 20px;
            bottom: 20px;
            width: 2px;
            background: #f1f5f9;
        }

        .t-item {
            position: relative;
            margin-bottom: 24px;
            padding-right: 32px;
        }

        .t-dot {
            position: absolute;
            right: 0;
            top: 4px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: white;
            border: 2px solid #cbd5e1;
            z-index: 2;
        }

        .t-dot.active { border-color: #3b82f6; background: #3b82f6; box-shadow: 0 0 0 3px #dbeafe; }
        .t-dot.success { border-color: #22c55e; background: #22c55e; }

        .t-card {
            background: #f8fafc;
            border: 1px solid #f1f5f9;
            border-radius: 12px;
            padding: 12px;
        }
        .t-card.active { background: #eff6ff; border-color: #bfdbfe; }

        .t-time { font-size: 10px; color: #94a3b8; display: block; margin-bottom: 4px; }
        .t-title { font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 4px; }
        .t-desc { font-size: 12px; color: #64748b; line-height: 1.4; }

    </style>
</head>
<body>

    <!-- SIDEBAR -->
    <aside class="app-sidebar">
        <div class="sidebar-header">
            <div class="brand-icon">B</div>
            <span class="brand-text">BGL Lab</span>
        </div>
        <div class="queue-list">
            <div class="queue-group-title">قيد الانتظار (3)</div>
            
            <div class="queue-item active">
                <div class="q-info">
                    <h4>LG-2024-8821</h4>
                    <p>شركة المقاولات المتحدة</p>
                </div>
                <div class="q-status orange"></div>
            </div>

            <div class="queue-item">
                <div class="q-info">
                    <h4>LG-2024-8822</h4>
                    <p>مؤسسة الأفق البعيد</p>
                </div>
                <div class="q-status orange"></div>
            </div>

            <div class="queue-item">
                <div class="q-info">
                    <h4>LG-2024-8825</h4>
                    <p>شركة الرواد</p>
                </div>
                <div class="q-status orange"></div>
            </div>
        </div>
    </aside>

    <!-- MAIN WORKSPACE -->
    <main class="app-main">
        <header class="workspace-header">
            <div class="record-title">
                <span class="record-id">LG-2024-8821</span>
                <span class="status-badge">مراجعة نهائية</span>
            </div>
            <div style="display: flex; gap: 8px;">
                <button class="btn-secondary" style="padding: 6px 12px; font-size: 12px;">← السجل السابق</button>
                <button class="btn-secondary" style="padding: 6px 12px; font-size: 12px;">السجل التالي →</button>
            </div>
        </header>

        <div class="workspace-body">
            <div class="decision-card">
                <div class="card-content">
                    <div class="form-grid">
                        <div class="field-group">
                            <label class="field-label">المورد (المستفيد)</label>
                            <input type="text" class="field-value-primary" value="شركة المقاولات المتحدة" readonly>
                            <div class="chips-row">
                                <span class="chip selected">✓ مطابق للسجل التجاري</span>
                                <span class="chip">⚠️ اختلاف بسيط في الاسم</span>
                            </div>
                        </div>
                        <div class="field-group">
                            <label class="field-label">المبلغ</label>
                            <div class="field-value-primary" style="font-family: monospace;">1,500,000.00 SAR</div>
                            <div class="chips-row">
                                <span class="chip selected">✓ ضمن الحد الائتماني</span>
                            </div>
                        </div>
                    </div>

                    <div class="info-grid">
                        <div class="info-item">
                            <label>البنك المصدر</label>
                            <span>البنك الأهلي السعودي</span>
                        </div>
                        <div class="info-item">
                            <label>رقم العقد</label>
                            <span>CON-2024-9982</span>
                        </div>
                        <div class="info-item">
                            <label>تاريخ الانتهاء</label>
                            <span class="highlight">30 ديسمبر 2025</span>
                        </div>
                    </div>

                    <div class="field-group">
                        <label class="field-label">ملاحظات القرار</label>
                        <textarea style="width: 100%; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; font-family: inherit; font-size: 13px; min-height: 80px;" placeholder="اكتب ملاحظات إضافية هنا..."></textarea>
                    </div>
                </div>

                <div class="actions-footer">
                    <div style="display: flex; gap: 12px;">
                        <button class="btn-primary">
                            <span>✅</span> اعتماد وتمديد الضمان
                        </button>
                        <button class="btn-secondary">
                            تحويل للمراجعة القانونية
                        </button>
                    </div>
                    <button class="btn-secondary" style="border: none; color: #94a3b8;">
                        إلغاء
                    </button>
                </div>
            </div>
        </div>
    </main>

    <!-- TIMELINE -->
    <aside class="app-timeline">
        <header class="timeline-header">
            <span class="section-title">سجل العمليات</span>
        </header>
        <div class="timeline-content">
            <div class="timeline-line"></div>
            
            <div class="t-item">
                <div class="t-dot active"></div>
                <div class="t-card active">
                    <span class="t-time">الآن (10:45 ص)</span>
                    <h5 class="t-title">في انتظار القرار</h5>
                    <p class="t-desc">تمت مراجعة البيانات آلياً ولم يتم العثور على ملاحظات جوهرية.</p>
                </div>
            </div>

            <div class="t-item">
                <div class="t-dot success"></div>
                <div class="t-card">
                    <span class="t-time">أمس (09:30 ص)</span>
                    <h5 class="t-title">وارد من البنك</h5>
                    <p class="t-desc">تم استلام طلب التمديد عبر سويفت MT760</p>
                </div>
            </div>

            <div class="t-item">
                <div class="t-dot"></div>
                <div class="t-card">
                    <span class="t-time">01/01/2024</span>
                    <h5 class="t-title">إصدار الضمان</h5>
                    <p class="t-desc">تم إصدار الضمان الأساسي لمدة سنة واحدة.</p>
                </div>
            </div>

        </div>
    </aside>

</body>
</html>
