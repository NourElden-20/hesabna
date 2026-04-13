<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\ForceJsonResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
    // تفعيل الـ Sanctum للـ API
    $middleware->statefulApi(); 
    
    // إضافة الـ Headers المطلوبة لضمان الرد بـ JSON دائماً
    $middleware->alias([
        'json.response' => \App\Http\Middleware\ForceJsonResponse::class, // لو كنت عملت الميدل وير ده
    ]);
})
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
