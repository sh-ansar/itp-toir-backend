<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MroCommercial\PortalAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MroGrantAdmin extends Command
{
    protected $signature = 'mro:grant-admin {email : An existing verified MRO user email} {--permission=platform.manage : Scoped MRO permission}';

    protected $description = 'Grant independent ITP MRO administration to a verified existing MRO account.';

    private const PERMISSIONS = [
        'platform.manage', 'platform.access', 'companies.read', 'users.write',
        'plans.read', 'plans.write', 'billing.read', 'billing.write',
        'legal.read', 'legal.write', 'subscriptions.write',
    ];

    public function handle(): int
    {
        $permission = (string) $this->option('permission');
        if (! in_array($permission, self::PERMISSIONS, true)) {
            $this->error('Unknown MRO permission. No changes made.');

            return self::FAILURE;
        }
        $email = Str::lower(trim((string) $this->argument('email')));
        $user = User::where('email', $email)->first();
        if (! $user || ! $user->email_verified_at || ! $user->is_active || ! $user->activeCompanies()->exists()) {
            $this->error('Verified, enabled and company-linked MRO user required. No changes made.');

            return self::FAILURE;
        }
        $count = DB::transaction(function () use ($user, $permission): int {
            $count = DB::table('mro_admin_grants')->insertOrIgnore([
                'user_id' => $user->id, 'permission' => $permission,
                'granted_by' => null, 'created_at' => now(),
            ]);
            if ($count) {
                PortalAudit::record(null, null, 'admin.bootstrap', 'user:'.$user->id, [
                    'permission' => $permission, 'source' => 'local_console',
                ]);
            }

            return $count;
        });
        $this->info($count ? 'MRO grant created. Portal administration remains isolated.' : 'Grant already exists; no changes made.');

        return self::SUCCESS;
    }
}
