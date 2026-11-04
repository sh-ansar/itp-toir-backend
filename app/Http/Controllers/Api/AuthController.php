<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\AuthAudit;
use App\Services\ToirMailReadiness;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $input = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $email = Str::lower(trim($input['email']));

        if (! Auth::attempt(['email' => $email, 'password' => $input['password'], 'is_active' => true])) {
            throw ValidationException::withMessages([
                'email' => ['Неверный адрес электронной почты или пароль.'],
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        // New users must activate their mailbox using their private invite link.
        if (! $user->email_verified_at) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => ['Email address has not been verified. Use the invitation link.'],
            ]);
        }

        $company = $user->activeCompanies()->orderBy('companies.id')->first();

        if (! $company) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => ['Доступ к компаниям не предоставлен. Обратитесь к администратору.'],
            ]);
        }

        $request->session()->regenerate();
        $request->session()->put('toir_company_id', $company->id);
        AuthAudit::record('auth.login.success', $user, $company);

        return response()->json(['data' => $this->sessionData($user, $company)]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = $this->activeCompany($request, $user);

        return response()->json(['data' => $this->sessionData($user, $company)]);
    }

    public function switchCompany(Request $request): JsonResponse
    {
        $payload = $request->validate(['company_id' => ['required', 'integer', 'min:1']]);

        /** @var User $user */
        $user = $request->user();
        $company = $user->activeCompanies()->whereKey($payload['company_id'])->first();

        if (! $company) {
            return response()->json(['message' => 'Нет доступа к выбранной компании.'], 403);
        }

        $previousCompanyId = $request->session()->get('toir_company_id');
        $request->session()->put('toir_company_id', $company->id);
        AuthAudit::record('auth.company.switched', $user, $company, [
            'previous_company_id' => $previousCompanyId ? (int) $previousCompanyId : null,
        ]);

        return response()->json(['data' => $this->sessionData($user, $company)]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $companyId = $request->session()->get('toir_company_id');
        $company = $companyId ? $user->activeCompanies()->whereKey($companyId)->first() : null;
        AuthAudit::record('auth.logout', $user, $company);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Вы вышли из системы.']);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->forceFill([
            'password' => Hash::make($payload['password']),
            'remember_token' => Str::random(60),
        ])->save();

        // Old database-backed sessions from other browsers are revoked.
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        $request->session()->regenerate();
        AuthAudit::record('auth.password.changed', $user, $this->activeCompany($request, $user));

        return response()->json(['message' => 'Пароль изменён.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $payload = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);
        $email = Str::lower(trim($payload['email']));

        if (! app(ToirMailReadiness::class)->configured()) {
            return response()->json([
                'message' => 'Восстановление пароля по почте временно недоступно. Обратитесь к администратору.',
            ], 503);
        }

        // Do not disclose account existence, even if SMTP transport fails only for known accounts.
        $user = User::where('email', $email)->where('is_active', true)->first();
        if ($user) {
            try {
                Password::sendResetLink(['email' => $email]);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return response()->json([
            'message' => 'Если учётная запись существует, на почту отправлена ссылка для сброса пароля.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        $payload['email'] = Str::lower(trim($payload['email']));

        $status = Password::reset($payload, function (User $user, string $password): void {
            if (! $user->is_active) {
                throw ValidationException::withMessages([
                    'email' => ['Invalid password reset link.'],
                ]);
            }

            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
                'email_verified_at' => $user->email_verified_at ?: now(),
            ])->save();

            DB::table('sessions')->where('user_id', $user->id)->delete();
            AuthAudit::record('auth.password.reset', $user, $user->activeCompanies()->orderBy('companies.id')->first());
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => ['Ссылка истекла или недействительна. Запросите новую.'],
            ]);
        }

        return response()->json(['message' => 'Пароль обновлён. Теперь можно войти.']);
    }

    private function activeCompany(Request $request, User $user): ?Company
    {
        $companyId = $request->session()->get('toir_company_id');
        $company = $companyId ? $user->activeCompanies()->whereKey($companyId)->first() : null;

        if (! $company) {
            $company = $user->activeCompanies()->orderBy('companies.id')->first();

            if ($company) {
                $request->session()->put('toir_company_id', $company->id);
            } else {
                $request->session()->forget('toir_company_id');
            }
        }

        return $company;
    }

    private function sessionData(User $user, ?Company $activeCompany): array
    {
        $companies = $user->activeCompanies()->orderBy('companies.name')->get();

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
            ],
            'companies' => $companies->map(fn (Company $company) => [
                'id' => $company->id,
                'name' => $company->name,
                'code' => $company->code,
                'role' => $company->pivot->role,
            ])->values(),
            'active_company_id' => $activeCompany?->id,
            'active_role' => $activeCompany?->pivot->role,
        ];
    }
}
