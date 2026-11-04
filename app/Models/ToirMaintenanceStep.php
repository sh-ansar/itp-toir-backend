<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToirMaintenanceStep extends Model
{
    protected $table = 'toir_maintenance_steps';

    protected $guarded = ['id', 'company_id', 'maintenance_plan_id', 'position'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ToirMaintenancePlan::class, 'maintenance_plan_id');
    }
}
