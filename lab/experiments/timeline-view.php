<?php
/**
 * Experiment: Timeline View
 * Purpose: Centering the user experience around the guarantee lifecycle (Timeline).
 */
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" class="h-full bg-slate-900">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timeline View - DesignLab</title>
    <link href="/assets/css/output.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        .timeline-line::before {
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            right: 2rem;
            width: 2px;
            background: linear-gradient(to bottom, 
                rgba(59, 130, 246, 0.5) 0%, 
                rgba(59, 130, 246, 1) 50%, 
                rgba(148, 163, 184, 0.1) 100%
            );
            z-index: 0;
        }
    </style>
</head>
<body class="h-full font-sans antialiased text-slate-200 selection:bg-brand-accent selection:text-white overflow-hidden">

    <div class="flex h-full">
        
        <!-- LEFT PANEL: Document Context (Static/Reference) -->
        <div class="hidden lg:flex w-[45%] bg-slate-800/50 backdrop-blur-md border-l border-slate-700/50 flex-col relative z-10">
            <!-- Header -->
            <div class="h-16 border-b border-slate-700/50 flex items-center justify-between px-6 bg-slate-800/80">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded bg-slate-700 flex items-center justify-center text-slate-400">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                    </div>
                    <div>
                        <h2 class="text-sm font-bold text-slate-200">LG-2024-8821.pdf</h2>
                        <span class="text-xs text-slate-500">وارد من: البنك الأهلي السعودي</span>
                    </div>
                </div>
                <div class="flex gap-2">
                     <span class="px-2 py-1 rounded bg-slate-700/50 text-xs font-mono text-slate-400">Page 1/3</span>
                </div>
            </div>

            <!-- Preview Area -->
            <div class="flex-1 overflow-auto p-8 relative flex justify-center bg-slate-900/50">
                <div class="w-full max-w-[600px] aspect-[1/1.4] bg-white shadow-2xl rounded-sm p-12 text-slate-900 opacity-90 hover:opacity-100 transition-opacity">
                    <!-- FAKE DOC CONTENT -->
                    <div class="flex justify-between items-start mb-8 opacity-50">
                        <div class="w-16 h-16 bg-slate-200 rounded-full"></div>
                        <div class="space-y-2">
                            <div class="w-32 h-4 bg-slate-200"></div>
                            <div class="w-24 h-4 bg-slate-200"></div>
                        </div>
                    </div>
                    <div class="space-y-4 opacity-50">
                        <div class="w-full h-4 bg-slate-200"></div>
                        <div class="w-full h-4 bg-slate-200"></div>
                        <div class="w-3/4 h-4 bg-slate-200"></div>
                    </div>
                    <div class="my-8 py-4 border-y-2 border-slate-100">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <span class="text-xs text-slate-500 uppercase tracking-wider">Beneficiary</span>
                                <p class="font-bold text-lg">United Contractors Co.</p>
                            </div>
                             <div class="text-right">
                                <span class="text-xs text-slate-500 uppercase tracking-wider">Amount</span>
                                <p class="font-bold text-lg font-mono">SAR 1,500,000</p>
                            </div>
                        </div>
                    </div>
                    <div class="space-y-4 opacity-50">
                        <div class="w-full h-4 bg-slate-200"></div>
                        <div class="w-full h-4 bg-slate-200"></div>
                        <div class="w-5/6 h-4 bg-slate-200"></div>
                         <div class="w-full h-4 bg-slate-200"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT PANEL: Timeline Action Stream -->
        <div class="flex-1 flex flex-col bg-slate-950 relative overflow-hidden">
            
            <!-- Global Nav/Header -->
            <div class="h-16 flex items-center justify-between px-8 border-b border-slate-800/50 z-20 bg-slate-950/80 backdrop-blur">
                <div class="flex items-center gap-4">
                    <a href="/lab" class="text-slate-500 hover:text-white transition-colors">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
                    </a>
                    <h1 class="text-xl font-bold tracking-tight text-white">
                        <span class="text-brand-accent">Live</span> Timeline
                    </h1>
                </div>
                <div class="flex items-center gap-4">
                   <div class="flex -space-x-2 space-x-reverse overflow-hidden">
                        <img class="inline-block h-8 w-8 rounded-full ring-2 ring-slate-900" src="https://ui-avatars.com/api/?name=Ali+Ahmed&bg=3B82F6&color=fff" alt=""/>
                        <img class="inline-block h-8 w-8 rounded-full ring-2 ring-slate-900" src="https://ui-avatars.com/api/?name=Sarah+M&bg=8B5CF6&color=fff" alt=""/>
                    </div>
                    <span class="text-sm text-slate-400">يشاهدون الآن</span>
                </div>
            </div>

            <!-- Timeline Scroll Area -->
            <div class="flex-1 overflow-y-auto overflow-x-hidden relative p-8 timeline-line scroll-smooth">
                
                <div class="max-w-2xl mx-auto relative pr-16 space-y-12">

                    <!-- PAST EVENT (Collapsed) -->
                    <div class="relative opacity-60 hover:opacity-100 transition-opacity group">
                        <!-- Node -->
                        <div class="absolute -right-[4.5rem] top-2 w-5 h-5 rounded-full bg-slate-700 border-4 border-slate-900 group-hover:bg-brand-500 transition-colors z-10"></div>
                        
                        <div class="bg-slate-900/50 border border-slate-800 rounded-lg p-4 flex justify-between items-center">
                            <div>
                                <span class="text-xs font-mono text-slate-500 mb-1 block">2023-10-15 09:30 AM</span>
                                <h3 class="font-bold text-slate-300">إصدار خطاب الضمان</h3>
                                <p class="text-sm text-slate-500">تم الإصدار من قبل البنك الأهلي</p>
                            </div>
                            <span class="px-3 py-1 rounded-full bg-slate-800 text-slate-400 text-xs">مؤرشف</span>
                        </div>
                    </div>

                     <!-- PAST EVENT (Collapsed) -->
                    <div class="relative opacity-60 hover:opacity-100 transition-opacity group">
                        <!-- Node -->
                        <div class="absolute -right-[4.5rem] top-2 w-5 h-5 rounded-full bg-slate-700 border-4 border-slate-900 group-hover:bg-brand-500 transition-colors z-10"></div>
                        
                        <div class="bg-slate-900/50 border border-slate-800 rounded-lg p-4 flex justify-between items-center">
                            <div>
                                <span class="text-xs font-mono text-slate-500 mb-1 block">2024-01-10 11:15 AM</span>
                                <h3 class="font-bold text-slate-300">تعديل قيمة (زيادة)</h3>
                                <p class="text-sm text-slate-500">تمت الموافقة الآلية - مطابق للشروط</p>
                            </div>
                            <span class="px-3 py-1 rounded-full bg-green-900/30 text-green-400 text-xs">تمت الموافقة</span>
                        </div>
                    </div>

                    <!-- CURRENT ACTION (Expanded & Heroic) -->
                    <div class="relative animate-slide-up">
                        <!-- Glowing Node -->
                        <div class="absolute -right-[4.5rem] top-8 w-6 h-6 rounded-full bg-brand-500 border-4 border-slate-900 shadow-[0_0_20px_rgba(59,130,246,0.6)] z-10 custom-pulse"></div>
                        
                        <div class="bg-slate-800 rounded-2xl border border-brand-500/50 shadow-glass overflow-hidden relative">
                            <!-- Gradient Glow -->
                            <div class="absolute top-0 right-0 w-full h-1 bg-gradient-to-l from-brand-500 to-transparent"></div>
                            
                            <div class="p-6">
                                <div class="flex justify-between items-start mb-6">
                                    <div>
                                        <div class="flex items-center gap-3">
                                            <span class="inline-flex items-center rounded-md bg-brand-500/10 px-2 py-1 text-xs font-medium text-brand-400 ring-1 ring-inset ring-brand-500/20">NOW</span>
                                            <span class="text-sm font-mono text-slate-400">Today, 10:42 AM</span>
                                        </div>
                                        <h2 class="text-2xl font-bold text-white mt-2">طلب تمديد ضمان</h2>
                                        <p class="text-slate-400 mt-1">يطلب البنك تمديد الضمان لمدة 6 أشهر إضافية.</p>
                                    </div>
                                    <div class="text-right">
                                        <span class="block text-3xl font-bold text-white tracking-tight">1.5M</span>
                                        <span class="text-xs text-slate-500 uppercase">SAR</span>
                                    </div>
                                </div>

                                <!-- Key Data Points (Grid) -->
                                <div class="grid grid-cols-2 gap-4 mb-8">
                                    <div class="bg-slate-900/50 p-3 rounded-lg border border-slate-700/50">
                                        <span class="text-xs text-slate-500 block mb-1">المدة الجديدة</span>
                                        <span class="font-medium text-white">365 يوم</span>
                                        <span class="text-xs text-green-400 ml-2">(+180)</span>
                                    </div>
                                    <div class="bg-slate-900/50 p-3 rounded-lg border border-slate-700/50">
                                        <span class="text-xs text-slate-500 block mb-1">تاريخ الانتهاء الجديد</span>
                                        <span class="font-medium text-white">2026-12-30</span>
                                    </div>
                                </div>

                                <!-- AI Insight -->
                                <div class="mb-8 flex gap-4 items-start bg-brand-900/20 p-4 rounded-xl border border-brand-500/20">
                                    <div class="p-2 bg-brand-500/20 rounded-lg text-brand-400">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                                    </div>
                                    <div>
                                        <h4 class="font-bold text-brand-100 text-sm">تحليل الذكاء الاصطناعي</h4>
                                        <p class="text-sm text-brand-200/80 mt-1 leading-relaxed">
                                            المشروع مرتبط بهذا الضمان ما زال قائماً (نسبة الإنجاز 60%). التمديد منطقي وموصى به لتغطية الفترة المتبقية.
                                        </p>
                                    </div>
                                </div>

                                <!-- Actions -->
                                <div class="flex gap-3">
                                    <button class="flex-1 bg-brand-600 hover:bg-brand-500 text-white font-bold py-3 px-4 rounded-xl shadow-lg shadow-brand-900/20 transition-all hover:-translate-y-0.5 active:translate-y-0 flex items-center justify-center gap-2">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                                        موافقة واعتماد
                                    </button>
                                     <button class="px-4 py-3 bg-slate-700 hover:bg-slate-600 text-white rounded-xl font-medium transition-colors">
                                        رفض
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- FUTURE (Ghosted) -->
                    <div class="relative opacity-30">
                        <!-- Node -->
                        <div class="absolute -right-[4.5rem] top-2 w-5 h-5 rounded-full bg-slate-800 border-4 border-slate-900 z-10"></div>
                        
                        <div class="border border-slate-800 border-dashed rounded-lg p-4">
                            <h3 class="font-bold text-slate-500">استحقاق المطالبة</h3>
                            <p class="text-sm text-slate-600">متوقع في 2026-12-30</p>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <!-- Animation CSS -->
    <style>
        .custom-pulse {
            animation: pulse-ring 2s cubic-bezier(0.215, 0.61, 0.355, 1) infinite;
        }
        @keyframes pulse-ring {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(59, 130, 246, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(59, 130, 246, 0); }
        }
    </style>
</body>
</html>
