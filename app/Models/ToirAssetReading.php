<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ToirAssetReading extends Model
{
    protected $table = 'toir_asset_readings';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['recorded_at' => 'datetime', 'created_at' => 'datetime'];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(ToirAsset::class, 'asset_id');
    }
}
