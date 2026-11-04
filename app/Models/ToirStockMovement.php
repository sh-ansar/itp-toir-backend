<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToirStockMovement extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'delta_on_hand' => 'decimal:3', 'delta_reserved' => 'decimal:3', 'unit_cost' => 'decimal:2'];
    }

    protected $table = 'toir_stock_movements';

    protected $guarded = ['id', 'company_id'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(ToirStockItem::class, 'stock_item_id');
    }
}
