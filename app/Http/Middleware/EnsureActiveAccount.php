<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        // Reload from the database: a cached session model must not retain access
        // after an administrator disables the user.
        $fresh = $user ? User::query()->find($user->getAuthIdentifier()) : null;
        if (! $fresh || ! $fresh->is_active) {
            $this->revokeSession($request);

            return response()->json(['message' => 'Учётная запись отключена.'], 401);
        }

        if (! $fresh->activeCompanies()->exists()) {
            $this->revokeSession($request);

            return response()->json(['message' => 'Нет доступа ни к одной активной компании.'], 403);
        }

        return $next($request);
    }

    private function revokeSession(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
