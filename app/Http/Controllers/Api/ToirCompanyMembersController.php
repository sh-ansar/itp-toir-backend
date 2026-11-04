<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Notifications\ToirCompanyAccessNotification;
use App\Services\AuthAudit;
use App\Services\ToirMailReadiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ToirCompanyMembersController extends Controller
{
    private const ROLES = ['owner', 'admin', 'manager', 'engineer', 'technician', 'viewer'];

    private function company(Request $request): Company
    {
        $company = $request->user()->activeCompanies()
            ->whereKey($request->session()->get('toir_company_id'))->first();
        abort_unless($company, 403, 'Нет доступа к компании.');
        abort_unless(in_array($company->pivot->role, ['owner', 'admin'], true), 403, 'Управление участниками доступно только владельцам и администраторам.');

        return $company;
    }

    private function requireOwner(Company $company): void
    {
        abort_unless($company->pivot->role === 'owner', 403, 'Назначать владельца может только владелец.');
    }

    private function lastOwner(Company $company, User $user): bool
    {
        return $company->users()->where('users.id', $user->id)->wherePivot('role', 'owner')
            ->wherePivot('is_active', true)->exists()
            && $company->users()->wherePivot('role', 'owner')->wherePivot('is_active', true)->count() <= 1;
    }

    private function mailEnabled(): bool
    {
        return app(ToirMailReadiness::class)->configured();
    }

    public function mailStatus(Request $request): JsonResponse
    {
        $this->company($request);

        return response()->json(['data' => ['delivery_enabled' => $this->mailEnabled()]]);
    }

    public function index(Request $request): JsonResponse
    {
        $company = $this->company($request);

        return response()->json(['data' => $company->users()
            ->select(['users.id', 'users.name', 'users.email', 'users.email_verified_at', 'users.is_active'])
            ->orderBy('users.name')->get()->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email,
                'role' => $u->pivot->role, 'membership_active' => (bool) $u->pivot->is_active,
                'email_verified' => (bool) $u->email_verified_at, 'account_active' => (bool) $u->is_active,
            ])]);
    }

    public function invite(Request $request): JsonResponse
    {
        $company = $this->company($request);
        if (! $this->mailEnabled()) {
            return response()->json(['message' => 'SMTP не настроен. Приглашения отключены.'], 503);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::in(self::ROLES)],
        ]);
        if ($data['role'] === 'owner') {
            $this->requireOwner($company);
        }
        $email = Str::lower(trim($data['email']));
        $user = DB::transaction(function () use ($company, $email, $data, $request) {
            $user = User::query()->where('email', $email)->lockForUpdate()->first();
            if (! $user) {
                $user = User::create(['name' => trim($data['name']), 'email' => $email,
                    'password' => Str::random(64), 'is_active' => true, 'email_verified_at' => null]);
            }
            if (! $user->is_active) {
                throw ValidationException::withMessages(['email' => 'Учётная запись отключена.']);
            }
            $current = $company->users()->where('users.id', $user->id)->first();
            if ($current && $current->pivot->is_active) {
                throw ValidationException::withMessages(['email' => 'Пользователь уже является участником компании.']);
            }
            $company->users()->syncWithoutDetaching([$user->id => ['role' => $data['role'], 'is_active' => true]]);
            AuthAudit::record('auth.member.invited', $request->user(), $company,
                ['target_user_id' => $user->id, 'role' => $data['role']]);

            return $user;
        });
        // Membership can be saved while mail transport fails. Always report that distinction.
        if ($user->email_verified_at) {
            try {
                $user->notify(new ToirCompanyAccessNotification($company->name));
            } catch (\Throwable $exception) {
                report($exception);

                return response()->json([
                    'data' => ['id' => $user->id, 'email' => $email, 'email_sent' => false],
                    'message' => 'Доступ предоставлен, но email-уведомление не отправлено.',
                ], 202);
            }

            return response()->json([
                'data' => ['id' => $user->id, 'email' => $email, 'email_sent' => false],
                'message' => 'Доступ предоставлен. Пользователь уведомлён по email.',
            ], 201);
        }
        try {
            $status = Password::sendResetLink(['email' => $email]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'data' => ['id' => $user->id, 'email' => $email, 'email_sent' => false],
                'message' => 'Участник добавлен, но письмо не отправлено. Повторите отправку из карточки.',
            ], 202);
        }
        if ($status !== Password::RESET_LINK_SENT) {
            return response()->json([
                'data' => ['id' => $user->id, 'email' => $email, 'email_sent' => false],
                'message' => 'Участник добавлен, но активация не отправлена. Повторите позже.',
            ], 202);
        }

        return response()->json([
            'data' => ['id' => $user->id, 'email' => $email, 'email_sent' => true],
            'message' => 'Участник добавлен. Ссылка активации отправлена.',
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $company = $this->company($request);
        $data = $request->validate([
            'role' => ['required', Rule::in(self::ROLES)],
            'membership_active' => ['required', 'boolean'],
        ]);
        $target = DB::transaction(function () use ($request, $company, $id, $data) {
            $target = $company->users()->where('users.id', $id)->lockForUpdate()->firstOrFail();
            if ($target->pivot->role === 'owner' || $data['role'] === 'owner') {
                $this->requireOwner($company);
            }
            if ($this->lastOwner($company, $target) && ($data['role'] !== 'owner' || ! $data['membership_active'])) {
                throw ValidationException::withMessages(['role' => 'Нельзя отключить или понизить последнего владельца компании.']);
            }
            if ($target->id === $request->user()->id && ! $data['membership_active']) {
                throw ValidationException::withMessages(['membership_active' => 'Нельзя отключить собственное членство.']);
            }
            $company->users()->updateExistingPivot($target->id, [
                'role' => $data['role'], 'is_active' => $data['membership_active'],
            ]);
            AuthAudit::record('auth.member.changed', $request->user(), $company, [
                'target_user_id' => $target->id, 'role' => $data['role'],
                'membership_active' => (bool) $data['membership_active'],
            ]);
            // Ensure changed permissions take effect in other browser sessions.
            DB::table('sessions')->where('user_id', $target->id)
                ->where('id', '!=', $request->session()->getId())->delete();

            return $target;
        });

        return response()->json(['data' => ['id' => $target->id, 'role' => $data['role'], 'membership_active' => (bool) $data['membership_active']]]);
    }

    public function resend(Request $request, int $id): JsonResponse
    {
        $company = $this->company($request);
        if (! $this->mailEnabled()) {
            return response()->json(['message' => 'SMTP не настроен.'], 503);
        }
        $user = $company->users()->where('users.id', $id)->wherePivot('is_active', true)->firstOrFail();
        if ($user->email_verified_at) {
            throw ValidationException::withMessages(['email' => 'Почта уже подтверждена.']);
        }
        try {
            $status = Password::sendResetLink(['email' => $user->email]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Не удалось отправить письмо через SMTP.'], 503);
        }
        if ($status === Password::RESET_THROTTLED) {
            return response()->json(['message' => 'Письмо уже недавно отправлено. Повторите позже.'], 429);
        }
        if ($status !== Password::RESET_LINK_SENT) {
            return response()->json(['message' => 'Не удалось отправить письмо.'], 503);
        }
        AuthAudit::record('auth.member.invitation_resent', $request->user(), $company, ['target_user_id' => $user->id]);

        return response()->json(['message' => 'Ссылка для активации отправлена.']);
    }
}
