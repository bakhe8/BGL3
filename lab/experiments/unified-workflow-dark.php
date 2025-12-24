<?php
/**
 * Experiment: Unified Workflow (Premium Edition)
 * ===============================================
 * 
 * تجربة موحدة بتصميم premium ذاتي التنسيق
 * لا يعتمد على Tailwind - CSS مدمج بالكامل
 * 
 * Design Decisions:
 * - Batch Print: Hidden
 * - Propagation: Auto with manual override
 * - Letter Preview: On-demand
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
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        html, body {
            font-family: 'Tajawal', sans-serif;
            min-height: 100vh;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }
        
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);
            color: #f1f5f9;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           LAYOUT
           ═══════════════════════════════════════════════════════════════ */
        .app-container {
            display: flex;
            min-height: 100vh;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           SIDEBAR
           ═══════════════════════════════════════════════════════════════ */
        .sidebar {
            width: 260px;
            background: rgba(15, 23, 42, 0.9);
            backdrop-filter: blur(20px);
            border-left: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        
        .sidebar-header {
            height: 64px;
            display: flex;
            align-items: center;
            padding: 0 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            gap: 12px;
        }
        
        .logo-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 18px;
            color: white;
            box-shadow: 0 8px 20px -4px rgba(59, 130, 246, 0.4);
        }
        
        .logo-text {
            font-weight: 700;
            font-size: 18px;
        }
        
        .logo-subtitle {
            font-size: 11px;
            color: #64748b;
            margin-top: -2px;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            padding: 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        
        .stat-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            padding: 12px;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.05);
            transition: all 0.3s ease;
            cursor: default;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            background: rgba(255, 255, 255, 0.08);
        }
        
        .stat-number {
            font-size: 28px;
            font-weight: 800;
        }
        
        .stat-number.pending {
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .stat-number.ready {
            color: #34d399;
        }
        
        .stat-label {
            font-size: 11px;
            color: #64748b;
            margin-top: 2px;
        }
        
        /* Queue */
        .queue-section {
            flex: 1;
            overflow-y: auto;
            padding: 16px 12px;
        }
        
        .queue-title {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: #64748b;
            margin-bottom: 12px;
            padding: 0 8px;
        }
        
        .queue-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border-radius: 12px;
            color: #94a3b8;
            text-decoration: none;
            transition: all 0.2s ease;
            margin-bottom: 4px;
        }
        
        .queue-item:hover {
            background: rgba(255, 255, 255, 0.05);
            color: white;
            transform: translateX(-4px);
        }
        
        .queue-item.active {
            background: linear-gradient(90deg, rgba(59, 130, 246, 0.2), transparent);
            border-right: 3px solid #3b82f6;
            color: white;
        }
        
        .queue-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        
        .queue-dot.orange { background: #f59e0b; }
        .queue-dot.green { background: #34d399; }
        
        .queue-dot.pulse {
            animation: pulse-dot 2s infinite;
            position: relative;
        }
        
        .queue-dot.pulse::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 50%;
            background: #f59e0b;
            animation: ping 1.5s infinite;
        }
        
        @keyframes ping {
            75%, 100% { transform: scale(2); opacity: 0; }
        }
        
        .queue-info {
            flex: 1;
            min-width: 0;
        }
        
        .queue-name {
            font-weight: 700;
            font-size: 14px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .queue-company {
            font-size: 11px;
            color: #64748b;
        }
        
        .queue-badge {
            font-size: 10px;
            padding: 4px 10px;
            border-radius: 50px;
            background: rgba(245, 158, 11, 0.2);
            color: #fbbf24;
            font-weight: 600;
        }
        
        .queue-divider {
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            margin: 16px 8px;
        }
        
        /* Sidebar Bottom */
        .sidebar-bottom {
            padding: 12px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }
        
        .sidebar-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border-radius: 12px;
            color: #94a3b8;
            background: none;
            border: none;
            width: 100%;
            font-family: inherit;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .sidebar-btn:hover {
            background: rgba(255, 255, 255, 0.05);
            color: white;
        }
        
        .sidebar-btn svg {
            width: 20px;
            height: 20px;
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
            height: 56px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(0, 0, 0, 0.08);
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
            font-size: 18px;
            font-weight: 700;
            color: #1e293b;
        }
        
        .status-badge {
            font-size: 11px;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 50px;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            box-shadow: 0 4px 12px -2px rgba(245, 158, 11, 0.4);
        }
        
        .topbar-actions {
            display: flex;
            gap: 8px;
        }
        
        .topbar-btn {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            border: none;
            background: none;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .topbar-btn:hover {
            background: #eff6ff;
            color: #3b82f6;
        }
        
        .topbar-btn svg {
            width: 20px;
            height: 20px;
        }
        
        /* Content Area */
        .content-area {
            flex: 1;
            overflow-y: auto;
            padding: 32px;
        }
        
        .content-wrapper {
            max-width: 1000px;
            margin: 0 auto;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           DECISION CARD
           ═══════════════════════════════════════════════════════════════ */
        .decision-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 24px;
            box-shadow: 
                0 25px 50px -12px rgba(0, 0, 0, 0.25),
                0 0 0 1px rgba(255, 255, 255, 0.1);
            overflow: hidden;
            margin-bottom: 24px;
            transition: all 0.3s ease;
        }
        
        .decision-card:hover {
            transform: translateY(-4px);
        }
        
        .card-header {
            height: 56px;
            background: linear-gradient(90deg, rgba(239, 246, 255, 0.8), transparent);
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
        }
        
        .card-header-title {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 700;
            font-size: 14px;
            color: #1e293b;
        }
        
        .header-icon {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 16px -4px rgba(59, 130, 246, 0.4);
        }
        
        .header-icon svg {
            width: 18px;
            height: 18px;
            color: white;
        }
        
        /* Propagation Toggle */
        .propagation-toggle {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f1f5f9;
            padding: 8px 16px;
            border-radius: 50px;
            cursor: pointer;
            user-select: none;
        }
        
        .toggle-switch {
            width: 36px;
            height: 20px;
            background: #cbd5e1;
            border-radius: 50px;
            position: relative;
            transition: all 0.3s ease;
        }
        
        .toggle-switch.active {
            background: #3b82f6;
        }
        
        .toggle-switch::after {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            background: white;
            border-radius: 50%;
            top: 2px;
            right: 2px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        
        .toggle-switch.active::after {
            transform: translateX(-16px);
        }
        
        .toggle-label {
            font-size: 12px;
            color: #64748b;
            font-weight: 500;
        }
        
        /* Card Body */
        .card-body {
            padding: 32px;
        }
        
        .fields-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 160px;
            gap: 24px;
            margin-bottom: 32px;
        }
        
        .field-group {
            display: flex;
            flex-direction: column;
        }
        
        .field-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #94a3b8;
            margin-bottom: 8px;
        }
        
        .field-input {
            font-family: inherit;
            font-size: 18px;
            font-weight: 700;
            color: #1e293b;
            background: rgba(255, 255, 255, 0.5);
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            padding: 14px 18px;
            transition: all 0.2s ease;
        }
        
        .field-input:hover {
            border-color: #93c5fd;
        }
        
        .field-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15);
        }
        
        /* Chips */
        .chips-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
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
            transition: all 0.2s ease;
            border: none;
            font-family: inherit;
        }
        
        .chip.selected {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            box-shadow: 0 6px 16px -4px rgba(16, 185, 129, 0.5);
        }
        
        .chip.candidate {
            background: white;
            border: 1px solid #e2e8f0;
            color: #64748b;
        }
        
        .chip.candidate:hover {
            border-color: #93c5fd;
            color: #3b82f6;
            transform: scale(1.05);
        }
        
        .field-hint {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 10px;
            font-size: 11px;
            color: #94a3b8;
        }
        
        .hint-dot {
            width: 8px;
            height: 8px;
            background: #3b82f6;
            border-radius: 50%;
        }
        
        /* Amount Card */
        .amount-display {
            background: linear-gradient(135deg, #1e293b, #0f172a);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.5);
        }
        
        .amount-value {
            font-size: 32px;
            font-weight: 800;
            color: white;
            font-family: 'Tajawal', sans-serif;
        }
        
        .amount-currency {
            font-size: 11px;
            color: #64748b;
            margin-top: 4px;
        }
        
        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            background: linear-gradient(135deg, #f8fafc, #eff6ff);
            padding: 20px;
            border-radius: 16px;
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
        }
        
        .info-value {
            font-weight: 700;
            color: #1e293b;
            font-size: 14px;
        }
        
        .info-value.highlight {
            color: #059669;
        }
        
        /* Card Footer */
        .card-footer {
            background: linear-gradient(90deg, #f8fafc, white, #eff6ff);
            border-top: 1px solid #e2e8f0;
            padding: 20px 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .action-buttons {
            display: flex;
            gap: 12px;
        }
        
        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 14px 28px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: white;
            font-weight: 700;
            font-size: 14px;
            border: none;
            border-radius: 16px;
            cursor: pointer;
            font-family: inherit;
            box-shadow: 
                0 10px 30px -8px rgba(59, 130, 246, 0.6),
                0 0 0 0 rgba(59, 130, 246, 0.4);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: 0.5s;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 
                0 15px 40px -10px rgba(59, 130, 246, 0.7),
                0 0 0 0 rgba(59, 130, 246, 0.4);
        }
        
        .btn-primary:hover::before {
            left: 100%;
        }
        
        .btn-primary svg {
            width: 18px;
            height: 18px;
        }
        
        .btn-secondary {
            padding: 14px 24px;
            background: white;
            color: #64748b;
            font-weight: 700;
            font-size: 14px;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.2s ease;
        }
        
        .btn-secondary:hover {
            border-color: #cbd5e1;
            background: #f8fafc;
        }
        
        .preview-toggle {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            color: #64748b;
            font-size: 12px;
            font-family: inherit;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .preview-toggle:hover {
            border-color: #93c5fd;
            color: #3b82f6;
        }
        
        .preview-toggle svg {
            width: 16px;
            height: 16px;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           LETTER PREVIEW
           ═══════════════════════════════════════════════════════════════ */
        .preview-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            overflow: hidden;
            margin-bottom: 24px;
        }
        
        .preview-header {
            height: 48px;
            background: #f1f5f9;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
        }
        
        .preview-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 700;
            color: #64748b;
        }
        
        .preview-title svg {
            width: 16px;
            height: 16px;
        }
        
        .preview-print {
            font-size: 12px;
            color: #3b82f6;
            font-weight: 700;
            background: none;
            border: none;
            cursor: pointer;
        }
        
        .preview-body {
            padding: 40px;
            background: linear-gradient(180deg, #f1f5f9, #e2e8f0);
            display: flex;
            justify-content: center;
        }
        
        .letter-paper {
            background: white;
            width: 100%;
            max-width: 560px;
            padding: 48px;
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.3);
            border-radius: 8px;
            font-family: 'Traditional Arabic', serif;
            font-size: 15px;
            line-height: 1.9;
            color: #374151;
        }
        
        .letter-header {
            text-align: center;
            margin-bottom: 28px;
        }
        
        .letter-to {
            font-weight: 700;
            font-size: 17px;
            color: #1e293b;
        }
        
        .letter-greeting {
            color: #64748b;
        }
        
        .letter-subject {
            margin-bottom: 20px;
        }
        
        .letter-subject strong {
            color: #1e293b;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           TIMELINE
           ═══════════════════════════════════════════════════════════════ */
        .timeline-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            overflow: hidden;
        }
        
        .timeline-header {
            height: 56px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
        }
        
        .timeline-title {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 700;
            font-size: 14px;
            color: #1e293b;
        }
        
        .timeline-icon {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, #a855f7, #ec4899);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 16px -4px rgba(168, 85, 247, 0.4);
        }
        
        .timeline-icon svg {
            width: 18px;
            height: 18px;
            color: white;
        }
        
        .timeline-count {
            font-size: 12px;
            color: #94a3b8;
            background: #f1f5f9;
            padding: 4px 12px;
            border-radius: 50px;
        }
        
        .timeline-body {
            padding: 24px;
        }
        
        .timeline-list {
            position: relative;
            padding-right: 40px;
        }
        
        .timeline-line {
            position: absolute;
            right: 12px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: linear-gradient(to bottom, #3b82f6, #a855f7, #e2e8f0);
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 24px;
        }
        
        .timeline-item:last-child {
            margin-bottom: 0;
        }
        
        .timeline-dot {
            position: absolute;
            right: -34px;
            top: 8px;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 3px solid #0f172a;
        }
        
        .timeline-dot.active {
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            box-shadow: 0 0 20px rgba(59, 130, 246, 0.6);
            animation: pulse-glow 2s infinite;
        }
        
        @keyframes pulse-glow {
            0%, 100% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.4); }
            50% { box-shadow: 0 0 0 12px rgba(59, 130, 246, 0); }
        }
        
        .timeline-dot.past {
            background: #94a3b8;
        }
        
        .event-card {
            border-radius: 16px;
            padding: 20px;
            transition: all 0.2s ease;
        }
        
        .event-card.current {
            background: linear-gradient(135deg, #eff6ff, #f5f3ff);
            border: 1px solid #bfdbfe;
            box-shadow: 0 10px 30px -10px rgba(59, 130, 246, 0.2);
        }
        
        .event-card.past {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            opacity: 0.7;
        }
        
        .event-card.past:hover {
            opacity: 1;
            transform: translateX(-4px);
        }
        
        .event-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }
        
        .event-badge {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 4px 10px;
            border-radius: 50px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: white;
            margin-bottom: 6px;
            display: inline-block;
        }
        
        .event-title {
            font-weight: 700;
            font-size: 16px;
            color: #1e293b;
        }
        
        .event-time {
            font-size: 11px;
            color: #94a3b8;
            font-family: monospace;
        }
        
        .event-desc {
            font-size: 13px;
            color: #64748b;
        }
        
        /* ═══════════════════════════════════════════════════════════════
           BACKGROUND DECORATION
           ═══════════════════════════════════════════════════════════════ */
        .bg-decoration {
            position: fixed;
            inset: 0;
            overflow: hidden;
            pointer-events: none;
            z-index: 0;
        }
        
        .bg-orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
        }
        
        .bg-orb.blue {
            width: 400px;
            height: 400px;
            background: rgba(59, 130, 246, 0.15);
            top: -150px;
            right: -150px;
        }
        
        .bg-orb.purple {
            width: 400px;
            height: 400px;
            background: rgba(139, 92, 246, 0.15);
            bottom: -150px;
            left: -150px;
        }
        
        /* Hidden checkbox for toggle */
        [x-cloak] { display: none !important; }
    </style>
