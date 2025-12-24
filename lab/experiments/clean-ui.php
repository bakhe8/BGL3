<?php
/**
 * Experiment: Clean UI / Visual Identity
 * Purpose: Demonstrate a premium, high-contrast, functional design for the main application.
 */
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clean UI Experiment - DesignLab</title>
    <link href="/assets/css/output.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
</head>
<body class="h-full font-sans antialiased text-slate-800 selection:bg-primary-100 selection:text-primary-700">

    <!-- App Layout -->
    <div class="min-h-screen flex flex-col md:flex-row">
        
        <!-- Sidebar Navigation -->
        <aside class="w-full md:w-64 bg-slate-900 text-white flex-shrink-0 flex flex-col transition-all duration-300 relative z-20">
            <!-- Logo -->
            <div class="h-16 flex items-center px-6 border-b border-slate-800">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-primary-500 to-indigo-600 shadow-lg shadow-primary-500/20 flex items-center justify-center font-bold text-white">
                        B
                    </div>
                    <span class="font-bold text-lg tracking-wide">BGL System</span>
                </div>
            </div>

            <!-- Nav Links -->
            <nav class="flex-1 overflow-y-auto py-6 px-3 space-y-1">
                <a href="#" class="flex items-center gap-3 px-3 py-2.5 rounded-lg bg-primary-600 text-white shadow-soft transition-all group">
                    <svg class="w-5 h-5 opacity-100" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span class="font-medium">اتخاذ القرار</span>
                    <span class="bg-primary-500 text-white text-xs px-2 py-0.5 rounded-full mr-auto">12</span>
                </a>

                <a href="#" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-all group">
                    <svg class="w-5 h-5 opacity-70 group-hover:opacity-100 transition-opacity" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                    </svg>
                    <span class="font-medium">الإحصائيات</span>
                </a>

                <a href="#" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-all group">
                    <svg class="w-5 h-5 opacity-70 group-hover:opacity-100 transition-opacity" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                    <span class="font-medium">استيراد جديد</span>
                </a>
            </nav>

            <!-- User/Footer -->
            <div class="p-4 border-t border-slate-800">
                <button class="flex items-center gap-3 w-full p-2 rounded-lg hover:bg-slate-800 transition-colors text-left">
                    <div class="w-8 h-8 rounded-full bg-slate-700 flex items-center justify-center text-xs font-bold text-slate-300">
                        MA
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-white truncate">Mohammed Ali</p>
                        <p class="text-xs text-slate-500 truncate">Admin</p>
                    </div>
                </button>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 flex flex-col min-w-0 overflow-hidden bg-slate-50">
            
            <!-- Topbar -->
            <header class="h-16 bg-white border-b border-slate-200 shadow-sm flex items-center justify-between px-4 sm:px-6 z-10">
                <div class="flex items-center gap-4">
                    <h1 class="text-xl font-bold text-slate-800 tracking-tight">معالجة الضمانات (Logic Lab)</h1>
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700 border border-green-200">
                        متصل
                    </span>
                </div>

                <div class="flex items-center gap-3">
                    <button class="flex items-center justify-center w-10 h-10 rounded-full text-slate-500 hover:text-primary-600 hover:bg-primary-50 transition-colors">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                        </svg>
                    </button>
                </div>
            </header>

            <!-- Scrollable Content -->
            <div class="flex-1 overflow-auto p-4 sm:p-6 lg:p-8">
                
                <!-- KPI Cards -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <!-- Card 1 -->
                    <div class="bg-white rounded-xl shadow-soft p-6 border border-slate-100 relative overflow-hidden group hover:shadow-card transition-shadow cursor-pointer">
                        <div class="absolute right-0 top-0 h-full w-1 bg-gradient-to-b from-primary-400 to-primary-600 opacity-0 group-hover:opacity-100 transition-opacity"></div>
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <p class="text-sm font-medium text-slate-500">الضمانات المعلقة</p>
                                <h3 class="text-3xl font-bold text-slate-800 mt-1">12</h3>
                            </div>
                            <div class="p-2 bg-primary-50 text-primary-600 rounded-lg">
                                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                        </div>
                        <div class="flex items-center text-xs text-slate-400">
                            <span class="text-green-600 font-medium flex items-center gap-1">
                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                                </svg>
                                +2
                            </span>
                            <span class="mr-1">منذ الصباح</span>
                        </div>
                    </div>

                    <!-- Card 2 -->
                    <div class="bg-white rounded-xl shadow-soft p-6 border border-slate-100 relative overflow-hidden group hover:shadow-card transition-shadow cursor-pointer">
                         <div class="absolute right-0 top-0 h-full w-1 bg-gradient-to-b from-green-400 to-green-600 opacity-0 group-hover:opacity-100 transition-opacity"></div>
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <p class="text-sm font-medium text-slate-500">تمت معالجته</p>
                                <h3 class="text-3xl font-bold text-slate-800 mt-1">85</h3>
                            </div>
                            <div class="p-2 bg-green-50 text-green-600 rounded-lg">
                                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                        </div>
                        <div class="flex items-center text-xs text-slate-400">
                           <span class="text-green-600 font-medium flex items-center gap-1">
                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                                </svg>
                                98%
                            </span>
                            <span class="mr-1">نسبة الإنجاز</span>
                        </div>
                    </div>

                    <!-- Card 3 -->
                    <div class="bg-white rounded-xl shadow-soft p-6 border border-slate-100 relative overflow-hidden group hover:shadow-card transition-shadow cursor-pointer">
                         <div class="absolute right-0 top-0 h-full w-1 bg-gradient-to-b from-amber-400 to-amber-600 opacity-0 group-hover:opacity-100 transition-opacity"></div>
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <p class="text-sm font-medium text-slate-500">يحتاج مراجعة</p>
                                <h3 class="text-3xl font-bold text-slate-800 mt-1">3</h3>
                            </div>
                            <div class="p-2 bg-amber-50 text-amber-600 rounded-lg">
                                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                            </div>
                        </div>
                         <div class="flex items-center text-xs text-slate-400">
                            <span class="text-amber-600 font-medium flex items-center gap-1">
                                أولوية عالية
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Action Area & Working Pane -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    
                    <!-- Left Column: Form -->
                    <div class="lg:col-span-1 space-y-6">
                        <div class="bg-white rounded-xl shadow-soft border border-slate-100 overflow-hidden">
                            <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50 flex justify-between items-center">
                                <h2 class="font-bold text-slate-800">بيانات الضمان</h2>
                                <span class="bg-primary-100 text-primary-700 text-xs px-2 py-1 rounded-md font-medium">قيد المعالجة</span>
                            </div>
                            
                            <div class="p-6 space-y-5">
                                <!-- Input Group -->
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1.5">رقم الضمان</label>
                                    <input type="text" value="LG-2024-001" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:ring-2 focus:ring-primary-100 focus:border-primary-500 outline-none transition-all text-sm font-medium text-slate-800">
                                </div>

                                <!-- Input Group -->
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1.5">المستفيد</label>
                                    <div class="relative">
                                        <input type="text" value="شركة المقاولات المتحدة" class="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:ring-2 focus:ring-primary-100 focus:border-primary-500 outline-none transition-all text-sm font-medium text-slate-800">
                                        <div class="absolute left-3 top-2.5 text-slate-400">
                                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                            </svg>
                                        </div>
                                    </div>
                                    <p class="text-xs text-green-600 mt-1 flex items-center gap-1">
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                                        تم التحقق من النطاق
                                    </p>
                                </div>

                                <!-- Input Group -->
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1.5">القيمة</label>
                                    <div class="relative">
                                        <input type="text" value="1,500,000" class="w-full pl-16 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:ring-2 focus:ring-primary-100 focus:border-primary-500 outline-none transition-all text-sm font-bold text-slate-800 tracking-wide text-left dir-ltr">
                                        <div class="absolute left-3 top-2.5 text-slate-500 font-medium text-sm border-r border-slate-200 pr-2">SAR</div>
                                    </div>
                                </div>

                                <div class="pt-4 flex gap-3">
                                    <button class="flex-1 bg-primary-600 hover:bg-primary-700 text-white px-4 py-2.5 rounded-lg font-medium shadow-lg shadow-primary-500/30 transition-all hover:-translate-y-0.5 active:translate-y-0 flex items-center justify-center gap-2">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                                        اعتماد
                                    </button>
                                    <button class="flex-1 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 px-4 py-2.5 rounded-lg font-medium shadow-sm transition-all flex items-center justify-center gap-2">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                        رفض
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- AI Insight (Contextual) -->
                        <div class="bg-gradient-to-br from-indigo-50 to-purple-50 rounded-xl border border-indigo-100 p-4 relative overflow-hidden">
                             <div class="flex items-start gap-3 relative z-10">
                                <div class="bg-white p-2 rounded-lg shadow-sm text-indigo-600">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                    </svg>
                                </div>
                                <div>
                                    <h4 class="text-sm font-bold text-indigo-900">اقتراح الذكاء الاصطناعي</h4>
                                    <p class="text-xs text-indigo-800 leading-relaxed mt-1">
                                        هذا المستفيد "شركة المقاولات" يطابق بنسبة 95% سجلات سابقة. يوصى بالموافقة المباشرة.
                                    </p>
                                </div>
                             </div>
                        </div>
                    </div>

                    <!-- Right Column: Document Preview (The 'Work') -->
                    <div class="lg:col-span-2">
                        <div class="bg-slate-800 rounded-xl shadow-card overflow-hidden h-[600px] flex flex-col border border-slate-700">
                            <!-- Toolbar -->
                            <div class="h-12 bg-slate-900 border-b border-slate-700 flex justify-between items-center px-4">
                                <span class="text-xs font-mono text-slate-400">preview_document_v2.pdf</span>
                                <div class="flex gap-2">
                                    <button class="p-1.5 text-slate-400 hover:text-white rounded-md hover:bg-slate-700"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7" /></svg></button>
                                    <button class="p-1.5 text-slate-400 hover:text-white rounded-md hover:bg-slate-700"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM13 10H7" /></svg></button>
                                </div>
                            </div>
                            
                            <!-- Viewer Mockup -->
                            <div class="flex-1 bg-slate-500 overflow-auto p-8 flex justify-center items-start relative">
                                <div class="bg-white w-[595px] min-h-[842px] shadow-2xl p-12 text-slate-800 relative group">
                                    <!-- Highlighting Effect -->
                                    <div class="absolute top-[200px] left-[60px] w-[200px] h-[24px] bg-yellow-300 mix-blend-multiply opacity-50 ring-2 ring-yellow-500 cursor-pointer hover:opacity-70 transition-opacity"></div>
                                    
                                    <!-- Fake Doc Content -->
                                    <div class="space-y-6 opacity-80 pointer-events-none select-none">
                                        <div class="h-8 w-1/3 bg-slate-900/10 mb-8"></div>
                                        <div class="space-y-3 font-serif text-sm leading-8">
                                            <div class="h-4 bg-slate-900/10 w-full"></div>
                                            <div class="h-4 bg-slate-900/10 w-full"></div>
                                            <div class="h-4 bg-slate-900/10 w-5/6"></div>
                                            <div class="h-4 bg-slate-900/10 w-full mt-8"></div>
                                            <div class="h-4 bg-slate-900/10 w-4/6"></div>
                                        </div>
                                        
                                        <div class="border-2 border-slate-900 h-32 w-full mt-12 flex items-center justify-center text-slate-300 font-bold uppercase tracking-widest text-2xl rotate-[-12deg] border-dashed">
                                            Original Copy
                                        </div>
                                    </div>
                                    
                                     <!-- Tooltip for Highlight -->
                                    <div class="absolute top-[160px] left-[60px] bg-slate-900 text-white text-xs px-3 py-1.5 rounded-lg shadow-xl opacity-0 hover:opacity-100 transition-opacity whitespace-nowrap z-10 pointer-events-none group-hover:opacity-100">
                                        تم استخراج الشركة: <span class="font-bold text-yellow-300">شركة المقاولات المتحدة</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

            </div>
        </main>
    </div>

    <!-- Quick Actions (FAB) -->
    <button class="fixed bottom-8 left-8 w-14 h-14 bg-primary-600 rounded-full shadow-lg shadow-primary-600/40 text-white flex items-center justify-center hover:bg-primary-700 hover:scale-110 active:scale-95 transition-all z-30">
        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
        </svg>
    </button>

</body>
</html>
