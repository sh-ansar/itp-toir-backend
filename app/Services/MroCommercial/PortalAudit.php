<?php

namespace App\Services\MroCommercial;

use Illuminate\Support\Facades\DB;

final class PortalAudit
{
    public static function record(?int $actor, ?int $company, string $action, string $subject, array $data = []): void
    {
        DB::table('mro_audits')->insert(['actor_id' => $actor, 'company_id' => $company, 'action' => $action, 'subject' => $subject, 'data' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
    }
}
