<?php
/**
 * Patch Template: Rate Limit Middleware Registration (Laravel-style)
 *
 * كيفية الاستخدام:
 * 1) انسخ هذا المقتطف وعدّل القيم بين الأقواس {}.
 * 2) أضف Middleware إلى kernel أو إلى مجموعة مسارات الكتابة فقط.
 *
 * المتغيرات:
 * - {RATE_LIMIT}: العدد المسموح لكل دقيقة (مثال: 60).
 * - {ROUTE_GROUP}: مجموعة المسارات المستهدفة (api/write أو تخصيصك).
 */

// في app/Http/Kernel.php أو مكافئه
protected $routeMiddleware = [
    // ...
    'bgl.rate_limit' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
];

// في routes/api.php (أو ملف المسارات المناسب)
Route::middleware(['bgl.rate_limit:{RATE_LIMIT},1'])->group(function () {
    // {ROUTE_GROUP}: ضع هنا مسارات POST/PUT/PATCH/DELETE التي تريد حمايتها
    // مثال:
    // Route::post('/create-bank.php', [BankController::class, 'store']);
});
