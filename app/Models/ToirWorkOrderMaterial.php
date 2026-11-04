<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToirWorkOrderMaterial extends Model
{
    protected function casts(): array
    {
        return ['quantity_reserved' => 'decimal:3', 'quantity_used' => 'decimal:3', 'actual_cost' => 'decimal:2'];
    }

    protected $table = 'toir_work_order_materials';

    protected $guarded = ['id', 'company_id', 'work_order_id', 'stock_item_id'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(ToirStockItem::class, 'stock_item_id');
    }
}
