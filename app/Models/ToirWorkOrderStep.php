<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToirWorkOrderStep extends Model
{
    protected $table='toir_work_order_steps';
    protected $guarded=['id','company_id','work_order_id','maintenance_step_id',
        'position','performed_by','performed_at'];
    protected function casts(): array
    {
        return ['is_required'=>'boolean','performed_at'=>'datetime','numeric_value'=>'decimal:3'];
    }
    public function order(): BelongsTo
    {
        return $this->belongsTo(ToirWorkOrder::class,'work_order_id');
    }
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class,'performed_by');
    }
}
