<?php
/**
 * Experiment: Ultimate Unified Interface - Enhanced
 * ==================================================
 * 
 * النسخة المحسّنة التي تجمع أفضل الميزات من:
 * - experiment-ultimate.php (التصميم الأساسي)
 * - unified-workflow.php (Header مع الأزرار في الوسط)
 * - improved-current.php (Footer + المستندات والملاحظات)
 * 
 * التحسينات:
 * 1. Header من unified-workflow (عنوان + حالة + أزرار في الوسط)
 * 2. Footer من improved-current (أزرار التنقل)
 * 3. أقسام المستندات والملاحظات في المنطقة الثالثة
 * 4. الحفاظ على "البيانات الأساسية" في الوسط
 * 5. Timeline تفاعلي من improved-current
 */

$EXPERIMENT_NAME = 'Ultimate Unified Interface - Enhanced';
$currentRecord = 1;
$totalRecords = 63;
$progressPercent = round(($currentRecord / $totalRecords) * 100, 1);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $EXPERIMENT_NAME ?> - DesignLab</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
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
        
        :root {
            /* Colors - Light Mode */
            --color-primary: #3b82f6;
            --color-primary-dark: #2563eb;
            --color-primary-light: #eff6ff;
            --color-bg: #f8fafc;
            --color-surface: #ffffff;
            --color-border: #e2e8f0;
            --color-text: #1e293b;
            --color-text-muted: #64748b;
            --color-success: #22c55e;
            --color-warning: #f59e0b;
            --color-error: #ef4444;
            
            /* Spacing */
            --space-xs: 8px;
            --space-sm: 12px;
            --space-md: 16px;
            --space-lg: 24px;
            --space-xl: 32px;
        }
        
        body {
            font-family: 'Tajawal', sans-serif;
            background: var(--color-bg);
            color: var(--color-text);
            height: 100vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           TOP BAR (من improved-current)
           ═══════════════════════════════════════════════════════════════ */
        .top-bar {
            height: 56px;
            background: var(--color-surface);
            border-bottom: 1px solid var(--color-border);
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
            color: var(--color-text);
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
            border: 1px solid var(--color-border);
            border-radius: 8px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            color: var(--color-text-muted);
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .btn-global:hover {
            background: var(--color-bg);
            border-color: #cbd5e1;
            color: var(--color-text);
        }
        
        /* Main Grid Container */
        .main-container {
            flex: 1;
            display: grid;
            grid-template-columns: 320px 1fr 420px;
            overflow: hidden;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           CONTEXT BAR (دمج topbar + context-bar)
           ═══════════════════════════════════════════════════════════════ */
        .context-bar {
            height: 64px;
            background: var(--color-surface);
            border-bottom: 1px solid var(--color-border);
            padding: 0 var(--space-lg);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .context-info {
            display: flex;
            align-items: center;
            gap: var(--space-md);
        }
        
        .context-title {
            font-size: 15px;
            font-weight: 700;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           TOPBAR (من unified-workflow) - Header
           ═══════════════════════════════════════════════════════════════ */
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
            gap: 8px; 
        }
        
        .topbar-btn {
            padding: 6px 14px;
            border-radius: 8px;
            font-family: inherit;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .topbar-btn.secondary { 
            background: white; 
            border: 1px solid #e2e8f0; 
            color: #64748b; 
        }
        
        .topbar-btn.secondary:hover { 
            background: #f8fafc; 
            border-color: #cbd5e1; 
        }
        
        .topbar-btn.primary { 
            background: #3b82f6; 
            border: none; 
            color: white; 
        }
        
        .topbar-btn.primary:hover { 
            background: #2563eb; 
        }
        
        
        /* ═══════════════════════════════════════════════════════════════
           TIMELINE PANEL (Right - Arabic First)
           ═══════════════════════════════════════════════════════════════ */
        .timeline-panel {
            background: var(--color-surface);
            border-left: 1px solid var(--color-border);
            border-radius: 0;
            box-shadow: none;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .timeline-header {
            height: 48px;
            background: var(--color-surface);
            border-bottom: 1px solid var(--color-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 var(--space-md);
        }
        
        .timeline-title {
            font-size: 13px;
            font-weight: 700;
            color: #475569;
            display: flex;
            align-items: center;
            gap: var(--space-xs);
        }
        
        .timeline-count {
            font-size: 11px;
            color: #94a3b8;
            background: #f1f5f9;
            padding: 3px 8px;
            border-radius: 6px;
        }
        
        .timeline-body {
            flex: 1;
            overflow-y: auto;
            padding: var(--space-md);
        }
        
        .timeline-list {
            position: relative;
            padding-right: 20px;
        }
        
        .timeline-line {
            position: absolute;
            right: 7px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--color-border);
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 20px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .timeline-item:hover .event-card {
            border-color: #93c5fd;
        }
        
        .timeline-dot {
            position: absolute;
            right: -17px;
            top: 6px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 3px solid var(--color-bg);
            background: #94a3b8;
            box-shadow: 0 0 0 1px var(--color-border);
        }
        
        .timeline-dot.active {
            background: var(--color-primary);
            box-shadow: 0 0 0 1px var(--color-primary), 0 0 0 4px rgba(59, 130, 246, 0.2);
        }
        
        .timeline-dot.success {
            background: var(--color-success);
            box-shadow: 0 0 0 1px var(--color-success);
        }
        
        .event-card {
            background: var(--color-surface);
            border-radius: 0;
            padding: 14px;
            border: 1px solid var(--color-border);
            box-shadow: none;
            transition: all 0.2s;
        }
        
        .event-card.current {
            background: var(--color-primary-light);
            border-color: #93c5fd;
        }
        
        .event-badge {
            display: inline-block;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 3px 8px;
            border-radius: 12px;
            background: var(--color-primary);
            color: white;
            margin-bottom: 6px;
        }
        
        .event-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--color-text);
            margin-bottom: 4px;
        }
        
        .event-time {
            font-size: 10px;
            color: #94a3b8;
            margin-bottom: 6px;
        }
        
        .event-desc {
            font-size: 11px;
            color: var(--color-text-muted);
            line-height: 1.5;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           DECISION PANEL (Center)
           ═══════════════════════════════════════════════════════════════ */
        .decision-panel {
            background: var(--color-surface);
            border-top: 1px solid var(--color-border);
            border-bottom: 1px solid var(--color-border);
            border-radius: 0;
            box-shadow: none;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .decision-body {
            flex: 1;
            overflow-y: auto;
            padding: var(--space-lg);
            padding-bottom: 0;
        }
        
        .form-section {
            background: white;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .section-title {
            font-size: 13px;
            font-weight: 700;
            color: #475569;
            margin-bottom: var(--space-md);
            display: flex;
            align-items: center;
            gap: var(--space-xs);
        }
        
        .field-group {
            margin-bottom: 20px;
        }
        
        .field-group:last-child {
            margin-bottom: 0;
        }
        
        .field-label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #94a3b8;
            margin-bottom: var(--space-xs);
        }
        
        .field-input {
            width: 100%;
            font-family: inherit;
            font-size: 15px;
            font-weight: 600;
            color: var(--color-text);
            background: transparent;
            border: none;
            border-bottom: 2px solid var(--color-border);
            padding: 8px 4px;
            transition: all 0.2s;
        }
        
        .field-input:hover {
            border-color: #93c5fd;
        }
        
        .field-input:focus {
            outline: none;
            border-color: var(--color-primary);
        }
        
        /* Chips with Source */
        .chips-row {
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-xs);
            margin-top: 10px;
        }
        
        .chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            border: 1.5px solid;
            font-family: inherit;
            background: var(--color-surface);
        }
        
        .chip-selected {
            background: #dcfce7;
            color: #16a34a;
            border-color: #86efac;
        }
        
        .chip-learned {
            background: #fef3c7;
            color: #d97706;
            border-color: #fde68a;
        }
        
        .chip-excel {
            background: #dbeafe;
            color: #2563eb;
            border-color: #93c5fd;
        }
        
        .chip-candidate {
            background: var(--color-bg);
            color: var(--color-text-muted);
            border-color: var(--color-border);
        }
        
        .chip-candidate:hover {
            background: var(--color-primary-light);
            border-color: #93c5fd;
            color: var(--color-primary);
        }
        
        .chip-source {
            font-size: 9px;
            padding: 2px 6px;
            background: rgba(0,0,0,0.08);
            border-radius: 6px;
            margin-right: 4px;
        }
        
        .chip-stars {
            color: var(--color-warning);
        }
        
        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: var(--space-md);
            background: var(--color-bg);
            padding: var(--space-md);
            border-radius: 0;
            border: 1px solid var(--color-border);
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
        }
        
        .info-label {
            font-size: 10px;
            color: #94a3b8;
            margin-bottom: 4px;
            font-weight: 600;
        }
        
        .info-value {
            font-size: 13px;
            font-weight: 700;
            color: var(--color-text);
        }
        
        /* ═══════════════════════════════════════════════════════════════
           FOOTER (Bottom - من improved-current) - Full Width
           ═══════════════════════════════════════════════════════════════ */
        .footer {
            height: 72px;
            background: var(--color-surface);
            border-top: 1px solid var(--color-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 var(--space-lg);
            box-shadow: 0 -1px 3px rgba(0,0,0,0.05);
            flex-shrink: 0;
        }
        
        .action-primary {
            display: flex;
            gap: 12px;
        }
        
        .btn-primary {
            padding: 12px 24px;
            background: var(--color-primary);
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
        
        .btn-primary:hover {
            background: var(--color-primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
        }
        
        .btn-secondary {
            padding: 12px 20px;
            background: var(--color-surface);
            color: var(--color-text-muted);
            border: 1px solid var(--color-border);
            border-radius: 8px;
            font-family: inherit;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-secondary:hover {
            background: var(--color-bg);
            border-color: #cbd5e1;
            color: var(--color-text);
        }
        
        .preview-toggle {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            background: transparent;
            border: 1px solid var(--color-border);
            border-radius: 6px;
            color: var(--color-text-muted);
            font-size: 11px;
            font-family: inherit;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .preview-toggle:hover {
            border-color: #93c5fd;
            color: var(--color-primary);
        }
        
        /* ═══════════════════════════════════════════════════════════════
           SIDEBAR PANEL (Left) - المستندات والملاحظات
           ═══════════════════════════════════════════════════════════════ */
        .sidebar-panel {
            background: #f1f5f9;
            border-right: 1px solid var(--color-border);
            border-radius: 0;
            box-shadow: none;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .sidebar-header {
            background: var(--color-surface);
            border-bottom: 1px solid var(--color-border);
            padding: var(--space-md);
        }
        
        .progress-container {
            width: 100%;
        }
        
        .progress-bar {
            height: 6px;
            background: var(--color-border);
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 6px;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--color-primary), #8b5cf6);
            transition: width 0.3s ease;
            border-radius: 3px;
        }
        
        .progress-text {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            color: var(--color-text-muted);
        }
        
        .progress-percent {
            font-weight: 700;
            color: var(--color-primary);
        }
        
        .sidebar-body {
            flex: 1;
            overflow-y: auto;
            padding: var(--space-lg);
        }
        
        .sidebar-section {
            margin-bottom: var(--space-lg);
            background: white;
            border: 1px solid var(--color-border);
            border-radius: 8px;
            padding: var(--space-md);
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .sidebar-section:last-child {
            margin-bottom: 0;
        }
        
        .sidebar-section-title {
            font-size: 13px;
            font-weight: 700;
            color: #475569;
            margin-bottom: var(--space-md);
            display: flex;
            align-items: center;
            gap: var(--space-xs);
            padding-bottom: var(--space-sm);
            border-bottom: 1px solid var(--color-border);
        }
        
        /* ═══════════════════════════════════════════════════════════════
           PREVIEW SECTION (من unified-workflow) - Inline
           ═══════════════════════════════════════════════════════════════ */
        .preview-section {
            margin-top: 20px;
            background: white;
            border-radius: 0;
            box-shadow: none;
            border: 1px solid var(--color-border);
            overflow: hidden;
        }
        
        .preview-header {
            height: 36px;
            background: #f1f5f9;
            border-bottom: 1px solid var(--color-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 14px;
        }
        
        .preview-title {
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
        }
        
        .preview-print {
            font-size: 11px;
            color: var(--color-primary);
            font-weight: 600;
            background: none;
            border: none;
            cursor: pointer;
            font-family: inherit;
        }
        
        .preview-body {
            padding: 24px;
            background: #f8fafc;
            display: flex;
            justify-content: center;
        }
        
        /* Letter Preview */
        .letter-preview-wrapper {
            display: flex;
            justify-content: center;
        }
        
        .letter-paper {
            width: 210mm;
            min-height: 297mm;
            transform: scale(0.65);
            transform-origin: top center;
            background: white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            padding: 1in;
            font-size: 12pt;
            line-height: 1.6;
            color: #1e293b;
            font-family: 'Tajawal', serif;
        }
        
        .letter-header {
            text-align: center;
            font-weight: 700;
            font-size: 14pt;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 2px solid #1e293b;
        }
        
        /* Attachments Section */
        .attachments-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .attachment-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: white;
            border: 1px solid var(--color-border);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .attachment-item:hover {
            border-color: var(--color-primary);
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.1);
        }
        
        .attachment-icon {
            width: 40px;
            height: 40px;
            background: var(--color-primary-light);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }
        
        .attachment-info {
            flex: 1;
        }
        
        .attachment-name {
            font-size: 13px;
            font-weight: 600;
            color: var(--color-text);
            margin-bottom: 2px;
        }
        
        .attachment-meta {
            font-size: 11px;
            color: var(--color-text-muted);
        }
        
        /* Notes Section */
        .notes-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .note-item {
            padding: 14px;
            background: white;
            border: 1px solid var(--color-border);
            border-radius: 8px;
            border-right: 3px solid var(--color-primary);
        }
        
        .note-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .note-author {
            font-size: 12px;
            font-weight: 600;
            color: var(--color-text);
        }
        
        .note-time {
            font-size: 10px;
            color: var(--color-text-muted);
        }
        
        .note-content {
            font-size: 12px;
            color: var(--color-text-muted);
            line-height: 1.6;
        }
        
        .add-note-btn {
            width: 100%;
            padding: 12px;
            background: var(--color-primary-light);
            border: 2px dashed var(--color-primary);
            border-radius: 8px;
            color: var(--color-primary);
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .add-note-btn:hover {
            background: white;
        }
        
        /* Scrollbar Styling */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: transparent;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        
        [x-cloak] { display: none !important; }
    </style>
</head>
<body x-data="{ showPreview: false }">

    <!-- TOP BAR (من improved-current) -->
    <div class="top-bar">
        <div class="brand">
            <div class="brand-icon">B</div>
            <span>نظام إدارة خطابات الضمان</span>
        </div>
        <div class="global-actions">
            <button class="btn-global"> إحصائيات</button>
            <button class="btn-global">⚙️ إعدادات</button>
        </div>
    </div>

    <!-- MAIN CONTAINER (3 Columns) -->
    <div class="main-container">

    <!-- TIMELINE PANEL (Right - Full Height) -->
    <aside class="timeline-panel">
        <header class="timeline-header">
            <span class="timeline-title">📜 سجل العمليات</span>
            <span class="timeline-count">3 أحداث</span>
        </header>
        <div class="timeline-body">
            <div class="timeline-list">
                <div class="timeline-line"></div>
                
                <div class="timeline-item">
                    <div class="timeline-dot active"></div>
                    <div class="event-card current">
                        <span class="event-badge">الآن</span>
                        <h5 class="event-title">في انتظار القرار</h5>
                        <p class="event-time">10:45 ص</p>
                        <p class="event-desc">تمت مراجعة البيانات آلياً ولم يتم العثور على ملاحظات جوهرية.</p>
                    </div>
                </div>

                <div class="timeline-item">
                    <div class="timeline-dot success"></div>
                    <div class="event-card">
                        <span class="event-time">أمس (09:30 ص)</span>
                        <h5 class="event-title">وارد من البنك</h5>
                        <p class="event-desc">تم استلام طلب التمديد عبر سويفت MT760</p>
                    </div>
                </div>

                <div class="timeline-item">
                    <div class="timeline-dot"></div>
                    <div class="event-card">
                        <span class="event-time">01/01/2024</span>
                        <h5 class="event-title">إصدار الضمان</h5>
                        <p class="event-desc">تم إصدار الضمان الأساسي لمدة سنة واحدة.</p>
                    </div>
                </div>

            </div>
        </div>
    </aside>

    <!-- MIDDLE COLUMN (Topbar + Decision Panel) -->
    <div class="middle-column">
        <!-- TOPBAR (من unified-workflow) -->
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

        <!-- DECISION PANEL (Center) -->
        <main class="decision-panel">
            <div class="decision-body">
                <!-- البيانات الأساسية -->
                <div class="form-section">
                    <h3 class="section-title">📝 البيانات الأساسية</h3>
                    
                    <!-- Supplier Field -->
                    <div class="field-group">
                        <label class="field-label">المورد (المستفيد)</label>
                        <input type="text" class="field-input" value="شركة المقاولات المتحدة" placeholder="ابحث عن المورد...">
                        
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
                    </div>
                    
                    <!-- Bank Field -->
                    <div class="field-group">
                        <label class="field-label">البنك المصدر</label>
                        <select class="field-input">
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
                            <span class="info-value">1,500,000.00 ريال</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">رقم العقد</span>
                            <span class="info-value">CON-2024-9982</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">تاريخ الانتهاء</span>
                            <span class="info-value" style="color: var(--color-success);">30 ديسمبر 2025</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">نوع الضمان</span>
                            <span class="info-value">نهائي</span>
                        </div>
                    </div>
                </div>

                <!-- Preview Toggle Button -->
                <div style="margin-top: 20px; text-align: center;">
                    <button class="preview-toggle" @click="showPreview = !showPreview" style="padding: 10px 20px; background: var(--color-primary-light); border: 1px solid var(--color-primary); border-radius: 8px; color: var(--color-primary); font-family: inherit; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s;">
                        <span x-text="showPreview ? '🔼 إخفاء المعاينة' : '👁️ معاينة الخطاب'"></span>
                    </button>
                </div>

                <!-- Preview Section (Inline) -->
                <div class="preview-section" x-show="showPreview" x-transition x-cloak>
                    <div class="preview-header">
                        <span class="preview-title">معاينة خطاب التمديد</span>
                        <button class="preview-print">🖨️ طباعة</button>
                    </div>
                    <div class="preview-body">
                        <div class="letter-paper">
                            <div class="letter-header">
                                مستشفى الملك فيصل التخصصي ومركز الأبحاث
                            </div>
                            
                            <div style="margin-bottom: 16px;">
                                <div style="font-weight: 700;">السادة / البنك الأهلي السعودي</div>
                                <div>المحترمين</div>
                            </div>
                            
                            <div style="margin-bottom: 20px; font-size: 11pt;">
                                السَّلام عليكُم ورحمَة الله وبركاتِه
                            </div>
                            
                            <div style="margin-bottom: 16px;">
                                <strong>الموضوع:</strong> طلب تمديد الضمان البنكي رقم (LG-2024-8821)
                            </div>
                            
                            <div style="margin-bottom: 24px; line-height: 1.8;">
                                <p style="margin-bottom: 12px;">
                                    إشارة الى الضمان البنكي الموضح أعلاه، والصادر منكم لصالحنا على حساب 
                                    <strong>شركة المقاولات المتحدة</strong> بمبلغ قدره (<strong>1,500,000.00</strong>) ريال،
                                </p>
                                <p style="margin-bottom: 12px;">
                                    نأمل منكم <strong>تمديد فترة سريان الضمان حتى تاريخ 30 ديسمبر 2025م</strong>، 
                                    مع بقاء الشروط الأخرى دون تغيير.
                                </p>
                                <p>
                                    علمًا بأنه في حال عدم تمكن البنك من تمديد الضمان المذكور قبل انتهاء مدة سريانه، 
                                    فيجب على البنك دفع قيمة الضمان إلينا حسب النظام.
                                </p>
                            </div>
                            
                            <div style="text-indent: 5em; margin-top: 20px;">
                                وَتفضَّلوا بِقبُول خَالِص تحيَّاتِي
                            </div>
                            
                            <div style="margin-top: 60px; text-align: left;">
                                <div style="margin-bottom: 60px; font-weight: 700;">
                                    مُدير الإدارة العامَّة للعمليَّات المحاسبيَّة
                                </div>
                                <div style="font-weight: 700;">
                                    سَامِي بن عبَّاس الفايز
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- SIDEBAR PANEL (Left) - المستندات والملاحظات -->
        <aside class="sidebar-panel">
            <div class="sidebar-header">
                <div class="progress-container">
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?= $progressPercent ?>%"></div>
                    </div>
                    <div class="progress-text">
                        <span>سجل <?= $currentRecord ?> من <?= $totalRecords ?></span>
                        <span class="progress-percent"><?= $progressPercent ?>%</span>
                    </div>
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
                                <div class="attachment-meta">2.3 MB • تم الرفع في 15 ديسمبر 2024</div>
                            </div>
                        </div>
                        
                        <div class="attachment-item">
                            <div class="attachment-icon">📧</div>
                            <div class="attachment-info">
                                <div class="attachment-name">طلب التمديد من البنك.eml</div>
                                <div class="attachment-meta">156 KB • تم الرفع في 20 ديسمبر 2024</div>
                            </div>
                        </div>
                        
                        <div class="attachment-item">
                            <div class="attachment-icon">📑</div>
                            <div class="attachment-info">
                                <div class="attachment-name">العقد الأساسي.pdf</div>
                                <div class="attachment-meta">4.1 MB • تم الرفع في 10 يناير 2024</div>
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

    <!-- FOOTER (Full Width) -->
    <footer class="footer">
        <button class="preview-toggle">
            <span>المزيد</span>
            <span>▼</span>
        </button>
        <div class="action-primary">
            <button class="btn-secondary">
                <span>⬅️</span>
                <span>السابق</span>
            </button>
            <button class="btn-primary">
                <span>💾</span>
                <span>حفظ وانتقل للتالي</span>
                <span>➡️</span>
            </button>
        </div>
    </footer>

</body>
</html>
