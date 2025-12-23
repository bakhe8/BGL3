<?php
/**
 * Unified Workflow v3.0 - Clean Rebuild
 * =====================================
 * 
 * Timeline-First approach with clean, maintainable code
 * Built from scratch following design system principles
 * 
 * @version 3.0.0
 * @date 2025-12-23
 * @author BGL Team
 */

// Mock data for prototype
header('Content-Type: text/html; charset=utf-8');
$mockRecord = [
    'id' => 14180,
    'session_id' => 517,
    'guarantee_number' => 'BG-2024-12345',
    'supplier_name' => 'شركة الاختبار التجريبية',
    'bank_name' => 'البنك الأهلي السعودي',
    'amount' => 500000,
    'expiry_date' => '2025-06-30',
    'issue_date' => '2024-01-15',
    'contract_number' => 'CNT-2024-001',
    'type' => 'ابتدائي',
    'status' => 'pending'
];

$mockTimeline = [
    [
        'id' => 7,
        'type' => 'release',
        'date' => '2025-01-15 11:45:00',
        'description' => 'إصدار إفراج',
        'details' => 'تم إصدار خطاب إفراج الضمان',
        'user' => 'أحمد محمد'
    ],
    [
        'id' => 6,
        'type' => 'extension',
        'date' => '2024-12-20 09:15:30',
        'description' => 'إصدار تمديد',
        'details' => 'تمديد صلاحية الضمان حتى 2025-12-31',
        'user' => 'سارة أحمد'
    ],
    [
        'id' => 5,
        'type' => 'bank_change',
        'date' => '2024-12-18 16:20:00',
        'description' => 'تعديل اسم البنك',
        'old_value' => 'SNB',
        'new_value' => 'البنك الأهلي السعودي',
        'user' => 'محمد علي'
    ],
    [
        'id' => 4,
        'type' => 'import',
        'date' => '2024-12-10 13:30:00',
        'description' => 'استيراد من لصق مباشر',
        'details' => 'تم لصق البيانات مباشرة من المستند',
        'user' => 'فاطمة حسن'
    ],
    [
        'id' => 3,
        'type' => 'supplier_change',
        'date' => '2024-12-05 14:20:30',
        'description' => 'تعديل اسم المورد',
        'old_value' => 'شركة الاختبار',
        'new_value' => 'شركة الاختبار التجريبية',
        'user' => 'سارة أحمد'
    ],
    [
        'id' => 2,
        'type' => 'status_change',
        'date' => '2024-12-01 10:30:16',
        'description' => 'مطابقة تلقائية',
        'details' => 'تمت مطابقة المورد والبنك تلقائياً',
        'confidence' => 95
    ],
    [
        'id' => 1,
        'type' => 'import',
        'date' => '2024-12-01 10:30:15',
        'description' => 'استيراد من ملف Excel',
        'details' => 'ملف: guarantees_dec_2024.xlsx',
        'user' => 'أحمد محمد'
    ]
];

