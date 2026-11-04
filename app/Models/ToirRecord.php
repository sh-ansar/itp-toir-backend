<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

abstract class ToirRecord extends Model
{
    use SoftDeletes;

    protected $guarded = ['id', 'company_id', 'deleted_at'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
