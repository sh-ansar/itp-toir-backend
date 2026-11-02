<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Services\AuthAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class InviteToirUser extends Command
{
    protected $signature = 'toir:invite-user
        {--email= : E-mail of the new user}
        {--name= : Full name}
        {--company-code= : Code of an existing active company}
        {--role=viewer : Membership role}';

    protected $description = 'Invite a new user through a one-use password setup link without exposing credentials';

    public function handle(): int
    {
        $input = [
            'email' => Str::lower(trim((string) $this->option('email'))),
            'name' => trim((string) $this->option('name')),
            'company_code' => trim((string) $this->option('company-code')),
            'role' => (string) $this->option('role'),
        ];

        $validation = Validator::make($input, [
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'name' => ['required', 'string', 'max:255'],
            'company_code' => ['required', 'string', 'max:64', 'exists:companies,code'],
            'role' => ['required', Rule::in((array) config('toir.roles'))],
        ]);

        if ($validation->fails()) {
            foreach ($validation->errors()->all() as $error) {
                $this->error($error);
            }
            return self::FAILURE;
        }

        $company = Company::query()
            ->where('code', $input['company_code'])
            ->where('is_active', true)
            ->first();

        if (!$company) {
            $this->error('Company is disabled or missing.');
            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($input, $company): User {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                // Random secret is never printed, sent in mail or used as a temporary login.
                'password' => Str::random(64),
                'is_active' => true,
                'email_verified_at' => null,
            ]);
            $company->users()->attach($user->id, [
                'role' => $input['role'],
                'is_active' => true,
            ]);

            AuthAudit::record('auth.user.invited', $user, $company, [
                'role' => $input['role'],
            ]);

            return $user;
        });

        // Only mailbox possession activates the account: setting the password
        // via this link also marks the email as verified.
        $status = Password::sendResetLink(['email' => $user->email]);

        if ($status !== Password::RESET_LINK_SENT) {
            $this->error('Account created but the invitation email could not be sent. Configure SMTP and resend a recovery link.');
            return self::FAILURE;
        }

        $this->info('User invited. The one-use setup link was delivered through the configured mail transport.');
        return self::SUCCESS;
    }
}
