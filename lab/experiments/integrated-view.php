<?php
/**
 * Experiment: Integrated Workflow
 * Purpose: Merging Clean UI practical forms with Timeline context.
 */
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Integrated Workflow - DesignLab</title>
    <link href="/assets/css/output.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
</head>
<body class="h-full font-sans antialiased text-slate-800 selection:bg-brand-accent selection:text-white">

    <div class="flex h-full overflow-hidden">
        
        <!-- COLUMN 1: Navigation (Visual Anchor) -->
        <aside class="w-20 lg:w-64 bg-slate-900 text-white flex-shrink-0 flex flex-col transition-all duration-300 relative z-30">
            <!-- Logo -->
            <div class="h-16 flex items-center justify-center lg:justify-start lg:px-6 border-b border-slate-800">
                <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-brand-500 to-indigo-600 shadow-lg shadow-brand-500/20 flex items-center justify-center font-bold text-white shrink-0">
                    B
                </div>
                <span class="font-bold text-lg tracking-wide hidden lg:block mr-3">BGL System</span>
            </div>

            <!-- Nav Links -->
            <nav class="flex-1 overflow-y-auto py-6 px-2 lg:px-3 space-y-1">
                <a href="/lab" class="flex items-center gap-3 px-2 lg:px-3 py-2.5 rounded-lg bg-brand-600 text-white shadow-soft transition-all group justify-center lg:justify-start">
                    <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <span class="font-medium hidden lg:block">اتخاذ القرار</span>
                </a>
                
                <div class="my-4 border-t border-slate-800 mx-2"></div>

                <a href="#" class="flex items-center gap-3 px-2 lg:px-3 py-2.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-all group justify-center lg:justify-start">
                    <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" /></svg>
                    <span class="font-medium hidden lg:block">محفوظات</span>
                </a>
            </nav>
        </aside>

        <!-- COLUMN 2: WORKSPACE (The "Clean UI" Part) -->
        <main class="flex-1 flex flex-col min-w-0 bg-slate-50 relative z-0">
            <!-- Topbar -->
            <header class="h-16 bg-white border-b border-slate-200 shadow-sm flex items-center justify-between px-6 z-20">
                <div class="flex items-center gap-4">
                    <h1 class="text-xl font-bold text-slate-800">طلب تمديد ضمان <span class="text-slate-400 font-normal mx-2">#LG-2024-8821</span></h1>
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700 border border-amber-200">
                        قيد المعالجة
                    </span>
                </div>
                <div class="flex items-center gap-3">
                     <button class="bg-white border border-slate-300 text-slate-600 px-3 py-1.5 rounded-lg text-sm font-medium hover:bg-slate-50">
                        تعليق
                    </button>
                    <button class="bg-brand-600 text-white px-4 py-1.5 rounded-lg text-sm font-medium hover:bg-brand-700 shadow-lg shadow-brand-500/20">
                        حفظ
                    </button>
                </div>
            </header>

            <!-- Scrollable Work Area -->
            <div class="flex-1 overflow-y-auto p-6 lg:p-10">
                <div class="max-w-5xl mx-auto space-y-8">
                    
                    <!-- 1. The Decision Card (Hero) -->
                    <div class="bg-white rounded-xl shadow-soft border border-slate-200 overflow-hidden" x-data="{ editing: false }">
                        <div class="p-6 border-b border-slate-100 bg-slate-50/50 flex justify-between items-center">
                            <h2 class="font-bold text-lg text-slate-800 flex items-center gap-2">
                                <svg class="w-5 h-5 text-brand-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                البيانات الأساسية
                            </h2>
                            <div class="text-sm text-slate-500">
                                ورد قبل: <span class="font-medium text-slate-900">ساعتين</span>
                            </div>
                        </div>
                        
                        <div class="p-8">
                            <!-- Critical Data Grid (Editable) -->
                            <div class="grid grid-cols-1 md:grid-cols-12 gap-6 mb-8">
                                
                                <!-- Supplier Name (Editable) -->
                                <div class="md:col-span-7 group relative">
                                    <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">اسم المورد (المستفيد)</label>
                                    <div class="relative">
                                        <input type="text" value="شركة المقاولات المتحدة" 
                                            class="w-full text-xl font-bold text-slate-800 bg-transparent border-b-2 border-transparent hover:border-slate-300 focus:border-brand-500 focus:bg-white focus:outline-none transition-all py-1 px-1 rounded-t -ml-1"
                                            title="Click to edit">
                                        <div class="absolute right-0 top-2 text-slate-400 opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
                                        </div>
                                    </div>
                                    <p class="text-xs text-green-600 mt-1 flex items-center gap-1">
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                                        مطابق للسجل التجاري (1010XXXXXX)
                                    </p>
                                </div>

                                <!-- Bank (Editable) -->
                                <div class="md:col-span-3 group relative">
                                    <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">البنك المصدر</label>
                                    <div class="relative">
                                         <select class="w-full text-lg font-bold text-slate-800 bg-transparent border-b-2 border-transparent hover:border-slate-300 focus:border-brand-500 focus:bg-white focus:outline-none transition-all py-1 px-1 rounded-t -ml-1 appearance-none cursor-pointer">
                                            <option selected>البنك الأهلي SNB</option>
                                            <option>مصرف الراجحي</option>
                                            <option>بنك الرياض</option>
                                        </select>
                                        <div class="absolute left-0 top-3 text-slate-400 pointer-events-none">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                                        </div>
                                    </div>
                                </div>

                                <!-- Amount (Editable) -->
                                <div class="md:col-span-2 group relative">
                                    <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">القيمة (SAR)</label>
                                    <div class="relative">
                                        <input type="text" value="1,500,000" 
                                            class="w-full text-xl font-bold text-slate-800 bg-transparent border-b-2 border-transparent hover:border-slate-300 focus:border-brand-500 focus:bg-white focus:outline-none transition-all py-1 px-1 rounded-t -ml-1 dir-ltr text-left font-mono group-hover:bg-slate-50">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Detailed Form Grid -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 border-t border-slate-100 pt-8">
                                <!-- Left: Form Details -->
                                <div class="space-y-6">
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium text-slate-700 mb-1.5">تاريخ الانتهاء الحالي</label>
                                            <div class="px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-slate-600 text-sm font-mono">2025-12-30</div>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-slate-700 mb-1.5 flex justify-between">
                                                تاريخ الانتهاء الجديد
                                                <span class="text-xs text-brand-600 font-bold bg-brand-50 px-2 py-0.5 rounded-full">+365 يوم تلقائي</span>
                                            </label>
                                            <div class="px-4 py-2.5 bg-green-50 border border-green-200 rounded-lg text-green-800 text-sm font-bold font-mono flex items-center gap-2">
                                                2026-12-30
                                                <svg class="w-4 h-4 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Right: AI Insight Panel -->
                                <div class="bg-indigo-50/50 rounded-xl border border-indigo-100 p-5">
                                    <h3 class="text-sm font-bold text-indigo-900 mb-3 flex items-center gap-2">
                                        <span class="w-6 h-6 rounded bg-indigo-100 text-indigo-600 flex items-center justify-center text-xs">AI</span>
                                        تحليل الذكاء الاصطناعي
                                    </h3>
                                    <p class="text-sm text-slate-600 leading-relaxed mb-4">
                                        بناءً على تاريخ التعامل مع "شركة المقاولات المتحدة"، التمديد يتماشى مع مدة المشروع المتبقية (12 شهر). لا توجد مخاطر ائتمانية مسجلة مؤخراً.
                                    </p>
                                    <div class="flex gap-2">
                                        <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-green-100 text-green-700">مطابق للسياسة</span>
                                        <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-blue-100 text-blue-700">عميل VIP</span>
                                    </div>
                                </div>
                            </div>

                        </div>

                         <div class="bg-slate-50 px-8 py-4 border-t border-slate-200 flex gap-4">
                             <button class="flex-1 bg-brand-600 hover:bg-brand-700 text-white py-2.5 rounded-lg font-bold shadow-md transition-all">
                                موافقة واعتماد التمديد
                             </button>
                             <button class="px-6 py-2.5 bg-white border border-slate-300 text-slate-700 rounded-lg font-medium hover:bg-slate-50 transition-all">
                                رفض
                             </button>
                        </div>
                    </div>

                    <!-- 2. Document Preview (Secondary) -->
                    <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-1">
                        <div class="h-10 px-4 flex items-center justify-between border-b border-slate-100 bg-slate-50 rounded-t-lg">
                            <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Document Preview</span>
                            <a href="#" class="text-xs text-brand-600 font-medium hover:underline">Full Screen</a>
                        </div>
                        <div class="h-96 bg-slate-100 overflow-hidden relative flex items-center justify-center">
                             <!-- Fake PDF look -->
                             <div class="w-[80%] h-full bg-white shadow-lg my-4 p-8 opacity-90 scale-95 origin-top">
                                <div class="space-y-4">
                                     <div class="h-4 bg-slate-200 w-1/3 mb-8"></div>
                                     <div class="h-3 bg-slate-100 w-full"></div>
                                     <div class="h-3 bg-slate-100 w-full"></div>
                                     <div class="h-3 bg-slate-100 w-2/3"></div>
                                     
                                     <div class="mt-8 border p-4 border-slate-200 rounded">
                                        <div class="h-3 bg-slate-200 w-1/4 mb-2"></div>
                                        <div class="h-6 bg-yellow-100 w-1/2">SAR 1,500,000</div>
                                     </div>
                                </div>
                             </div>
                        </div>
                    </div>

                </div>
            </div>
        </main>

        <!-- COLUMN 3: CONTEXT TIMELINE (The "Timeline View" Part) -->
        <aside class="w-80 bg-white border-r border-slate-200 flex flex-col z-20 shadow-xl shadow-slate-200/50">
            <div class="h-16 flex items-center px-6 border-b border-slate-100 bg-slate-50/80 backdrop-blur">
                <h3 class="font-bold text-slate-700">سجل العمليات</h3>
            </div>
            
            <div class="flex-1 overflow-y-auto p-6 relative">
                <!-- Timeline Line -->
                <div class="absolute top-0 bottom-0 right-[2.25rem] w-px bg-slate-200"></div>

                <div class="space-y-8 relative">
                    
                    <!-- Event 1 (Latest - Active) -->
                    <div class="relative mr-8">
                        <div class="absolute -right-[2.35rem] top-1 w-4 h-4 rounded-full bg-white border-4 border-brand-500 shadow-md"></div>
                        <div class="bg-brand-50 rounded-lg p-3 border border-brand-100 shadow-sm">
                            <span class="text-[10px] font-bold text-brand-600 uppercase tracking-wider mb-1 block">اليوم، 10:42 AM</span>
                            <h4 class="font-bold text-slate-800 text-sm">طلب تمديد</h4>
                            <p class="text-xs text-slate-600 mt-1">تمديد لمدة 365 يوم إضافية.</p>
                        </div>
                    </div>

                    <!-- Event 2 -->
                    <div class="relative mr-8 opacity-70">
                        <div class="absolute -right-[2.35rem] top-1 w-3 h-3 rounded-full bg-slate-300 border-2 border-white"></div>
                        <div>
                            <span class="text-[10px] font-medium text-slate-400 mb-0.5 block">2024-01-15</span>
                            <h4 class="font-medium text-slate-700 text-sm">زيادة قيمة</h4>
                            <p class="text-xs text-slate-500">تمت الموافقة الآلية.</p>
                        </div>
                    </div>

                    <!-- Event 3 -->
                    <div class="relative mr-8 opacity-70">
                        <div class="absolute -right-[2.35rem] top-1 w-3 h-3 rounded-full bg-slate-300 border-2 border-white"></div>
                        <div>
                             <span class="text-[10px] font-medium text-slate-400 mb-0.5 block">2023-10-15</span>
                            <h4 class="font-medium text-slate-700 text-sm">إصدار خطاب الضمان</h4>
                            <p class="text-xs text-slate-500">البداية</p>
                        </div>
                    </div>
                    
                </div>
            </div>
            
            <div class="p-4 border-t border-slate-100 bg-slate-50">
                <button class="w-full text-xs text-slate-500 hover:text-brand-600 font-medium py-2 border border-slate-200 rounded-lg hover:bg-white hover:shadow-sm transition-all">
                    عرض السجل الكامل
                </button>
            </div>
        </aside>

    </div>

</body>
</html>
