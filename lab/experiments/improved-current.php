<?php
/**
 * Experiment #10: Improved Current Interface
 * ==========================================
 * 
 * التطوير التدريجي - تحسين الواجهة الحالية بدلاً من إعادة تصميم كاملة
 * 
 * التحسينات الخمسة:
 * 1. إعادة ترتيب الأزرار (تصنيف واضح)
 * 2. Timeline مرئي دائماً (توزيع ثلاثي)
 * 3. مصدر البيانات للشرائح (من Excel / استخدمته X مرة)
 * 4. معاينة A4 دقيقة (210mm × 297mm)
 * 5. مؤشر التقدم (شريط + نسبة مئوية)
 */

$EXPERIMENT_NAME = 'التطوير التدريجي (Improved Current)';
$currentRecord = 1;
$totalRecords = 63;
$progressPercent = round(($currentRecord / $totalRecords) * 100, 1);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>التجربة #10 - <?php echo $EXPERIMENT_NAME; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    
    <style>
        /* ═══════════════════════════════════════════════════════════════
           RESET & BASE
           ═══════════════════════════════════════════════════════════════ */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        
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
           TOP BAR (Global Actions)
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
        }
        
        .btn-global:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #1e293b;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           CONTEXT BAR (Record Info)
           ═══════════════════════════════════════════════════════════════ */
        .context-bar {
            height: 64px;
            background: white;
            border-bottom: 1px solid #e2e8f0;
            padding: 0 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        
        .context-info {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .context-title {
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
        
        .status-badge.ready {
            background: #dcfce7;
            color: #16a34a;
            border-color: #86efac;
        }
        
        /* Progress */
        .progress-container {
            min-width: 200px;
        }
        
        .progress-bar {
            height: 6px;
            background: #e2e8f0;
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 6px;
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
        
        /* ═══════════════════════════════════════════════════════════════
           THREE-COLUMN LAYOUT
           ═══════════════════════════════════════════════════════════════ */
        .workspace {
            flex: 1;
            width: 100%;
            display: grid;
            grid-template-columns: 320px 1fr 420px;
            gap: 16px;
            overflow: hidden;
            min-height: 0;
            background: #f8fafc;
            padding: 16px;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           TIMELINE PANEL (Right - Arabic First)
           ═══════════════════════════════════════════════════════════════ */
        .timeline-panel {
            background: white;
            border-left: 1px solid #e2e8f0;
            border-radius: 0;
            box-shadow: none;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .timeline-header {
            height: 48px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
        }
        
        .timeline-title {
            font-size: 13px;
            font-weight: 700;
            color: #475569;
            display: flex;
            align-items: center;
            gap: 8px;
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
            padding: 16px;
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
            background: #e2e8f0;
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 20px;
        }
        
        .timeline-dot {
            position: absolute;
            right: -17px;
            top: 6px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 3px solid #f8fafc;
            background: #94a3b8;
            box-shadow: 0 0 0 1px #e2e8f0;
        }
        
        .timeline-dot.active {
            background: #3b82f6;
            box-shadow: 0 0 0 1px #3b82f6, 0 0 0 4px rgba(59, 130, 246, 0.2);
        }
        
        .event-card {
            background: #f8fafc;
            border-radius: 8px;
            padding: 14px;
            border: 1px solid #e2e8f0;
            box-shadow: none;
        }
        
        .event-card.current {
            background: #eff6ff;
            border-color: #bfdbfe;
        }
        
        .event-badge {
            display: inline-block;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 2px 6px;
            border-radius: 50px;
            background: #3b82f6;
            color: white;
            margin-bottom: 6px;
        }
        
        .event-title {
            font-size: 13px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 4px;
        }
        
        .event-time {
            font-size: 10px;
            color: #94a3b8;
            margin-bottom: 6px;
        }
        
        .event-desc {
            font-size: 11px;
            color: #64748b;
            line-height: 1.5;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           DECISION PANEL (Center)
           ═══════════════════════════════════════════════════════════════ */
        .decision-panel {
            background: white;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .decision-body {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
        }
        
        .form-section {
            background: white;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .section-title {
            font-size: 13px;
            font-weight: 700;
            color: #475569;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
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
            margin-bottom: 8px;
        }
        
        .field-input {
            width: 100%;
            font-family: inherit;
            font-size: 15px;
            font-weight: 600;
            color: #1e293b;
            background: transparent;
            border: none;
            border-bottom: 2px solid #e2e8f0;
            padding: 8px 4px;
            transition: all 0.2s;
        }
        
        .field-input:hover {
            border-color: #93c5fd;
        }
        
        .field-input:focus {
            outline: none;
            border-color: #3b82f6;
        }
        
        /* Chips with Source */
        .chips-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
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
            background: white;
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
            background: #f8fafc;
            color: #64748b;
            border-color: #e2e8f0;
        }
        
        .chip-candidate:hover {
            background: #eff6ff;
            border-color: #93c5fd;
            color: #3b82f6;
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
        
        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            background: #f8fafc;
            padding: 16px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
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
            color: #1e293b;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           PREVIEW PANEL (Left)
           ═══════════════════════════════════════════════════════════════ */
        .preview-panel {
            background: #f1f5f9;
            border-right: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .preview-header {
            height: 48px;
            background: white;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
        }
        
        .preview-title {
            font-size: 12px;
            font-weight: 700;
            color: #475569;
        }
        
        .preview-print {
            padding: 6px 12px;
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.2s;
        }
        
        .preview-print:hover {
            background: #2563eb;
        }
        
        .preview-body {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
            display: flex;
            justify-content: center;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           QUICK ACTIONS - Simple & Professional
           ═══════════════════════════════════════════════════════════════ */
        
        .insights-panel-body {
            padding: 20px;
            background: white;
        }
        
        .actions-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .action-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 20px;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            width: 100%;
            text-align: right;
        }
        
        .action-item:hover {
            border-color: #3b82f6;
            background: #f8fafc;
            transform: translateX(-2px);
        }
        
        .action-icon {
            font-size: 24px;
            flex-shrink: 0;
        }
        
        .action-text {
            font-size: 11pt;
            font-weight: 600;
            color: #1e293b;
        }
        
        /* Timeline-Pro Preview Pane Styling */
        .preview-pane {
            background: white;
            border-radius: 20px;
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            border: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .preview-pane .preview-header {
            background: #f8fafc;
            padding: 16px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .preview-pane .preview-body {
            flex: 1;
            overflow-y: auto;
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
        
        .delay-3 { animation-delay: 0.3s; }
        
        /* ═══════════════════════════════════════════════════════════════
           LETTER PREVIEW - Matching Interface Design
           ═══════════════════════════════════════════════════════════════ */
        
        .letter-paper {
            background: white;
            padding: 32px;
            font-size: 12px;
            line-height: 1.7;
            color: #334155;
        }
        
        .letter-header {
            text-align: center;
            margin-bottom: 32px;
            padding-bottom: 16px;
            border-bottom: 2px solid #1e293b;
        }
        
        .letter-header h2 {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
            color: #1e293b;
        }
        
        .letter-to {
            margin-bottom: 16px;
            font-size: 13px;
        }
        
        .letter-greeting {
            margin-bottom: 20px;
            font-size: 12px;
        }
        
        .letter-body {
            margin-bottom: 24px;
            line-height: 1.8;
        }
        
        .letter-details {
            margin: 28px 0;
        }
        
        .details-title {
            margin-bottom: 12px;
            font-weight: 600;
            font-size: 12px;
            color: #1e293b;
        }
        
        .details-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }
        
        .details-table tr {
            border-bottom: 1px solid #e2e8f0;
        }
        
        .details-table tr:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            padding: 10px 12px;
            background: #f8fafc;
            font-weight: 600;
            color: #64748b;
            width: 35%;
        }
        
        .detail-value {
            padding: 10px 12px;
            color: #1e293b;
            font-weight: 500;
        }
        
        .letter-closing {
            margin-top: 32px;
            margin-bottom: 48px;
        }
        
        .letter-signature {
            margin-top: 60px;
        }
        
        .signature-label {
            margin-bottom: 40px;
            font-weight: 600;
            color: #1e293b;
        }
        
        .signature-line {
            width: 180px;
            border-top: 2px solid #1e293b;
            padding-top: 4px;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           MODERN LETTER PREVIEW - Beautiful & Clear
           ═══════════════════════════════════════════════════════════════ */
        
        .letter-paper-simple {
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }
        
        .letter-header-simple {
            background: white;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            margin-bottom: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .letter-title-simple {
            font-size: 14pt;
            font-weight: 800;
            color: #1e293b;
            margin: 0 0 8px 0;
            line-height: 1.3;
        }
        
        .letter-subtitle-simple {
            font-size: 9pt;
            color: #64748b;
            margin: 0;
        }
        
        .letter-to-simple {
            background: white;
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            font-weight: 600;
            font-size: 11pt;
            color: #1e293b;
            border-right: 4px solid #667eea;
        }
        
        .letter-to-simple span {
            color: #667eea;
            font-weight: 700;
        }
        
        .letter-greeting-simple {
            background: rgba(255,255,255,0.95);
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 12px;
            font-size: 10pt;
            color: #475569;
            text-align: center;
            font-style: italic;
        }
        
        .letter-body-simple {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            flex: 1;
        }
        
        .letter-body-simple p {
            margin: 0 0 14px 0;
            font-size: 10pt;
            line-height: 1.7;
            color: #334155;
        }
        
        .letter-body-simple p:first-child {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            padding: 12px;
            border-radius: 8px;
            border-right: 3px solid #667eea;
            font-weight: 600;
        }
        
        .letter-body-simple strong {
            color: #667eea;
            font-weight: 700;
        }
        
        .letter-body-simple #letterSupplier,
        .letter-body-simple #letterAmount {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            padding: 2px 8px;
            border-radius: 4px;
            font-weight: 700;
            color: #92400e;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           ACTION BAR (Bottom)
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
        
        .btn-primary {
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
        
        .btn-primary:hover {
            background: #2563eb;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
        }
        
        .btn-secondary {
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
        
        .btn-secondary:hover {
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
        
        /* Scrollbar Styling */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           HISTORICAL VIEW BANNER
           ═══════════════════════════════════════════════════════════════ */
        .historical-banner {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border: 2px solid #f59e0b;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.2);
        }
        
        .historical-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .historical-icon {
            font-size: 24px;
        }
        
        .historical-text {
            display: flex;
            flex-direction: column;
        }
        
        .historical-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: #92400e;
            letter-spacing: 0.5px;
        }
        
        .historical-time {
            font-size: 14px;
            font-weight: 700;
            color: #78350f;
        }
        
        .btn-return {
            padding: 8px 16px;
            background: white;
            color: #d97706;
            border: 1px solid #f59e0b;
            border-radius: 8px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .btn-return:hover {
            background: #fffbeb;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
        }
        
        /* Timeline Item Clickable */
        .timeline-item {
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .timeline-item:hover .event-card {
            transform: translateX(-4px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .timeline-item.selected .event-card {
            border-color: #f59e0b;
            background: #fffbeb;
        }
        
        .timeline-item.selected .timeline-dot {
            background: #f59e0b;
            box-shadow: 0 0 0 1px #f59e0b, 0 0 0 4px rgba(245, 158, 11, 0.2);
        }
        
        /* ═══════════════════════════════════════════════════════════════
           DECISION PANEL (Center)
           ═══════════════════════════════════════════════════════════════ */
        .decision-panel {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .decision-body {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           PREVIEW SECTION (Unified Workflow Style)
           ═══════════════════════════════════════════════════════════════ */
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
            margin-top: 16px;
        }
        
        .preview-toggle:hover { border-color: #93c5fd; color: #3b82f6; }
        
        /* ═══════════════════════════════════════════════════════════════
           ATTACHMENTS & NOTES PANEL (Left Sidebar)
           ═══════════════════════════════════════════════════════════════ */
        .attachments-notes-panel {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            overflow-y: auto;
            align-content: flex-start;
        }
        
        .attachments-notes-panel .panel-body {
            padding: 16px;
            flex-shrink: 0;
        }
        
        [x-cloak] { display: none !important; }
    </style>
</head>
<body x-data="{
    showMore: false,
    showPreview: false,
    viewingHistorical: false,
    currentEventIndex: 0,
    events: [
        {
            id: 'current',
            title: 'يحتاج قرار',
            time: 'اليوم 10:42 ص',
            timestamp: '2024-12-22 10:42:00',
            badge: 'الآن',
            desc: 'تم استيراد الضمان من ملف Excel ويحتاج مطابقة المورد والبنك.',
            data: {
                supplier: 'شركة المقاولات المتحدة',
                bank: 'البنك الأهلي السعودي (SNB)',
                amount: '50,000.00',
                letterTo: 'البنك الأهلي السعودي'
            }
        },
        {
            id: 'match',
            title: 'مطابقة آلية',
            time: 'اليوم 10:31 ص',
            timestamp: '2024-12-22 10:31:00',
            desc: 'تم التعرف على المورد بنسبة 85% والبنك بنسبة 98%.',
            data: {
                supplier: 'UNITED CONTRACTORS CO',
                bank: 'SNB',
                amount: '50,000.00',
                letterTo: 'البنك الأهلي السعودي'
            }
        },
        {
            id: 'import',
            title: 'استيراد',
            time: 'اليوم 10:30 ص',
            timestamp: '2024-12-22 10:30:00',
            desc: 'تم استيراد السجل من ملف guarantees_dec_2024.xlsx',
            data: {
                supplier: 'UNITED CONTRACTORS CO',
                bank: 'SNB',
                amount: '50000',
                letterTo: 'SNB'
            }
        }
    ],
    
    viewEvent(index) {
        this.currentEventIndex = index;
        this.viewingHistorical = (index !== 0);
        this.updateDisplay();
    },
    
    returnToCurrent() {
        this.viewEvent(0);
    },
    
    updateDisplay() {
        const event = this.events[this.currentEventIndex];
        
        // Update supplier field
        document.getElementById('supplierInput').value = event.data.supplier;
        
        // Update bank field  
        document.getElementById('bankInput').value = event.data.bank;
        
        // Update amount
        document.getElementById('amountValue').textContent = event.data.amount;
        
        // Update letter preview
        document.getElementById('letterBank').textContent = event.data.letterTo;
        document.getElementById('letterSupplier').textContent = event.data.supplier;
        document.getElementById('letterAmount').textContent = event.data.amount;
        
        // Update historical time
        if (this.viewingHistorical) {
            document.getElementById('historicalTime').textContent = event.timestamp;
        }
    }
}">
    
    <!-- Top Bar (Global) -->
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
    
    <!-- Context Bar (Record Info) -->
    <div class="context-bar">
        <div class="context-info">
            <h1 class="context-title">ضمان #B123456</h1>
            <span class="status-badge">🟠 يحتاج قرار</span>
        </div>
        <div class="progress-container">
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?php echo $progressPercent; ?>%"></div>
            </div>
            <div class="progress-text">
                <span>سجل <?php echo $currentRecord; ?> من <?php echo $totalRecords; ?></span>
                <span class="progress-percent"><?php echo $progressPercent; ?>%</span>
            </div>
        </div>
    </div>
    
    <!-- Three-Column Workspace -->
    <div class="workspace">
        
        <!-- Timeline Panel (Right) -->
        <aside class="timeline-panel">
            <div class="timeline-header">
                <div class="timeline-title">
                    📜 سجل العمليات
                </div>
                <span class="timeline-count">3 أحداث</span>
            </div>
            <div class="timeline-body">
                <div class="timeline-list">
                    <div class="timeline-line"></div>
                    
                    <!-- Current Event -->
                    <div class="timeline-item" :class="{ 'selected': currentEventIndex === 0 }" @click="viewEvent(0)">
                        <div class="timeline-dot" :class="{ 'active': currentEventIndex === 0 }"></div>
                        <div class="event-card" :class="{ 'current': currentEventIndex === 0 }">
                            <span class="event-badge">الآن</span>
                            <h3 class="event-title">يحتاج قرار</h3>
                            <div class="event-time">اليوم 10:42 ص</div>
                            <p class="event-desc">تم استيراد الضمان من ملف Excel ويحتاج مطابقة المورد والبنك.</p>
                        </div>
                    </div>
                    
                    <!-- Past Event: Match -->
                    <div class="timeline-item" :class="{ 'selected': currentEventIndex === 1 }" @click="viewEvent(1)">
                        <div class="timeline-dot" :class="{ 'active': currentEventIndex === 1 }"></div>
                        <div class="event-card">
                            <h3 class="event-title">مطابقة آلية</h3>
                            <div class="event-time">اليوم 10:31 ص</div>
                            <p class="event-desc">تم التعرف على المورد بنسبة 85% والبنك بنسبة 98%.</p>
                        </div>
                    </div>
                    
                    <!-- Past Event: Import -->
                    <div class="timeline-item" :class="{ 'selected': currentEventIndex === 2 }" @click="viewEvent(2)">
                        <div class="timeline-dot" :class="{ 'active': currentEventIndex === 2 }"></div>
                        <div class="event-card">
                            <h3 class="event-title">استيراد</h3>
                            <div class="event-time">اليوم 10:30 ص</div>
                            <p class="event-desc">تم استيراد السجل من ملف "guarantees_dec_2024.xlsx".</p>
                        </div>
                    </div>
                </div>
            </div>
        </aside>
        
        <!-- Decision Panel (Center) -->
        <main class="decision-panel">
            <div class="decision-body">
                
                <!-- Historical Banner -->
                <div class="historical-banner" x-show="viewingHistorical" x-transition>
                    <div class="historical-info">
                        <div class="historical-icon">📜</div>
                        <div class="historical-text">
                            <div class="historical-label">عرض تاريخي</div>
                            <div class="historical-time" id="historicalTime">2024-12-22 10:30:00</div>
                        </div>
                    </div>
                    <button class="btn-return" @click="returnToCurrent()">
                        🔙 العودة للحالة الحالية
                    </button>
                </div>
                
                <!-- Main Data Section -->
                <div class="form-section">
                    <h2 class="section-title">📝 البيانات الأساسية</h2>
                    
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
                    
                    <!-- Preview Toggle Button -->
                    <button class="preview-toggle" @click="showPreview = !showPreview">
                        <span x-text="showPreview ? 'إخفاء المعاينة' : 'معاينة الخطاب'"></span>
                    </button>
                </div>
                
                    <!-- Preview Section (Unified Workflow Style) -->
                    <div class="preview-section" x-show="showPreview" x-transition x-cloak>
                        <div class="preview-header">
                            <span class="preview-title">معاينة خطاب التمديد</span>
                            <button class="preview-print">🖨️ طباعة</button>
                        </div>
                        <div class="preview-body">
                            <div class="letter-paper">
                                <div class="letter-header">
                                    <div class="letter-to" id="letterBank">السادة / البنك الأهلي السعودي</div>
                                    <div class="letter-greeting">المحترمين</div>
                                </div>
                                <p><strong>الموضوع:</strong> طلب تمديد الضمان البنكي رقم (B123456)</p>
                                <p style="margin-top: 12px;">إشارة إلى الضمان البنكي النهائي الموضح أعلاه، والصادر لصالح <span id="letterSupplier">شركة الأفق للتجارة والمقاولات</span> بمبلغ <span id="letterAmount">50,000.00 ر.س</span>...</p>
                                <p style="margin-top: 12px;">نرجو من سعادتكم التكرم بتمديد مدة الضمان لمدة إضافية حسب المطلوب.</p>
                                <p style="margin-top: 20px;">وتفضلوا بقبول فائق الاحترام والتقدير،</p>
                            </div>
                        </div>
                    </div>
                
            </div>
        </main>
        
        <!-- Attachments & Notes Panel (Left) -->
        <aside class="attachments-notes-panel">
            <div class="panel-body">
                <!-- Attachments Section -->
                <div class="attachments-section" style="margin-top: 0;">
                    <div class="section-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                        <h3 class="section-title" style="font-size: 14px; font-weight: 700; color: #1e293b; margin: 0;">📎 المستندات المرفقة</h3>
                        <button class="btn-add-attachment" style="padding: 6px 12px; background: #3b82f6; color: white; border: none; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer;">+ إضافة مستند</button>
                    </div>
                    <div class="attachments-list" style="display: flex; flex-direction: column; gap: 8px;">
                        <div class="attachment-item" style="display: flex; align-items: center; gap: 12px; padding: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                            <div class="attachment-icon" style="font-size: 24px;">📄</div>
                            <div class="attachment-info" style="flex: 1; min-width: 0;">
                                <div class="attachment-name" style="font-size: 13px; font-weight: 600; color: #1e293b; margin-bottom: 4px;">خطاب الضمان الأصلي.pdf</div>
                                <div class="attachment-meta" style="font-size: 11px; color: #64748b;">2.3 MB • تم الرفع 15 يناير 2024</div>
                            </div>
                            <div class="attachment-actions" style="display: flex; gap: 4px;">
                                <button class="btn-icon" title="تحميل" style="padding: 6px; background: white; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; cursor: pointer;">⬇️</button>
                                <button class="btn-icon" title="عرض" style="padding: 6px; background: white; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; cursor: pointer;">👁️</button>
                            </div>
                        </div>
                        <div class="attachment-item" style="display: flex; align-items: center; gap: 12px; padding: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                            <div class="attachment-icon" style="font-size: 24px;">📊</div>
                            <div class="attachment-info" style="flex: 1; min-width: 0;">
                                <div class="attachment-name" style="font-size: 13px; font-weight: 600; color: #1e293b; margin-bottom: 4px;">guarantees_dec_2024.xlsx</div>
                                <div class="attachment-meta" style="font-size: 11px; color: #64748b;">1.8 MB • تم الرفع اليوم 10:30 ص</div>
                            </div>
                            <div class="attachment-actions" style="display: flex; gap: 4px;">
                                <button class="btn-icon" title="تحميل" style="padding: 6px; background: white; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; cursor: pointer;">⬇️</button>
                                <button class="btn-icon" title="عرض" style="padding: 6px; background: white; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; cursor: pointer;">👁️</button>
                            </div>
                        </div>
                        <div class="attachment-item" style="display: flex; align-items: center; gap: 12px; padding: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                            <div class="attachment-icon" style="font-size: 24px;">🖼️</div>
                            <div class="attachment-info" style="flex: 1; min-width: 0;">
                                <div class="attachment-name" style="font-size: 13px; font-weight: 600; color: #1e293b; margin-bottom: 4px;">صورة العقد.jpg</div>
                                <div class="attachment-meta" style="font-size: 11px; color: #64748b;">856 KB • تم الرفع 20 يناير 2024</div>
                            </div>
                            <div class="attachment-actions" style="display: flex; gap: 4px;">
                                <button class="btn-icon" title="تحميل" style="padding: 6px; background: white; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; cursor: pointer;">⬇️</button>
                                <button class="btn-icon" title="عرض" style="padding: 6px; background: white; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; cursor: pointer;">👁️</button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Notes Section -->
                <div class="notes-section" style="margin-top: 24px;">
                    <div class="section-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                        <h3 class="section-title" style="font-size: 14px; font-weight: 700; color: #1e293b; margin: 0;">📝 الملاحظات والتعليقات</h3>
                    </div>
                    <div class="notes-list" style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 16px;">
                        <div class="note-item" style="padding: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                            <div class="note-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                <div class="note-author" style="display: flex; align-items: center; gap: 8px;">
                                    <span class="author-avatar" style="width: 28px; height: 28px; background: #3b82f6; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700;">أ</span>
                                    <span class="author-name" style="font-size: 13px; font-weight: 600; color: #1e293b;">أحمد المالكي</span>
                                </div>
                                <div class="note-time" style="font-size: 11px; color: #64748b;">اليوم 10:42 ص</div>
                            </div>
                            <div class="note-content" style="font-size: 12px; line-height: 1.6; color: #475569;">
                                تم استيراد الضمان من ملف Excel. يحتاج مراجعة بيانات المورد والبنك للتأكد من الدقة.
                            </div>
                        </div>
                        <div class="note-item" style="padding: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                            <div class="note-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                <div class="note-author" style="display: flex; align-items: center; gap: 8px;">
                                    <span class="author-avatar" style="width: 28px; height: 28px; background: #3b82f6; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700;">م</span>
                                    <span class="author-name" style="font-size: 13px; font-weight: 600; color: #1e293b;">محمد العتيبي</span>
                                </div>
                                <div class="note-time" style="font-size: 11px; color: #64748b;">15 يناير 2024</div>
                            </div>
                            <div class="note-content" style="font-size: 12px; line-height: 1.6; color: #475569;">
                                تم إصدار الخطاب بنجاح. المبلغ: 50,000 ريال. تاريخ الانتهاء: 30 ديسمبر 2025.
                            </div>
                        </div>
                    </div>
                    <div class="note-input-area" style="display: flex; flex-direction: column; gap: 8px;">
                        <textarea class="note-input" placeholder="أضف ملاحظة جديدة..." style="width: 100%; min-height: 80px; padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; font-family: inherit; font-size: 12px; resize: vertical;"></textarea>
                        <button class="btn-add-note" style="align-self: flex-end; padding: 8px 16px; background: #3b82f6; color: white; border: none; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer;">إضافة ملاحظة</button>
                    </div>
                </div>
            </div>
        </aside>
        
    </div>
    
    <!-- Action Bar (Bottom) -->
    <div class="action-bar">
        <div class="action-more">
            <button class="btn-more" @click="showMore = !showMore">
                المزيد ▼
            </button>
            <!-- Dropdown menu can be added here -->
        </div>
        <div class="action-primary">
            <button class="btn-secondary">⬅️ السابق</button>
            <button class="btn-primary">
                💾 حفظ وانتقل للتالي
                ➡️
            </button>
        </div>
    </div>
    
</body>
</html>
