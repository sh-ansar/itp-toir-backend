<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ToirSupply extends ToirRecord
{
    protected $table = 'toir_supplies';

    public function asset(): BelongsTo { return $this->belongsTo(ToirAsset::class, 'asset_id'); }
}
