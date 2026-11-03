<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ToirCrewShift extends Model
{
    protected $table='toir_crew_shifts';
    protected $guarded=['id'];

    public function crew(): BelongsTo {return $this->belongsTo(ToirCrew::class,'crew_id');}
}
