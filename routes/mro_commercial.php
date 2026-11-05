<?php

use App\Http\Controllers\Api\MroCommercialController;
use App\Http\Controllers\Api\MroPlatformAdministrationController;
use App\Http\Controllers\Api\MroRegistrationController;
use App\Services\MroCommercial\PlatformAdminAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Separate MRO identity/database. Never proxy to Portal's API or share its super-admin grants.
Route::prefix('api/v1')->group(function (): void {
    Route::get('/public/plans', [MroCommercialController::class, 'plans']);
    Route::get('/public/legal', [MroCommercialController::class, 'documents']);
    Route::get('/public/legal/{version}', [MroCommercialController::class, 'document'])->whereNumber('version');
    Route::get('/public/legal/{version}/pdf', [MroCommercialController::class, 'legalPdf'])->whereNumber('version');
    Route::post('/public/register/start', [MroRegistrationController::class, 'start'])->middleware('throttle:3,10');
    Route::post('/public/register/verify', [MroRegistrationController::class, 'verify'])->middleware('throttle:6,10');

    Route::middleware(['auth', 'toir.account.active'])->group(function (): void {
        Route::get('/commercial/subscription', [MroCommercialController::class, 'subscription']);
        Route::get('/commercial/invoices', [MroCommercialController::class, 'invoices']);
        Route::post('/commercial/invoices', [MroCommercialController::class, 'createInvoice'])->middleware('throttle:6,1');
        Route::get('/commercial/invoices/{invoice}/pdf', [MroCommercialController::class, 'invoicePdf'])->whereNumber('invoice');
        Route::post('/commercial/invoices/{invoice}/proof', [MroCommercialController::class, 'proof'])->whereNumber('invoice')->middleware('throttle:6,1');
        Route::post('/commercial/legal/accept', [MroCommercialController::class, 'accept']);
        Route::get('/commercial/legal/consents', [MroCommercialController::class, 'ownConsents']);
    });

    Route::middleware('auth')->group(function (): void {
        Route::get('/platform-admin/access', function (Request $request) {
            abort_unless(PlatformAdminAccess::allowed($request->user()), 403);
            return response()->json(['allowed' => true]);
        });
        Route::prefix('platform-admin')->group(function (): void {
            $c = MroPlatformAdministrationController::class;
            Route::get('/overview', [$c, 'overview']);
            Route::get('/organizations', [$c, 'organizations']);
            Route::get('/organizations/{company}', [$c, 'organization'])->whereNumber('company');
            Route::get('/organizations/{company}/users', [$c, 'users'])->whereNumber('company');
            Route::post('/organizations/{company}/users/{user}/resend', [$c, 'resend'])->whereNumber(['company','user'])->middleware('throttle:5,1');
            Route::put('/organizations/{company}/subscription', [$c, 'assign'])->whereNumber('company');
            Route::put('/organizations/{company}/billing', [$c, 'saveBuyer'])->whereNumber('company');
            Route::get('/plans', [$c, 'plans']);
            Route::post('/plans', [$c, 'savePlan']);
            Route::put('/plans/{version}/status', [$c, 'planState'])->whereNumber('version');
            Route::post('/billing/assets/{kind}', [$c, 'billingAsset'])->middleware('throttle:8,1');
            Route::get('/billing/supplier', [$c, 'billingProfile']);
            Route::put('/billing/supplier', [$c, 'saveSupplier']);
            Route::get('/invoices', [$c, 'invoices']);
            Route::post('/invoices', [$c, 'createInvoice']);
            Route::put('/invoices/{invoice}/status', [$c, 'invoiceState'])->whereNumber('invoice');
            Route::get('/invoices/{invoice}/pdf', [$c, 'invoicePdf'])->whereNumber('invoice');
            Route::get('/invoices/{invoice}/proofs', [$c, 'proofs'])->whereNumber('invoice');
            Route::get('/proofs/{proof}/file', [$c, 'proofFile'])->whereNumber('proof');
            Route::put('/proofs/{proof}/review', [$c, 'reviewProof'])->whereNumber('proof');
            Route::get('/legal', [$c, 'legalVersions']);
            Route::get('/legal/{version}', [$c, 'legalVersion'])->whereNumber('version');
            Route::post('/legal', [$c, 'saveLegal']);
            Route::put('/legal/{version}/status', [$c, 'legalState'])->whereNumber('version');
            Route::get('/legal/{version}/pdf', [$c, 'legalPdf'])->whereNumber('version');
            Route::get('/consents', [$c, 'consents']);
            Route::get('/grants', [$c, 'grants']);
            Route::post('/grants', [$c, 'grant']);
            Route::delete('/grants/{grant}', [$c, 'revoke'])->whereNumber('grant');
        });
    });
});
