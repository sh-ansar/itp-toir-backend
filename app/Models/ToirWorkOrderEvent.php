<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ToirWorkOrderEvent extends Model
{
    public $timestamps = false;
    protected $table = 'toir_work_order_events';
    protected $guarded = ['id', 'company_id'];
    protected function casts(): array { return ['changes' => 'array']; }
}
