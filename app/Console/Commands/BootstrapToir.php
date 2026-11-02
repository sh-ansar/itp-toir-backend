<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class BootstrapToir extends Command
{
    protected $signature = 'toir:bootstrap
        {--email= : E-mail первого администратора}
        {--name= : Имя первого администратора}
        {--company= : Название компании}
        {--code= : Код компании}';

    protected $description = 'Creates the first verified TOIR administrator without saving plaintext credentials';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) ($this->option('email') ?: $this->ask('E-mail'))));
        $name = trim((string) ($this->option('name') ?: $this->ask('Имя')));
        $companyName = trim((string) ($this->option('company') ?: $this->ask('Компания')));
        $companyCode = trim((string) ($this->option('code') ?: $this->ask('Код компании')));
        $password = (string) $this->secret('Пароль (не менее 12 символов, верхний/нижний регистр, цифра, символ)');
        $confirm = (string) $this->secret('Повторите пароль');

        $data = compact('email', 'name', 'password');
        $data['company_name'] = $companyName;
        $data['company_code'] = $companyCode;
        $data['password_confirmation'] = $confirm;

        $validator = Validator::make($data, [
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['required', 'string', 'max:255'],
            'company_code' => ['required', 'alpha_dash:ascii', 'max:64'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($data): void {
            $company = Company::firstOrCreate(
                ['code' => $data['company_code']],
                ['name' => $data['company_name'], 'is_active' => true]
            );

            if (!$company->is_active) {
                throw new \RuntimeException('Компания отключена.');
            }

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'], // hashed via User cast
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $company->users()->attach($user->id, ['role' => 'owner', 'is_active' => true]);
        });

        $this->info('Администратор создан. Учётные данные никому не отправлялись.');
        return self::SUCCESS;
    }
}