$mockCandidates = [
    'suppliers' => [
        [
            'id' => 1,
            'name' => 'شركة الاختبار التجريبية',
            'confidence' => 95,
            'usage_count' => 15,
            'source' => 'learned'
        ],
        [
            'id' => 2,
            'name' => 'شركة الاختبار',
            'confidence' => 85,
            'usage_count' => 3,
            'source' => 'excel'
        ]
    ],
    'banks' => [
        [
            'id' => 1,
            'name' => 'البنك الأهلي السعودي',
            'short_code' => 'SNB',
            'confidence' => 95,
            'usage_count' => 42,
            'source' => 'learned'
        ]
    ]
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unified Workflow v3.0 - BGL</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    
    <style>
        /* ═══════════════════════════════════════════════════════════════
           DESIGN SYSTEM - CSS VARIABLES
           ═══════════════════════════════════════════════════════════════ */
        :root {
            /* Colors */
            --bg-body: #f1f5f9;
            --bg-card: #ffffff;
            --bg-secondary: #f8fafc;
            --bg-neutral: #fafbfc;
            --bg-hover: #f8fafc;
            
            --border-primary: #e2e8f0;
            --border-light: #f1f5f9;
            --border-neutral: #cbd5e1;
            --border-focus: #3b82f6;
            
            --text-primary: #1e293b;
            --text-secondary: #475569;
            --text-muted: #64748b;
            --text-light: #94a3b8;
            
            --accent-primary: #3b82f6;
            --accent-primary-hover: #2563eb;
            --accent-success: #16a34a;
            --accent-warning: #d97706;
            --accent-danger: #dc2626;
            
            /* Spacing */
            --space-xs: 4px;
            --space-sm: 8px;
            --space-md: 16px;
            --space-lg: 24px;
            --space-xl: 32px;
            
            --gap-card: 20px;
            --gap-section: 16px;
            --gap-small: 6px;
            
            /* Typography */
            --font-family: 'Tajawal', sans-serif;
            --font-size-xs: 10px;
            --font-size-sm: 11px;
            --font-size-base: 13px;
            --font-size-lg: 15px;
            --font-size-xl: 18px;
            
            --font-weight-normal: 400;
            --font-weight-medium: 500;
            --font-weight-semibold: 600;
            --font-weight-bold: 700;
            --font-weight-black: 800;
            
            /* Dimensions */
            --width-sidebar: 290px;
            --width-timeline: 360px;
            --height-top-bar: 56px;
            --height-record-header: 48px;
            --height-action-bar: 72px;
            
            /* Border Radius */
            --radius-sm: 6px;
            --radius-md: 8px;
            --radius-lg: 12px;
            --radius-full: 50px;
            
            /* Shadows */
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 4px 20px rgba(0, 0, 0, 0.1);
            --shadow-focus: 0 0 0 3px rgba(59, 130, 246, 0.1);
            
            /* Transitions */
            --transition-fast: 0.15s ease;
            --transition-base: 0.2s ease;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           RESET & BASE
           ═══════════════════════════════════════════════════════════════ */
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        html, body {
            font-family: var(--font-family);
            height: 100%;
            -webkit-font-smoothing: antialiased;
        }
        
        
        body {
            background: var(--bg-body);
            color: var(--text-primary);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           CUSTOM SCROLLBAR - Modern & Clean
           ═══════════════════════════════════════════════════════════════ */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: transparent;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
            border: 2px solid transparent;
            background-clip: padding-box;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
            border: 2px solid transparent;
            background-clip: padding-box;
        }
        
        /* Firefox Scrollbar */
        * {
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 transparent;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           TOP BAR (Global)
           ═══════════════════════════════════════════════════════════════ */
        .top-bar {
            height: var(--height-top-bar);
            background: var(--bg-card);
            border-bottom: 1px solid var(--border-primary);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 var(--space-lg);
            box-shadow: var(--shadow-sm);
            flex-shrink: 0;
        }
        
        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: var(--font-weight-black);
            font-size: var(--font-size-xl);
            color: var(--text-primary);
        }
        
        .brand-icon {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, var(--accent-primary), #8b5cf6);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
        }
        
        .global-actions {
            display: flex;
            gap: var(--space-sm);
        }
        
        .btn-global {
            padding: 8px 16px;
            background: transparent;
            border: 1px solid var(--border-primary);
            border-radius: var(--radius-md);
            font-family: inherit;
            font-size: var(--font-size-base);
            font-weight: var(--font-weight-semibold);
            color: var(--text-muted);
            cursor: pointer;
            transition: all var(--transition-base);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: var(--space-sm);
        }
        
        .btn-global:hover {
            background: var(--bg-hover);
            border-color: var(--border-neutral);
            color: var(--text-primary);
        }
        
        /* ═══════════════════════════════════════════════════════════════
           MAIN CONTAINER
           ═══════════════════════════════════════════════════════════════ */
        .app-container {
            display: flex;
            flex: 1;
            overflow: hidden;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           SIDEBAR (Left)
           ═══════════════════════════════════════════════════════════════ */
        .sidebar {
            width: var(--width-sidebar);
            background: var(--bg-card);
            border-right: 1px solid var(--border-primary);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        
        .progress-container {
            height: 48px;
            background: var(--bg-card);
            padding: 0 var(--space-md);
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: var(--gap-small);
            border-bottom: 1px solid var(--border-primary);
        }
        
        .progress-bar {
            height: 6px;
            background: var(--border-primary);
            border-radius: 3px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--accent-primary), #8b5cf6);
            border-radius: 3px;
            transition: width 0.3s ease;
        }
        
        .progress-text {
            display: flex;
            justify-content: space-between;
            font-size: var(--font-size-sm);
            color: var(--text-muted);
        }
        
        .progress-percent {
            font-weight: var(--font-weight-bold);
            color: var(--accent-primary);
        }
        
        .sidebar-body {
            flex: 1;
            overflow-y: auto;
            padding: var(--space-md);
            background: var(--bg-secondary);
        }
        
        .sidebar-section {
            margin-bottom: var(--space-md);
            background: var(--bg-card);
            border: 1px solid var(--border-primary);
            border-radius: var(--radius-md);
            padding: var(--space-md);
        }
        
        /* Input Toolbar */
        .input-toolbar {
            padding: 16px;
            border-bottom: 1px solid var(--border-primary);
            background: #ffffff;
        }
        .toolbar-label {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            margin-bottom: 10px;
            letter-spacing: 0.5px;
        }
        .toolbar-actions {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
        }
        .btn-input {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 6px;
            background: #f8fafc;
            border: 1px solid var(--border-primary);
            border-radius: 8px;
            padding: 10px 4px;
            cursor: pointer;
            transition: all 0.2s;
            color: var(--text-secondary);
        }
        .btn-input:hover {
            background: #eff6ff;
            border-color: var(--accent-primary);
            color: var(--accent-primary);
            transform: translateY(-1px);
        }
        .btn-input span:first-child {
            font-size: 18px;
        }
        .btn-input span:last-child {
            font-size: 11px;
            font-weight: 500;
        }
        
        .sidebar-section:last-child {
            margin-bottom: 0;
        }
        
        .sidebar-section-title {
            font-size: var(--font-size-sm);
            font-weight: var(--font-weight-bold);
            color: var(--text-muted);
            margin-bottom: var(--space-md);
            padding-bottom: var(--space-sm);
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            gap: var(--gap-small);
        }
        
        /* Attachments */
        .attachment-item {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            padding: var(--space-sm);
            background: var(--bg-neutral);
            border: 1px solid var(--border-primary);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all var(--transition-fast);
            margin-bottom: var(--gap-small);
        }
        
        .attachment-item:last-child {
            margin-bottom: 0;
        }
        
        .attachment-item:hover {
            background: var(--bg-hover);
            border-color: var(--border-neutral);
        }
        
        .attachment-icon {
            width: 32px;
            height: 32px;
            background: var(--bg-secondary);
            border-radius: var(--radius-sm);
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
            font-size: var(--font-size-sm);
            font-weight: var(--font-weight-semibold);
            color: var(--text-primary);
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .attachment-meta {
            font-size: var(--font-size-xs);
            color: var(--text-light);
        }
        
        /* Notes */
        .note-item {
            padding: 10px;
            background: var(--bg-neutral);
            border: 1px solid var(--border-primary);
            border-radius: var(--radius-sm);
            border-right: 2px solid var(--border-neutral);
            margin-bottom: var(--gap-small);
        }
        
        .note-item:last-child {
            margin-bottom: 0;
        }
        
        .note-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--gap-small);
        }
        
        .note-author {
            font-size: var(--font-size-sm);
            font-weight: var(--font-weight-semibold);
            color: var(--text-secondary);
        }
        
        .note-time {
            font-size: var(--font-size-xs);
            color: var(--text-light);
        }
        
        .note-content {
            font-size: var(--font-size-sm);
            color: var(--text-muted);
            line-height: 1.5;
        }
        
        .add-note-btn {
            width: 100%;
            padding: var(--space-sm);
            background: var(--bg-card);
            border: 1px dashed var(--border-neutral);
            border-radius: var(--radius-sm);
            color: var(--text-muted);
            font-family: inherit;
            font-size: var(--font-size-sm);
            font-weight: var(--font-weight-semibold);
            cursor: pointer;
            transition: all var(--transition-fast);
            margin-top: var(--gap-small);
        }
        
        .add-note-btn:hover {
            border-color: var(--text-light);
            color: var(--text-secondary);
            background: var(--bg-neutral);
        }
        
        /* Note Input Box */
        .note-input-box {
            margin-top: var(--gap-small);
            background: white;
            border: 1px solid var(--border-primary);
            border-radius: var(--radius-md);
            padding: 12px;
            box-shadow: var(--shadow-md);
        }
        
        .note-input-box textarea {
            width: 100%;
            min-height: 80px;
            padding: 8px;
            font-family: inherit;
            font-size: var(--font-size-sm);
            color: var(--text-primary);
            background: var(--bg-secondary);
            border: 1px solid var(--border-primary);
            border-radius: var(--radius-sm);
            resize: vertical;
            transition: all var(--transition-base);
        }
        
        .note-input-box textarea:focus {
            outline: none;
            border-color: var(--border-focus);
            box-shadow: var(--shadow-focus);
        }
        
        .note-input-actions {
            display: flex;
            gap: 8px;
            margin-top: 8px;
            justify-content: flex-end;
        }
        
        .note-input-actions button {
            padding: 6px 12px;
            font-family: inherit;
            font-size: var(--font-size-sm);
            font-weight: var(--font-weight-semibold);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all var(--transition-fast);
        }
        
        .note-save-btn {
            background: var(--accent-primary);
            color: white;
            border: none;
        }
        
        .note-save-btn:hover {
            background: var(--accent-primary-hover);
        }
        
        .note-cancel-btn {
            background: var(--bg-secondary);
            color: var(--text-muted);
            border: 1px solid var(--border-primary);
        }
        
        .note-cancel-btn:hover {
            background: var(--bg-hover);
            color: var(--text-primary);
        }
        
        /* ═══════════════════════════════════════════════════════════════
           CENTER SECTION
           ═══════════════════════════════════════════════════════════════ */
        .center-section {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        /* Record Header */
        .record-header {
            height: var(--height-record-header);
            background: var(--bg-card);
            border-bottom: 1px solid var(--border-primary);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 var(--space-lg);
            flex-shrink: 0;
        }
        
        .record-title {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .record-title h1 {
            font-size: var(--font-size-lg);
            font-weight: var(--font-weight-bold);
            color: var(--text-primary);
        }
        
        /* History Banner */
        .history-banner {
            background: #fffbeb;
            border-bottom: 1px solid #fcd34d;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 13px;
            color: #92400e;
            animation: slideDown 0.3s ease-out;
        }
        .history-info {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .history-icon {
            font-size: 16px;
        }
        .history-label {
            font-weight: 700;
        }
        .history-date {
            font-family: monospace;
            font-weight: 600;
            margin: 0 4px;
            background: rgba(0,0,0,0.05);
            padding: 2px 6px;
            border-radius: 4px;
        }
        .btn-return {
            background: #ffffff;
            border: 1px solid #f59e0b;
            color: #d97706;
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-return:hover {
            background: #fef3c7;
            transform: translateY(-1px);
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .badge {
            display: inline-flex;
            align-items: center;
            gap: var(--space-xs);
            padding: 3px 10px;
            font-size: var(--font-size-xs);
            font-weight: var(--font-weight-bold);
            border-radius: var(--radius-full);
            border: 1px solid;
        }
        
        .badge-pending {
            background: #fef3c7;
            color: #d97706;
            border-color: #fde68a;
        }
        
        .record-actions {
            display: flex;
            gap: var(--space-sm);
        }
        
        /* Content Wrapper */
        .content-wrapper {
            display: flex;
            flex: 1;
            overflow: hidden;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           TIMELINE PANEL
           ═══════════════════════════════════════════════════════════════ */
        .timeline-panel {
            width: var(--width-timeline);
            background: var(--bg-card);
            border-right: 1px solid var(--border-primary);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        
        .timeline-header {
            height: var(--height-record-header);
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border-primary);
            border-left: 1px solid var(--border-primary);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 var(--space-md);
        }
        
        .timeline-title {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            font-size: var(--font-size-base);
            font-weight: var(--font-weight-bold);
            color: var(--text-secondary);
        }
        
        .timeline-count {
            font-size: var(--font-size-xs);
            color: var(--text-light);
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
            transition: all var(--transition-fast);
        }
        
        .timeline-dot.active {
            background: #3b82f6;
            box-shadow: 0 0 0 1px #3b82f6, 0 0 0 4px rgba(59, 130, 246, 0.2);
            transform: scale(1.1);
        }
        
        /* Event Card Styling */
        .event-card {
            background: #ffffff;
            border-radius: 8px;
            padding: 12px;
            border: 1px solid var(--border-light);
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
            transition: all 0.2s ease;
            cursor: pointer;
            position: relative;
        }

        .event-card:hover {
            border-color: var(--accent-primary);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.1);
            transform: translateY(-1px);
        }
        
        .event-card.current {
            background: #f0f9ff;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 1px var(--accent-primary);
        }

        .event-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
        }

        .event-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.4;
        }

        .event-desc {
            font-size: 12px;
            color: var(--text-secondary);
            margin-bottom: 8px;
            line-height: 1.5;
        }

        /* Badge Styling */
        .event-badge {
            display: inline-block;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 12px;
            background: var(--accent-primary);
            color: white;
            margin-bottom: 8px;
            box-shadow: 0 2px 4px rgba(59, 130, 246, 0.2);
        }

        /* Meta Data (Date & User) */
        .event-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 8px;
            border-top: 1px solid rgba(0,0,0,0.04);
            font-size: 11px;
            color: var(--text-light);
        }
        
        /* Diff View Styling */
        .diff-view {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
            background: #f8fafc;
            padding: 6px 8px;
            border-radius: 6px;
            margin-bottom: 8px;
            border: 1px dashed var(--border-neutral);
        }
        
        .diff-old {
            color: var(--text-muted);
            text-decoration: line-through;
            font-size: 11px;
        }
        
        .diff-arrow {
            color: var(--text-light);
            font-size: 10px;
        }
        
        .diff-new {
            color: var(--accent-success);
            font-weight: 600;
            font-size: 11px;
            background: rgba(22, 163, 74, 0.1);
            padding: 1px 4px;
            border-radius: 4px;
        }

        
        /* ═══════════════════════════════════════════════════════════════
           MAIN CONTENT
           ═══════════════════════════════════════════════════════════════ */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            overflow-x: hidden;
            padding: var(--space-lg);
            background: var(--bg-body);
            min-height: 0;
        }
        
        .main-content > * {
            flex-shrink: 0;
        }
        
        /* Decision Card */
        .decision-card {
            background: var(--bg-card);
            border: 1px solid var(--border-primary);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
        }
        
        .card-header {
            height: 44px;
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border-primary);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 var(--space-md);
        }
        
        .card-title {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            font-size: var(--font-size-base);
            font-weight: var(--font-weight-bold);
            color: var(--text-secondary);
        }
        
        .header-actions {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
        }
        
        .card-body {
            padding: var(--space-lg);
            display: flex;
            flex-direction: column;
            gap: var(--gap-card);
        }
        
        /* Field Group */
        .field-group {
            display: flex;
            flex-direction: column;
            gap: var(--space-sm);
        }
        
        .field-row {
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: var(--space-md);
        }
        
        .field-label {
            font-size: var(--font-size-base);
            font-weight: 600;
            color: var(--text-primary);
            min-width: 80px;
            flex-shrink: 0;
        }
        
        .field-input {
            flex: 1;
            padding: 10px 12px;
            font-family: inherit;
            font-size: var(--font-size-base);
            color: var(--text-primary);
            background: var(--bg-card);
            border: 1px solid var(--border-primary);
            border-radius: var(--radius-md);
            transition: all var(--transition-base);
        }
        
        .field-input:hover {
            border-color: #93c5fd;
        }
        
        .field-input:focus {
            outline: none;
            border-color: var(--border-focus);
            box-shadow: var(--shadow-focus);
        }
        
        /* Chips */
        .chips-row {
            display: flex;
            flex-wrap: wrap;
            gap: var(--gap-small);
            margin-top: var(--space-xs);
            margin-right: calc(80px + var(--space-md));
        }
        
        .chip {
            display: inline-flex;
            align-items: center;
            gap: var(--space-xs);
            padding: 5px 10px;
            font-size: var(--font-size-sm);
            font-weight: var(--font-weight-semibold);
            border-radius: var(--radius-full);
            border: 1px solid;
            cursor: pointer;
            transition: all var(--transition-base);
            background: transparent;
            font-family: inherit;
        }
        
        .chip-selected {
            background: #dcfce7;
            color: var(--accent-success);
            border-color: #86efac;
        }
        
        .chip-candidate {
            background: var(--bg-secondary);
            color: var(--text-muted);
            border-color: var(--border-primary);
        }
        
        .chip-candidate:hover {
            background: #eff6ff;
            border-color: #93c5fd;
            color: var(--accent-primary);
        }
        
        .chip-source {
            font-size: var(--font-size-xs);
            opacity: 0.8;
        }
        
        .field-hint {
            display: flex;
            align-items: center;
            gap: var(--space-md);
            margin-top: var(--gap-small);
            margin-right: calc(80px + var(--space-md));
            font-size: var(--font-size-xs);
            color: var(--text-light);
        }
        
        .hint-group {
            display: flex;
            align-items: center;
            gap: var(--gap-small);
        }
        
        .hint-label {
            color: var(--text-muted);
        }
        
        .hint-value {
            font-weight: 600;
            color: var(--text-secondary);
        }
        
        .hint-score {
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--accent-success); /* تم التصحيح لاستخدام المتغير الصحيح */
            font-weight: 600;
        }

        .hint-divider {
            color: var(--border-neutral);
        }

        .hint-dot {
            width: 6px;
            height: 6px;
            background: var(--accent-success);
            border-radius: 50%;
        }
        
        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: var(--space-md);
            padding: var(--space-md);
            background: var(--bg-secondary);
            border: 1px solid var(--border-primary);
            border-radius: var(--radius-md);
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
            gap: var(--space-xs);
        }
        
        .info-label {
            font-size: var(--font-size-sm);
            font-weight: var(--font-weight-semibold);
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .info-value {
            font-size: var(--font-size-base);
            font-weight: var(--font-weight-semibold);
            color: var(--text-primary);
        }
        
        .info-value.highlight {
            color: var(--accent-success);
            font-size: var(--font-size-lg);
        }
        
        .card-footer {
            background: var(--bg-secondary);
            border-top: 1px solid var(--border-primary);
            padding: 14px var(--space-lg);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: var(--space-sm);
            padding: 10px 18px;
            font-family: inherit;
            font-size: var(--font-size-base);
            font-weight: var(--font-weight-bold);
            border-radius: var(--radius-md);
            border: none;
            cursor: pointer;
            transition: all var(--transition-base);
            white-space: nowrap;
        }
        
        .btn-sm {
            padding: 6px 14px;
            font-size: var(--font-size-sm);
        }
        
        .btn-primary {
            background: var(--accent-primary);
            color: white;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
        }
        
        .btn-primary:hover {
            background: var(--accent-primary-hover);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
        }
        
        .btn-secondary {
            background: var(--bg-card);
            color: var(--text-muted);
            border: 1px solid var(--border-primary);
        }
        
        .btn-secondary:hover {
            background: var(--bg-hover);
            border-color: var(--border-neutral);
            color: var(--text-primary);
        }
        
        .btn-ghost {
            background: transparent;
            color: var(--text-muted);
            border: 1px solid var(--border-primary);
        }
        
        .btn-ghost:hover {
            background: var(--bg-hover);
            border-color: var(--border-neutral);
        }
        
        /* Preview Section */
        .preview-section {
            margin-top: var(--space-lg);
            background: var(--bg-card);
            border: 1px solid var(--border-primary);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
        }
        
        .preview-header {
            height: 36px;
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border-primary);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 14px;
        }
        
        .preview-title {
            font-size: var(--font-size-sm);
            font-weight: var(--font-weight-bold);
            color: var(--text-muted);
        }
        
        .preview-print {
            font-size: var(--font-size-sm);
            color: var(--accent-primary);
            font-weight: var(--font-weight-semibold);
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: var(--radius-sm);
            transition: background var(--transition-fast);
        }
        
        .preview-print:hover {
            background: var(--bg-hover);
        }
        
        .preview-body {
            padding: var(--space-lg);
            background: var(--bg-secondary);
            display: flex;
            justify-content: center;
        }
        
        .letter-paper {
            background: white;
            width: 100%;
            max-width: 480px;
            padding: 32px;
            box-shadow: var(--shadow-lg);
            font-size: var(--font-size-base);
            line-height: 1.8;
            color: #374151;
            border-radius: var(--radius-sm);
        }
        
        .letter-header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--border-primary);
        }
        
        .letter-to {
            font-weight: var(--font-weight-bold);
            font-size: var(--font-size-lg);
            color: var(--text-primary);
            margin-bottom: var(--space-sm);
        }
        
        .letter-greeting {
            color: var(--text-muted);
            font-size: var(--font-size-base);
        }
        
        .letter-body {
            margin: var(--space-lg) 0;
        }
        
        .letter-body p {
            margin-bottom: var(--space-md);
        }
        
        /* ═══════════════════════════════════════════════════════════════
           ACTION BAR (Bottom)
           ═══════════════════════════════════════════════════════════════ */
        .action-bar {
            height: var(--height-action-bar);
            background: var(--bg-card);
            border-top: 1px solid var(--border-primary);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 var(--space-lg);
            box-shadow: 0 -1px 3px rgba(0, 0, 0, 0.05);
            flex-shrink: 0;
        }
        
        .primary-actions {
            display: flex;
            gap: 12px;
        }
        
        /* Alpine.js Cloak */
        [x-cloak] {
            display: none !important;
        }
    </style>
