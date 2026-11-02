<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ToirResourceController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(function (): void {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'itp-toir-api',
        'capabilities' => ['password_reset' => !in_array(config('mail.default'), ['log', 'array'], true)],
    ]));

    Route::middleware(['auth', 'toir.account.active'])->group(function (): void {
        Route::get('/overview', [ToirResourceController::class, 'overview']);
        Route::get('/schedule', [ToirResourceController::class, 'schedule']);
        Route::get('/resources/{resource}', [ToirResourceController::class, 'index']);
        Route::post('/resources/{resource}', [ToirResourceController::class, 'store']);
        Route::get('/resources/{resource}/{id}', [ToirResourceController::class, 'show'])->whereNumber('id');
        Route::patch('/resources/{resource}/{id}', [ToirResourceController::class, 'update'])->whereNumber('id');
        Route::delete('/resources/{resource}/{id}', [ToirResourceController::class, 'destroy'])->whereNumber('id');
    });

    Route::prefix('auth')->group(function (): void {
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:toir-login');
        Route::post('/password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:toir-recovery');
        Route::post('/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:toir-recovery');

        Route::middleware('auth')->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout']);

            Route::middleware('toir.account.active')->group(function (): void {
                Route::get('/me', [AuthController::class, 'me']);
                Route::post('/companies/switch', [AuthController::class, 'switchCompany']);
                Route::put('/password', [AuthController::class, 'changePassword'])->middleware('throttle:6,1');
            });
        });
    });
});
