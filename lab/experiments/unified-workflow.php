<?php
/**
 * Experiment: Unified Workflow - Final Version
 * =============================================
 * 
 * دمج:
 * - التوزيع الثلاثي من integrated-view (التايم لاين على اليسار)
 * - التصميم الفاتح البسيط من unified-workflow-light
 * 
 * النتيجة: تصميم نظيف وعملي مع رؤية شاملة
 */
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unified Workflow - DesignLab</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        /* ═══════════════════════════════════════════════════════════════
           RESET & BASE
           ═══════════════════════════════════════════════════════════════ */
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
           TOP BAR (Global Actions) - من improved-current
           ═══════════════════════════════════════════════════════════════ */
        .top-bar {
            height: 56px;
            background: white;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            flex-shrink: 0;
        }
        
        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 800;
            font-size: 18px;
            color: #1e293b;
        }
        
        .brand-icon {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
        }
        
        .global-actions {
            display: flex;
            gap: 8px;
        }
        
        .btn-global {
            padding: 8px 16px;
            background: transparent;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }
        
        .btn-global:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #1e293b;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           ACTION BAR (Bottom) - من improved-current
           ═══════════════════════════════════════════════════════════════ */
        .action-bar {
            height: 72px;
            background: white;
            border-top: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
            box-shadow: 0 -1px 3px rgba(0,0,0,0.05);
            flex-shrink: 0;
        }
        
        .action-primary {
            display: flex;
            gap: 12px;
        }
        
        .btn-primary-action {
            padding: 12px 24px;
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 8px;
            font-family: inherit;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
        }
        
        .btn-primary-action:hover {
            background: #2563eb;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
        }
        
        .btn-secondary-action {
            padding: 12px 20px;
            background: white;
            color: #64748b;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-family: inherit;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-secondary-action:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #1e293b;
        }
        
        .action-more {
            position: relative;
        }
        
        .btn-more {
            padding: 12px 20px;
            background: transparent;
            color: #64748b;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-family: inherit;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-more:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           THREE-COLUMN LAYOUT
           ═══════════════════════════════════════════════════════════════ */
        body {
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
        }
        
        .app-container {
            display: flex;
            flex: 1;
            overflow: hidden;
        }
        
        /* Right Section: Timeline + Main Content */
        .right-section {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .columns-wrapper {
            display: flex;
            flex: 1;
            overflow: hidden;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           SIDEBAR (Left) - المستندات والملاحظات
           ═══════════════════════════════════════════════════════════════ */
        .sidebar {
            width: 290px;
            background: white;
            border-right: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        
        .progress-container {
            height: 48px;
            width: 100%;
            background: white;
            padding: 0 16px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 6px;
        }
        
        .progress-bar {
            height: 6px;
            background: #e2e8f0;
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 8px;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6);
            transition: width 0.3s ease;
            border-radius: 3px;
        }
        
        .progress-text {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            color: #64748b;
        }
        
        .progress-percent {
            font-weight: 700;
            color: #3b82f6;
        }
        
        .sidebar-body {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            background: #f8fafc;
        }
        
        .sidebar-section {
            margin-bottom: 16px;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
        }
        
        .sidebar-section:last-child {
            margin-bottom: 0;
        }
        
        .sidebar-section-title {
            font-size: 12px;
            font-weight: 700;
            color: #64748b;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
            padding-bottom: 8px;
            border-bottom: 1px solid #f1f5f9;
        }
        
        /* Attachments */
        .attachments-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        
        .attachment-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px;
            background: #fafbfc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s;
        }
        
        .attachment-item:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }
        
        .attachment-icon {
            width: 32px;
            height: 32px;
            background: #f1f5f9;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }
        
        .attachment-info {
            flex: 1;
            min-width: 0;
        }
        
        .attachment-name {
            font-size: 11px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .attachment-meta {
            font-size: 10px;
            color: #94a3b8;
        }
        
        /* Notes */
        .notes-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        
        .note-item {
            padding: 10px;
            background: #fafbfc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            border-left: 2px solid #cbd5e1;
        }
        
        .note-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
        }
        
        .note-author {
            font-size: 11px;
            font-weight: 600;
            color: #475569;
        }
        
        .note-time {
            font-size: 9px;
            color: #94a3b8;
        }
        
        .note-content {
            font-size: 10px;
            color: #64748b;
            line-height: 1.5;
        }
        
        .add-note-btn {
            width: 100%;
            padding: 8px;
            background: white;
            border: 1px dashed #cbd5e1;
            border-radius: 6px;
            color: #64748b;
            font-family: inherit;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
            margin-top: 6px;
        }
        
        .add-note-btn:hover {
            border-color: #94a3b8;
            color: #475569;
            background: #fafbfc;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           MAIN CONTENT (Center)
           ═══════════════════════════════════════════════════════════════ */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
            overflow: hidden;
        }
        
        /* Top Bar (Full Width Above All) */
        .topbar {
            height: 48px;
            background: white;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            flex-shrink: 0;
        }
        
        .topbar-title { display: flex; align-items: center; gap: 10px; }
        .topbar-title h1 { font-size: 15px; font-weight: 700; color: #1e293b; }
        
        .status-badge {
            font-size: 10px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 50px;
            background: #fef3c7;
            color: #d97706;
            border: 1px solid #fde68a;
        }
        
        .topbar-actions { display: flex; gap: 8px; }
        
        .topbar-btn {
            padding: 6px 14px;
            border-radius: 8px;
            font-family: inherit;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .topbar-btn.secondary { background: white; border: 1px solid #e2e8f0; color: #64748b; }
        .topbar-btn.secondary:hover { background: #f8fafc; border-color: #cbd5e1; }
        .topbar-btn.primary { background: #3b82f6; border: none; color: white; }
        .topbar-btn.primary:hover { background: #2563eb; }
        
        /* Content Area */
        .content-area {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           DECISION CARD
           ═══════════════════════════════════════════════════════════════ */
        .decision-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }
        
        .card-header {
            height: 44px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
        }
        
        .card-header-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 700;
            font-size: 13px;
            color: #475569;
        }
        
        .header-icon {
            width: 24px;
            height: 24px;
            background: #3b82f6;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .header-icon svg { width: 12px; height: 12px; color: white; }
        
        /* Toggle */
        .propagation-toggle {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            user-select: none;
        }
        
        .toggle-checkbox { width: 14px; height: 14px; accent-color: #3b82f6; }
        .toggle-label { font-size: 11px; color: #64748b; }
        
        /* Card */
        .card-body {
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        /* Field Group */
        .field-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .field-label {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 0;
        }
        
        .field-input {
            padding: 10px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-family: inherit;
            font-size: 13px;
            color: #1e293b;
            background: white;
            transition: all 0.2s;
        }
        
        .field-input:hover { border-color: #93c5fd; }
        .field-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        /* Chips Row */
        .chips-row {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 4px;
        }
        
        .chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 5px 10px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            font-family: inherit;
        }
        
        .chip.selected { background: #dcfce7; color: #16a34a; border: 1px solid #86efac; }
        .chip.candidate { background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; }
        .chip.candidate:hover { background: #eff6ff; border-color: #93c5fd; color: #3b82f6; }
        
        .field-hint {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 6px;
            font-size: 10px;
            color: #94a3b8;
        }
        
        .hint-dot { width: 6px; height: 6px; background: #22c55e; border-radius: 50%; }
        
        /* Amount */
        .amount-display { text-align: left; padding: 6px 0; }
        .amount-value { font-size: 22px; font-weight: 800; color: #1e293b; font-family: monospace; }
        .amount-currency { font-size: 10px; color: #94a3b8; }
        
        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            padding: 16px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .info-label {
            font-size: 11px;
            font-weight: 600;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .info-value {
            font-size: 13px;
            font-weight: 600;
            color: #1e293b;
        }
        
        .info-value.highlight { color: #16a34a; }
        
        /* Card Footer */
        .card-footer {
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            padding: 14px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .action-buttons { display: flex; gap: 10px; }
        
        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 18px;
            background: #3b82f6;
            color: white;
            font-weight: 700;
            font-size: 13px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-family: inherit;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
            transition: all 0.2s;
        }
        
        .btn-primary:hover { background: #2563eb; transform: translateY(-1px); }
        .btn-primary svg { width: 14px; height: 14px; }
        
        .btn-secondary {
            padding: 10px 16px;
            background: white;
            color: #64748b;
            font-weight: 600;
            font-size: 13px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.2s;
        }
        
        .btn-secondary:hover { background: #f8fafc; border-color: #cbd5e1; }
        
        .preview-toggle {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            background: transparent;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            color: #64748b;
            font-size: 11px;
            font-family: inherit;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .preview-toggle:hover { border-color: #93c5fd; color: #3b82f6; }
        .preview-toggle svg { width: 14px; height: 14px; }
        
        /* Preview */
        .preview-section {
            margin-top: 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }
        
        .preview-header {
            height: 36px;
            background: #f1f5f9;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 14px;
        }
        
        .preview-title { font-size: 11px; font-weight: 700; color: #64748b; }
        .preview-print { font-size: 11px; color: #3b82f6; font-weight: 600; background: none; border: none; cursor: pointer; }
        
        .preview-body {
            padding: 24px;
            background: #f8fafc;
            display: flex;
            justify-content: center;
        }
        
        .letter-paper {
            background: white;
            width: 100%;
            max-width: 480px;
            padding: 32px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            font-size: 13px;
            line-height: 1.8;
            color: #374151;
        }
        
        .letter-header { text-align: center; margin-bottom: 20px; }
        .letter-to { font-weight: 700; font-size: 14px; color: #1e293b; }
        .letter-greeting { color: #64748b; font-size: 13px; }
        
        /* ═══════════════════════════════════════════════════════════════
           TIMELINE PANEL (Right)
           ═══════════════════════════════════════════════════════════════ */
        .timeline-panel {
            width: 360px;
            background: white;
            border-left: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        
        .timeline-header {
            height: 48px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            background: #f8fafc;
        }
        
        .timeline-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 700;
            font-size: 13px;
            color: #475569;
        }
        
        .timeline-icon {
            width: 24px;
            height: 24px;
            background: #64748b;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .timeline-icon svg { width: 12px; height: 12px; color: white; }
        
        .timeline-count { font-size: 10px; color: #94a3b8; }
        
        .timeline-body {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
        }
        
        .timeline-list {
            position: relative;
            padding-right: 24px;
        }
        
        .timeline-line {
            position: absolute;
            right: 8px;
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
            right: -20px;
            top: 4px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            border: 2px solid white;
            box-shadow: 0 0 0 2px #e2e8f0;
        }
        
        .timeline-dot.active { background: #3b82f6; box-shadow: 0 0 0 2px #3b82f6; }
        .timeline-dot.past { background: #94a3b8; }
        
        .event-card {
            background: #f8fafc;
            border-radius: 8px;
            padding: 12px;
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
            margin-bottom: 4px;
        }
        
        .event-badge {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 2px 6px;
            border-radius: 50px;
            background: #3b82f6;
            color: white;
            margin-bottom: 4px;
            display: inline-block;
        }
        
        .event-title { font-weight: 700; font-size: 12px; color: #1e293b; }
        .event-time { font-size: 10px; color: #94a3b8; }
        .event-desc { font-size: 11px; color: #64748b; margin-top: 4px; }
        
        [x-cloak] { display: none !important; }
    </style>
</head>
<body x-data="{ showPreview: false, propagateAuto: true }">

    <!-- ══════════════════════════════════════════════════════════════ -->
    <!-- TOP BAR (Global) - من improved-current -->
    <!-- ══════════════════════════════════════════════════════════════ -->
    <div class="top-bar">
        <div class="brand">
            <div class="brand-icon">B</div>
            <span>نظام إدارة خطابات الضمان</span>
        </div>
        <div class="global-actions">
            <button class="btn-global">⚙️ الإعدادات</button>
            <button class="btn-global">📊 الإحصائيات</button>
            <a href="/lab" class="btn-global">🔬 المختبر</a>
        </div>
    </div>

    <div class="app-container">
        
        <!-- ══════════════════════════════════════════════════════════════ -->
        <!-- RIGHT SECTION (Timeline + Main Content) -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <div class="right-section">
            <!-- TOPBAR (Record Header) - فوق Timeline و Main فقط -->
            <header class="topbar">
                <div class="topbar-title">
                    <h1>ضمان #LG-2024-8821</h1>
                    <span class="status-badge">يحتاج قرار</span>
                </div>
                <div class="topbar-actions">
                    <button class="topbar-btn secondary">تعليق</button>
                    <button class="topbar-btn primary">حفظ</button>
                </div>
            </header>
            
            <div class="columns-wrapper">
                <!-- TIMELINE PANEL (Right) -->
                <aside class="timeline-panel">
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
                                            <h3 class="event-title">يحتاج قرار</h3>
                                        </div>
                                    </div>
                                    <span class="event-time">اليوم 10:42 ص</span>
                                    <p class="event-desc">تم استيراد الضمان ويحتاج قرار.</p>
                                </div>
                            </div>
                            
                            <div class="timeline-item">
                                <div class="timeline-dot past"></div>
                                <div class="event-card">
                                    <div class="event-header">
                                        <h3 class="event-title">تمديد سابق</h3>
                                    </div>
                                    <span class="event-time">01/06/2024</span>
                                    <p class="event-desc">تم التمديد لمدة سنة</p>
                                </div>
                            </div>
                            
                            <div class="timeline-item">
                                <div class="timeline-dot past"></div>
                                <div class="event-card">
                                    <div class="event-header">
                                        <h3 class="event-title">تمديد</h3>
                                    </div>
                                    <span class="event-time">01/06/2023</span>
                                    <p class="event-desc">تم التمديد لمدة سنة</p>
                                </div>
                            </div>
                            
                            <div class="timeline-item">
                                <div class="timeline-dot past"></div>
                                <div class="event-card">
                                    <div class="event-header">
                                        <h3 class="event-title">إصدار أولي</h3>
                                    </div>
                                    <span class="event-time">01/12/2022</span>
                                    <p class="event-desc">تم إصدار الضمان</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </aside>
                
                <!-- MAIN CONTENT (Center) -->
                <main class="main-content">
                    
                    <div class="content-area">
                <!-- Decision Card -->
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
                        <!-- Supplier Field -->
                        <div class="field-group">
                            <label class="field-label">المورد (المستفيد)</label>
                            <input type="text" id="supplierInput" class="field-input" value="شركة المقاولات المتحدة" placeholder="ابحث عن المورد...">
                            
                            <div class="chips-row">
                                <!-- Selected -->
                                <button class="chip chip-selected">
                                    ✓ المختار
                                </button>
                                
                                <!-- Learned (Used before) -->
                                <button class="chip chip-learned">
                                    <span class="chip-stars">⭐⭐⭐</span>
                                    <span>شركة المراعي</span>
                                    <span class="chip-source">استخدمته 15 مرة</span>
                                </button>
                                
                                <!-- From Excel -->
                                <button class="chip chip-excel">
                                    <span>المقاولات التجارية</span>
                                    <span class="chip-source">من Excel: 85%</span>
                                </button>
                                
                                <!-- Candidate -->
                                <button class="chip chip-candidate">
                                    <span>شركة البناء الحديث</span>
                                    <span class="chip-source">مطابقة: 72%</span>
                                </button>
                            </div>
                            
                            <div class="field-hint">
                                <div class="hint-dot"></div>
                                من Excel: "UNITED CONTRACTORS CO"
                            </div>
                        </div>
                        
                        <!-- Bank Field -->
                        <div class="field-group">
                            <label class="field-label">البنك المصدر</label>
                            <select id="bankInput" class="field-input">
                                <option selected>البنك الأهلي السعودي (SNB)</option>
                                <option>مصرف الراجحي</option>
                                <option>بنك الرياض</option>
                                <option>البنك السعودي الفرنسي</option>
                            </select>
                            
                            <div class="chips-row">
                                <button class="chip chip-selected">
                                    ✓ البنك الأهلي
                                </button>
                                <button class="chip chip-excel">
                                    <span>الراجحي</span>
                                    <span class="chip-source">من Excel: "Al Rajhi"</span>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Additional Info Grid -->
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">المبلغ</span>
                                <span id="amountValue" class="info-value">50,000.00 ر.س</span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">رقم العقد</span>
                                <span class="info-value">CON-2024-1234</span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">تاريخ الانتهاء</span>
                                <span class="info-value">2025-12-30</span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">نوع الضمان</span>
                                <span class="info-value">نهائي</span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">تاريخ الإصدار</span>
                                <span class="info-value">2024-01-15</span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">الحالة</span>
                                <span class="info-value">ساري</span>
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
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <span x-text="showPreview ? 'إخفاء' : 'معاينة'"></span>
                        </button>
                    </div>
                </div>
                
                <!-- Preview -->
                <div class="preview-section" x-show="showPreview" x-transition x-cloak>
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
                            <p style="margin-top: 12px;">إشارة إلى الضمان البنكي النهائي الموضح أعلاه...</p>
                        </div>
                    </div>
                    </div>
                </main>
            </div> <!-- /columns-wrapper -->
        </div> <!-- /right-section -->
        
        <!-- ══════════════════════════════════════════════════════════════ -->
        <!-- SIDEBAR (Left) - المستندات والملاحظات -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <aside class="sidebar">
            <div class="progress-container">
                <div class="progress-bar">
                    <div class="progress-fill" style="width: 2%;"></div>
                </div>
                <div class="progress-text">
                    <span>سجل 1 من 63</span>
                    <span class="progress-percent">2%</span>
                </div>
            </div>
            
            <div class="sidebar-body">
                <!-- المستندات -->
                <div class="sidebar-section">
                    <h3 class="sidebar-section-title">📎 المستندات المرفقة</h3>
                    <div class="attachments-list">
                        <div class="attachment-item">
                            <div class="attachment-icon">📄</div>
                            <div class="attachment-info">
                                <div class="attachment-name">الضمان الأصلي.pdf</div>
                                <div class="attachment-meta">2.3 MB • 15 ديسمبر 2024</div>
                            </div>
                        </div>
                        
                        <div class="attachment-item">
                            <div class="attachment-icon">📧</div>
                            <div class="attachment-info">
                                <div class="attachment-name">طلب التمديد من البنك.eml</div>
                                <div class="attachment-meta">156 KB • 20 ديسمبر 2024</div>
                            </div>
                        </div>
                        
                        <div class="attachment-item">
                            <div class="attachment-icon">📑</div>
                            <div class="attachment-info">
                                <div class="attachment-name">العقد الأساسي.pdf</div>
                                <div class="attachment-meta">4.1 MB • 10 يناير 2024</div>
                            </div>
                        </div>
                        
                        <button class="add-note-btn">
                            ➕ إضافة مستند جديد
                        </button>
                    </div>
                </div>

                <!-- الملاحظات -->
                <div class="sidebar-section">
                    <h3 class="sidebar-section-title">📝 الملاحظات والتعليقات</h3>
                    <div class="notes-list">
                        <div class="note-item">
                            <div class="note-header">
                                <span class="note-author">أحمد المالكي</span>
                                <span class="note-time">منذ ساعتين</span>
                            </div>
                            <div class="note-content">
                                تم التواصل مع البنك للتأكد من إمكانية التمديد. البنك أكد الموافقة المبدئية.
                            </div>
                        </div>
                        
                        <div class="note-item">
                            <div class="note-header">
                                <span class="note-author">فاطمة السعيد</span>
                                <span class="note-time">أمس</span>
                            </div>
                            <div class="note-content">
                                المورد طلب تمديد الضمان لمدة سنة إضافية. تم إرسال الطلب للبنك.
                            </div>
                        </div>
                        
                        <button class="add-note-btn">
                            ➕ إضافة ملاحظة جديدة
                        </button>
                    </div>
                </div>
            </div>
        </aside>
        
    </div>
    
    <!-- ══════════════════════════════════════════════════════════════ -->
    <!-- ACTION BAR (Bottom) - من improved-current -->
    <!-- ══════════════════════════════════════════════════════════════ -->
    <div class="action-bar">
        <div class="action-more">
            <button class="btn-more">
                المزيد ▼
            </button>
        </div>
        <div class="action-primary">
            <button class="btn-secondary-action">⬅️ السابق</button>
            <button class="btn-primary-action">
                💾 حفظ وانتقل للتالي
                ➡️
            </button>
        </div>
    </div>

</body>
</html>
