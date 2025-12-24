<?php
/**
 * Experiment: Unified Workflow v1 (Light Theme)
 * ==============================================
 * 
 * النسخة الأولى - تصميم فاتح وبسيط
 * CSS مدمج ذاتي بدون اعتماد على Tailwind
 */
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unified Workflow v1 - DesignLab</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        /* ═══════════════════════════════════════════════════════════════
           RESET & BASE
           ═══════════════════════════════════════════════════════════════ */
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        html, body {
            font-family: 'Tajawal', sans-serif;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }
        
        body {
            background: #f8fafc;
            color: #1e293b;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           LAYOUT
           ═══════════════════════════════════════════════════════════════ */
        .app-container {
            display: flex;
            min-height: 100vh;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           SIDEBAR (Light Version)
           ═══════════════════════════════════════════════════════════════ */
        .sidebar {
            width: 240px;
            background: #0f172a;
            color: white;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        
        .sidebar-header {
            height: 56px;
            display: flex;
            align-items: center;
            padding: 0 16px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            gap: 10px;
        }
        
        .logo-icon {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, #3b82f6, #6366f1);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 14px;
        }
        
        .logo-text {
            font-weight: 700;
            font-size: 16px;
        }
        
        .logo-subtitle {
            font-size: 10px;
            color: #64748b;
            margin-top: -2px;
        }
        
        /* Stats */
        .stats-row {
            display: flex;
            gap: 8px;
            padding: 12px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .stat-box {
            flex: 1;
            background: rgba(255,255,255,0.05);
            border-radius: 8px;
            padding: 10px;
            text-align: center;
        }
        
        .stat-number {
            font-size: 20px;
            font-weight: 800;
        }
        
        .stat-number.pending { color: #60a5fa; }
        .stat-number.ready { color: #34d399; }
        
        .stat-label {
            font-size: 10px;
            color: #64748b;
        }
        
        /* Queue */
        .queue-section {
            flex: 1;
            overflow-y: auto;
            padding: 12px 8px;
        }
        
        .queue-title {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #64748b;
            margin-bottom: 8px;
            padding: 0 8px;
        }
        
        .queue-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 8px;
            color: #94a3b8;
            text-decoration: none;
            transition: all 0.2s;
            margin-bottom: 2px;
        }
        
        .queue-item:hover {
            background: rgba(255,255,255,0.05);
            color: white;
        }
        
        .queue-item.active {
            background: #3b82f6;
            color: white;
        }
        
        .queue-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        
        .queue-dot.orange { background: #f59e0b; }
        .queue-dot.green { background: #34d399; }
        
        .queue-name {
            font-size: 13px;
            font-weight: 600;
        }
        
        /* Sidebar Bottom */
        .sidebar-bottom {
            padding: 8px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 8px;
            color: #94a3b8;
            background: none;
            border: none;
            width: 100%;
            font-family: inherit;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .sidebar-btn:hover {
            background: rgba(255,255,255,0.05);
            color: white;
        }
        
        .sidebar-btn svg {
            width: 18px;
            height: 18px;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           MAIN CONTENT
           ═══════════════════════════════════════════════════════════════ */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
            overflow: hidden;
        }
        
        /* Top Bar */
        .topbar {
            height: 48px;
            background: white;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .topbar-title {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .topbar-title h1 {
            font-size: 15px;
            font-weight: 700;
            color: #1e293b;
        }
        
        .status-badge {
            font-size: 10px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 50px;
            background: #fef3c7;
            color: #d97706;
            border: 1px solid #fde68a;
        }
        
        .topbar-actions {
            display: flex;
            gap: 4px;
        }
        
        .topbar-btn {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            border: none;
            background: none;
            color: #94a3b8;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .topbar-btn:hover {
            background: #f1f5f9;
            color: #3b82f6;
        }
        
        .topbar-btn svg {
            width: 16px;
            height: 16px;
        }
        
        /* Content Area */
        .content-area {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
        }
        
        .content-wrapper {
            max-width: 900px;
            margin: 0 auto;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           DECISION CARD (Light Theme)
           ═══════════════════════════════════════════════════════════════ */
        .decision-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px -4px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
            overflow: hidden;
            margin-bottom: 20px;
            transition: all 0.2s;
        }
        
        .decision-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px -8px rgba(0,0,0,0.15);
        }
        
        .card-header {
            height: 48px;
            background: linear-gradient(90deg, #eff6ff, transparent);
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
        }
        
        .card-header-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            font-size: 13px;
            color: #475569;
        }
        
        .header-icon {
            width: 28px;
            height: 28px;
            background: #3b82f6;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .header-icon svg {
            width: 14px;
            height: 14px;
            color: white;
        }
        
        /* Toggle */
        .propagation-toggle {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            user-select: none;
        }
        
        .toggle-checkbox {
            width: 16px;
            height: 16px;
            accent-color: #3b82f6;
        }
        
        .toggle-label {
            font-size: 12px;
            color: #64748b;
        }
        
        /* Card Body */
        .card-body {
            padding: 24px;
        }
        
        .fields-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 140px;
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .field-group {
            display: flex;
            flex-direction: column;
        }
        
        .field-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #94a3b8;
            margin-bottom: 6px;
        }
        
        .field-input {
            font-family: inherit;
            font-size: 16px;
            font-weight: 700;
            color: #1e293b;
            background: transparent;
            border: none;
            border-bottom: 2px solid #e2e8f0;
            padding: 8px 2px;
            transition: all 0.2s;
        }
        
        .field-input:hover {
            border-color: #93c5fd;
        }
        
        .field-input:focus {
            outline: none;
            border-color: #3b82f6;
        }
        
        /* Chips */
        .chips-row {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 10px;
        }
        
        .chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 6px 12px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            font-family: inherit;
        }
        
        .chip.selected {
            background: #dcfce7;
            color: #16a34a;
            border: 1px solid #86efac;
        }
        
        .chip.candidate {
            background: #f1f5f9;
            color: #64748b;
            border: 1px solid #e2e8f0;
        }
        
        .chip.candidate:hover {
            background: #eff6ff;
            border-color: #93c5fd;
            color: #3b82f6;
        }
        
        .field-hint {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 8px;
            font-size: 10px;
            color: #94a3b8;
        }
        
        .hint-dot {
            width: 6px;
            height: 6px;
            background: #22c55e;
            border-radius: 50%;
        }
        
        /* Amount */
        .amount-display {
            text-align: left;
            padding: 8px 0;
        }
        
        .amount-value {
            font-size: 24px;
            font-weight: 800;
            color: #1e293b;
            font-family: monospace;
        }
        
        .amount-currency {
            font-size: 10px;
            color: #94a3b8;
        }
        
        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            background: #f8fafc;
            padding: 16px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
        }
        
        .info-label {
            font-size: 10px;
            color: #94a3b8;
            margin-bottom: 2px;
        }
        
        .info-value {
            font-weight: 600;
            color: #1e293b;
            font-size: 13px;
        }
        
        .info-value.highlight {
            color: #16a34a;
        }
        
        /* Card Footer */
        .card-footer {
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            padding: 16px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
        }
        
        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: #3b82f6;
            color: white;
            font-weight: 700;
            font-size: 13px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-family: inherit;
            box-shadow: 0 4px 12px -2px rgba(59, 130, 246, 0.4);
            transition: all 0.2s;
        }
        
        .btn-primary:hover {
            background: #2563eb;
            transform: translateY(-1px);
        }
        
        .btn-primary svg {
            width: 16px;
            height: 16px;
        }
        
        .btn-secondary {
            padding: 10px 18px;
            background: white;
            color: #64748b;
            font-weight: 600;
            font-size: 13px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.2s;
        }
        
        .btn-secondary:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }
        
        .preview-toggle {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            background: transparent;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            color: #64748b;
            font-size: 12px;
            font-family: inherit;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .preview-toggle:hover {
            border-color: #93c5fd;
            color: #3b82f6;
        }
        
        .preview-toggle svg {
            width: 14px;
            height: 14px;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           LETTER PREVIEW
           ═══════════════════════════════════════════════════════════════ */
        .preview-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px -4px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
            overflow: hidden;
            margin-bottom: 20px;
        }
        
        .preview-header {
            height: 40px;
            background: #f1f5f9;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
        }
        
        .preview-title {
            font-size: 12px;
            font-weight: 700;
            color: #64748b;
        }
        
        .preview-print {
            font-size: 11px;
            color: #3b82f6;
            font-weight: 600;
            background: none;
            border: none;
            cursor: pointer;
        }
        
        .preview-body {
            padding: 32px;
            background: #f8fafc;
            display: flex;
            justify-content: center;
        }
        
        .letter-paper {
            background: white;
            width: 100%;
            max-width: 500px;
            padding: 40px;
            box-shadow: 0 10px 40px -10px rgba(0,0,0,0.2);
            font-size: 14px;
            line-height: 1.8;
            color: #374151;
        }
        
        .letter-header {
            text-align: center;
            margin-bottom: 24px;
        }
        
        .letter-to {
            font-weight: 700;
            font-size: 15px;
            color: #1e293b;
        }
        
        .letter-greeting { color: #64748b; }
        
        /* ═══════════════════════════════════════════════════════════════
           TIMELINE
           ═══════════════════════════════════════════════════════════════ */
        .timeline-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px -4px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }
        
        .timeline-header {
            height: 48px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
        }
        
        .timeline-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            font-size: 13px;
            color: #475569;
        }
        
        .timeline-icon {
            width: 28px;
            height: 28px;
            background: #94a3b8;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .timeline-icon svg {
            width: 14px;
            height: 14px;
            color: white;
        }
        
        .timeline-count {
            font-size: 11px;
            color: #94a3b8;
        }
        
        .timeline-body {
            padding: 20px;
        }
        
        .timeline-list {
            position: relative;
            padding-right: 32px;
        }
        
        .timeline-line {
            position: absolute;
            right: 10px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e2e8f0;
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 16px;
        }
        
        .timeline-item:last-child { margin-bottom: 0; }
        
        .timeline-dot {
            position: absolute;
            right: -26px;
            top: 6px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 3px solid white;
            box-shadow: 0 0 0 2px #e2e8f0;
        }
        
        .timeline-dot.active {
            background: #3b82f6;
            box-shadow: 0 0 0 2px #3b82f6;
        }
        
        .timeline-dot.past {
            background: #94a3b8;
        }
        
        .event-card {
            background: #f8fafc;
            border-radius: 10px;
            padding: 14px;
            border: 1px solid #e2e8f0;
        }
        
        .event-card.current {
            background: #eff6ff;
            border-color: #bfdbfe;
        }
        
        .event-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 6px;
        }
        
        .event-badge {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 2px 8px;
            border-radius: 50px;
            background: #3b82f6;
            color: white;
            margin-bottom: 4px;
            display: inline-block;
        }
        
        .event-title {
            font-weight: 700;
            font-size: 14px;
            color: #1e293b;
        }
        
        .event-time {
            font-size: 10px;
            color: #94a3b8;
        }
        
        .event-desc {
            font-size: 12px;
            color: #64748b;
        }
        
        [x-cloak] { display: none !important; }
    </style>
</head>
<body x-data="{ showPreview: false, propagateAuto: true }">

    <div class="app-container">
        
        <!-- SIDEBAR -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="logo-icon">B</div>
                <div>
                    <div class="logo-text">BGL</div>
                    <div class="logo-subtitle">إدارة الضمانات</div>
                </div>
            </div>
            
            <div class="stats-row">
                <div class="stat-box">
                    <div class="stat-number pending">3</div>
                    <div class="stat-label">معلق</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number ready">60</div>
                    <div class="stat-label">جاهز</div>
                </div>
            </div>
            
            <nav class="queue-section">
                <div class="queue-title">قائمة الانتظار</div>
                
                <a href="#" class="queue-item active">
                    <div class="queue-dot orange"></div>
                    <span class="queue-name">LG-8821</span>
                </a>
                <a href="#" class="queue-item">
                    <div class="queue-dot orange"></div>
                    <span class="queue-name">LG-8820</span>
                </a>
                <a href="#" class="queue-item">
                    <div class="queue-dot orange"></div>
                    <span class="queue-name">LG-8819</span>
                </a>
                <a href="#" class="queue-item" style="opacity: 0.5;">
                    <div class="queue-dot green"></div>
                    <span class="queue-name">LG-8818</span>
                </a>
            </nav>
            
            <div class="sidebar-bottom">
                <button class="sidebar-btn">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" /></svg>
                    استيراد
                </button>
                <button class="sidebar-btn">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                    الإعدادات
                </button>
            </div>
        </aside>
        
        <!-- MAIN -->
        <main class="main-content">
            <header class="topbar">
                <div class="topbar-title">
                    <h1>ضمان #LG-2024-8821</h1>
                    <span class="status-badge">يحتاج قرار</span>
                </div>
                <div class="topbar-actions">
                    <button class="topbar-btn">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                    </button>
                    <button class="topbar-btn">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
                    </button>
                </div>
            </header>
            
            <div class="content-area">
                <div class="content-wrapper">
                    
                    <!-- DECISION CARD -->
                    <div class="decision-card">
                        <div class="card-header">
                            <div class="card-header-title">
                                <div class="header-icon">
                                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                </div>
                                البيانات الأساسية
                            </div>
                            <label class="propagation-toggle">
                                <input type="checkbox" class="toggle-checkbox" x-model="propagateAuto">
                                <span class="toggle-label">تطبيق على المشابهة</span>
                            </label>
                        </div>
                        
                        <div class="card-body">
                            <div class="fields-grid">
                                <div class="field-group">
                                    <label class="field-label">المورد (المستفيد)</label>
                                    <input type="text" class="field-input" value="شركة المقاولات المتحدة">
                                    <div class="chips-row">
                                        <button class="chip selected">✓ المختار</button>
                                        <button class="chip candidate">⭐⭐ المقاولات التجارية</button>
                                        <button class="chip candidate">⭐ مؤسسة المتحدة</button>
                                    </div>
                                    <div class="field-hint">
                                        <div class="hint-dot"></div>
                                        من Excel: "UNITED CONTRACTORS CO"
                                    </div>
                                </div>
                                
                                <div class="field-group">
                                    <label class="field-label">البنك المصدر</label>
                                    <select class="field-input">
                                        <option selected>البنك الأهلي SNB</option>
                                        <option>مصرف الراجحي</option>
                                        <option>بنك الرياض</option>
                                    </select>
                                </div>
                                
                                <div class="field-group">
                                    <label class="field-label">المبلغ</label>
                                    <div class="amount-display">
                                        <div class="amount-value" dir="ltr">1.5M</div>
                                        <div class="amount-currency">SAR</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="info-grid">
                                <div class="info-item">
                                    <span class="info-label">رقم العقد</span>
                                    <span class="info-value">CON-2024-1234</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">تاريخ الانتهاء</span>
                                    <span class="info-value">2025-12-30</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">النوع</span>
                                    <span class="info-value">نهائي</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">التمديد المقترح</span>
                                    <span class="info-value highlight">2026-12-30</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card-footer">
                            <div class="action-buttons">
                                <button class="btn-primary">
                                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    اعتماد وإصدار تمديد
                                </button>
                                <button class="btn-secondary">إفراج</button>
                            </div>
                            <button class="preview-toggle" @click="showPreview = !showPreview">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                <span x-text="showPreview ? 'إخفاء المعاينة' : 'معاينة الخطاب'"></span>
                            </button>
                        </div>
                    </div>
                    
                    <!-- PREVIEW -->
                    <div class="preview-card" x-show="showPreview" x-transition x-cloak>
                        <div class="preview-header">
                            <span class="preview-title">معاينة خطاب التمديد</span>
                            <button class="preview-print">🖨️ طباعة</button>
                        </div>
                        <div class="preview-body">
                            <div class="letter-paper">
                                <div class="letter-header">
                                    <div class="letter-to">السادة / البنك الأهلي السعودي</div>
                                    <div class="letter-greeting">المحترمين</div>
                                </div>
                                <p><strong>الموضوع:</strong> طلب تمديد الضمان البنكي رقم (LG-2024-8821)</p>
                                <p>إشارة إلى الضمان البنكي النهائي الموضح أعلاه...</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- TIMELINE -->
                    <div class="timeline-card">
                        <div class="timeline-header">
                            <div class="timeline-title">
                                <div class="timeline-icon">
                                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                </div>
                                سجل العمليات
                            </div>
                            <span class="timeline-count">4 أحداث</span>
                        </div>
                        
                        <div class="timeline-body">
                            <div class="timeline-list">
                                <div class="timeline-line"></div>
                                
                                <div class="timeline-item">
                                    <div class="timeline-dot active"></div>
                                    <div class="event-card current">
                                        <div class="event-header">
                                            <div>
                                                <span class="event-badge">الآن</span>
                                                <h3 class="event-title">يحتاج قرار - تمديد</h3>
                                            </div>
                                            <span class="event-time">اليوم 10:42 ص</span>
                                        </div>
                                        <p class="event-desc">تم استيراد الضمان ويحتاج اتخاذ قرار.</p>
                                    </div>
                                </div>
                                
                                <div class="timeline-item">
                                    <div class="timeline-dot past"></div>
                                    <div class="event-card">
                                        <div class="event-header">
                                            <h3 class="event-title">تمديد سابق</h3>
                                            <span class="event-time">01/06/2024</span>
                                        </div>
                                        <p class="event-desc">تم التمديد لمدة سنة</p>
                                    </div>
                                </div>
                                
                                <div class="timeline-item">
                                    <div class="timeline-dot past"></div>
                                    <div class="event-card">
                                        <div class="event-header">
                                            <h3 class="event-title">إصدار أولي</h3>
                                            <span class="event-time">01/12/2023</span>
                                        </div>
                                        <p class="event-desc">تم إصدار الضمان</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
        </main>
    </div>

</body>
</html>
