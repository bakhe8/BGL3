<?php
/**
 * Experiment: Focused Workflow (Final Design)
 * ============================================
 * 
 * تصميم نهائي يجمع أفضل العناصر:
 * 1. التوزيع الثلاثي من integrated-view
 * 2. التصميم الفاتح البسيط من unified-workflow-light
 * 3. التركيز على بطاقة واحدة من clean-ui
 * 
 * النتيجة: واجهة مركزة، نظيفة، وعملية
 */
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Focused Workflow - DesignLab</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        
        html, body {
            font-family: 'Tajawal', sans-serif;
            height: 100%;
            -webkit-font-smoothing: antialiased;
        }
        
        body {
            background: #f1f5f9;
            color: #1e293b;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           THREE-COLUMN LAYOUT
           ═══════════════════════════════════════════════════════════════ */
        .app-container {
            display: flex;
            height: 100vh;
            overflow: hidden;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           SIDEBAR - Minimal (Right)
           ═══════════════════════════════════════════════════════════════ */
        .sidebar {
            width: 64px;
            background: #0f172a;
            color: white;
            display: flex;
            flex-direction: column;
            align-items: center;
            flex-shrink: 0;
            padding: 12px 0;
        }
        
        .logo-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #3b82f6, #6366f1);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 16px;
            margin-bottom: 20px;
        }
        
        .nav-items {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
        }
        
        .nav-item {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s;
            background: none;
            border: none;
        }
        
        .nav-item:hover { background: rgba(255,255,255,0.1); color: white; }
        .nav-item.active { background: #3b82f6; color: white; }
        .nav-item svg { width: 20px; height: 20px; }
        
        /* Queue Badge */
        .queue-badge {
            background: #ef4444;
            color: white;
            font-size: 11px;
            font-weight: 700;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-top: auto;
            margin-bottom: 8px;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           MAIN CONTENT (Center) - THE FOCUS AREA
           ═══════════════════════════════════════════════════════════════ */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
            overflow: hidden;
        }
        
        /* Minimal Top Bar */
        .topbar {
            height: 52px;
            background: white;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
        }
        
        .topbar-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .topbar-title h1 {
            font-size: 16px;
            font-weight: 700;
            color: #1e293b;
        }
        
        .status-badge {
            font-size: 11px;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 50px;
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            color: #b45309;
        }
        
        .topbar-nav {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .nav-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: white;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .nav-btn:hover { background: #f8fafc; color: #1e293b; border-color: #cbd5e1; }
        .nav-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        .nav-btn svg { width: 16px; height: 16px; }
        
        .nav-counter {
            font-size: 12px;
            color: #64748b;
            padding: 0 8px;
        }
        
        /* Content Area - Centered Focus */
        .content-area {
            flex: 1;
            overflow-y: auto;
            padding: 32px;
            display: flex;
            justify-content: center;
            align-items: flex-start;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           THE FOCUS CARD - One Card To Rule Them All
           ═══════════════════════════════════════════════════════════════ */
        .focus-card {
            width: 100%;
            max-width: 680px;
            background: white;
            border-radius: 16px;
            box-shadow: 
                0 4px 6px -1px rgba(0, 0, 0, 0.05),
                0 10px 30px -5px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }
        
        /* Card Header */
        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .card-title {
            font-size: 18px;
            font-weight: 800;
            color: #1e293b;
        }
        
        .card-subtitle {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 2px;
        }
        
        .card-actions {
            display: flex;
            gap: 8px;
        }
        
        .icon-btn {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            background: white;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .icon-btn:hover { background: #f8fafc; color: #3b82f6; border-color: #bfdbfe; }
        .icon-btn svg { width: 18px; height: 18px; }
        
        /* Card Body */
        .card-body {
            padding: 24px;
        }
        
        /* Form Layout */
        .form-section {
            margin-bottom: 24px;
        }
        
        .form-section:last-child { margin-bottom: 0; }
        
        .section-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #94a3b8;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .section-title::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #f1f5f9;
        }
        
        /* Fields */
        .field-row {
            display: flex;
            gap: 16px;
            margin-bottom: 16px;
        }
        
        .field-row:last-child { margin-bottom: 0; }
        
        .field-group {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        
        .field-group.small { flex: 0.4; }
        
        .field-label {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 6px;
        }
        
        .field-input {
            font-family: inherit;
            font-size: 15px;
            font-weight: 600;
            color: #1e293b;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 14px;
            transition: all 0.2s;
        }
        
        .field-input:hover { border-color: #cbd5e1; }
        .field-input:focus { outline: none; border-color: #3b82f6; background: white; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
        
        /* Chips */
        .chips-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }
        
        .chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            font-family: inherit;
        }
        
        .chip.selected {
            background: linear-gradient(135deg, #dcfce7, #bbf7d0);
            color: #15803d;
            box-shadow: 0 2px 8px rgba(34, 197, 94, 0.2);
        }
        
        .chip.candidate {
            background: #f1f5f9;
            color: #64748b;
        }
        
        .chip.candidate:hover {
            background: #e2e8f0;
            color: #1e293b;
        }
        
        .chip-score {
            font-size: 10px;
            opacity: 0.7;
        }
        
        /* Info Strip */
        .info-strip {
            display: flex;
            gap: 1px;
            background: #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
            margin-top: 8px;
        }
        
        .info-item {
            flex: 1;
            background: #f8fafc;
            padding: 12px;
            text-align: center;
        }
        
        .info-item:first-child { border-radius: 10px 0 0 10px; }
        .info-item:last-child { border-radius: 0 10px 10px 0; }
        
        .info-label { font-size: 10px; color: #94a3b8; margin-bottom: 2px; }
        .info-value { font-size: 13px; font-weight: 700; color: #1e293b; }
        .info-value.success { color: #16a34a; }
        
        /* Card Footer */
        .card-footer {
            padding: 20px 24px;
            background: linear-gradient(to top, #f8fafc, white);
            border-top: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .footer-options {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .option-toggle {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }
        
        .toggle-checkbox { accent-color: #3b82f6; }
        .toggle-label { font-size: 12px; color: #64748b; }
        
        .footer-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            border-radius: 10px;
            font-family: inherit;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            box-shadow: 0 4px 14px -3px rgba(59, 130, 246, 0.5);
        }
        
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px -3px rgba(59, 130, 246, 0.6);
        }
        
        .btn-primary svg { width: 16px; height: 16px; }
        
        .btn-secondary {
            background: white;
            color: #64748b;
            border: 1px solid #e2e8f0;
        }
        
        .btn-secondary:hover { background: #f8fafc; color: #1e293b; }
        
        /* ═══════════════════════════════════════════════════════════════
           TIMELINE PANEL - Compact (Left)
           ═══════════════════════════════════════════════════════════════ */
        .timeline-panel {
            width: 240px;
            background: white;
            border-right: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        
        .timeline-header {
            height: 52px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            padding: 0 16px;
            gap: 10px;
        }
        
        .timeline-icon {
            width: 28px;
            height: 28px;
            background: #f1f5f9;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
        }
        
        .timeline-icon svg { width: 14px; height: 14px; }
        
        .timeline-title {
            font-weight: 700;
            font-size: 13px;
            color: #475569;
        }
        
        .timeline-body {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
        }
        
        .timeline-list {
            position: relative;
            padding-right: 20px;
        }
        
        .timeline-line {
            position: absolute;
            right: 6px;
            top: 8px;
            bottom: 8px;
            width: 2px;
            background: linear-gradient(to bottom, #3b82f6, #e2e8f0 30%);
            border-radius: 1px;
        }
        
        .event {
            position: relative;
            margin-bottom: 16px;
        }
        
        .event:last-child { margin-bottom: 0; }
        
        .event-dot {
            position: absolute;
            right: -17px;
            top: 2px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            border: 2px solid white;
        }
        
        .event-dot.current {
            background: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }
        
        .event-dot.past { background: #cbd5e1; }
        
        .event-content {
            background: #f8fafc;
            border-radius: 8px;
            padding: 10px 12px;
            border: 1px solid #e2e8f0;
        }
        
        .event-content.current {
            background: #eff6ff;
            border-color: #bfdbfe;
        }
        
        .event-label {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #3b82f6;
            margin-bottom: 2px;
        }
        
        .event-title {
            font-size: 12px;
            font-weight: 700;
            color: #1e293b;
        }
        
        .event-time {
            font-size: 10px;
            color: #94a3b8;
            margin-top: 4px;
        }
        
        [x-cloak] { display: none !important; }
    </style>
</head>
<body x-data="{ propagateAuto: true }">

    <div class="app-container">
        
        <!-- ══════════════════════════════════════════════════════════════ -->
        <!-- MINIMAL SIDEBAR (Right) -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <aside class="sidebar">
            <div class="logo-icon">B</div>
            
            <div class="nav-items">
                <button class="nav-item active" title="اتخاذ القرار">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                </button>
                <button class="nav-item" title="محفوظات">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" /></svg>
                </button>
                <button class="nav-item" title="استيراد">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" /></svg>
                </button>
            </div>
            
            <div class="queue-badge" title="3 معلق">3</div>
            
            <button class="nav-item" title="الإعدادات">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /></svg>
            </button>
        </aside>
        
        <!-- ══════════════════════════════════════════════════════════════ -->
        <!-- MAIN CONTENT - THE FOCUS ZONE -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <main class="main-content">
            <!-- Minimal Top Bar with Navigation -->
            <header class="topbar">
                <div class="topbar-title">
                    <h1>طلب تمديد ضمان</h1>
                    <span class="status-badge">يحتاج قرار</span>
                </div>
                
                <div class="topbar-nav">
                    <button class="nav-btn" disabled>
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                    </button>
                    <span class="nav-counter">1 / 3</span>
                    <button class="nav-btn">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
                    </button>
                </div>
            </header>
            
            <!-- Centered Focus Card -->
            <div class="content-area">
                <div class="focus-card">
                    
                    <!-- Card Header -->
                    <div class="card-header">
                        <div>
                            <div class="card-title">#LG-2024-8821</div>
                            <div class="card-subtitle">ضمان نهائي • ورد قبل ساعتين</div>
                        </div>
                        <div class="card-actions">
                            <button class="icon-btn" title="معاينة الخطاب">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            </button>
                            <button class="icon-btn" title="طباعة">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" /></svg>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Card Body -->
                    <div class="card-body">
                        
                        <!-- Section: Supplier -->
                        <div class="form-section">
                            <div class="section-title">المستفيد</div>
                            <div class="field-group">
                                <input type="text" class="field-input" value="شركة المقاولات المتحدة">
                                <div class="chips-row">
                                    <button class="chip selected">✓ المختار</button>
                                    <button class="chip candidate">المقاولات التجارية <span class="chip-score">⭐⭐</span></button>
                                    <button class="chip candidate">مؤسسة المتحدة <span class="chip-score">⭐</span></button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Section: Bank & Amount -->
                        <div class="form-section">
                            <div class="section-title">البنك والمبلغ</div>
                            <div class="field-row">
                                <div class="field-group">
                                    <label class="field-label">البنك المصدر</label>
                                    <select class="field-input">
                                        <option selected>البنك الأهلي السعودي (SNB)</option>
                                        <option>مصرف الراجحي</option>
                                        <option>بنك الرياض</option>
                                    </select>
                                </div>
                                <div class="field-group small">
                                    <label class="field-label">المبلغ (SAR)</label>
                                    <input type="text" class="field-input" value="1,500,000.00" dir="ltr" style="text-align: left; font-family: monospace;">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Section: Details Strip -->
                        <div class="form-section">
                            <div class="section-title">تفاصيل</div>
                            <div class="info-strip">
                                <div class="info-item">
                                    <div class="info-label">رقم العقد</div>
                                    <div class="info-value">CON-2024-1234</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">تاريخ الانتهاء</div>
                                    <div class="info-value">30/12/2025</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">التمديد إلى</div>
                                    <div class="info-value success">30/12/2026</div>
                                </div>
                            </div>
                        </div>
                        
                    </div>
                    
                    <!-- Card Footer -->
                    <div class="card-footer">
                        <div class="footer-options">
                            <label class="option-toggle">
                                <input type="checkbox" class="toggle-checkbox" x-model="propagateAuto">
                                <span class="toggle-label">تطبيق على المشابهة</span>
                            </label>
                        </div>
                        <div class="footer-actions">
                            <button class="btn btn-secondary">إفراج</button>
                            <button class="btn btn-primary">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                اعتماد وتمديد
                            </button>
                        </div>
                    </div>
                    
                </div>
            </div>
        </main>
        
        <!-- ══════════════════════════════════════════════════════════════ -->
        <!-- COMPACT TIMELINE (Left) -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <aside class="timeline-panel">
            <div class="timeline-header">
                <div class="timeline-icon">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                </div>
                <span class="timeline-title">السجل</span>
            </div>
            
            <div class="timeline-body">
                <div class="timeline-list">
                    <div class="timeline-line"></div>
                    
                    <div class="event">
                        <div class="event-dot current"></div>
                        <div class="event-content current">
                            <div class="event-label">الآن</div>
                            <div class="event-title">يحتاج قرار</div>
                            <div class="event-time">اليوم 10:42 ص</div>
                        </div>
                    </div>
                    
                    <div class="event">
                        <div class="event-dot past"></div>
                        <div class="event-content">
                            <div class="event-title">تمديد</div>
                            <div class="event-time">01/06/2024</div>
                        </div>
                    </div>
                    
                    <div class="event">
                        <div class="event-dot past"></div>
                        <div class="event-content">
                            <div class="event-title">تمديد</div>
                            <div class="event-time">01/06/2023</div>
                        </div>
                    </div>
                    
                    <div class="event">
                        <div class="event-dot past"></div>
                        <div class="event-content">
                            <div class="event-title">إصدار أولي</div>
                            <div class="event-time">01/12/2022</div>
                        </div>
                    </div>
                </div>
            </div>
        </aside>
        
    </div>

</body>
</html>
