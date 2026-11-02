<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ToirMaintenancePlan extends ToirRecord
{
    protected $table = 'toir_maintenance_plans';

    public function asset(): BelongsTo { return $this->belongsTo(ToirAsset::class, 'asset_id'); }
    public function employee(): BelongsTo { return $this->belongsTo(ToirEmployee::class, 'employee_id'); }
    public function workOrders(): HasMany { return $this->hasMany(ToirWorkOrder::class, 'maintenance_plan_id'); }
}
