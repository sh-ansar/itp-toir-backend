<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AuthAudit
{
    /** Security events never include credentials, reset tokens or raw session cookies. */
    public static function record(
        string $action,
        ?User $user,
        ?Company $company = null,
        array $metadata = []
    ): void {
        DB::table('toir_audit_events')->insert([
            'actor_id' => $user?->id,
            'company_id' => $company?->id,
            'action' => $action,
            'target_type' => $user ? 'user' : null,
            'target_id' => $user ? (string) $user->id : null,
            'metadata' => $metadata ? json_encode($metadata, JSON_THROW_ON_ERROR) : null,
            'created_at' => now(),
        ]);
    }
}
