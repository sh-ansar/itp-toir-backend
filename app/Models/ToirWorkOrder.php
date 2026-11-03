<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ToirWorkOrder extends ToirRecord
{
    protected $table = 'toir_work_orders';

    public function asset(): BelongsTo { return $this->belongsTo(ToirAsset::class, 'asset_id'); }
    public function project(): BelongsTo { return $this->belongsTo(ToirProject::class, 'project_id'); }
    public function maintenancePlan(): BelongsTo { return $this->belongsTo(ToirMaintenancePlan::class, 'maintenance_plan_id'); }
    public function employee(): BelongsTo { return $this->belongsTo(ToirEmployee::class, 'employee_id'); }
    public function events(): HasMany { return $this->hasMany(ToirWorkOrderEvent::class, 'work_order_id')->orderByDesc('id'); }
    public function materials(): HasMany { return $this->hasMany(ToirWorkOrderMaterial::class, 'work_order_id'); }
    public function steps(): HasMany {return $this->hasMany(ToirWorkOrderStep::class,'work_order_id')->orderBy('position');}
    public function crew(): BelongsTo {return $this->belongsTo(ToirCrew::class,'crew_id');}
}
