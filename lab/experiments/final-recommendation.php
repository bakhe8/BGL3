<?php
/**
 * Final Recommendation: The Optimal BGL Interface
 * ================================================
 * 
 * التوصية النهائية بناءً على التحليل العميق
 * 
 * يدمج أفضل ما في:
 * ✓ Unified Practical → Task Queue في الجانب الأيمن
 * ✓ Improved Current → مصدر البيانات الواضح + Timeline دائماً ظاهر
 * ✓ Unified Workflow → نظام التصميم الموحد والاحترافي
 * 
 * الميزة الفريدة: Validation Chips فوق الحقول مباشرة
 * الهدف: منصة عمل شاملة للمعالجة السريعة والآمنة
 */

$EXPERIMENT_NAME = 'التوصية النهائية (Final Optimal Design)';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>التوصية النهائية - DesignLab</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        /* ═══════════════════════════════════════════════════════════════
           RESET & BASE
           ═══════════════════════════════════════════════════════════════ */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        
        [x-cloak] { display: none !important; }
        
        body {
            font-family: 'Tajawal', sans-serif;
            background: #f1f5f9;
            color: #1e293b;
            height: 100vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        /* ═══════════════════════════════════════════════════════════════
           TOP BAR (Global Actions - من Improved Current)
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
            z-index: 100;
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
        }
        
        .btn-global:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #1e293b;
        }

        /* ═══════════════════════════════════════════════════════════════
           THREE-COLUMN LAYOUT (Sidebar | Decision | Timeline) - من unified-workflow
           ═══════════════════════════════════════════════════════════════ */
        .workspace {
            flex: 1;
            display: flex;
            overflow: hidden;
            min-height: 0;
        }

        /* ═══════════════════════════════════════════════════════════════
           SIDEBAR (Left - 290px من unified-workflow)
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
           MAIN DECISION WORKSPACE (Center - Flex)
           ═══════════════════════════════════════════════════════════════ */
        .main-workspace {
            flex: 1;
            background: #f8fafc;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .workspace-header {
            height: 64px;
            background: white;
            border-bottom: 1px solid #e2e8f0;
            padding: 0 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }

        .record-info {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .record-id {
            font-size: 20px;
            font-weight: 800;
            color: #1e293b;
        }

        .status-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            background: #fef3c7;
            color: #d97706;
            border: 1px solid #fde68a;
        }

        .progress-mini {
            font-size: 11px;
            color: #64748b;
        }

        .progress-percent {
            font-weight: 700;
            color: #3b82f6;
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
            max-width: 900px;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }

        /* ═══════════════════════════════════════════════════════════════
           VALIDATION CHIPS (الميزة الفريدة - Instant Feedback)
           ═══════════════════════════════════════════════════════════════ */
        .validation-banner {
            background: linear-gradient(135deg, #dcfce7 0%, #d1fae5 100%);
            border-bottom: 2px solid #86efac;
            padding: 16px 32px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .validation-banner.warning {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-bottom-color: #fbbf24;
        }

        .v-chip {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .v-chip.success {
            background: white;
            color: #16a34a;
            border: 1px solid #86efac;
        }

        .v-chip.warning {
            background: white;
            color: #d97706;
            border: 1px solid #fbbf24;
        }

        /* ═══════════════════════════════════════════════════════════════
           FORM FIELDS (من Improved Current)
           ═══════════════════════════════════════════════════════════════ */
        .card-content {
            padding: 32px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }

        .field-group { margin-bottom: 24px; }
        .field-group:last-child { margin-bottom: 0; }

        .field-label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            margin-bottom: 8px;
            letter-spacing: 0.5px;
        }
        
        .field-value-primary {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            padding-bottom: 8px;
            border-bottom: 2px solid #e2e8f0;
            width: 100%;
            background: transparent;
            border-top: none;
            border-left: none;
            border-right: none;
            font-family: inherit;
            transition: all 0.2s;
        }

        .field-value-primary:focus {
            outline: none;
            border-color: #3b82f6;
        }

        /* CHIPS WITH SOURCE (من Improved Current) */
        .chips-row {
            display: flex;
            gap: 8px;
            margin-top: 12px;
            flex-wrap: wrap;
        }
        
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
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .chip:hover { border-color: #94a3b8; color: #334155; }
        .chip.selected {
            background: #eff6ff;
            color: #2563eb;
            border-color: #bfdbfe;
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

        .chip-source {
            font-size: 9px;
            padding: 2px 6px;
            background: rgba(0,0,0,0.08);
            border-radius: 6px;
            margin-right: 4px;
        }

        .chip-stars {
            color: #f59e0b;
        }

        /* INFO GRID */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            background: #f8fafc;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 24px;
        }

        .info-item label {
            font-size: 10px;
            color: #94a3b8;
            font-weight: 600;
            display: block;
            margin-bottom: 4px;
        }

        .info-item span {
            font-size: 13px;
            font-weight: 700;
            color: #334155;
        }

        .info-item span.highlight { color: #16a34a; }

        /* ACTIONS FOOTER */
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
            padding: 12px 28px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
            font-family: inherit;
        }
        .btn-primary:hover { background: #2563eb; transform: translateY(-1px); }

        .btn-secondary {
            background: white;
            color: #64748b;
            border: 1px solid #e2e8f0;
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.2s;
        }
        .btn-secondary:hover { border-color: #cbd5e1; color: #334155; }

        /* ═══════════════════════════════════════════════════════════════
           TIMELINE PANEL (Right - 360px)
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
            height: 60px;
            padding: 0 20px;
            background: white;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .timeline-title {
            font-weight: 700;
            color: #334155;
            font-size: 14px;
        }

        .timeline-count {
            font-size: 11px;
            color: #94a3b8;
            font-weight: 600;
        }

        .timeline-content {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            position: relative;
        }

        .timeline-line {
            position: absolute;
            right: 32px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e2e8f0;
        }

        .t-item {
            position: relative;
            margin-bottom: 24px;
            padding-left: 48px;
        }

        .t-item:last-child {
            margin-bottom: 0;
        }

        .t-dot {
            position: absolute;
            right: 24px;
            top: 4px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: #e2e8f0;
            border: 3px solid white;
            box-shadow: 0 0 0 2px #e2e8f0;
            z-index: 1;
        }

        .t-dot.active {
            background: #3b82f6;
            box-shadow: 0 0 0 2px #3b82f6, 0 0 0 4px #dbeafe;
        }

        .t-dot.success {
            background: #10b981;
            box-shadow: 0 0 0 2px #10b981;
        }

        .t-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px;
            transition: all 0.2s;
        }

        .t-card:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }

        .t-card.active {
            background: #eff6ff;
            border-color: #bfdbfe;
        }

        .t-time {
            font-size: 10px;
            color: #94a3b8;
            font-weight: 600;
            display: block;
            margin-bottom: 6px;
        }

        .t-title {
            font-size: 13px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 4px;
        }

        .t-desc {
            font-size: 11px;
            color: #64748b;
            line-height: 1.5;
            margin: 0;
        }

        /* ═══════════════════════════════════════════════════════════════
           PREVIEW SECTION
           ═══════════════════════════════════════════════════════════════ */
        .preview-toggle {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            color: #3b82f6;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.2s;
            margin-top: 16px;
        }

        .preview-toggle:hover {
            border-color: #93c5fd;
            background: #eff6ff;
        }

        .preview-toggle svg {
            width: 16px;
            height: 16px;
        }

        .preview-section {
            margin-top: 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }

        .preview-header {
            height: 48px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
        }

        .preview-title {
            font-size: 13px;
            font-weight: 700;
            color: #334155;
        }

        .preview-print {
            font-size: 12px;
            color: #3b82f6;
            font-weight: 600;
            background: none;
            border: none;
            cursor: pointer;
            padding: 6px 12px;
            border-radius: 6px;
            transition: all 0.2s;
            font-family: inherit;
        }

        .preview-print:hover {
            background: #eff6ff;
        }

        .preview-body {
            padding: 32px;
            background: #f8fafc;
            display: flex;
            justify-content: center;
        }

        .letter-paper {
            width: 100%;
            max-width: 600px;
            background: white;
            padding: 48px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            font-size: 13px;
            line-height: 1.8;
            color: #334155;
        }

        .letter-header-section {
            margin-bottom: 32px;
            padding-bottom: 16px;
            border-bottom: 2px solid #e2e8f0;
        }

        .letter-to {
            font-size: 15px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 8px;
        }

        .letter-greeting {
            font-size: 13px;
            color: #64748b;
        }

        /* ═══════════════════════════════════════════════════════════════
           TASK QUEUE (Left - 280px من Unified Practical)
           ═══════════════════════════════════════════════════════════════ */
        .task-queue-panel {
            width: 280px;
            background: white;
            border-right: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }

        .queue-header {
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            border-bottom: 1px solid #f1f5f9;
        }

        .queue-title {
            font-weight: 700;
            color: #334155;
            font-size: 14px;
        }

        .queue-badge {
            font-size: 11px;
            font-weight: 700;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: white;
            padding: 4px 10px;
            border-radius: 12px;
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
            transform: translateX(-2px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.04);
        }

        .queue-item.active {
            background: #eff6ff;
            border-color: #bfdbfe;
        }

        .q-info h4 {
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 4px;
            color: #334155;
        }

        .q-info p {
            font-size: 11px;
            color: #64748b;
        }
        
        .q-status {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        .q-status.red { background: #ef4444; }
        .q-status.orange { background: #f59e0b; }
        .q-status.green { background: #22c55e; }

        /* SCROLLBAR STYLING */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #f8fafc; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

    </style>
</head>
<body x-data="{ showPreview: false }">

    <!-- TOP BAR (Global) -->
    <div class="top-bar">
        <div class="brand">
            <div class="brand-icon">B</div>
            <span>BGL - نظام خطابات الضمان</span>
        </div>
        <div class="global-actions">
            <button class="btn-global">
                <span>⚙️</span> الإعدادات
            </button>
            <button class="btn-global">
                <span>📊</span> الإحصائيات
            </button>
            <button class="btn-global">
                <span>🧪</span> المختبر
            </button>
        </div>
    </div>

    <!-- WORKSPACE (3 Columns: Sidebar | Decision | Timeline) -->
    <div class="workspace">
        
        <!-- TIMELINE (Right - 360px) - FIRST in HTML for RTL -->
        <aside class="timeline-panel">
            <header class="timeline-header">
                <span class="timeline-title">سجل العمليات</span>
                <span class="timeline-count">5 أحداث</span>
            </header>
            <div class="timeline-content">
                <div class="timeline-line"></div>
                
                <div class="t-item">
                    <div class="t-dot active"></div>
                    <div class="t-card active">
                        <span class="t-time">الآن (10:45 ص)</span>
                        <h5 class="t-title">في انتظار القرار</h5>
                        <p class="t-desc">تمت مراجعة البيانات آلياً. جميع المطابقات صحيحة ✓</p>
                    </div>
                </div>

                <div class="t-item">
                    <div class="t-dot success"></div>
                    <div class="t-card">
                        <span class="t-time">أمس، 09:30 ص</span>
                        <h5 class="t-title">طلب تمديد من البنك</h5>
                        <p class="t-desc">تم استلام الطلب عبر سويفت MT760</p>
                    </div>
                </div>

                <div class="t-item">
                    <div class="t-dot success"></div>
                    <div class="t-card">
                        <span class="t-time">15 سبتمبر 2024</span>
                        <h5 class="t-title">تصحيح اسم المورد</h5>
                        <p class="t-desc">من: "المقاولات المتحدة" إلى: "شركة المقاولات المتحدة"</p>
                    </div>
                </div>

                <div class="t-item">
                    <div class="t-dot success"></div>
                    <div class="t-card">
                        <span class="t-time">10 مارس 2024</span>
                        <h5 class="t-title">تمديد الضمان</h5>
                        <p class="t-desc">تم تمديد الضمان من 31/12/2024 إلى 30/12/2025</p>
                    </div>
                </div>

                <div class="t-item">
                    <div class="t-dot"></div>
                    <div class="t-card">
                        <span class="t-time">01 يناير 2024</span>
                        <h5 class="t-title">إصدار الضمان</h5>
                        <p class="t-desc">تم إصدار الضمان الأساسي لمدة سنة واحدة. المبلغ: 1,500,000 SAR</p>
                    </div>
                </div>

            </div>
        </aside>

        <!-- MAIN WORKSPACE (Center) - SECOND in HTML for RTL -->
        <main class="main-workspace">
            <header class="workspace-header">
                <div class="record-info">
                    <span class="record-id">LG-2024-5821</span>
                    <span class="status-badge">يحتاج قرار</span>
                </div>
                <div class="progress-mini">
                    سجل <strong>1</strong> من <strong>63</strong>
                    <span class="progress-percent">1.6%</span>
                </div>
            </header>

            <div class="workspace-body">
                <div class="decision-card">
                    <!-- VALIDATION BANNER (الميزة الفريدة) -->
                    <div class="validation-banner">
                        <div class="v-chip success">
                            <span>✓</span> يطابق السجل التجاري
                        </div>
                        <div class="v-chip success">
                            <span>✓</span> ضمن الحد الائتماني
                        </div>
                        <div class="v-chip success">
                            <span>✓</span> البنك موثوق (SNB)
                        </div>
                    </div>

                    <div class="card-content">
                        <div class="form-grid">
                            <div class="field-group">
                                <label class="field-label">المورد (المستفيد)</label>
                                <input type="text" class="field-value-primary" value="شركة المقاولات المتحدة" readonly>
                                <div class="chips-row">
                                    <span class="chip chip-learned selected">
                                        <span class="chip-stars">⭐⭐⭐</span>
                                        شركة المقاولات المتحدة
                                        <span class="chip-source">استخدمته 15 مرة</span>
                                    </span>
                                    <span class="chip">
                                        المقاولات المتحدة للإنشاءات
                                    </span>
                                </div>
                            </div>

                            <div class="field-group">
                                <label class="field-label">البنك المصدر</label>
                                <input type="text" class="field-value-primary" value="البنك الأهلي السعودي" readonly>
                                <div class="chips-row">
                                    <span class="chip chip-excel selected">
                                        البنك الأهلي السعودي
                                        <span class="chip-source">من Excel: 95%</span>
                                    </span>
                                    <span class="chip">
                                        SNB
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="info-grid">
                            <div class="info-item">
                                <label>رقم الضمان</label>
                                <span>LG-2024-5821</span>
                            </div>
                            <div class="info-item">
                                <label>رقم العقد</label>
                                <span>CON-2024-9982</span>
                            </div>
                            <div class="info-item">
                                <label>المبلغ</label>
                                <span style="font-family: monospace;">1,500,000.00 SAR</span>
                            </div>
                            <div class="info-item">
                                <label>تاريخ الإصدار</label>
                                <span>01 يناير 2024</span>
                            </div>
                            <div class="info-item">
                                <label>تاريخ الانتهاء</label>
                                <span class="highlight">30 ديسمبر 2025</span>
                            </div>
                            <div class="info-item">
                                <label>النوع</label>
                                <span>نهائي (Final)</span>
                            </div>
                        </div>

                        <div class="field-group">
                            <label class="field-label">ملاحظات القرار</label>
                            <textarea style="width: 100%; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; font-family: inherit; font-size: 13px; min-height: 80px;" placeholder="اكتب ملاحظات إضافية هنا..."></textarea>
                        </div>

                        <!-- Preview Toggle Button -->
                        <button class="preview-toggle" @click="showPreview = !showPreview">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width: 16px; height: 16px;">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                            <span x-text="showPreview ? 'إخفاء المعاينة' : 'معاينة الخطاب'">معاينة الخطاب</span>
                        </button>
                    </div>

                    <!-- Preview Section -->
                    <div class="preview-section" x-show="showPreview" x-transition x-cloak>
                        <div class="preview-header">
                            <span class="preview-title">📄 معاينة خطاب الضمان</span>
                            <button class="preview-print">🖨️ طباعة</button>
                        </div>
                        <div class="preview-body">
                            <div class="letter-paper">
                                <div class="letter-header-section">
                                    <div class="letter-to">السادة / البنك الأهلي السعودي</div>
                                    <div class="letter-greeting">المحترمين</div>
                                </div>
                                <p><strong>الموضوع:</strong> خطاب الضمان النهائي رقم (LG-2024-5821)</p>
                                <p style="margin-top: 12px;">إشارة إلى خطاب الضمان النهائي رقم <strong>LG-2024-5821</strong>، الصادر بتاريخ <strong>01 يناير 2024</strong> والمنتهي في <strong>30 ديسمبر 2025</strong>...</p>
                                <p style="margin-top: 12px;">نفيدكم بأن ه تم مراجعة الضمان البنكي الخاص بـ <strong>شركة المقاولات المتحدة</strong> بقيمة <strong>1,500,000.00 ريال</strong> (مليون وخمسمائة ألف ريال سعودي).</p>
                                <p style="margin-top: 12px;">جميع المعلومات صحيحة ومطابقة للمستندات المرفقة.</p>
                                <p style="margin-top: 20px;">وتفضلوا بقبول فائق الاحترام والتقدير،</p>
                                <div style="margin-top: 40px; text-align: left; direction: ltr;">
                                    <div style="font-weight: 700;">الإدارة المالية</div>
                                    <div style="font-size: 11px; color: #64748b;">نظام إدارة خطابات الضمان - BGL</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="actions-footer">
                        <div style="display: flex; gap: 12px;">
                            <button class="btn-primary">
                                <span>✅</span> اعتماد وحفظ
                            </button>
                            <button class="btn-secondary">
                                تحويل للمراجعة القانونية
                            </button>
                            <button class="btn-secondary">
                                إلغاء
                            </button>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <button class="btn-secondary" style="padding: 6px 12px; font-size: 12px;">← السابق</button>
                            <button class="btn-secondary" style="padding: 6px 12px; font-size: 12px;">التالي →</button>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- SIDEBAR (Left - Documents + Notes + Progress) - THIRD in HTML for RTL -->
        <aside class="sidebar">
            <div class="progress-container">
                <div class="progress-bar">
                    <div class="progress-fill" style="width: 1.6%"></div>
                </div>
                <div class="progress-text">
                    <span>سجل 1 من 63</span>
                    <span class="progress-percent">1.6%</span>
                </div>
            </div>
            <div class="sidebar-body">
                <div class="sidebar-section">
                    <div class="sidebar-section-title">
                        📎 المستندات المرفقة
                    </div>
                    <div class="attachments-list">
                        <div class="attachment-item">
                            <div class="attachment-icon">📄</div>
                            <div class="attachment-info">
                                <div class="attachment-name">خطاب الضمان الأصلي.pdf</div>
                                <div class="attachment-meta">1.2 MB</div>
                            </div>
                        </div>
                        <div class="attachment-item">
                            <div class="attachment-icon">📄</div>
                            <div class="attachment-info">
                                <div class="attachment-name">نسخة العقد.pdf</div>
                                <div class="attachment-meta">890 KB</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="sidebar-section">
                    <div class="sidebar-section-title">
                        💬 الملاحظات والتعليقات
                    </div>
                    <div class="notes-list">
                        <div class="note-item">
                            <div class="note-header">
                                <span class="note-author">أحمد المدير</span>
                                <span class="note-time">قبل 10 دقائق</span>
                            </div>
                            <div class="note-content">تم التأكد من صحة المعلومات مع البنك.</div>
                        </div>
                    </div>
                    <button class="add-note-btn">+ إضافة ملاحظة</button>
                </div>
            </div>
        </aside>

    </div>

</body>
</html>
