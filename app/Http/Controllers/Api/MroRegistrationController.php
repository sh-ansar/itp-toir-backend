<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\MroCommercial\LegalDocumentService;
use App\Services\ToirMailReadiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
            'legal_choices' => ['required', 'array:offer,privacy,tariff_policy,acceptable_use,personal_data_consent'],
            'legal_choices.*' => ['boolean'],
            'locale' => ['nullable', Rule::in(['ru', 'en', 'ka', 'ge', 'de', 'fr', 'es'])],
        ]);
        abort_unless(config('mro_commercial.public_registration_enabled'), 503, 'Public registration is not enabled.');
        abort_unless(app(ToirMailReadiness::class)->configured(), 503, 'Email delivery is not configured');
        $locale = ($data['locale'] ?? 'ru') === 'ka' ? 'ge' : ($data['locale'] ?? 'ru');
        $legal = app(LegalDocumentService::class)->current($locale, 'KZ');
        $types = array_map(static fn ($v) => $v->type, $legal);
        abort_unless(in_array('offer', $types, true) && in_array('privacy', $types, true), 409, 'Published MRO offer and privacy terms are required before registration.');
        foreach ($legal as $version) {
            if ($version->required || in_array($version->type, ['offer', 'privacy'], true)) {
                abort_unless(($data['legal_choices'][$version->type] ?? false) === true, 422, 'Required legal document must be accepted: '.$version->type);
            }
        }
        $legalSnapshot = json_encode([
            'choices' => $data['legal_choices'],
            'locale' => $locale,
            'versions' => array_map(static fn ($v) => ['id' => $v->id, 'sha256' => $v->sha256, 'type' => $v->type], $legal),
        ], JSON_THROW_ON_ERROR);

        $email = Str::lower(trim($data['email']));
        $token = Str::random(80);
        $hash = hash('sha256', $token);
        $expiration = now()->addMinutes(45);
        $pending = DB::transaction(function () use ($email, $data, $hash, $expiration, $legalSnapshot) {
            DB::table('mro_signup_requests')->where('email', $email)->delete();

            return DB::table('mro_signup_requests')->insertGetId([
                'email' => $email, 'name' => trim($data['name']),
                'company_name' => trim($data['company_name']),
                'plan_code' => $data['plan'] ?? null, 'legal_snapshot' => $legalSnapshot,
                'token_hash' => $hash, 'expires_at' => $expiration,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $frontend = rtrim((string) config('mro_commercial.frontend_url'), '/');
        $url = $frontend.'/register/verify?token='.rawurlencode($token);
        $mailTexts = [
            'ru' => ['Подтвердите регистрацию в ITP ТОиР.', 'Ссылка действительна 45 минут:', 'Если вы не запрашивали регистрацию, проигнорируйте это письмо.', 'ITP ТОиР — подтверждение почты'],
            'en' => ['Confirm your registration for ITP MRO.', 'Your verification link is valid for 45 minutes:', 'If you did not request an account, ignore this message.', 'ITP MRO — verify your email'],
            'ge' => ['დაადასტურეთ რეგისტრაცია ITP ТОиР-ში.', 'დადასტურების ბმული მოქმედებს 45 წუთი:', 'თუ რეგისტრაცია არ მოგითხოვიათ, უგულებელყავით ეს წერილი.', 'ITP ТОиР — ელფოსტის დადასტურება'],
            'de' => ['Bestätigen Sie Ihre Registrierung für ITP MRO.', 'Der Link ist 45 Minuten gültig:', 'Falls Sie keine Registrierung angefordert haben, ignorieren Sie diese Nachricht.', 'ITP MRO — E-Mail bestätigen'],
            'fr' => ['Confirmez votre inscription à ITP MRO.', 'Ce lien est valable 45 minutes :', 'Si vous n’avez pas demandé de compte, ignorez ce message.', 'ITP MRO — vérification de l’e-mail'],
            'es' => ['Confirme su registro en ITP MRO.', 'El enlace es válido durante 45 minutos:', 'Si no solicitó una cuenta, ignore este mensaje.', 'ITP MRO — confirmar correo electrónico'],
        ];
        $messageParts = $mailTexts[$locale] ?? $mailTexts['ru'];
        try {
            Mail::raw(
                $messageParts[0]."\n\n".$messageParts[1]."\n".$url."\n\n".$messageParts[2],
                function ($message) use ($email, $messageParts) {
                    $message->to($email)->subject($messageParts[3]);
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
        abort_unless(config('mro_commercial.public_registration_enabled'), 503, 'Public registration is not enabled.');
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
            $snapshot = json_decode($pending->legal_snapshot ?? '', true);
            if (! is_array($snapshot) || empty($snapshot['versions']) || empty($snapshot['locale'])) {
                throw ValidationException::withMessages(['token' => ['Подтвердите актуальные юридические документы заново.']]);
            }
            $current = app(LegalDocumentService::class)->current($snapshot['locale'], 'KZ');
            $expected = array_map(static fn ($v) => $v['id'].':'.$v['sha256'], $snapshot['versions']);
            $actual = array_map(static fn ($v) => $v->id.':'.$v->sha256, $current);
            if ($expected !== $actual) {
                throw ValidationException::withMessages(['token' => ['Юридические условия обновлены. Начните регистрацию заново.']]);
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
            foreach ($current as $version) {
                if (($snapshot['choices'][$version->type] ?? false) !== true) {
                    continue;
                }
                DB::table('mro_legal_consents')->insertOrIgnore([
                    'user_id' => $user->id, 'company_id' => $company->id,
                    'legal_version_id' => $version->id, 'sha256' => $version->sha256,
                    'action' => $version->acceptance, 'source' => 'public_signup',
                    'locale' => $version->locale, 'created_at' => now(),
                ]);
            }
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