</head>
<body x-data="unifiedWorkflow()">
    
    <!-- Top Bar (Global) -->
    <header class="top-bar">
        <div class="brand">
            <div class="brand-icon">&#x1F4CB;</div>
            <span>نظام إدارة الضمانات</span>
        </div>
        <nav class="global-actions">
            <a href="/settings.php" class="btn-global">&#x2699; إعدادات</a>
            <a href="/stats" class="btn-global">&#x1F4CA; إحصائيات</a>
            <a href="/lab" class="btn-global">&#x1F9EA; المختبر</a>
        </nav>
    </header>

    <!-- Main Container -->
    <div class="app-container">
        
        <!-- Center Section -->
        <div class="center-section">
            
            <!-- Record Header -->
            <header class="record-header">
                <div class="record-title">
                    <h1>ضمان رقم <?= $mockRecord['guarantee_number'] ?></h1>
                    <span class="badge badge-pending">يحتاج قرار</span>
                </div>
                <div class="record-actions">
                    <button class="btn btn-secondary btn-sm">&#x1F4BE; حفظ</button>
                    <button class="btn btn-secondary btn-sm">&#x1F504; تمديد</button>
                    <button class="btn btn-secondary btn-sm">&#x1F4C9; تخفيض</button>
                    <button class="btn btn-secondary btn-sm">&#x1F4E4; إفراج</button>
                </div>
            </header>

            <!-- Content Wrapper -->
            <div class="content-wrapper">
                
                <!-- Timeline Panel -->
                <aside class="timeline-panel">
                    <header class="timeline-header">
                        <div class="timeline-title">
                            <span></span>
                            <span>السجل</span>
                        </div>
                        <span class="timeline-count"><?= count($mockTimeline) ?> أحداث</span>
                    </header>
                    <div class="timeline-body">
                        <div class="timeline-list">
                            <div class="timeline-line"></div>
                            
                            <template x-for="(event, index) in timelineEvents" :key="event.id">
                                <div class="timeline-item" @click="selectEvent(event)" style="cursor: pointer;">
                                    <div class="timeline-dot" :class="{ 'active': activeEventId === event.id }"></div>
                                    <div class="event-card" :class="{ 'current': activeEventId === event.id }">
                                        <!-- Badge for current item (optional logic) -->
                                        <template x-if="index === 0">
                                            <span class="event-badge">الآن</span>
                                        </template>

                                        <div class="event-header">
                                            <span class="event-title" x-text="event.description"></span>
                                        </div>
                                        
                                        <!-- Show Details if available -->
                                        <template x-if="event.details">
                                            <div class="event-desc" x-text="event.details"></div>
                                        </template>
                                        
                                        <!-- Show Change Diff if available -->
                                        <template x-if="event.old_value">
                                            <div class="diff-view">
                                                <span class="diff-old" x-text="event.old_value"></span>
                                                <span class="diff-arrow">←</span>
                                                <span class="diff-new" x-text="event.new_value"></span>
                                            </div>
                                        </template>
                                        
                                        <div class="event-meta">
                                            <div class="meta-row">
                                                <!-- Other meta info if needed -->
                                            </div>
                                            <span class="event-date" x-text="event.date"></span>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </aside>

                <!-- Main Content -->
                <main class="main-content">
                    <!-- Decision Card -->
                    <div class="decision-card">
                        
                        <!-- History View Warning Banner -->
                        <div class="history-banner" x-show="activeEventId !== timelineEvents[0].id" x-transition x-cloak>
                            <div class="history-info">
                                <span class="history-icon">&#x23EA;</span>
                                <div>
                                    <span class="history-label">وضع الأرشيف:</span>
                                    <span>أنت تستعرض حالة البيانات كما كانت بتاريخ </span>
                                    <span class="history-date" x-text="timelineEvents.find(e => e.id === activeEventId)?.date"></span>
                                </div>
                            </div>
                            <button class="btn-return" @click="selectEvent(timelineEvents[0])">
                                العودة للوضع الحالي
                            </button>
                        </div>

                        <header class="card-header">
                            <div class="card-title">
                                <span>&#x1F4CB;</span>
                                <span>بيانات الضمان</span>
                            </div>
                            <button class="btn btn-ghost btn-sm" @click="togglePreview">
                                <span x-text="showPreview ? '&#x1F53C;' : '&#x1F441;'"></span>
                                <span x-text="showPreview ? 'إخفاء المعاينة' : 'معاينة الخطاب'"></span>
                            </button>
                        </header>
                        <div class="card-body">
                            <!-- Supplier Field -->
                            <div class="field-group">
                                <div class="field-row">
                                    <label class="field-label">المورد</label>
                                    <input type="text" class="field-input" 
                                           x-model="record.supplier_name">
                                </div>
                                <div class="chips-row">
                                    <?php foreach ($mockCandidates['suppliers'] as $idx => $supplier): ?>
                                    <button class="chip <?= $idx === 0 ? 'chip-selected' : 'chip-candidate' ?>">
                                        <?= $idx === 0 ? '&#x2B50;&#x2B50;&#x2B50; ' : '' ?><?= $supplier['name'] ?>
                                        <?php if ($supplier['source'] !== 'learned'): ?>
                                        <span class="chip-source">
                                            <?= "{$supplier['confidence']}%" ?>
                                        </span>
                                        <?php endif; ?>
                                    </button>
                                    <?php endforeach; ?>
                                </div>
                                <div class="field-hint">
                                    <div class="hint-group">
                                        <span class="hint-label">Excel:</span>
                                        <span class="hint-value">شركة الاختبار التجريبية</span>
                                    </div>
                                    <div class="hint-divider">|</div>
                                    <div class="hint-score">
                                        <div class="hint-dot"></div>
                                        <span>تطابق 95%</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Bank Field -->
                            <div class="field-group">
                                <div class="field-row">
                                    <label class="field-label">البنك</label>
                                    <input type="text" class="field-input" 
                                           x-model="record.bank_name">
                                </div>
                                <div class="chips-row">
                                    <?php foreach ($mockCandidates['banks'] as $idx => $bank): ?>
                                    <button class="chip chip-selected">
                                        &#x2B50;&#x2B50;&#x2B50; <?= $bank['name'] ?>
                                    </button>
                                    <?php endforeach; ?>
                                </div>
                                <div class="field-hint">
                                    <div class="hint-group">
                                        <span class="hint-label">Excel:</span>
                                        <span class="hint-value">SNB</span>
                                    </div>
                                    <div class="hint-divider">|</div>
                                    <div class="hint-score">
                                        <div class="hint-dot"></div>
                                        <span>تطابق 100%</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Info Grid -->
                            <div class="info-grid">
                                <div class="info-item">
                                    <div class="info-label">المبلغ</div>
                                    <div class="info-value highlight"><span x-text="Number(record.amount).toLocaleString('en-US')"></span> ر.س</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">تاريخ الانتهاء</div>
                                    <div class="info-value" x-text="record.expiry_date"></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">رقم العقد</div>
                                    <div class="info-value" x-text="record.contract_number"></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">تاريخ الإصدار</div>
                                    <div class="info-value" x-text="record.issue_date"></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">النوع</div>
                                    <div class="info-value" x-text="record.type"></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">الحالة</div>
                                    <div class="info-value">نشط</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Preview Section (togglable) -->
                    <div class="preview-section" x-show="showPreview" x-cloak x-transition>
                        <header class="preview-header">
                            <span class="preview-title">&#x1F4C4; معاينة خطاب التمديد</span>
                            <button class="preview-print">&#x1F5A8; طباعة</button>
                        </header>
                        <div class="preview-body">
                            <div class="letter-paper">
                                <div class="letter-header">
                                    <div class="letter-to">إلى: <span x-text="record.bank_name"></span></div>
                                    <div class="letter-greeting">السلام عليكم ورحمة الله وبركاته</div>
                                </div>
                                <div class="letter-body">
                                    <p><strong>الموضوع:</strong> طلب تمديد الضمان البنكي رقم <span x-text="record.guarantee_number"></span></p>
                                    
                                    <p>نشير إلى الضمان البنكي <span x-text="record.type"></span> المشار إليه أعلاه والصادر لصالحنا من قبلكم بتاريخ <span x-text="record.issue_date"></span> بمبلغ وقدره <strong><span x-text="Number(record.amount).toLocaleString('en-US')"></span> ريال سعودي</strong> لصالح المورد <strong><span x-text="record.supplier_name"></span></strong> بموجب العقد رقم <span x-text="record.contract_number"></span>.</p>
                                    
                                    <p>نرجو التكرم بتمديد صلاحية الضمان المذكور أعلاه لمدة إضافية حتى تاريخ <strong><span x-text="record.expiry_date"></span></strong>.</p>
                                    
                                    <p>شاكرين لكم حسن تعاونكم،،،</p>
                                </div>
                                <div style="margin-top: 40px; text-align: left;">
                                    <p><strong>مستشفى الملك فيصل التخصصي</strong></p>
                                    <p>قسم الشؤون المالية</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </main>

            </div>
        </div>

        <!-- Sidebar (Left) -->
        <aside class="sidebar">
            
            <!-- Input Actions (New Proposal) -->
            <div class="input-toolbar">
                <div class="toolbar-label">إدخال جديد</div>
                <div class="toolbar-actions">
                    <button class="btn-input" title="إدخال يدوي" @click="showManualInput = true">
                        <span>&#x270D;</span>
                        <span>يدوي</span>
                    </button>
                    <button class="btn-input" title="رفع ملف Excel" @click="showImportModal = true">
                        <span>&#x1F4CA;</span>
                        <span>ملف</span>
                    </button>
                    <button class="btn-input" title="لصق بيانات" @click="showPasteModal = true">
                        <span>&#x1F4CB;</span>
                        <span>لصق</span>
                    </button>
                </div>
            </div>

            <!-- Progress -->
            <div class="progress-container">
                <div class="progress-bar">
                    <div class="progress-fill" :style="`width: ${progress}%`"></div>
                </div>
                <div class="progress-text">
                    <span>سجل <span x-text="currentIndex"></span> من <span x-text="totalRecords"></span></span>
                    <span class="progress-percent" x-text="`${progress}%`"></span>
                </div>
            </div>
            
            <!-- Sidebar Body -->
            <div class="sidebar-body">
                <!-- Attachments -->
                <section class="sidebar-section">
                    <h3 class="sidebar-section-title">&#x1F4CE; المستندات المرفقة</h3>
                    <div class="attachments-list">
                        <div class="attachment-item">
                            <div class="attachment-icon">📄</div>
                            <div class="attachment-info">
                                <div class="attachment-name">original_request.pdf</div>
                                <div class="attachment-meta">2.3 MB • قبل 5 أيام</div>
                            </div>
                        </div>
                        <div class="attachment-item">
                            <div class="attachment-icon">📊</div>
                            <div class="attachment-info">
                                <div class="attachment-name">contract_details.xlsx</div>
                                <div class="attachment-meta">856 KB • قبل أسبوع</div>
                            </div>
                        </div>
                    </div>
                    <button class="add-note-btn" style="margin-top: 12px;">
                        📎 إضافة مستند
                    </button>
                </section>
                
                <!-- Notes -->
                <section class="sidebar-section">
                    <h3 class="sidebar-section-title">📝 الملاحظات</h3>
                    <div class="notes-list">
                        <div class="note-item">
                            <div class="note-header">
                                <span class="note-author">أحمد محمد</span>
                                <span class="note-time">قبل ساعتين</span>
                            </div>
                            <div class="note-content">
                                يرجى التحقق من المبلغ مع القسم المالي قبل إصدار خطاب التمديد
                            </div>
                        </div>
                        <div class="note-item">
                            <div class="note-header">
                                <span class="note-author">سارة أحمد</span>
                                <span class="note-time">أمس</span>
                            </div>
                            <div class="note-content">
                                تم التأكد من صحة اسم المورد مع قسم المشتريات
                            </div>
                        </div>
                    </div>
                    
                    <!-- Note Input Box (expandable) -->
                    <div x-data="{ showNoteInput: false, newNote: '' }">
                        <div x-show="showNoteInput" x-cloak x-transition class="note-input-box">
                            <textarea 
                                x-model="newNote" 
                                placeholder="اكتب ملاحظتك هنا..."
                                x-ref="noteTextarea"
                            ></textarea>
                            <div class="note-input-actions">
                                <button 
                                    class="note-cancel-btn" 
                                    @click="showNoteInput = false; newNote = ''"
                                >
                                    إلغاء
                                </button>
                                <button 
                                    class="note-save-btn"
                                    @click="showNoteInput = false; newNote = ''"
                                >
                                    حفظ
                                </button>
                            </div>
                        </div>
                        <button 
                            class="add-note-btn" 
                            @click="showNoteInput = true; $nextTick(() => $refs.noteTextarea.focus())"
                        >
                            + إضافة ملاحظة
                        </button>
                    </div>
                </section>
            </div>
        </aside>

    </div>

    <script>
    function unifiedWorkflow() {
        return {
            // State
            currentIndex: 1,
            totalRecords: 63,
            showPreview: false,
            activeEventId: <?= $mockTimeline[0]['id'] ?>,
            
            // Data
            record: {
                guarantee_number: '<?= $mockRecord['guarantee_number'] ?>',
                issue_date: '<?= $mockRecord['issue_date'] ?>',
                amount: <?= $mockRecord['amount'] ?>,
                supplier_name: '<?= $mockRecord['supplier_name'] ?>',
                bank_name: '<?= $mockRecord['bank_name'] ?>',
                contract_number: '<?= $mockRecord['contract_number'] ?>',
                expiry_date: '2025-06-30',
                type: '<?= $mockRecord['type'] ?>'
            },
            
            // Mock Data for Simulation
            timelineEvents: <?= json_encode($mockTimeline) ?>,
            
            // Computed
            get progress() {
                return Math.round((this.currentIndex / this.totalRecords) * 100);
            },
            
            // Methods
            togglePreview() {
                this.showPreview = !this.showPreview;
            },
            
            selectEvent(event) {
                this.activeEventId = event.id;
                console.log('Selected Event ID:', event.id);
                
                // Logic to simulate "Time Travel" based on Event ID
                // Events are ordered: 6 (Newest) -> 1 (Oldest)
                
                // 1. Reset to Base State (Oldest known values)
                let tempState = {
                    supplier_name: 'شركة الاختبار', // Old value from event 3
                    bank_name: 'SNB',               // Old value from event 5
                    amount: 500000,
                    expiry_date: '2025-06-30'
                };

                // 2. Apply changes sequentially up to the selected event
                // We iterate from Oldest (ID 1) to Selected ID
                const allEvents = this.timelineEvents.slice().reverse(); // Make it 1 -> 6
                
                for (let e of allEvents) {
                    if (e.id > event.id) break; // Stop if we passed the selected event
                    
                    console.log('Applying event:', e.type, e.id);

                    // Apply this event's changes based on TYPE
                    if (e.type === 'supplier_change') {
                        tempState.supplier_name = 'شركة الاختبار التجريبية'; // New Value
                    }
                    else if (e.type === 'bank_change') {
                        tempState.bank_name = 'البنك الأهلي السعودي'; // New Value
                    }
                    else if (e.type === 'extension') {
                        tempState.expiry_date = '2025-12-31'; // Extended Date
                    }
                }

                // 3. Update the View (Spread object to trigger reactivity)
                this.record = {
                    ...this.record,
                    supplier_name: tempState.supplier_name,
                    bank_name: tempState.bank_name,
                    amount: tempState.amount,
                    expiry_date: tempState.expiry_date
                };
                
                console.log('Record updated:', this.record);
            },
            
            previousRecord() {
                if (this.currentIndex > 1) {
                    this.currentIndex--;
                    console.log('Navigate to previous record:', this.currentIndex);
                }
            },
            
            saveAndNext() {
                console.log('Saving record:', this.record);
                if (this.currentIndex < this.totalRecords) {
                    this.currentIndex++;
                    console.log('Navigate to next record:', this.currentIndex);
                }
            },
            
            // Lifecycle
            init() {
                console.log('Unified Workflow v3.0 initialized');
                console.log('Current record:', this.currentIndex, '/', this.totalRecords);
            }
        }
    }
    </script>
</body>
</html>
