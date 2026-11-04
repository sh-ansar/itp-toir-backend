<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ToirAsset extends ToirRecord
{
    protected $table = 'toir_assets';

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function readings(): HasMany
    {
        return $this->hasMany(ToirAssetReading::class, 'asset_id');
    }

    public function maintenancePlans(): HasMany
    {
        return $this->hasMany(ToirMaintenancePlan::class, 'asset_id');
    }
}
