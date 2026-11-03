<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ToirCrew extends Model
{
    protected $table='toir_crews';
    protected $guarded=['id'];

    public function lead(): BelongsTo { return $this->belongsTo(ToirEmployee::class,'lead_employee_id'); }
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(ToirEmployee::class,'toir_crew_members','crew_id','employee_id')
            ->withPivot(['role','company_id'])->withTimestamps();
    }
    public function shifts(): HasMany {return $this->hasMany(ToirCrewShift::class,'crew_id');}
}
