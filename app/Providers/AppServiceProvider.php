<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('toir-login', function (Request $request) {
            $key = hash('sha256', mb_strtolower((string) $request->input('email')).'|'.$request->ip());

            return [
                Limit::perMinute(6)->by($key),
                Limit::perMinute(30)->by('ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('toir-recovery', fn (Request $request) =>
            Limit::perMinute(3)->by('recovery:'.$request->ip())
        );

        ResetPassword::createUrlUsing(function ($user, string $token): string {
            return rtrim((string) config('toir.frontend_url'), '/')
                .'/reset-password?token='.rawurlencode($token)
                .'&email='.rawurlencode($user->getEmailForPasswordReset());
        });
    }
}
