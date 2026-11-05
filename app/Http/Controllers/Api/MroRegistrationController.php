<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\ToirMailReadiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class MroRegistrationController extends Controller
{
    public function start(Request $request): JsonResponse
    {
        // An unverified request never creates a user or company.
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'company_name' => ['required', 'string', 'min:2', 'max:200'],
            'plan' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/'],
            'consent' => ['required', 'accepted'],
        ]);
        abort_unless(app(ToirMailReadiness::class)->configured(), 503, 'Email delivery is not configured');
        $email = Str::lower(trim($data['email']));
        $token = Str::random(80);
        $hash = hash('sha256', $token);
        $expiration = now()->addMinutes(45);
        $pending = DB::transaction(function () use ($email, $data, $hash, $expiration) {
            DB::table('mro_signup_requests')->where('email', $email)->delete();
            return DB::table('mro_signup_requests')->insertGetId([
                'email' => $email, 'name' => trim($data['name']),
                'company_name' => trim($data['company_name']),
                'plan_code' => $data['plan'] ?? null,
                'token_hash' => $hash, 'expires_at' => $expiration,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $frontend = rtrim((string) config('mro_commercial.frontend_url'), '/');
        $url = $frontend.'/register/verify?token='.rawurlencode($token);
        try {
            Mail::raw(
                "Подтвердите регистрацию в ITP ТОиР.\n\nСсылка действительна 45 минут:\n".
                $url."\n\nЕсли вы не запрашивали регистрацию, проигнорируйте это письмо.",
                function ($message) use ($email) {
                    $message->to($email)->subject('ITP ТОиР — подтверждение почты');
                }
            );
        } catch (\Throwable $exception) {
            // Do not leave an unfulfillable pending token behind.
            DB::table('mro_signup_requests')->where('id', $pending)->where('token_hash', $hash)->delete();
            report($exception);
            return response()->json(['message' => 'Не удалось отправить письмо. Проверьте SMTP и повторите попытку.'], 503);
        }
        return response()->json(['message' => 'Проверьте почту: ссылка для подтверждения отправлена.'], 202);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:50', 'max:100'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        $hash = hash('sha256', $data['token']);
        $user = DB::transaction(function () use ($hash, $data, $request): User {
            $pending = DB::table('mro_signup_requests')->where('token_hash', $hash)->lockForUpdate()->first();
            if (! $pending || now()->greaterThanOrEqualTo($pending->expires_at)) {
                throw ValidationException::withMessages(['token' => ['Ссылка недействительна или устарела.']]);
            }
            if (User::where('email', $pending->email)->exists()) {
                throw ValidationException::withMessages(['token' => ['Для этого email уже есть учётная запись. Войдите в систему.']]);
            }
            $company = Company::create([
                'name' => $pending->company_name,
                'code' => Str::limit(Str::slug($pending->company_name), 42, '').'-'.Str::lower(Str::random(12)),
                'is_active' => true,
            ]);
            $user = User::create([
                'name' => $pending->name,
                'email' => $pending->email,
                'password' => Hash::make($data['password']),
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $company->users()->attach($user->id, ['role' => 'owner', 'is_active' => true]);
            DB::table('mro_signup_requests')->where('id', $pending->id)->delete();
            // Trial / tariff assignment must be explicitly configured by MRO super admin.
            DB::table('toir_audit_events')->insert([
                'company_id' => $company->id, 'actor_id' => $user->id,
                'action' => 'public.signup.verified', 'target_type' => 'company',
                'target_id' => (string) $company->id,
                'metadata' => json_encode(['plan_request' => $pending->plan_code]),
                'created_at' => now(),
            ]);
            $request->attributes->set('registered_company_id', $company->id);
            return $user;
        }, 3);
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('toir_company_id', $request->attributes->get('registered_company_id'));
        return response()->json(['message' => 'Регистрация подтверждена.', 'redirect' => '/dashboard'], 201);
    }
}
