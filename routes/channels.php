<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('toir.company.{companyId}.user.{userId}', function ($user, $companyId, $userId) {
    if ((int)$userId !== (int)$user->id || !$user->fresh()?->is_active) return false;

    return (int)request()->session()->get('toir_company_id') === (int)$companyId
        && $user->activeCompanies()->whereKey((int)$companyId)->exists();
});

Broadcast::channel('toir.company.{companyId}.presence', function ($user, $companyId) {
    $allowed=$user->fresh()?->is_active
        && (int)request()->session()->get('toir_company_id')===(int)$companyId
        && $user->activeCompanies()->whereKey((int)$companyId)->exists();
    return $allowed?['id'=>$user->id,'name'=>$user->name]:false;
});
