<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ToirChatMessage extends Model
{
    protected $table='toir_chat_messages';
    protected $guarded=['id','company_id','chat_id','user_id','deleted_at'];
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function receipts(): HasMany { return $this->hasMany(ToirChatReceipt::class,'message_id'); }
    public function reply(): BelongsTo { return $this->belongsTo(self::class,'reply_to_id'); }
}
