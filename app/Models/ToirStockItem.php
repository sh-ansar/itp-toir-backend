<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ToirStockItem extends Model
{
    protected function casts(): array
    {
        return ['quantity_on_hand' => 'decimal:3', 'quantity_reserved' => 'decimal:3', 'unit_cost' => 'decimal:2', 'minimum_quantity' => 'decimal:3'];
    }

    protected $table = 'toir_stock_items';

    protected $guarded = ['id', 'company_id', 'quantity_on_hand', 'quantity_reserved'];

    public function movements(): HasMany
    {
        return $this->hasMany(ToirStockMovement::class, 'stock_item_id');
    }

    public function materialLines(): HasMany
    {
        return $this->hasMany(ToirWorkOrderMaterial::class, 'stock_item_id');
    }
}