</head>
<body x-data="{ showPreview: false, propagateAuto: true }">
    
    <!-- Background Decoration -->
    <div class="bg-decoration">
        <div class="bg-orb blue"></div>
        <div class="bg-orb purple"></div>
    </div>

    <div class="app-container" style="position: relative; z-index: 1;">
        
        <!-- ══════════════════════════════════════════════════════════════ -->
        <!-- SIDEBAR -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <aside class="sidebar">
            <!-- Header -->
            <div class="sidebar-header">
                <div class="logo-icon">B</div>
                <div>
                    <div class="logo-text">BGL</div>
                    <div class="logo-subtitle">إدارة الضمانات</div>
                </div>
            </div>
            
            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number pending">3</div>
                    <div class="stat-label">معلق</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number ready">60</div>
                    <div class="stat-label">جاهز</div>
                </div>
            </div>
            
            <!-- Queue -->
            <nav class="queue-section">
                <div class="queue-title">قائمة الانتظار</div>
                
                <a href="#" class="queue-item active">
                    <div class="queue-dot orange pulse"></div>
                    <div class="queue-info">
                        <div class="queue-name">LG-8821</div>
                        <div class="queue-company">شركة المقاولات</div>
                    </div>
                    <span class="queue-badge">جديد</span>
                </a>
                
                <a href="#" class="queue-item">
                    <div class="queue-dot orange"></div>
                    <div class="queue-info">
                        <div class="queue-name">LG-8820</div>
                        <div class="queue-company">مؤسسة التجارة</div>
                    </div>
                </a>
                
                <a href="#" class="queue-item">
                    <div class="queue-dot orange"></div>
                    <div class="queue-info">
                        <div class="queue-name">LG-8819</div>
                        <div class="queue-company">شركة التوريدات</div>
                    </div>
                </a>
                
                <div class="queue-divider"></div>
                
                <a href="#" class="queue-item" style="opacity: 0.5;">
                    <div class="queue-dot green"></div>
                    <div class="queue-info">
                        <div class="queue-name">LG-8818</div>
                        <div class="queue-company">تم الانتهاء</div>
                    </div>
                </a>
            </nav>
            
            <!-- Bottom -->
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
        
        <!-- ══════════════════════════════════════════════════════════════ -->
        <!-- MAIN CONTENT -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <main class="main-content">
            <!-- Top Bar -->
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
            
            <!-- Content -->
            <div class="content-area">
                <div class="content-wrapper">
                    
                    <!-- ══════════════════════════════════════════════════════════ -->
                    <!-- DECISION CARD -->
                    <!-- ══════════════════════════════════════════════════════════ -->
                    <div class="decision-card">
                        <div class="card-header">
                            <div class="card-header-title">
                                <div class="header-icon">
                                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                </div>
                                البيانات الأساسية
                            </div>
                            <label class="propagation-toggle" @click="propagateAuto = !propagateAuto">
                                <div class="toggle-switch" :class="{ 'active': propagateAuto }"></div>
                                <span class="toggle-label">تطبيق على المشابهة</span>
                            </label>
                        </div>
                        
                        <div class="card-body">
                            <div class="fields-grid">
                                <!-- Supplier -->
                                <div class="field-group">
                                    <label class="field-label">المورد (المستفيد)</label>
                                    <input type="text" class="field-input" value="شركة المقاولات المتحدة">
                                    <div class="chips-row">
                                        <button class="chip selected">
                                            <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                            المختار
                                        </button>
                                        <button class="chip candidate">⭐⭐ المقاولات التجارية</button>
                                        <button class="chip candidate">⭐ مؤسسة المتحدة</button>
                                    </div>
                                    <div class="field-hint">
                                        <div class="hint-dot"></div>
                                        من Excel: "UNITED CONTRACTORS CO"
                                    </div>
                                </div>
                                
                                <!-- Bank -->
                                <div class="field-group">
                                    <label class="field-label">البنك المصدر</label>
                                    <select class="field-input">
                                        <option selected>البنك الأهلي SNB</option>
                                        <option>مصرف الراجحي</option>
                                        <option>بنك الرياض</option>
                                        <option>البنك الأول</option>
                                    </select>
                                </div>
                                
                                <!-- Amount -->
                                <div class="field-group">
                                    <label class="field-label">المبلغ</label>
                                    <div class="amount-display">
                                        <div class="amount-value" dir="ltr">1.5M</div>
                                        <div class="amount-currency">ريال سعودي</div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Info Grid -->
                            <div class="info-grid">
                                <div class="info-item">
                                    <span class="info-label">رقم العقد</span>
                                    <span class="info-value">CON-2024-1234</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">تاريخ الانتهاء</span>
                                    <span class="info-value">٣٠ ديسمبر ٢٠٢٥</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">النوع</span>
                                    <span class="info-value">نهائي</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">التمديد المقترح</span>
                                    <span class="info-value highlight">٣٠ ديسمبر ٢٠٢٦</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Footer -->
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
                    
                    <!-- ══════════════════════════════════════════════════════════ -->
                    <!-- LETTER PREVIEW -->
                    <!-- ══════════════════════════════════════════════════════════ -->
                    <div class="preview-card" x-show="showPreview" x-transition x-cloak>
                        <div class="preview-header">
                            <span class="preview-title">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                                معاينة خطاب التمديد
                            </span>
                            <button class="preview-print">🖨️ طباعة</button>
                        </div>
                        <div class="preview-body">
                            <div class="letter-paper">
                                <div class="letter-header">
                                    <div class="letter-to">السادة / البنك الأهلي السعودي</div>
                                    <div class="letter-greeting">المحترمين</div>
                                </div>
                                <div class="letter-subject">
                                    <strong>الموضوع:</strong> طلب تمديد الضمان البنكي رقم (LG-2024-8821) والعائد للعقد رقم (CON-2024-1234)
                                </div>
                                <p>
                                    إشارة إلى الضمان البنكي النهائي الموضح أعلاه، والصادر منكم لصالحنا على حساب <strong>شركة المقاولات المتحدة</strong> بمبلغ قدره (<strong>١,٥٠٠,٠٠٠</strong>) ريال...
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- ══════════════════════════════════════════════════════════ -->
                    <!-- TIMELINE -->
                    <!-- ══════════════════════════════════════════════════════════ -->
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
                                
                                <!-- Event 1: Current -->
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
                                        <p class="event-desc">تم استيراد الضمان من ملف Excel ويحتاج اتخاذ قرار بالتمديد أو الإفراج.</p>
                                    </div>
                                </div>
                                
                                <!-- Event 2 -->
                                <div class="timeline-item">
                                    <div class="timeline-dot past"></div>
                                    <div class="event-card past">
                                        <div class="event-header">
                                            <div>
                                                <h3 class="event-title">تمديد سابق</h3>
                                            </div>
                                            <span class="event-time">01/06/2024</span>
                                        </div>
                                        <p class="event-desc">تم التمديد لمدة سنة إضافية</p>
                                    </div>
                                </div>
                                
                                <!-- Event 3 -->
                                <div class="timeline-item">
                                    <div class="timeline-dot past"></div>
                                    <div class="event-card past">
                                        <div class="event-header">
                                            <div>
                                                <h3 class="event-title">إصدار أولي</h3>
                                            </div>
                                            <span class="event-time">01/12/2023</span>
                                        </div>
                                        <p class="event-desc">تم إصدار الضمان من البنك</p>
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
