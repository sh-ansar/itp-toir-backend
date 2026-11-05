<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ToirAssetPassportController;
use App\Http\Controllers\Api\ToirChatController;
use App\Http\Controllers\Api\ToirChecklistController;
use App\Http\Controllers\Api\ToirCompanyMembersController;
use App\Http\Controllers\Api\ToirDispatchController;
use App\Http\Controllers\Api\ToirFaultController;
use App\Http\Controllers\Api\ToirOperationsController;
use App\Http\Controllers\Api\ToirRealtimeController;
use App\Http\Controllers\Api\ToirResourceController;
use App\Http\Controllers\Api\ToirStockController;
use App\Http\Controllers\Api\ToirWorkControlController;
use App\Http\Controllers\Api\ToirWorkflowController;
use App\Services\ToirMailReadiness;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

require __DIR__.'/channels.php';

Broadcast::routes(['middleware' => ['auth', 'toir.account.active'], 'prefix' => 'api/v1']);

Route::prefix('api/v1')->group(function (): void {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'itp-toir-api',
        'capabilities' => ['password_reset' => app(ToirMailReadiness::class)->configured()],
    ]));

    Route::middleware(['auth', 'toir.account.active'])->group(function (): void {
        Route::get('/overview', [ToirResourceController::class, 'overview']);
        Route::get('/operations/work-control', [ToirWorkControlController::class, 'index']);
        Route::get('/company/members/mail-status', [ToirCompanyMembersController::class, 'mailStatus']);
        Route::get('/company/members', [ToirCompanyMembersController::class, 'index']);
        Route::post('/company/members', [ToirCompanyMembersController::class, 'invite']);
        Route::patch('/company/members/{id}', [ToirCompanyMembersController::class, 'update'])->whereNumber('id');
        Route::post('/company/members/{id}/resend', [ToirCompanyMembersController::class, 'resend'])->whereNumber('id');
        // Equipment hierarchy, measured condition, crew calendar and explainable risks.
        Route::get('/operations/asset-tree', [ToirOperationsController::class, 'assetTree']);
        Route::get('/assets/{id}/passport', [ToirAssetPassportController::class, 'show'])->whereNumber('id');
        Route::post('/assets/{id}/faults', [ToirFaultController::class, 'report'])->whereNumber('id');
        Route::get('/operations/risk', [ToirOperationsController::class, 'risk']);
        Route::get('/operations/forecast', [ToirOperationsController::class, 'forecast']);
        Route::post('/operations/generate', [ToirOperationsController::class, 'generate']);
        Route::get('/operations/crews', [ToirOperationsController::class, 'crews']);
        Route::post('/operations/crews', [ToirOperationsController::class, 'createCrew']);
        Route::patch('/operations/crews/{id}', [ToirOperationsController::class, 'updateCrew'])->whereNumber('id');
        Route::post('/operations/crews/{id}/members', [ToirOperationsController::class, 'addCrewMember'])->whereNumber('id');
        Route::delete('/operations/crews/{id}/members/{employeeId}', [ToirOperationsController::class, 'removeCrewMember'])->whereNumber('id')->whereNumber('employeeId');
        Route::get('/operations/shifts', [ToirOperationsController::class, 'shifts']);
        Route::post('/operations/shifts/generate', [ToirOperationsController::class, 'generateShifts']);
        Route::delete('/operations/shifts/{id}', [ToirOperationsController::class, 'cancelShift'])->whereNumber('id');
        Route::get('/operations/assets/{id}/readings', [ToirOperationsController::class, 'readings'])->whereNumber('id');
        Route::post('/operations/assets/{id}/readings', [ToirOperationsController::class, 'recordReading'])->whereNumber('id');
        // Plan templates and immutable execution snapshots.
        Route::get('/plans/{planId}/checklist', [ToirChecklistController::class, 'planSteps'])->whereNumber('planId');
        Route::post('/plans/{planId}/checklist', [ToirChecklistController::class, 'createPlanStep'])->whereNumber('planId');
        Route::patch('/plans/{planId}/checklist/{stepId}', [ToirChecklistController::class, 'updatePlanStep'])->whereNumber('planId')->whereNumber('stepId');
        Route::delete('/plans/{planId}/checklist/{stepId}', [ToirChecklistController::class, 'deletePlanStep'])->whereNumber('planId')->whereNumber('stepId');
        Route::get('/work-orders/{orderId}/checklist', [ToirChecklistController::class, 'orderSteps'])->whereNumber('orderId');
        Route::patch('/work-orders/{orderId}/checklist/{stepId}', [ToirChecklistController::class, 'recordOrderStep'])->whereNumber('orderId')->whereNumber('stepId');
        Route::get('/realtime/config', [ToirRealtimeController::class, 'config']);
        Route::get('/schedule', [ToirResourceController::class, 'schedule']);
        // Isolated stock and work-order material ledger.
        Route::get('/stock/items', [ToirStockController::class, 'index']);
        Route::post('/stock/items', [ToirStockController::class, 'store']);
        Route::patch('/stock/items/{id}', [ToirStockController::class, 'update'])->whereNumber('id');
        Route::get('/stock/items/{id}/movements', [ToirStockController::class, 'movements'])->whereNumber('id');
        Route::post('/stock/items/{id}/receive', [ToirStockController::class, 'receive'])->whereNumber('id');
        Route::post('/supplies/{id}/receive', [ToirStockController::class, 'receiveSupply'])->whereNumber('id');
        Route::get('/work-orders/{id}/materials', [ToirStockController::class, 'orderMaterials'])->whereNumber('id');
        Route::post('/work-orders/{id}/materials', [ToirStockController::class, 'operate'])->whereNumber('id');
        // Standalone chat: Portal protocol and UX principles, separate data and sessions.
        Route::get('/chats/users', [ToirChatController::class, 'users']);
        Route::get('/chats', [ToirChatController::class, 'index']);
        Route::post('/chats', [ToirChatController::class, 'store']);
        Route::get('/chats/{id}', [ToirChatController::class, 'show'])->whereNumber('id');
        Route::post('/chats/{id}/archive', [ToirChatController::class, 'archive'])->whereNumber('id');
        Route::post('/chats/{id}/leave', [ToirChatController::class, 'leave'])->whereNumber('id');
        Route::post('/chats/{id}/members', [ToirChatController::class, 'addMember'])->whereNumber('id');
        Route::get('/chats/{id}/messages', [ToirChatController::class, 'messages'])->whereNumber('id');
        Route::post('/chats/{id}/messages', [ToirChatController::class, 'send'])->whereNumber('id');
        Route::post('/chats/{id}/typing', [ToirChatController::class, 'typing'])->whereNumber('id')->middleware('throttle:90,1');
        Route::post('/chats/{id}/receipts', [ToirChatController::class, 'receipt'])->whereNumber('id');
        Route::patch('/chats/{id}/messages/{messageId}', [ToirChatController::class, 'modify'])->whereNumber('id')->whereNumber('messageId');
        Route::delete('/chats/{id}/messages/{messageId}', [ToirChatController::class, 'destroy'])->whereNumber('id')->whereNumber('messageId');
        Route::post('/work-orders/{id}/transition', [ToirWorkflowController::class, 'transition'])->whereNumber('id');
        Route::post('/work-orders/{id}/dispatch', [ToirDispatchController::class, 'assign'])->whereNumber('id');
        Route::post('/work-orders/{id}/review', [ToirDispatchController::class, 'review'])->whereNumber('id');
        Route::post('/plans/{id}/issue', [ToirWorkflowController::class, 'issuePlan'])->whereNumber('id');
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

// Independently scoped MRO commercial and public routes.
require __DIR__.'/mro_commercial.php';
