<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(function (): void {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'itp-toir-api',
    ]));

    Route::prefix('auth')->group(function (): void {
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:toir-login');
        Route::post('/password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:toir-recovery');
        Route::post('/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:toir-recovery');

        Route::middleware('auth')->group(function (): void {
            // Always allow explicit sign-out, even when access was revoked.
            Route::post('/logout', [AuthController::class, 'logout']);

            Route::middleware('toir.account.active')->group(function (): void {
                Route::get('/me', [AuthController::class, 'me']);
                Route::post('/companies/switch', [AuthController::class, 'switchCompany']);
                Route::put('/password', [AuthController::class, 'changePassword'])->middleware('throttle:6,1');
            });
        });
    });
});
